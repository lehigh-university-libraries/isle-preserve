FROM golang:1.26-alpine@sha256:0178a641fbb4858c5f1b48e34bdaabe0350a330a1b1149aabd498d0699ff5fb2 AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY *.go ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /search . \
    && mkdir -p /cache

FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /search /search
# Writable cache dir owned by the nonroot user (65532). A named volume mounted
# here inherits this ownership, so the collection cache snapshot is writable.
COPY --from=build --chown=65532:65532 /cache /var/lib/search
EXPOSE 8080
ENTRYPOINT ["/search"]
