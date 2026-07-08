package main

import (
	"context"
	"fmt"
	"html/template"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

// Renderer turns a classified Page into an HTML response. Registering one per
// kind is how a new metadata-driven theme is added — no routing changes.
type Renderer interface {
	Render(w http.ResponseWriter, p Page)
}

func newRenderers(sh *shell, solr *solrClient, bo browseOpts) map[string]Renderer {
	return map[string]Renderer{
		kindBrowse:     browseRenderer{sh: sh, solr: solr, opts: bo},
		kindCollection: collectionRenderer{sh: sh, solr: solr, opts: bo},
		kindCompound:   compoundRenderer{sh},
	}
}

// shell wraps body HTML in the site header/footer. These are static files
// maintained on disk (SHELL_DIR) so the Go service matches the Drupal theme;
// if absent we fall back to a minimal document so the service still renders.
type shell struct {
	dir string
	log *slog.Logger
}

func newShell(dir string, log *slog.Logger) *shell { return &shell{dir: dir, log: log} }

func (s *shell) write(w http.ResponseWriter, title string, body template.HTML) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	header, herr := os.ReadFile(filepath.Join(s.dir, "header.html"))
	footer, ferr := os.ReadFile(filepath.Join(s.dir, "footer.html"))
	if herr == nil && ferr == nil {
		_, _ = w.Write(header)
		_, _ = io.WriteString(w, string(body))
		_, _ = w.Write(footer)
		return
	}
	fmt.Fprintf(w,
		"<!doctype html><html><head><meta charset=\"utf-8\"><title>%s</title></head><body>%s</body></html>",
		template.HTMLEscapeString(title), body)
}

// browseOpts carries the environment-specific bits of the browse query.
type browseOpts struct {
	indexID       string   // search_api index id (Solr `index_id` field)
	excludeModels []string // model term ids hidden from browse (view's field_model NOT IN)
}

var browsePageSizes = map[int]bool{24: true, 48: true, 96: true, 240: true}

type facetSpec struct {
	Alias string
	Label string
	Field string
}

var browseFacets = []facetSpec{
	{Alias: "member_of", Label: "Sub-Collections", Field: solrFieldMember},
	{Alias: "model", Label: "Model", Field: solrFieldModel},
	{Alias: "material_type", Label: "Material Type", Field: "itm_field_resource_type"},
	{Alias: "media_type", Label: "Media Type", Field: solrFieldMedia},
	{Alias: "date_created_items", Label: "Date Created", Field: "its_edtf_year"},
	{Alias: "genre", Label: "Genre", Field: solrFieldGenre},
	{Alias: "keywords", Label: "Keywords", Field: "itm_field_keywords"},
	{Alias: "collection", Label: "Collection", Field: "itm_field_collection_hierarchy"},
	{Alias: "places", Label: "Places", Field: "itm_field_geographic_subject"},
	{Alias: "publisher", Label: "Publisher", Field: solrFieldPub},
	{Alias: "subject_general", Label: "Subject", Field: "itm_field_subject_general"},
	{Alias: "subject_lcsh", Label: "Subject LCSH", Field: "itm_field_subject_lcsh"},
	{Alias: "subject_name", Label: "Subject Name", Field: "itm_field_subjects_name"},
	{Alias: "subject_temporal", Label: "Temporal Subject", Field: "itm_field_temporal_subject"},
	{Alias: "subject_topical", Label: "Topical Subject", Field: "itm_field_subject"},
}

var browseFacetByAlias = func() map[string]facetSpec {
	out := make(map[string]facetSpec, len(browseFacets))
	for _, spec := range browseFacets {
		out[spec.Alias] = spec
	}
	return out
}()

// browseRenderer serves /browse (all items across all collections), replicating
// the browse view's Solr query: islandora_object, minus excluded models,
// published only (this service is anonymous-only), sorted by relevance then
// field_weight. The grid is the pre-rendered `zs_card` per doc.
type browseRenderer struct {
	sh   *shell
	solr *solrClient
	opts browseOpts
}

func (r browseRenderer) Render(w http.ResponseWriter, p Page) {
	body, err := browseFragment(p.Req.Context(), r.solr, r.opts, p.Req.URL.Query())
	if err != nil {
		r.sh.log.Error("browse solr query", "err", err)
		http.Error(w, "search temporarily unavailable", http.StatusBadGateway)
		return
	}

	r.sh.write(w, "Browse Items", body)
}

