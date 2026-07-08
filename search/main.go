// Command search fronts the anonymous browse/collection traffic for the
// Islandora site. Traefik routes anonymous GET HTML here; this service renders
// /browse and collection pages directly from Solr (TODO) and reverse-proxies
// everything else to the existing Drupal (static) upstream.
package main

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

type config struct {
	listenAddr      string
	upstream        string
	dsn             string
	refreshEvery    time.Duration
	cacheFile       string
	collectionURI   string
	compoundURI     string
	solrURL         string
	solrIndexID     string
	excludeModels   []string
	solrRagURL      string
	embeddingURL    string
	embeddingDim    int
	semanticHybrid  bool
	llmSummaryURL   string
	llmSummaryModel string
}

func loadConfig() (config, error) {
	c := config{
		listenAddr:      getenv("LISTEN_ADDR", ":8080"),
		upstream:        getenv("DRUPAL_UPSTREAM", "http://drupal-static:80"),
		cacheFile:       getenv("COLLECTION_CACHE_FILE", "/var/lib/search/collections.json"),
		collectionURI:   getenv("COLLECTION_MODEL_URI", "http://purl.org/dc/dcmitype/Collection"),
		compoundURI:     getenv("COMPOUND_MODEL_URI", "http://vocab.getty.edu/aat/300242735"),
		solrURL:         getenv("SOLR_URL", "http://solr:8983/solr/default"),
		solrIndexID:     getenv("SOLR_INDEX_ID", "default_solr_index"),
		excludeModels:   splitCSV(getenv("BROWSE_EXCLUDE_MODELS", "27821,23,28")),
		solrRagURL:      getenv("SOLR_RAG_URL", "http://solr:8983/solr/islandora_rag"),
		embeddingURL:    embeddingServiceURL,
		embeddingDim:    getenvInt("EMBEDDING_DIMENSION", 1024),
		semanticHybrid:  getenv("SEMANTIC_HYBRID", "true") != "false",
		llmSummaryURL:   getenv("LLM_SUMMARY_URL", getenv("OLLAMA_URL", "")),
		llmSummaryModel: getenv("LLM_SUMMARY_MODEL", getenv("OLLAMA_MODEL", "")),
	}

	d, err := time.ParseDuration(getenv("COLLECTION_REFRESH", "5m"))
	if err != nil {
		return c, fmt.Errorf("invalid COLLECTION_REFRESH: %w", err)
	}
	c.refreshEvery = d

	pw, err := dbPassword()
	if err != nil {
		return c, err
	}
	c.dsn = fmt.Sprintf(
		"%s:%s@tcp(%s:%s)/%s?parseTime=true&timeout=5s&readTimeout=5s",
		getenv("DRUPAL_DEFAULT_DB_USER", "drupal_default"),
		pw,
		getenv("DB_MYSQL_HOST", "mariadb"),
		getenv("DB_MYSQL_PORT", "3306"),
		getenv("DRUPAL_DEFAULT_DB_NAME", "drupal_default"),
	)
	return c, nil
}

