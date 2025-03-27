# Decision Record for removing docker compose profiles

Title: Decision for removing docker compose profiles

## Context

Islandora's [isle-site-template](https://github.com/Islandora-Devops/isle-site-template) uses docker compose profiles to manage configuration differences in development and production environments.

Given Lehigh's infrastructure consists of a local/development environment, a CI server at `wight.cc.lehigh.edu`, a staging server, and a production server, managing four separate profiles in a single `docker-compose.yaml` file is not sustainable.

## Decision

Make `docker-compose.yaml` mimick the production configuration as closely as possible. For any host-specific overrides, add those in a `docker-compose.$HOST.yaml` using [docker compose's merge feature](https://docs.docker.com/compose/how-tos/multiple-compose-files/merge/)

## Rationale

Before the switch, we added a third profile for the CI environment since it needed special configuration as well as a selenium service to run functional tests. Managing three profiles was becoming unsustainable and difficult to reason about.

## Consequences

Positive:

- Reduce lines of YAML by 35% from 939 lines to 594
- More clear host overrides in their respective files
- Having the default config so close to production makes reasoning about production changes easier since that's the default config for all hosts

Negative:

- Though the isle-site-template is meant to only be a starting point that institutions then make changes to, this change is a significant difference from isle-site-template's base install.