func browseFragment(ctx context.Context, solr *solrClient, opts browseOpts, q url.Values) (template.HTML, error) {
	rows := 24
	if v, err := strconv.Atoi(q.Get("items_per_page")); err == nil && browsePageSizes[v] {
		rows = v
	}
	page := 0
	if v, err := strconv.Atoi(q.Get("page")); err == nil && v > 0 {
		page = v
	}
	start := page * rows

	params := url.Values{}
	fulltext := strings.TrimSpace(q.Get("search_api_fulltext"))
	if fulltext == "" {
		params.Set("q", "*:*")
	} else {
		params.Set("defType", "edismax")
		params.Set("q", fulltext)
		params.Set("qf", solrObjectTextQF)
	}
	params.Add("fq", solrFieldType+":islandora_object")
	if len(opts.excludeModels) > 0 {
		params.Add("fq", "-"+solrFieldModel+":("+strings.Join(opts.excludeModels, " ")+")")
	}
	// Anonymous-only service, so always restrict to published content.
	params.Add("fq", solrFieldStatus+":true")
	if opts.indexID != "" {
		params.Add("fq", "index_id:"+opts.indexID)
	}
	if nodeID, ok := collectionNodeID(q); ok {
		params.Add("fq", solrFieldMember+":"+strconv.Itoa(nodeID))
	}
	for _, fq := range facetFilterQueries(q) {
		params.Add("fq", fq)
	}
	params.Set("facet", "true")
	params.Set("facet.mincount", "1")
	params.Set("facet.limit", "10")
	params.Set("facet.sort", "count")
	for _, spec := range browseFacets {
		params.Add("facet.field", spec.Field)
	}
	params.Set("sort", "score desc, "+solrFieldWeight+" asc")
	params.Set("fl", solrFieldCard)
	params.Set("rows", strconv.Itoa(rows))
	params.Set("start", strconv.Itoa(start))

	res, err := solr.selectQuery(ctx, params)
	if err != nil {
		return "", err
	}

	return browseBody(res, q, start, rows), nil
}

func facetFilterQueries(q url.Values) []string {
	included := map[string][]string{}
	excluded := map[string][]string{}
	for _, raw := range facetParamValues(q) {
		alias, value, exclude, ok := parseFacetFilter(raw)
		if !ok {
			continue
		}
		spec, ok := browseFacetByAlias[alias]
		if !ok || value == "" {
			continue
		}
		if exclude {
			excluded[spec.Field] = append(excluded[spec.Field], value)
			continue
		}
		included[spec.Field] = append(included[spec.Field], value)
	}

	out := make([]string, 0, len(included)+len(excluded))
	for field, values := range included {
		out = append(out, field+":("+solrTerms(values)+")")
	}
	for field, values := range excluded {
		out = append(out, "-"+field+":("+solrTerms(values)+")")
	}
	return out
}

func facetParamValues(q url.Values) []string {
	var out []string
	for key, values := range q {
		if key != "f" && !strings.HasPrefix(key, "f[") {
			continue
		}
		out = append(out, values...)
	}
	return out
}

func parseFacetFilter(raw string) (string, string, bool, bool) {
	raw = strings.TrimSpace(raw)
	exclude := false
	if strings.HasPrefix(raw, "-") {
		exclude = true
		raw = strings.TrimSpace(strings.TrimPrefix(raw, "-"))
	}
	alias, value, ok := strings.Cut(raw, ":")
	if !ok {
		return "", "", false, false
	}
	alias = strings.TrimSpace(alias)
	value = strings.TrimSpace(value)
	if alias == "" || value == "" {
		return "", "", false, false
	}
	return alias, value, exclude, true
}

func solrTerms(values []string) string {
	terms := make([]string, 0, len(values))
	for _, value := range values {
		terms = append(terms, solrTerm(value))
	}
	return strings.Join(terms, " ")
}

func solrTerm(value string) string {
	if _, err := strconv.Atoi(value); err == nil {
		return value
	}
	replacer := strings.NewReplacer(`\`, `\\`, `"`, `\"`)
	return `"` + replacer.Replace(value) + `"`
}

func collectionNodeID(q url.Values) (int, bool) {
	nodeID, err := strconv.Atoi(strings.TrimSpace(q.Get("node_id")))
	if err != nil || nodeID <= 0 {
		return 0, false
	}
	return nodeID, true
}

func browseFragmentHandler(solr *solrClient, opts browseOpts, log *slog.Logger) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		body, err := browseFragment(r.Context(), solr, opts, r.URL.Query())
		if err != nil {
			log.Error("browse fragment solr query", "err", err)
			http.Error(w, "search temporarily unavailable", http.StatusBadGateway)
			return
		}
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		w.Header().Set("Cache-Control", "private, no-store")
		_, _ = io.WriteString(w, string(body))
	}
}

