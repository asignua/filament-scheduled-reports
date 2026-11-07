#!/usr/bin/env bash
# Runs the concurrency tests against a REAL MariaDB 11.4 and a REAL PostgreSQL 16, each in a throwaway
# container that is removed afterwards. Touches no other container, network or volume.
#
#   bash tests/Databases/run.sh            (from the plugin directory; needs the asignua-plugin-php:8.4 image,
#                                           which scripts/plugin-php.sh in the plugins directory builds)
set -euo pipefail

plugin="$(cd "$(dirname "$0")/../.." && pwd)"
plugins="$(cd "$plugin/.." && pwd)"
tag="sr-test-$$"
net="$tag-net"
maria="$tag-maria"
pg="$tag-pg"
image="asignua-plugin-php:8.4-db"

cleanup() {
    docker rm -f "$maria" "$pg" >/dev/null 2>&1 || true
    docker network rm "$net" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker image inspect "$image" >/dev/null 2>&1 || docker build -t "$image" -f "$plugin/tests/Databases/Dockerfile" "$plugin/tests/Databases"

docker network create "$net" >/dev/null
docker run -d --name "$maria" --network "$net" -e MARIADB_ROOT_PASSWORD=secret -e MARIADB_DATABASE=reports mariadb:11.4 >/dev/null
docker run -d --name "$pg" --network "$net" -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=reports postgres:16 >/dev/null

echo "waiting for the databases..."
for _ in $(seq 1 60); do
    docker exec "$maria" mariadb-admin ping -uroot -psecret --silent >/dev/null 2>&1 && break
    sleep 1
done
for _ in $(seq 1 60); do
    docker exec "$pg" pg_isready -U postgres -d reports >/dev/null 2>&1 && break
    sleep 1
done
# Postgres restarts once after its init scripts; give it a moment to settle.
sleep 3

run() {
    docker run --rm --network "$net" -v "$plugins":/plugins -w /plugins/filament-scheduled-reports \
        -e SR_DB="$1" -e SR_DB_HOST="$2" -e SR_DB_PORT="$3" -e SR_DB_USER="$4" -e SR_DB_PASSWORD=secret -e SR_DB_NAME=reports \
        "$image" php vendor/bin/phpunit --group databases
}

echo "== MariaDB 11.4 =="
docker exec "$maria" mariadb --version
run mariadb "$maria" 3306 root

echo "== PostgreSQL 16 =="
docker exec "$pg" postgres --version
run pgsql "$pg" 5432 postgres
