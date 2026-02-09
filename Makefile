.PHONY: help
.PHONY: create-starter-site-pr overwrite-starter-site
.PHONY: build pull down down-% logs-% up up-%
.PHONY: clean demo-objects init ping status
.PHONY: sequelace
.PHONY: traefik-certs traefik-http traefik-https-letsencrypt traefik-https-mkcert
.SILENT:

# If custom.makefile exists include it.
-include custom.Makefile

DEFAULT_HTTP=80
DEFAULT_HTTPS=443

help: ## Show this help message
	echo 'Usage: make [target]'
	echo ''
	echo 'Available targets:'
	awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%s\033[0m\t%s\n", $$1, $$2}' $(MAKEFILE_LIST) | sort | column -t -s $$'\t'

status: ## Show the current status of the development environment
	./scripts/isle/status.sh

traefik-http: ## Switch to HTTP mode (default)
	./scripts/isle/traefik-http.sh

traefik-https-mkcert: traefik-certs ## Switch to HTTPS mode using mkcert self-signed certificates
	./scripts/isle/traefik-https-mkcert.sh

traefik-https-letsencrypt: ## Switch to HTTPS mode using Let's Encrypt ACME
	./scripts/isle/traefik-https-letsencrypt.sh

traefik-certs: ## Generate mkcert certificates
	./scripts/isle/generate-certs.sh

pull:
	docker compose pull --ignore-buildable --ignore-pull-failures

build: pull ## Build the drupal container
	./scripts/isle/build.sh

init: ## Get the host machine configured to run ISLE
	./scripts/isle/init.sh

up: ## Start docker compose project with smart port allocation
	./scripts/isle/up.sh

up-%:  ## Start a specific service (e.g., make up-drupal)
	docker compose up $* -d

down:  ## Stop/remove the docker compose project's containers and network.
	docker compose down

down-%:  ## Stop/remove a specific service (e.g., make down-traefik)
	docker compose down $*

logs-%:  ## Look at logs for a specific service (e.g., make logs-drupal)
	docker compose logs $* --tail 20 -f

clean:  ## Delete all stateful data.
	./scripts/isle/clean.sh

ping:  ## Ensure site is available.
	./scripts/isle/ping.sh

demo-objects: ## Add demo objects from https://github.com/Islandora-Devops/islandora_demo_objects
	./scripts/isle/demo-objects.sh

overwrite-starter-site: ## Keep site template's drupal install in sync with islandora-starter-site
	./scripts/isle/overwrite-starter-site.sh

create-starter-site-pr: ## Create a PR for islandora-starter-site updates
	./scripts/isle/create-pr.sh
sequelace:
	./scripts/isle/sequelace.sh

