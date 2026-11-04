#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
compose=(docker compose --env-file .env.production -f compose.production.yaml)
"${compose[@]}" config --quiet
"${compose[@]}" build app
"${compose[@]}" up -d --wait mysql
"${compose[@]}" run --rm -T --no-deps app php artisan migrate --force --no-interaction </dev/null
"${compose[@]}" up -d --wait app queue
