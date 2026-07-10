package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Solr field names, derived from search_api_solr's naming
// (prefix + s|m + "_" + field). Verified against the committed schema.xml.
// TODO: when facets move from tid to plain text, the facet field names change
// too — keep those separate from these.
const (
	solrFieldType   = "ss_type"          // node bundle (string, single)
	solrFieldStatus = "bs_status"        // published (boolean, single)
	solrFieldModel  = "its_field_model"  // model term id (integer, single)
	solrFieldWeight = "its_field_weight" // custom sort (integer, single)
	solrFieldCard   = "zs_card"          // rendered card HTML (solr_string_storage)
	solrFieldNID    = "its_nid"          // node id (integer, single)
	solrFieldMember = "itm_field_member_of"
	solrFieldGenre  = "sm_field_genre"
	solrFieldMedia  = "its_field_media_type"
	solrFieldPub    = "sm_field_publisher"

	// Text dynamic-field patterns used for hybrid semantic search. These must
	// track the Search API Solr schema generated for the object index.
	solrObjectTextQF = "tm_X3b_en_* tm_X3b_und_* tm_* tum_X3b_en_* tum_X3b_und_* tum_*"

	// Vector core (islandora_rag) field.
	solrRagVectorField = "embedding" // DenseVectorField queried with {!knn}
	solrRagAccessField = "access"    // access token field; public-only today
)

type solrClient struct {
	base string // e.g. http://solr:8983/solr/default
	http *http.Client
}

func newSolrClient(base string) *solrClient {
	return &solrClient{base: base, http: &http.Client{Timeout: 5 * time.Second}}
}

type solrResponse struct {
	Response struct {
		NumFound int64            `json:"numFound"`
		Start    int64            `json:"start"`
		Docs     []map[string]any `json:"docs"`
	} `json:"response"`
	FacetCounts struct {
		FacetFields map[string][]any `json:"facet_fields"`
	} `json:"facet_counts"`
}

func (c *solrClient) selectQuery(ctx context.Context, params url.Values) (*solrResponse, error) {
	params.Set("wt", "json")
	body := params.Encode()
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.base+"/select", strings.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := c.http.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))
		return nil, fmt.Errorf("solr returned %d: %s", resp.StatusCode, strings.TrimSpace(string(msg)))
	}
	var out solrResponse
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return nil, fmt.Errorf("decode solr response: %w", err)
	}
	return &out, nil
}

// docString reads a doc field as a string, tolerating single- or multi-valued
// dynamic fields (Solr may return either a scalar or a one-element array).
func docString(doc map[string]any, field string) string {
	switch t := doc[field].(type) {
	case string:
		return t
	case []any:
		if len(t) > 0 {
			if s, ok := t[0].(string); ok {
				return s
			}
		}
	}
	return ""
}

// docFloat reads a numeric doc field (JSON numbers decode as float64).
func docFloat(doc map[string]any, field string) float64 {
	switch t := doc[field].(type) {
	case float64:
		return t
	case []any:
		if len(t) > 0 {
			if f, ok := t[0].(float64); ok {
				return f
			}
		}
	}
	return 0
}

// docInt reads an integer doc field, reporting whether it was present.
func docInt(doc map[string]any, field string) (int, bool) {
	switch t := doc[field].(type) {
	case float64:
		return int(t), true
	case []any:
		if len(t) > 0 {
			if f, ok := t[0].(float64); ok {
				return int(f), true
			}
		}
	}
	return 0, false
}
