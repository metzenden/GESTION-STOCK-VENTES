#!/usr/bin/env bash
# Génère le squelette Laravel dans un répertoire temporaire puis le fusionne dans backend/
# SANS écraser les fichiers déjà écrits pour le projet (bootstrap/app.php, tests, modules…).
# À exécuter dans le conteneur PHP : make bootstrap-backend
set -euo pipefail

cd /var/www/backend

if [ -f vendor/pestphp/pest/bin/pest ]; then
  echo "Squelette Laravel déjà présent."
  exit 0
fi

# Les étapes suivantes sont idempotentes : le script peut être relancé après un échec.
if [ ! -f artisan ]; then
tmp=$(mktemp -d)
composer create-project laravel/laravel "$tmp/skeleton" --no-interaction --no-scripts --prefer-dist

# Fichiers du squelette inutiles ou remplacés par la conception (users en BIGINT : refait au lot 1).
rm -f "$tmp/skeleton/database/migrations/0001_01_01_000000_create_users_table.php"
rm -f "$tmp/skeleton/tests/Feature/ExampleTest.php" "$tmp/skeleton/tests/Unit/ExampleTest.php"
rm -rf "$tmp/skeleton/routes/web.php" "$tmp/skeleton/resources/js" "$tmp/skeleton/resources/css" \
       "$tmp/skeleton/vite.config.js" "$tmp/skeleton/package.json" \
       "$tmp/skeleton/README.md" "$tmp/skeleton/AGENTS.md" "$tmp/skeleton/CLAUDE.md"

cp -rn "$tmp/skeleton/." .
rm -rf "$tmp"
fi

# Configuration fournie par Docker Compose ; un .env vide évite les avertissements de phpdotenv.
touch .env

composer config --no-plugins allow-plugins.pestphp/pest-plugin true
# `composer config` ne sait pas écrire autoload-dev : on modifie composer.json directement.
php -r '$f = "composer.json"; $j = json_decode(file_get_contents($f), true);
  $j["autoload-dev"]["psr-4"] = ["Tests\\" => "tests/", "Tools\\PHPStan\\" => "tools/phpstan/"];
  file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'

# URL du service PDF (D-032)
php -r '$f = "config/services.php"; $c = file_get_contents($f);
  if (! str_contains($c, "gotenberg")) {
    $c = preg_replace("/\];\s*$/", "    \x27gotenberg\x27 => [\n        \x27url\x27 => env(\x27GOTENBERG_URL\x27, \x27http://gotenberg:3000\x27),\n    ],\n\n];\n", $c);
    file_put_contents($f, $c);
  }'

composer remove --dev --no-interaction --no-update phpunit/phpunit || true
composer require --no-interaction brick/math
composer require --dev --no-interaction --with-all-dependencies \
  pestphp/pest pestphp/pest-plugin-laravel brianium/paratest \
  larastan/larastan laravel/pint \
  league/openapi-psr7-validator nyholm/psr7 symfony/psr-http-message-bridge

composer dump-autoload
echo "Squelette Laravel installé."