// browseBody renders the result summary, the card grid, and a pager.
func browseBody(res *solrResponse, q url.Values, start, rows int) template.HTML {
	var grid strings.Builder
	for _, doc := range res.Response.Docs {
		card := docString(doc, solrFieldCard)
		if card == "" {
			continue
		}
		grid.WriteString(`<div class="views-row"><span class="field-content">`)
		grid.WriteString(card) // trusted, Drupal-rendered HTML
		grid.WriteString(`</span></div>`)
	}

	total := res.Response.NumFound
	first := start + 1
	if total == 0 {
		first = 0
	}
	last := start + len(res.Response.Docs)

	var b strings.Builder
	b.WriteString(`<div class="go-search-layout">`)
	b.WriteString(renderFacets(res, q))
	b.WriteString(`<div class="go-search-results">`)
	fmt.Fprintf(&b, `<div class="result-summary">Viewing items <strong>%d</strong> - <strong>%d</strong> of <strong>%d</strong></div>`, first, last, total)
	b.WriteString(`<div style="--grid-columns-sml: 1; --grid-columns-med: 2; --grid-columns-lrg: 3; --grid-columns-xlrg: 3;" class="themed-grid rows">`)
	b.WriteString(grid.String())
	b.WriteString(`</div>`)
	b.WriteString(browsePager(q, start, rows, total))
	b.WriteString(`</div></div>`)
	return template.HTML(b.String())
}

func renderFacets(res *solrResponse, q url.Values) string {
	if len(res.FacetCounts.FacetFields) == 0 {
		return ""
	}

	var b strings.Builder
	b.WriteString(`<aside class="left"><div class="search-facets-list" id="form-facets">`)
	for _, spec := range browseFacets {
		items := facetItems(res.FacetCounts.FacetFields[spec.Field])
		if len(items) == 0 {
			continue
		}
		fmt.Fprintf(&b, `<div class="block-facets"><h3>%s</h3><ul>`, template.HTMLEscapeString(spec.Label))
		for _, item := range items {
			includeRaw := spec.Alias + ":" + item.Value
			excludeRaw := "-" + includeRaw
			includeActive := facetActive(q, includeRaw)
			excludeActive := facetActive(q, excludeRaw)
			includeLink := facetURL(q, includeRaw, includeActive)
			excludeLink := facetURL(q, excludeRaw, excludeActive)
			includeClass := "facet-action facet-action--include"
			if includeActive {
				includeClass += " is-active"
			}
			excludeClass := "facet-action facet-action--exclude"
			if excludeActive {
				excludeClass += " is-active"
			}
			fmt.Fprintf(
				&b,
				`<li class="facet-item"><span class="facet-item__actions"><a class="%s" href="%s" aria-label="Include %s">+</a><a class="%s" href="%s" aria-label="Exclude %s">-</a></span> <span class="facet-item__value">%s</span> <span class="facet-item__count">(%d)</span></li>`,
				includeClass,
				template.HTMLEscapeString(includeLink),
				template.HTMLEscapeString(item.Value),
				excludeClass,
				template.HTMLEscapeString(excludeLink),
				template.HTMLEscapeString(item.Value),
				template.HTMLEscapeString(item.Value),
				item.Count,
			)
		}
		b.WriteString(`</ul></div>`)
	}
	b.WriteString(`</div></aside>`)
	return b.String()
}

type facetItem struct {
	Value string
	Count int64
}

func facetItems(raw []any) []facetItem {
	out := make([]facetItem, 0, len(raw)/2)
	for i := 0; i+1 < len(raw); i += 2 {
		value := fmt.Sprint(raw[i])
		count, ok := anyInt64(raw[i+1])
		if value == "" || !ok || count <= 0 {
			continue
		}
		out = append(out, facetItem{Value: value, Count: count})
	}
	return out
}

func anyInt64(v any) (int64, bool) {
	switch t := v.(type) {
	case float64:
		return int64(t), true
	case int64:
		return t, true
	case int:
		return int64(t), true
	default:
		return 0, false
	}
}

func facetActive(q url.Values, raw string) bool {
	for _, existing := range facetParamValues(q) {
		if existing == raw {
			return true
		}
	}
	return false
}

