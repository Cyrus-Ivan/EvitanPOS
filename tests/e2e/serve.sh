#!/usr/bin/env bash
# E2E web server launcher (invoked by playwright.config.ts webServer.command).
#
# Playwright starts the webServer BEFORE globalSetup runs, so all isolation
# setup lives here, strictly ordered ahead of the server: copy the dev DB to
# an isolated runtime path, build the frontend, seed per-role users + fixture
# products into the COPY, then exec php -S against it.
#
# Never `php artisan serve` (drops env overrides) and never the real DB (the
# gapless transaction serial makes checkouts permanent).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RUNTIME="$ROOT/tests/e2e/.runtime"
DB="$RUNTIME/e2e.sqlite"

mkdir -p "$RUNTIME"
cp "$ROOT/database/nativephp.sqlite" "$DB"

# The server renders through the built Vite manifest.
(cd "$ROOT" && npm run build)

(cd "$ROOT" && DB_DATABASE="$DB" php artisan tinker \
    --execute="require base_path('tests/e2e/seed.php');")

cd "$ROOT/public"
exec env DB_DATABASE="$DB" APP_DEBUG=false \
    php -S 127.0.0.1:8123 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
