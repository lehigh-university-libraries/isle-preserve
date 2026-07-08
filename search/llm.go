package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

type semanticSummarizer interface {
	summarize(ctx context.Context, query string, citations []semanticCitation) (string, error)
}

type ollamaSummarizer struct {
	base  string
	model string
	http  *http.Client
}

func newOllamaSummarizer(base, model string) *ollamaSummarizer {
	base = strings.TrimRight(strings.TrimSpace(base), "/")
	model = strings.TrimSpace(model)
	if base == "" || model == "" {
		return nil
	}
	return &ollamaSummarizer{
		base:  base,
		model: model,
		http:  &http.Client{Timeout: 90 * time.Second},
	}
}

func (s *ollamaSummarizer) summarize(ctx context.Context, query string, citations []semanticCitation) (string, error) {
	if len(citations) == 0 {
		return "", nil
	}

	body, err := json.Marshal(map[string]any{
		"model":  s.model,
		"prompt": summaryPrompt(query, citations),
		"stream": false,
		"options": map[string]any{
			"temperature": 0.1,
			"num_predict": 300,
		},
	})
	if err != nil {
		return "", err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, s.base+"/api/generate", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := s.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))
		return "", fmt.Errorf("ollama returned %d: %s", resp.StatusCode, strings.TrimSpace(string(msg)))
	}

	var out struct {
		Response string `json:"response"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return "", err
	}
	return strings.TrimSpace(out.Response), nil
}

func summaryPrompt(query string, citations []semanticCitation) string {
	var b strings.Builder
	b.WriteString("Answer the question using only the cited repository passages below.\n")
	b.WriteString("If the passages do not contain enough evidence, say that the available sources do not answer it.\n")
	b.WriteString("Cite supporting claims with bracketed source numbers like [1]. Keep the answer concise.\n\n")
	fmt.Fprintf(&b, "Question: %s\n\nSources:\n", query)
	for i, citation := range citations {
		fmt.Fprintf(&b, "[%d] Title: %s\nURL: %s\nPassage: %s\n\n",
			i+1,
			citation.Title,
			citation.ObjectURL,
			citation.Text,
		)
	}
	b.WriteString("Answer:")
	return b.String()
}
