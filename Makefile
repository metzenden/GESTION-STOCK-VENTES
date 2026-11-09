SHELL := /bin/bash
DC      := docker compose -f docker-compose.yml -f docker-compose.dev.yml
APP     := $(DC) exec app
RUN_APP := $(DC) run --rm --no-deps app
RUN_FE  := $(DC) run --rm --no-deps frontend

.PHONY: help init build up down restart logs ps sh artisan composer migrate fresh seed test test-php test-front lint stan openapi types front-install

help: ## Liste des commandes
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

init: ## Crée .env avec des secrets aléatoires, construit les images, installe les dépendances
	@test -f .env || { cp .env.example .env; \
	  for k in DB_PASSWORD DB_ROOT_PASSWORD REDIS_PASSWORD; do \
	    sed -i "s|^$$k=__GENERATE__|$$k=$$(openssl rand -hex 24)|" .env; done; \
	  sed -i "s|^HOST_UID=.*|HOST_UID=$$(id -u)|; s|^HOST_GID=.*|HOST_GID=$$(id -g)|" .env; \
	  echo ".env créé"; }
	$(DC) build
	$(RUN_APP) bash scripts/bootstrap-laravel.sh
	$(RUN_APP) composer install --no-interaction
	@grep -q '^APP_KEY=base64' .env || sed -i "s|^APP_KEY=.*|APP_KEY=$$($(RUN_APP) php artisan key:generate --show --no-ansi | tail -n1 | tr -d '\r')|" .env
	$(RUN_FE) sh -c 'if [ -f package-lock.json ]; then npm ci; else npm install; fi'
	$(RUN_FE) npm run api:types
	@echo "Initialisation terminée : make up puis make migrate"

build: ## Construit les images
	$(DC) build

up: ## Démarre la pile de développement
	$(DC) up -d
	@echo "API : http://localhost:8080/api/v1/health — SPA : http://localhost:5173 — Mailpit : http://localhost:8025"

down: ## Arrête la pile
	$(DC) down

restart: down up ## Redémarre la pile

logs: ## Journaux (suivi)
	$(DC) logs -f --tail=100

ps: ## État des conteneurs
	$(DC) ps

sh: ## Shell dans le conteneur PHP
	$(APP) bash

artisan: ## make artisan c="migrate:status"
	$(APP) php artisan $(c)

composer: ## make composer c="require vendor/paquet"
	$(RUN_APP) composer $(c)

migrate: ## Applique les migrations
	$(APP) php artisan migrate

fresh: ## Recrée la base et exécute les seeders
	$(APP) php artisan migrate:fresh --seed

test: test-php test-front ## Tous les tests

test-php: ## Tests backend (Pest, MySQL réel)
	$(APP) php artisan test --parallel --processes=4

test-front: ## Tests frontend (Vitest)
	$(RUN_FE) npm run test -- --run

lint: ## Lint backend et frontend
	$(APP) vendor/bin/pint --test
	$(RUN_FE) npm run lint

stan: ## Analyse statique Larastan
	$(APP) vendor/bin/phpstan analyse --memory-limit=1G

openapi: ## Valide le contrat OpenAPI (Redocly bundle puis Spectral)
	@# Spectral 6 valide mal les $$ref externes de « paths » en OAS 3.1 : on lint le contrat assemblé.
	docker run --rm --user "$$(id -u):$$(id -g)" -v "$$PWD/openapi:/spec" redocly/cli:1.34.5 bundle /spec/openapi.yaml -o /spec/dist/openapi.bundled.yaml
	docker run --rm -v "$$PWD/openapi:/spec:ro" stoplight/spectral:6 lint /spec/dist/openapi.bundled.yaml --ruleset /spec/.spectral.yaml

types: ## Régénère les types TypeScript depuis le contrat
	$(RUN_FE) npm run api:types

front-install: ## Installe les dépendances frontend
	$(RUN_FE) npm ci