func main() {
	log := slog.New(slog.NewJSONHandler(os.Stderr, nil))

	cfg, err := loadConfig()
	if err != nil {
		log.Error("load config", "err", err)
		os.Exit(1)
	}

	upstreamURL, err := url.Parse(cfg.upstream)
	if err != nil {
		log.Error("parse DRUPAL_UPSTREAM", "url", cfg.upstream, "err", err)
		os.Exit(1)
	}

	db, err := sql.Open("mysql", cfg.dsn)
	if err != nil {
		log.Error("open db", "err", err)
		os.Exit(1)
	}
	defer db.Close()
	db.SetMaxOpenConns(4)
	db.SetConnMaxLifetime(time.Minute)

	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancel()

	store := newStore(db, cfg.cacheFile, cfg.collectionURI, cfg.compoundURI, log)
	// Warm from the on-disk snapshot so a cold start (or a DB blip) still has
	// routing data before the first successful refresh.
	if err := store.loadFromDisk(); err != nil {
		log.Warn("load collection cache from disk", "err", err)
	}
	if err := store.refresh(ctx); err != nil {
		log.Warn("initial collection refresh", "err", err)
	}
	go store.refreshLoop(ctx, cfg.refreshEvery)

	proxy := newProxy(upstreamURL, log)
	sh := newShell(getenv("SHELL_DIR", "/var/lib/search/shell"), log)
	solr := newSolrClient(cfg.solrURL)
	renderers := newRenderers(sh, solr, browseOpts{indexID: cfg.solrIndexID, excludeModels: cfg.excludeModels})

	embed := newEmbeddingClient(cfg.embeddingURL, cfg.embeddingDim)
	ragSolr := newSolrClient(cfg.solrRagURL)
	summarizer := newOllamaSummarizer(cfg.llmSummaryURL, cfg.llmSummaryModel)
	var semanticObjects *solrClient
	if cfg.semanticHybrid {
		semanticObjects = solr
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/healthcheck", func(w http.ResponseWriter, _ *http.Request) {
		io.WriteString(w, "ok")
	})
	mux.HandleFunc("/_go-search/browse", browseFragmentHandler(solr, browseOpts{indexID: cfg.solrIndexID, excludeModels: cfg.excludeModels}, log))
	mux.HandleFunc("/semantic-search", semanticHandler(embed, ragSolr, semanticObjects, summarizer, semanticSearchOptions{}, log))
	mux.HandleFunc("/", handler(store, proxy, renderers))

	srv := &http.Server{
		Addr:              cfg.listenAddr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
	}

	go func() {
		<-ctx.Done()
		shutCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		_ = srv.Shutdown(shutCtx)
	}()

	log.Info("search listening", "addr", cfg.listenAddr, "upstream", cfg.upstream)
	if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Error("serve", "err", err)
		os.Exit(1)
	}
}

// handler classifies the request and dispatches to the Renderer registered for
// its kind; anything not handled by search is proxied to Drupal unchanged.
func handler(store *store, proxy *httputil.ReverseProxy, renderers map[string]Renderer) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		p := normalizePath(r.URL.Path)

		page, ok := Page{Kind: kindBrowse}, p == "/browse"
		if !ok {
			page, ok = store.lookup(p)
		}
		if ok {
			if rnd, has := renderers[page.Kind]; has {
				page.Path, page.Req = p, r
				w.Header().Set("X-Handled-By", "search")
				w.Header().Set("X-Render-Kind", page.Kind)
				rnd.Render(w, page)
				return
			}
		}
		proxy.ServeHTTP(w, r)
	}
}

func newProxy(target *url.URL, log *slog.Logger) *httputil.ReverseProxy {
	return &httputil.ReverseProxy{
		Rewrite: func(pr *httputil.ProxyRequest) {
			pr.SetURL(target)
			// Preserve the original Host so Drupal builds correct absolute URLs.
			pr.Out.Host = pr.In.Host
		},
		ErrorHandler: func(w http.ResponseWriter, r *http.Request, err error) {
			log.Error("upstream proxy", "path", r.URL.Path, "err", err)
			http.Error(w, "bad gateway", http.StatusBadGateway)
		},
	}
}

// normalizePath lowercases and strips a trailing slash so incoming requests
// match the pathauto-generated aliases stored in Drupal.
func normalizePath(p string) string {
	p = strings.ToLower(p)
	if len(p) > 1 {
		p = strings.TrimRight(p, "/")
	}
	if !strings.HasPrefix(p, "/") {
		p = "/" + p
	}
	return p
}

// splitCSV parses a comma-separated env value, trimming and dropping blanks.
func splitCSV(s string) []string {
	var out []string
	for p := range strings.SplitSeq(s, ",") {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p)
		}
	}
	return out
}

func getenv(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}

func getenvInt(k string, def int) int {
	v := os.Getenv(k)
	if v == "" {
		return def
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		return def
	}
	return n
}

// dbPassword reads the mounted secret file, falling back to an env var for
// local runs without Docker secrets.
func dbPassword() (string, error) {
	if v := os.Getenv("DRUPAL_DEFAULT_DB_PASSWORD"); v != "" {
		return v, nil
	}
	f := getenv("DRUPAL_DEFAULT_DB_PASSWORD_FILE", "/run/secrets/DRUPAL_DEFAULT_DB_PASSWORD")
	b, err := os.ReadFile(f)
	if err != nil {
		return "", fmt.Errorf("read db password: %w", err)
	}
	return strings.TrimSpace(string(b)), nil
}