func facetURL(q url.Values, raw string, remove bool) string {
	u := url.Values{}
	var facets []string
	for k, vs := range q {
		if k == "page" {
			continue
		}
		if k != "f" && !strings.HasPrefix(k, "f[") {
			u[k] = vs
			continue
		}
		for _, existing := range vs {
			if remove && existing == raw {
				continue
			}
			facets = append(facets, existing)
		}
	}
	if !remove {
		facets = append(facets, raw)
	}
	query := u.Encode()
	for i, facet := range facets {
		if query != "" {
			query += "&"
		}
		query += fmt.Sprintf("f%%5B%d%%5D=%s", i, url.QueryEscape(facet))
	}
	return "?" + query
}

// browsePager renders a minimal prev/next pager, preserving other query params.
func browsePager(q url.Values, start, rows int, total int64) string {
	if rows <= 0 {
		return ""
	}
	cur := start / rows
	lastPage := max(int((total-1)/int64(rows)), 0)
	pageURL := func(n int) string {
		u := url.Values{}
		for k, vs := range q {
			if k == "page" {
				continue
			}
			u[k] = vs
		}
		u.Set("page", strconv.Itoa(n))
		return "?" + u.Encode()
	}

	var b strings.Builder
	b.WriteString(`<nav class="pager" role="navigation" aria-label="Pagination"><ul class="pager__items js-pager__items">`)
	if cur > 0 {
		fmt.Fprintf(&b, `<li class="pager__item pager__item--previous"><a href="%s" rel="prev" title="Go to previous page">Previous</a></li>`, pageURL(cur-1))
	}
	fmt.Fprintf(&b, `<li class="pager__item"><span>Page %d of %d</span></li>`, cur+1, lastPage+1)
	if cur < lastPage {
		fmt.Fprintf(&b, `<li class="pager__item pager__item--next"><a href="%s" rel="next" title="Go to next page">Next</a></li>`, pageURL(cur+1))
	}
	b.WriteString(`</ul></nav>`)
	return b.String()
}

// collectionRenderer serves a collection: the collection tabs (rendered fully
// here) with the "View this collection" pane holding the members grid.
type collectionRenderer struct {
	sh   *shell
	solr *solrClient
	opts browseOpts
}

func (r collectionRenderer) Render(w http.ResponseWriter, p Page) {
	q := collectionBrowseQuery(p)
	grid, err := browseFragment(p.Req.Context(), r.solr, r.opts, q)
	if err != nil {
		r.sh.log.Error("collection solr query", "err", err, "nid", p.NID)
		http.Error(w, "search temporarily unavailable", http.StatusBadGateway)
		return
	}
	r.sh.write(w, "Collection", renderCollectionTabs(p, grid))
}

func collectionBrowseQuery(p Page) url.Values {
	q := url.Values{}
	if p.Req != nil {
		for key, values := range p.Req.URL.Query() {
			q[key] = append([]string(nil), values...)
		}
	}
	if p.NID > 0 {
		q.Set("node_id", strconv.FormatInt(p.NID, 10))
	}
	return q
}

// compoundRenderer serves a compound object: metadata block on top + ordered
// child list. Both TODO; tabs still render if the node has them.
type compoundRenderer struct{ sh *shell }

func (r compoundRenderer) Render(w http.ResponseWriter, p Page) {
	children := template.HTML(`<div class="compound-children"><!-- ordered children list TODO (Solr) --></div>`)
	body := template.HTML(`<section id="primary-content" class="region">` +
		`<div class="compound-metadata"><!-- metadata block TODO --></div>` +
		string(renderCollectionTabs(p, children)) + `</section>`)
	r.sh.write(w, "Compound Object", body)
}

// --- collection tabs ---------------------------------------------------------

type tabVM struct {
	ID      string
	Label   string
	Content template.HTML
	Default bool
	Active  bool
}

type sectionVM struct {
	Label   string
	Content template.HTML
	HasMap  bool
	NID     int64
}

