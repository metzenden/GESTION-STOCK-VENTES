#!/bin/sh
# L8-02 — Déploiement (sur le serveur, depuis /home/fds/fds) : construction, migrations, cache, redémarrage.
set -eu
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"

git pull --ff-only
docker run --rm -v "$PWD/frontend:/app" -w /app node:22-alpine sh -c "npm ci && npm run build"
$COMPOSE build
$COMPOSE up -d mysql redis gotenberg
$COMPOSE run --rm app php artisan down --retry=30 || true
$COMPOSE run --rm app php artisan migrate --force
$COMPOSE run --rm app php artisan optimize
$COMPOSE up -d
$COMPOSE exec -T app php artisan up
curl -fsS "https://$(grep ^APP_DOMAIN .env | cut -d= -f2)/api/v1/health" && echo " — déploiement vérifié"
