#!/usr/bin/env bash
# Starts the workbench on http://localhost:8776 in a throwaway container (removed with `docker rm -f sr-workbench`).
# Seeded users: admin@example.test (manages all reports), oleh@example.test, nina@example.test; password "password".
#   bash workbench/serve.sh      (from the plugin directory; needs the asignua-plugin-php:8.4 image)
set -euo pipefail
plugin="$(cd "$(dirname "$0")/.." && pwd)"
plugins="$(cd "$plugin/.." && pwd)"

docker rm -f sr-workbench >/dev/null 2>&1 || true
mkdir -p "$plugin/build" && rm -f "$plugin/build/workbench.sqlite" && touch "$plugin/build/workbench.sqlite"

docker run -d --name sr-workbench -p 8776:8000 -v "$plugins":/plugins -w /plugins/filament-scheduled-reports \
    -e DB_CONNECTION=sqlite -e DB_DATABASE=/plugins/filament-scheduled-reports/build/workbench.sqlite \
    -e APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE= -e APP_URL=http://host.docker.internal:8776 \
    -e QUEUE_CONNECTION=sync -e MAIL_MAILER=log -e MAIL_FROM_ADDRESS=reports@example.test -e LOG_CHANNEL=single \
    -e SESSION_DRIVER=file -e CACHE_STORE=file -e PHP_CLI_SERVER_WORKERS=4 \
    asignua-plugin-php:8.4 sh -c "rm -f vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log \
    && vendor/bin/testbench migrate --force \
    && vendor/bin/testbench db:seed --class='Workbench\\Database\\Seeders\\DatabaseSeeder' --force \
    && vendor/bin/testbench filament:assets \
    && vendor/bin/testbench serve --host=0.0.0.0 --port=8000"
echo "workbench: http://localhost:8776/admin  (admin@example.test / password)"