var (
	tabsTmpl = template.Must(template.New("tabs").Parse(`<ul class="nav nav-tabs justify-content-end nav-fill bg-light opacity-75" role="tablist">
{{- range .}}
  <li class="nav-item m-0 p-0 border border-black border-1" role="presentation">
    <a href="#{{.ID}}" id="{{.ID}}-tab" class="nav-link fs-5 pt-3 fw-medium text-dark{{if .Active}} active{{end}}" data-bs-toggle="tab" data-bs-target="#{{.ID}}" type="button" role="tab" aria-controls="{{.ID}}" aria-selected="{{if .Active}}true{{else}}false{{end}}">{{.Label}}</a>
  </li>
{{- end}}
</ul>
<div class="tab-content mb-5 col-9 m-auto" id="collectionTabContent">
{{- range .}}
  <div id="{{.ID}}" class="tab-pane fade{{if .Active}} show active{{end}}" role="tabpanel" aria-labelledby="{{.ID}}-tab" tabindex="-1">{{.Content}}</div>
{{- end}}
</div>`))

	inlineTmpl = template.Must(template.New("inline").Parse(`<div class="collection-tab-sections">
{{- range .}}
  <div class="collection-tab-section mb-5">
    {{- if .Label}}<h2 class="h3 mb-3">{{.Label}}</h2>{{end}}
    {{- if .Content}}<div class="collection-tab-section__content">{{.Content}}</div>{{end}}
    {{- if .HasMap}}<div class="collection-map" data-nid="{{.NID}}"><!-- map view TODO --></div>{{end}}
  </div>
{{- end}}
</div>`))
)

// renderCollectionTabs reproduces CollectionTabsDefaultFormatter: a synthetic
// "View this collection" tab (holding grid) first, then each field_collection_tabs
// item. Inline display mode renders stacked sections instead of a tab bar.
func renderCollectionTabs(p Page, grid template.HTML) template.HTML {
	if displayMode(p.Tabs) == "inline" {
		return renderInlineSections(p)
	}

	vms := []tabVM{{ID: "tab-view-collection", Label: "View this collection", Content: grid}}
	for delta, t := range p.Tabs {
		if !tabHasContent(t) {
			continue
		}
		content := t.Value
		if t.Map {
			content += mapPlaceholder(p.NID)
		}
		vms = append(vms, tabVM{
			ID:      fmt.Sprintf("tab-%d", delta),
			Label:   t.Label,
			Content: template.HTML(content),
			Default: t.Default,
		})
	}

	// Filters (search or a facet) force the grid tab; otherwise the item marked
	// default wins, else the grid tab.
	active := 0
	if !filtersActive(p.Req) {
		for i := 1; i < len(vms); i++ {
			if vms[i].Default {
				active = i
				break
			}
		}
	}
	vms[active].Active = true

	var b strings.Builder
	_ = tabsTmpl.Execute(&b, vms)
	return template.HTML(b.String())
}

func renderInlineSections(p Page) template.HTML {
	var sections []sectionVM
	for _, t := range p.Tabs {
		if !tabHasContent(t) {
			continue
		}
		sections = append(sections, sectionVM{
			Label:   t.Label,
			Content: template.HTML(t.Value),
			HasMap:  t.Map,
			NID:     p.NID,
		})
	}
	var b strings.Builder
	_ = inlineTmpl.Execute(&b, sections)
	return template.HTML(b.String())
}

func displayMode(tabs []Tab) string {
	if len(tabs) > 0 && tabs[0].Display != "" {
		return tabs[0].Display
	}
	return "tabs"
}

func mapPlaceholder(nid int64) string {
	return fmt.Sprintf(`<div class="collection-map" data-nid="%d"><!-- map view TODO --></div>`, nid)
}

// tabHasContent mirrors the formatter's shouldRenderItem: a label, some visible
// value, or a map makes the item worth rendering.
func tabHasContent(t Tab) bool {
	return strings.TrimSpace(t.Label) != "" ||
		strings.TrimSpace(stripTags(t.Value)) != "" ||
		t.Map
}

// filtersActive reports whether the request carries a fulltext search or any
// facet filter (f[0]=...), matching the formatter's default-tab override.
func filtersActive(r *http.Request) bool {
	if r == nil {
		return false
	}
	q := r.URL.Query()
	if strings.TrimSpace(q.Get("search_api_fulltext")) != "" {
		return true
	}
	for k := range q {
		if k == "f" || strings.HasPrefix(k, "f[") {
			return true
		}
	}
	return false
}

// stripTags is a coarse tag remover used only to decide emptiness.
func stripTags(s string) string {
	var b strings.Builder
	depth := 0
	for _, r := range s {
		switch r {
		case '<':
			depth++
		case '>':
			if depth > 0 {
				depth--
			}
		default:
			if depth == 0 {
				b.WriteRune(r)
			}
		}
	}
	return b.String()
}
