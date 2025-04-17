package static_response

import (
	"context"
	"log/slog"
	"net/http"
	"os"
)

var log *slog.Logger

type Config struct {
}

func CreateConfig() *Config {
	return &Config{}
}

type StaticResponse struct {
	name string
	next http.Handler
}

// Creates and returns a new plugin instance.
func New(ctx context.Context, next http.Handler, config *Config, name string) (http.Handler, error) {
	handler := slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{
		Level: slog.LevelInfo,
	})
	log = slog.New(handler)
	return &StaticResponse{
		name: name,
		next: next,
	}, nil
}

func (r *StaticResponse) ServeHTTP(rw http.ResponseWriter, req *http.Request) {
	log.Info("Blocking bad bot", "clientIP", req.Header.Get("X-Forwarded-For"), "method", req.Method, "path", req.URL.Path, "useragent", req.UserAgent())

	rw.WriteHeader(400)
}
