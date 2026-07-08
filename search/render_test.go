package main

import (
	"html/template"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
)

func TestRenderCollectionTabs(t *testing.T) {
	grid := template.HTML(`<div class="rows">GRID</div>`)

	tests := []struct {
		name         string
		query        string
		tabs         []Tab
		wantContains []string
		wantExcludes []string
	}{
		{
			name:  "no tabs, no filters -> grid tab active",
			query: "/c",
			wantContains: []string{
				`id="tab-view-collection"`,
				`show active`,
				"GRID",
			},
		},
		{
			name:  "default item active, grid not active",
			query: "/c",
			tabs: []Tab{
				{Label: "About", Value: "<p>about</p>", Default: true},
			},
			wantContains: []string{
				`id="tab-0-tab"`,
				`aria-selected="true">About`,
				`<p>about</p>`,
			},
			wantExcludes: []string{
				// the grid pane must not be the active one
				`id="tab-view-collection" class="tab-pane fade show active"`,
			},
		},
		{
			name:  "filters override default back to grid",
			query: "/c?f%5B0%5D=member_of%3A1",
			tabs: []Tab{
				{Label: "About", Value: "<p>about</p>", Default: true},
			},
			wantContains: []string{
				`id="tab-view-collection" class="tab-pane fade show active"`,
			},
		},
		{
			name:  "inline display renders sections, no tab bar",
			query: "/c",
			tabs: []Tab{
				{Label: "About", Value: "<p>about</p>", Display: "inline"},
			},
			wantContains: []string{
				`collection-tab-sections`,
				`<h2 class="h3 mb-3">About</h2>`,
			},
			wantExcludes: []string{`nav-tabs`},
		},
		{
			name:  "empty tab is skipped",
			query: "/c",
			tabs:  []Tab{{Label: "", Value: "   "}},
			wantExcludes: []string{
				`id="tab-0"`,
			},
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			p := Page{Kind: kindCollection, NID: 42, Tabs: tc.tabs, Req: httptest.NewRequest("GET", tc.query, nil)}
			got := string(renderCollectionTabs(p, grid))
			for _, want := range tc.wantContains {
				if !strings.Contains(got, want) {
					t.Errorf("output missing %q\n---\n%s", want, got)
				}
			}
			for _, ex := range tc.wantExcludes {
				if strings.Contains(got, ex) {
					t.Errorf("output should not contain %q\n---\n%s", ex, got)
				}
			}
		})
	}
}

func TestCollectionNodeID(t *testing.T) {
	tests := []struct {
		name string
		q    url.Values
		want int
		ok   bool
	}{
		{name: "valid", q: url.Values{"node_id": {"123"}}, want: 123, ok: true},
		{name: "trim whitespace", q: url.Values{"node_id": {" 123 "}}, want: 123, ok: true},
		{name: "missing", q: url.Values{}, ok: false},
		{name: "not numeric", q: url.Values{"node_id": {"abc"}}, ok: false},
		{name: "zero", q: url.Values{"node_id": {"0"}}, ok: false},
		{name: "negative", q: url.Values{"node_id": {"-1"}}, ok: false},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got, ok := collectionNodeID(tc.q)
			if ok != tc.ok {
				t.Fatalf("ok = %t, want %t", ok, tc.ok)
			}
			if got != tc.want {
				t.Fatalf("node ID = %d, want %d", got, tc.want)
			}
		})
	}
}

func TestFacetFilterQueriesAllowlistAndGroup(t *testing.T) {
	q := url.Values{
		"f[0]": {"member_of:10"},
		"f[1]": {"member_of:20"},
		"f[2]": {"model:5"},
		"f[3]": {"-genre:photographs"},
		"f[4]": {"unknown:999"},
		"f[5]": {"malformed"},
	}

	got := strings.Join(facetFilterQueries(q), "\n")
	for _, want := range []string{
		"itm_field_member_of:(10 20)",
		"its_field_model:(5)",
		"-sm_field_genre:(\"photographs\")",
	} {
		if !strings.Contains(got, want) {
			t.Fatalf("facet filters missing %q in:\n%s", want, got)
		}
	}
	if strings.Contains(got, "unknown") || strings.Contains(got, "malformed") {
		t.Fatalf("unexpected unallowlisted filter in:\n%s", got)
	}
}

func TestBrowseFacetFieldNames(t *testing.T) {
	want := map[string]string{
		"genre":      solrFieldGenre,
		"publisher":  solrFieldPub,
		"media_type": solrFieldMedia,
	}
	for _, spec := range browseFacets {
		if field, ok := want[spec.Alias]; ok && spec.Field != field {
			t.Fatalf("%s facet field = %q, want %q", spec.Alias, spec.Field, field)
		}
	}
}

func TestRenderFacetsPreservesBetaAndTogglesActiveFacet(t *testing.T) {
	res := &solrResponse{}
	res.FacetCounts.FacetFields = map[string][]any{
		solrFieldMember: {"10", float64(3), "20", float64(2)},
	}
	q := url.Values{
		"v": {"beta"},
		"f": {"member_of:10"},
	}

	got := renderFacets(res, q)
	if !strings.Contains(got, `facet-action--include is-active`) {
		t.Fatalf("active include action not marked:\n%s", got)
	}
	if !strings.Contains(got, `facet-action--exclude`) {
		t.Fatalf("exclude action not rendered:\n%s", got)
	}
	if !strings.Contains(got, `href="?v=beta"`) {
		t.Fatalf("active facet link should remove member_of:10:\n%s", got)
	}
	if !strings.Contains(got, "v=beta") || !strings.Contains(got, "member_of%3A20") {
		t.Fatalf("inactive facet link should preserve beta and add facet:\n%s", got)
	}
	if !strings.Contains(got, "-member_of%3A20") {
		t.Fatalf("inactive exclude link should add negative facet:\n%s", got)
	}
}

func TestCollectionBrowseQueryAddsNodeIDAndPreservesQuery(t *testing.T) {
	req := httptest.NewRequest("GET", "/collection?v=beta&search_api_fulltext=letters&f%5B0%5D=genre%3Aphotographs", nil)
	got := collectionBrowseQuery(Page{NID: 42, Req: req})
	if got.Get("node_id") != "42" {
		t.Fatalf("node_id = %q, want 42", got.Get("node_id"))
	}
	if got.Get("v") != "beta" || got.Get("search_api_fulltext") != "letters" || got.Get("f[0]") != "genre:photographs" {
		t.Fatalf("query was not preserved: %#v", got)
	}
}
