Mirador 4 local library for islandora_mirador.

Current bundle:
- Source: https://github.com/Islandora/mirador-integration-islandora/pull/16
- Branch: mirador-update
- Commit: b96694f1638a2d9648ccaf2a2b536c62dd1a8264
- Deployed file: dist/main.js

Rebuild:

```sh
git clone --branch mirador-update https://github.com/Islandora/mirador-integration-islandora.git
cd mirador-integration-islandora
npm ci
npm run build
cp dist/main.js /path/to/drupal/web/libraries/mirador/dist/main.js
```
