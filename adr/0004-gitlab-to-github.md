# Decision Record for moving from on-prem GitLab to GitHub

Title: Decision for moving from on-prem GitLab to GitHub

## Context

To date, The Lehigh Preserve codebase was hosted in Lehigh's GitLab instance.

## Decision

Move from on-prem/private GitLab to GitHub.

## Rationale

Since our GitLab instance is only on-prem, we can't easily share code with other institutions. This resulted in moving a Drupal module out from this codebase, into a public GitHub repo, and bringing it in as a `composer` dependency. This complicated the DX experience by forcing changes to get committed in that repo, then `composer update` to bring into this repo.

The same "sharing" problem was also why [ADR-0002](./0002-caching.md) was hosted in a separate repo.

And we've generally done some novel things in our ISLE deployment other instutions may find useful for their own deployments.

We also wanted to leverage renovate for dependency management, which has a free GitHub bot. While we could have built the integration/orchestration for our self-hosted GitLab repo, moving to GitHub made this much easier.

Plus, I just have a preference for GitHub over GitLab, and to date I've been the only one contributing code to this repo.

## Consequences

Positive:

- All custom code in a central repo
- Can leverage GitHub Actions since code is open source
- Can easily leverage renovate using the GitHub bot
- ISLE codebase alongside our other projects in our GitHub org

Negative:

- Need to change pipeline from GitLab to GitHub
