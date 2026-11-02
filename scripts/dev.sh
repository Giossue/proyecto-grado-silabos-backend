#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND="$ROOT/proyecto-grado-silabos-frontend"
PHP_INI_DIR="$HOME/.config/silabos-php"
GOTENBERG="gotenberg-local"

cd "$ROOT"

if command -v podman >/dev/null 2>&1; then
    CONTAINERS=podman
else
    CONTAINERS=docker
fi

echo "› Base de datos"
$CONTAINERS compose up -d >/dev/null

echo "› Gotenberg"
if ! $CONTAINERS start "$GOTENBERG" >/dev/null 2>&1; then
    $CONTAINERS run -d --name "$GOTENBERG" -p 127.0.0.1:3010:3000 docker.io/gotenberg/gotenberg:8 >/dev/null
fi

mkdir -p "$PHP_INI_DIR"
printf 'upload_max_filesize = 32M\npost_max_size = 32M\n' > "$PHP_INI_DIR/uploads.ini"

echo "› Migraciones"
php artisan migrate --no-interaction

stop() {
    trap - INT TERM EXIT
    echo
    echo "› Apagando"
    $CONTAINERS stop "$GOTENBERG" >/dev/null 2>&1 || true
    kill 0 2>/dev/null || true
}
trap stop INT TERM EXIT

prefix() { sed -u "s/^/[$1] /"; }

PHP_INI_SCAN_DIR=":$PHP_INI_DIR" php artisan serve --host=127.0.0.1 --port=8000 2>&1 | prefix api &
php artisan queue:work --queue=critica,documentos,ia,general 2>&1 | prefix worker &
(cd "$FRONTEND" && npm run dev -- --host 127.0.0.1 --port 4173) 2>&1 | prefix web &

echo "› Listo: http://127.0.0.1:4173  (Ctrl+C para apagar)"
wait
