#!/usr/bin/env bash
# Builds the admin app (app/) and writes the output into the repo-root
# dist/ folder (see app/vite.config.js's outDir), which is what
# docker-compose.yml, docker/apache/site-dev.conf and docker/caddy/site.caddy
# serve at /app. Run this any time app/src changes and commit the result.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_DIR="$ROOT_DIR/app"

cd "$APP_DIR"

if [ ! -d node_modules ]; then
  npm install
fi

npm run build

echo "Built app into $ROOT_DIR/dist"
