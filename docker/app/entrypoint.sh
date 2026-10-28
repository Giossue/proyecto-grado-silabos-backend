#!/bin/sh
set -e

cd /var/www/html

if [ -z "${APP_KEY}" ]; then
    echo "ERROR: falta APP_KEY. Genérela con 'php artisan key:generate --show' y" >&2
    echo "       cárguela como variable de entorno en Dokploy." >&2
    exit 1
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

attempt=1
until php artisan db:monitor --max=1 >/dev/null 2>&1 || [ "${attempt}" -ge 30 ]; do
    echo "Esperando a PostgreSQL (intento ${attempt}/30)…"
    attempt=$((attempt + 1))
    sleep 2
done

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan db:rename-migrations-table --force

    # --isolated toma un bloqueo en la caché, que vive en PostgreSQL (bloqueos_temporales). En una
    # base nueva, o antes de la migración que crea esa tabla, el bloqueo no existe: se migra sin él.
    php artisan migrate --force --isolated || php artisan migrate --force
fi

mkdir -p storage/app/private storage/app/public
chown -R www-data:www-data storage/app
chmod -R u+rwX,g+rwX storage/app

php artisan storage:link || true

exec "$@"
