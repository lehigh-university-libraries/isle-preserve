package static_response

import (
	"context"
	"net/http"
)

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
	return &StaticResponse{
		name: name,
		next: next,
	}, nil
}

func (r *StaticResponse) ServeHTTP(rw http.ResponseWriter, req *http.Request) {
	rw.WriteHeader(429)
}
