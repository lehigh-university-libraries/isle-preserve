package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestEmbedQuerySendsConfiguredDimension(t *testing.T) {
	var got map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if err := json.NewDecoder(r.Body).Decode(&got); err != nil {
			t.Fatalf("decode request: %v", err)
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"embedding": []float64{0.1, 0.2}})
	}))
	defer srv.Close()

	client := newEmbeddingClient(srv.URL, 2)
	vec, err := client.embedQuery(context.Background(), "question")
	if err != nil {
		t.Fatalf("embedQuery: %v", err)
	}
	if len(vec) != 2 {
		t.Fatalf("got vector length %d, want 2", len(vec))
	}
	if got["dimension"] != float64(2) {
		t.Fatalf("expected dimension 2 in request, got %#v", got)
	}
}

func TestGroupByNodeKeepsBestScoreAndChunks(t *testing.T) {
	res := &solrResponse{}
	res.Response.Docs = []map[string]any{
		{
			"node_id":     "10",
			"title":       "First",
			"object_url":  "https://example.test/node/10",
			"chunk_type":  "ocr",
			"page_number": float64(3),
			"text":        "first passage",
			"score":       0.92,
		},
		{
			"node_id":    "10",
			"chunk_type": "ocr",
			"text":       "second passage",
			"score":      0.81,
		},
		{
			"node_id":    "10",
			"chunk_type": "ocr",
			"text":       "third passage should be clipped",
			"score":      0.7,
		},
		{
			"node_id":    "11",
			"title":      "Second",
			"chunk_type": "metadata",
			"text":       "metadata passage",
			"score":      0.8,
		},
	}

	got := groupByNode(res, 2)
	if len(got) != 2 {
		t.Fatalf("got %d results, want 2", len(got))
	}
	if got[0].NodeID != "10" || got[0].Score != 0.92 || got[0].VectorScore != 0.92 {
		t.Fatalf("unexpected first result: %#v", got[0])
	}
	if len(got[0].Chunks) != 2 {
		t.Fatalf("got %d chunks, want 2", len(got[0].Chunks))
	}
	if got[0].Chunks[0].Page == nil || *got[0].Chunks[0].Page != 3 {
		t.Fatalf("expected page 3, got %#v", got[0].Chunks[0].Page)
	}
}

func TestMergeLexicalReranksVectorResults(t *testing.T) {
	results := []semanticResult{
		{NodeID: "1", Score: 0.9, VectorScore: 0.9},
		{NodeID: "2", Score: 0.8, VectorScore: 0.8},
	}
	lexical := []lexicalHit{
		{NodeID: "2", Score: 10},
		{NodeID: "1", Score: 1},
	}

	got := mergeLexical(results, lexical, 1, 1)
	if got[0].NodeID != "2" {
		t.Fatalf("expected lexical boost to rank node 2 first, got %#v", got)
	}
	if got[0].LexicalScore != 10 {
		t.Fatalf("expected lexical score retained, got %f", got[0].LexicalScore)
	}
	if got[0].Score <= got[1].Score {
		t.Fatalf("expected descending score order, got %#v", got)
	}
}

func TestExtractiveAnswerBuildsCitations(t *testing.T) {
	results := []semanticResult{
		{
			NodeID:    "10",
			Title:     "Object",
			ObjectURL: "https://example.test/node/10",
			Chunks: []semanticChunk{{
				ChunkType: "ocr",
				Text:      "  This   is a matched passage with extra whitespace.  ",
			}},
		},
	}

	answer, citations := extractiveAnswer(results)
	if answer != "This is a matched passage with extra whitespace." {
		t.Fatalf("unexpected answer %q", answer)
	}
	if len(citations) != 1 {
		t.Fatalf("got %d citations, want 1", len(citations))
	}
	if citations[0].NodeID != "10" || citations[0].ChunkType != "ocr" {
		t.Fatalf("unexpected citation: %#v", citations[0])
	}
}

func TestOllamaSummarizerCallsGenerate(t *testing.T) {
	var got map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/generate" {
			t.Fatalf("unexpected path %s", r.URL.Path)
		}
		if err := json.NewDecoder(r.Body).Decode(&got); err != nil {
			t.Fatalf("decode request: %v", err)
		}
		_ = json.NewEncoder(w).Encode(map[string]string{"response": "A concise answer [1]."})
	}))
	defer srv.Close()

	s := newOllamaSummarizer(srv.URL, "test-model")
	answer, err := s.summarize(
		context.Background(),
		"what happened?",
		[]semanticCitation{{
			Title:     "Object",
			ObjectURL: "https://example.test/node/10",
			Text:      "Evidence passage.",
		}},
	)
	if err != nil {
		t.Fatalf("summarize: %v", err)
	}
	if answer != "A concise answer [1]." {
		t.Fatalf("unexpected answer %q", answer)
	}
	if got["model"] != "test-model" || got["stream"] != false {
		t.Fatalf("unexpected request: %#v", got)
	}
	prompt, ok := got["prompt"].(string)
	if !ok || !strings.Contains(prompt, "[1] Title: Object") || !strings.Contains(prompt, "Question: what happened?") {
		t.Fatalf("prompt missing citation context: %#v", got["prompt"])
	}
}

func TestNewOllamaSummarizerRequiresURLAndModel(t *testing.T) {
	if newOllamaSummarizer("", "model") != nil {
		t.Fatal("expected nil without URL")
	}
	if newOllamaSummarizer("http://ollama:11434", "") != nil {
		t.Fatal("expected nil without model")
	}
}
