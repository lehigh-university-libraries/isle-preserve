package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// embeddingServiceURL is the hosted Qwen embedding service (isle-microservice).
const embeddingServiceURL = "https://isle-microservices.cc.lehigh.edu/transformer"

// embeddingClient calls the sentence-transformer service to embed a query.
type embeddingClient struct {
	base      string
	dimension int
	http      *http.Client
}

func newEmbeddingClient(base string, dimension int) *embeddingClient {
	return &embeddingClient{
		base:      strings.TrimRight(base, "/"),
		dimension: dimension,
		http:      &http.Client{Timeout: 30 * time.Second},
	}
}

func (c *embeddingClient) embedQuery(ctx context.Context, text string) ([]float64, error) {
	if c.base == "" {
		return nil, fmt.Errorf("EMBEDDING_SERVICE_URL not configured")
	}
	payload := map[string]any{"texts": []string{text}}
	if c.dimension > 0 {
		payload["dimension"] = c.dimension
	}
	body, _ := json.Marshal(payload)
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.base+"/embed/query", bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("embedding service returned %d", resp.StatusCode)
	}
	var out struct {
		Embedding  []float64   `json:"embedding"`
		Embeddings [][]float64 `json:"embeddings"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return nil, err
	}
	if len(out.Embedding) > 0 {
		return out.Embedding, nil
	}
	if len(out.Embeddings) > 0 {
		return out.Embeddings[0], nil
	}
	return nil, fmt.Errorf("embedding service returned no vector")
}

type semanticChunk struct {
	ChunkType string  `json:"chunk_type"`
	Page      *int    `json:"page,omitempty"`
	Text      string  `json:"text"`
	Score     float64 `json:"score"`
}

type semanticCitation struct {
	NodeID    string `json:"node_id"`
	Title     string `json:"title"`
	ObjectURL string `json:"object_url"`
	ChunkType string `json:"chunk_type"`
	Page      *int   `json:"page,omitempty"`
	Text      string `json:"text"`
}

type semanticResult struct {
	NodeID       string          `json:"node_id"`
	Title        string          `json:"title"`
	ObjectURL    string          `json:"object_url"`
	Score        float64         `json:"score"`
	VectorScore  float64         `json:"vector_score,omitempty"`
	LexicalScore float64         `json:"lexical_score,omitempty"`
	Chunks       []semanticChunk `json:"chunks"`
}

type semanticSearchOptions struct {
	MaxChunksPerNode int
	ObjectRows       int
	VectorWeight     float64
	LexicalWeight    float64
}

// semanticHandler embeds the query, runs KNN against the vector core, and groups
// the chunk hits by node_id so callers get the node plus its matching passages.
// It also performs an optional lexical lookup against the object index and
// merges those scores into the vector ranking.
func semanticHandler(embed *embeddingClient, rag *solrClient, objects *solrClient, summarizer semanticSummarizer, opts semanticSearchOptions, log *slog.Logger) http.HandlerFunc {
	if opts.MaxChunksPerNode <= 0 {
		opts.MaxChunksPerNode = 3
	}
	if opts.ObjectRows <= 0 {
		opts.ObjectRows = 40
	}
	if opts.VectorWeight == 0 {
		opts.VectorWeight = 1.0
	}
	if opts.LexicalWeight == 0 {
		opts.LexicalWeight = 0.35
	}

	return func(w http.ResponseWriter, r *http.Request) {
		q := strings.TrimSpace(r.URL.Query().Get("q"))
		if q == "" {
			http.Error(w, "missing q", http.StatusBadRequest)
			return
		}
		topK := 40
		if v, err := strconv.Atoi(r.URL.Query().Get("topK")); err == nil && v > 0 && v <= 200 {
			topK = v
		}

		vec, err := embed.embedQuery(r.Context(), q)
		if err != nil {
			log.Error("embed query", "err", err)
			http.Error(w, "embedding unavailable", http.StatusBadGateway)
			return
		}

		params := url.Values{}
		params.Set("q", fmt.Sprintf("{!knn f=%s topK=%d}[%s]", solrRagVectorField, topK, floatsCSV(vec)))
		params.Add("fq", "published:true") // anonymous-only retrieval
		params.Add("fq", solrRagAccessField+":public")
		params.Set("fl", "node_id,title,object_url,chunk_type,page_number,text,score")
		params.Set("rows", strconv.Itoa(topK))

		res, err := rag.selectQuery(r.Context(), params)
		if err != nil {
			log.Error("rag knn query", "err", err)
			http.Error(w, "search unavailable", http.StatusBadGateway)
			return
		}

		results := groupByNode(res, opts.MaxChunksPerNode)
		if objects != nil {
			objectRows := max(opts.ObjectRows, topK)
			lexical, err := lexicalObjectScores(r.Context(), objects, q, objectRows)
			if err != nil {
				log.Warn("semantic lexical query", "err", err)
			} else if len(lexical) == 0 {
				log.Warn("semantic lexical query returned no object hits", "query", q)
			} else {
				results = mergeLexical(results, lexical, opts.VectorWeight, opts.LexicalWeight)
			}
		}

		answer, citations := extractiveAnswer(results)
		answerSource := "extractive"
		if summarizer != nil && len(citations) > 0 {
			summary, err := summarizer.summarize(r.Context(), q, citations)
			if err != nil {
				log.Warn("semantic llm summary", "err", err)
			} else if summary != "" {
				answer = summary
				answerSource = "llm"
			}
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"query":         q,
			"answer":        answer,
			"answer_source": answerSource,
			"citations":     citations,
			"results":       results,
		})
	}
}

// groupByNode collapses KNN chunk hits (already score-sorted) into per-node
// results, keeping each node's best score and up to maxChunks passages.
func groupByNode(res *solrResponse, maxChunks int) []semanticResult {
	var order []string
	byNode := map[string]*semanticResult{}
	for _, doc := range res.Response.Docs {
		nid := docString(doc, "node_id")
		if nid == "" {
			continue
		}
		score := docFloat(doc, "score")
		chunk := semanticChunk{
			ChunkType: docString(doc, "chunk_type"),
			Text:      docString(doc, "text"),
			Score:     score,
		}
		if p, ok := docInt(doc, "page_number"); ok {
			chunk.Page = &p
		}
		node, ok := byNode[nid]
		if !ok {
			node = &semanticResult{
				NodeID:      nid,
				Title:       docString(doc, "title"),
				ObjectURL:   docString(doc, "object_url"),
				Score:       score,
				VectorScore: score,
			}
			byNode[nid] = node
			order = append(order, nid)
		}
		if len(node.Chunks) < maxChunks {
			node.Chunks = append(node.Chunks, chunk)
		}
	}

	out := make([]semanticResult, 0, len(order))
	for _, nid := range order {
		out = append(out, *byNode[nid])
	}
	return out
}

type lexicalHit struct {
	NodeID string
	Score  float64
}

func lexicalObjectScores(ctx context.Context, objects *solrClient, q string, rows int) ([]lexicalHit, error) {
	params := url.Values{}
	params.Set("defType", "edismax")
	params.Set("q", q)
	params.Set("qf", solrObjectTextQF)
	params.Add("fq", solrFieldType+":islandora_object")
	params.Add("fq", solrFieldStatus+":true")
	params.Set("fl", solrFieldNID+",score")
	params.Set("rows", strconv.Itoa(rows))

	res, err := objects.selectQuery(ctx, params)
	if err != nil {
		return nil, err
	}
	hits := make([]lexicalHit, 0, len(res.Response.Docs))
	for _, doc := range res.Response.Docs {
		nid, ok := docInt(doc, solrFieldNID)
		if !ok || nid <= 0 {
			continue
		}
		hits = append(hits, lexicalHit{
			NodeID: strconv.Itoa(nid),
			Score:  docFloat(doc, "score"),
		})
	}
	return hits, nil
}

func mergeLexical(results []semanticResult, lexical []lexicalHit, vectorWeight, lexicalWeight float64) []semanticResult {
	if len(results) == 0 || len(lexical) == 0 {
		return results
	}

	maxVector := 0.0
	for _, result := range results {
		if result.VectorScore > maxVector {
			maxVector = result.VectorScore
		}
	}
	if maxVector == 0 {
		maxVector = 1
	}

	maxLexical := 0.0
	lexicalByNode := map[string]float64{}
	for _, hit := range lexical {
		if hit.Score > lexicalByNode[hit.NodeID] {
			lexicalByNode[hit.NodeID] = hit.Score
		}
		if hit.Score > maxLexical {
			maxLexical = hit.Score
		}
	}
	if maxLexical == 0 {
		maxLexical = 1
	}

	for i := range results {
		lexicalScore := lexicalByNode[results[i].NodeID]
		results[i].LexicalScore = lexicalScore
		results[i].Score = vectorWeight*(results[i].VectorScore/maxVector) + lexicalWeight*(lexicalScore/maxLexical)
	}

	sortSemanticResults(results)
	return results
}

func sortSemanticResults(results []semanticResult) {
	for i := 1; i < len(results); i++ {
		for j := i; j > 0 && semanticLess(results[j], results[j-1]); j-- {
			results[j], results[j-1] = results[j-1], results[j]
		}
	}
}

func semanticLess(a, b semanticResult) bool {
	if a.Score != b.Score {
		return a.Score > b.Score
	}
	if a.VectorScore != b.VectorScore {
		return a.VectorScore > b.VectorScore
	}
	return a.NodeID < b.NodeID
}

func extractiveAnswer(results []semanticResult) (string, []semanticCitation) {
	const maxCitations = 5
	var parts []string
	citations := make([]semanticCitation, 0, min(len(results), maxCitations))
	seen := map[string]bool{}
	for _, result := range results {
		if len(result.Chunks) == 0 {
			continue
		}
		chunk := result.Chunks[0]
		key := result.NodeID + "\x00" + chunk.Text
		if seen[key] {
			continue
		}
		seen[key] = true
		parts = append(parts, trimPassage(chunk.Text, 360))
		citations = append(citations, semanticCitation{
			NodeID:    result.NodeID,
			Title:     result.Title,
			ObjectURL: result.ObjectURL,
			ChunkType: chunk.ChunkType,
			Page:      chunk.Page,
			Text:      trimPassage(chunk.Text, 220),
		})
		if len(citations) >= maxCitations {
			break
		}
	}
	return strings.Join(parts, "\n\n"), citations
}

func trimPassage(text string, limit int) string {
	text = strings.Join(strings.Fields(text), " ")
	if len(text) <= limit {
		return text
	}
	cut := strings.LastIndex(text[:limit], " ")
	if cut < limit/2 {
		cut = limit
	}
	return strings.TrimSpace(text[:cut]) + "..."
}

func floatsCSV(v []float64) string {
	var b strings.Builder
	for i, f := range v {
		if i > 0 {
			b.WriteByte(',')
		}
		b.WriteString(strconv.FormatFloat(f, 'g', -1, 64))
	}
	return b.String()
}
