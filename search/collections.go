package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"sync"
	"time"
)

// Render kinds. A path handled by search resolves to one of these; anything
// else is proxied to Drupal. /browse is classified in the request handler.
const (
	kindBrowse     = "browse"     // all items across all collections
	kindCollection = "collection" // facet browse of the collection's members
	kindCompound   = "compound"   // metadata block + ordered list of children
)

// Tab mirrors one field_collection_tabs delta on an Islandora node.
type Tab struct {
	Label   string
	Value   string // trusted admin-authored HTML
	Default bool
	Map     bool
	Display string // tabs | inline | iiip
}

// pathInfo is what a handled path resolves to.
type pathInfo struct {
	Kind string
	NID  int64
}

// Page is the classified request passed to a Renderer. It carries the metadata
// criteria (kind + node data) that select and populate the theme.
type Page struct {
	Kind string
	Path string
	NID  int64
	Tabs []Tab
	Req  *http.Request
}

// snapshot is the on-disk cache format.
type snapshot struct {
	Paths map[string]pathInfo `json:"paths"`
	Tabs  map[int64][]Tab     `json:"tabs"`
}

// store maps normalized paths to their render kind + node id, and holds each
// node's collection tabs. Detection mirrors
// lehigh_site_support_identify_collection(): an islandora_object whose
// field_model term's field_external_uri is a Collection or Compound Object URI.
type store struct {
	db            *sql.DB
	cacheFile     string
	collectionURI string
	compoundURI   string
	log           *slog.Logger

	mu    sync.RWMutex
	paths map[string]pathInfo
	tabs  map[int64][]Tab
}

func newStore(db *sql.DB, cacheFile, collectionURI, compoundURI string, log *slog.Logger) *store {
	return &store{
		db:            db,
		cacheFile:     cacheFile,
		collectionURI: collectionURI,
		compoundURI:   compoundURI,
		log:           log,
		paths:         map[string]pathInfo{},
		tabs:          map[int64][]Tab{},
	}
}

// lookup classifies a path. Tabs are attached so the renderer needs no further
// DB access. The returned Tabs slice is never mutated in place (refresh swaps
// whole maps), so it is safe to use after the lock is released.
func (s *store) lookup(path string) (Page, bool) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	pi, ok := s.paths[path]
	if !ok {
		return Page{}, false
	}
	return Page{Kind: pi.Kind, NID: pi.NID, Tabs: s.tabs[pi.NID]}, true
}

func (s *store) refreshLoop(ctx context.Context, every time.Duration) {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			if err := s.refresh(ctx); err != nil {
				s.log.Warn("collection refresh", "err", err)
			}
		}
	}
}

// refresh rebuilds the path->info map and per-node tabs from the DB and, on
// success, swaps them in and writes the disk snapshot. On error the previously
// loaded data is kept.
func (s *store) refresh(ctx context.Context) error {
	paths, err := s.queryPaths(ctx)
	if err != nil {
		return err
	}
	tabs, err := s.queryTabs(ctx)
	if err != nil {
		return err
	}

	s.mu.Lock()
	s.paths, s.tabs = paths, tabs
	s.mu.Unlock()

	if err := s.saveToDisk(); err != nil {
		s.log.Warn("save cache to disk", "err", err)
	}
	s.log.Info("cache refreshed", "paths", len(paths), "nodes_with_tabs", len(tabs))
	return nil
}

func (s *store) queryPaths(ctx context.Context) (map[string]pathInfo, error) {
	const q = `
SELECT m.entity_id, u.field_external_uri_uri, pa.alias
FROM node__field_model m
JOIN node_field_data n
  ON n.nid = m.entity_id AND n.status = 1 AND n.type = 'islandora_object'
JOIN taxonomy_term__field_external_uri u
  ON u.entity_id = m.field_model_target_id AND u.deleted = 0
  AND u.field_external_uri_uri IN (?, ?)
LEFT JOIN path_alias pa
  ON pa.path = CONCAT('/node/', m.entity_id) AND pa.status = 1
WHERE m.deleted = 0`

	rows, err := s.db.QueryContext(ctx, q, s.collectionURI, s.compoundURI)
	if err != nil {
		return nil, fmt.Errorf("query paths: %w", err)
	}
	defer rows.Close()

	paths := map[string]pathInfo{}
	for rows.Next() {
		var nid int64
		var uri string
		var alias sql.NullString
		if err := rows.Scan(&nid, &uri, &alias); err != nil {
			return nil, fmt.Errorf("scan path row: %w", err)
		}
		kind := kindCollection
		if uri == s.compoundURI {
			kind = kindCompound
		}
		info := pathInfo{Kind: kind, NID: nid}
		paths[normalizePath(fmt.Sprintf("/node/%d", nid))] = info
		if alias.Valid && alias.String != "" {
			paths[normalizePath(alias.String)] = info
		}
	}
	return paths, rows.Err()
}

func (s *store) queryTabs(ctx context.Context) (map[int64][]Tab, error) {
	const q = `
SELECT t.entity_id,
       t.field_collection_tabs_label,
       t.field_collection_tabs_value,
       t.field_collection_tabs_default,
       t.field_collection_tabs_map,
       t.field_collection_tabs_display
FROM node__field_collection_tabs t
JOIN node__field_model m ON m.entity_id = t.entity_id AND m.deleted = 0
JOIN taxonomy_term__field_external_uri u
  ON u.entity_id = m.field_model_target_id AND u.deleted = 0
  AND u.field_external_uri_uri IN (?, ?)
WHERE t.deleted = 0
ORDER BY t.entity_id, t.delta`

	rows, err := s.db.QueryContext(ctx, q, s.collectionURI, s.compoundURI)
	if err != nil {
		return nil, fmt.Errorf("query tabs: %w", err)
	}
	defer rows.Close()

	tabs := map[int64][]Tab{}
	for rows.Next() {
		var nid int64
		var label, value, display sql.NullString
		var def, mp sql.NullInt64
		if err := rows.Scan(&nid, &label, &value, &def, &mp, &display); err != nil {
			return nil, fmt.Errorf("scan tab row: %w", err)
		}
		tabs[nid] = append(tabs[nid], Tab{
			Label:   label.String,
			Value:   value.String,
			Default: def.Int64 != 0,
			Map:     mp.Int64 != 0,
			Display: display.String,
		})
	}
	return tabs, rows.Err()
}

func (s *store) saveToDisk() error {
	s.mu.RLock()
	snap := snapshot{Paths: s.paths, Tabs: s.tabs}
	b, err := json.Marshal(snap)
	s.mu.RUnlock()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(s.cacheFile), 0o755); err != nil {
		return err
	}
	// Atomic replace so a crash mid-write can't leave a truncated snapshot.
	tmp := s.cacheFile + ".tmp"
	if err := os.WriteFile(tmp, b, 0o644); err != nil {
		return err
	}
	return os.Rename(tmp, s.cacheFile)
}

func (s *store) loadFromDisk() error {
	b, err := os.ReadFile(s.cacheFile)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}
	var snap snapshot
	if err := json.Unmarshal(b, &snap); err != nil {
		return err
	}
	if snap.Paths == nil {
		snap.Paths = map[string]pathInfo{}
	}
	if snap.Tabs == nil {
		snap.Tabs = map[int64][]Tab{}
	}
	s.mu.Lock()
	s.paths, s.tabs = snap.Paths, snap.Tabs
	s.mu.Unlock()
	return nil
}
