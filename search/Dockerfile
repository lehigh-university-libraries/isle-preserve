FROM golang:1.25-alpine@sha256:56961d79ea8129efddcc0b8643fd8a5416b4e6228cfd477e3fd61deb2672c587 AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY *.go ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /search . \
    && mkdir -p /cache

FROM gcr.io/distroless/static-debian12:nonroot@sha256:f5b485ea962d9bd1186b2f6b3a061191539b905b82ec395de78cbfae51f20e35
COPY --from=build /search /search
# Writable cache dir owned by the nonroot user (65532). A named volume mounted
# here inherits this ownership, so the collection cache snapshot is writable.
COPY --from=build --chown=65532:65532 /cache /var/lib/search
EXPOSE 8080
ENTRYPOINT ["/search"]
