# Verificación de release y recuperación

Esta guía produce evidencia técnica reproducible antes de promover una versión. No
autoriza un despliegue productivo: RPO/RTO, retención, privacidad, infraestructura y
responsables siguen sujetos a los `PV` de `docs/plans/pendientes.md`.

No hay CI: el repositorio solo tiene `.github/dependabot.yml`. Las verificaciones se
ejecutan a mano.

## 1. Puerta desde instalación limpia

Use PostgreSQL y Redis locales. No ejecute dos suites a la vez contra la misma base:
`RefreshDatabase` recrea el esquema.

```bash
composer validate --strict --no-check-publish
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
docker compose up -d
composer verify
```

`composer verify` es la puerta canónica del backend. Ejecuta, en orden:

1. `security:scan`: busca secretos en el repositorio (`scripts/check-repository-secrets.sh`);
2. `config:clear`;
3. Pint en modo comprobación;
4. Larastan nivel 7;
5. Pest sobre PostgreSQL (base `silabos_ueb_test`, que crea `docker compose`).

El backend no tiene `package.json`. La puerta del frontend se ejecuta en su repositorio:
`npm run lint`, `npm run typecheck`, `npm test`, `npm run contract:check` y
`npm run build`.

Audite las dependencias por separado, porque los avisos cambian con el tiempo:

```bash
composer audit --locked --no-interaction    # backend
npm audit --audit-level=high                # en el repositorio del frontend
```

## 2. Configuración del candidato

Verifique al menos:

- `APP_ENV=production`, `APP_DEBUG=false`, URL HTTPS y `APP_KEY` generada fuera del
  repositorio;
- `SESSION_SECURE_COOKIE=true` y `TRUSTED_PROXIES` con la IP real del proxy;
- usuario PostgreSQL de ejecución sin DDL, red restringida y `DB_TIMEZONE=UTC`;
- `QUEUE_CONNECTION=redis` y `REDIS_QUEUE_RETRY_AFTER` mayor que 120 s, el `timeout` de
  `GenerateSyllabusExportJob`;
- volumen persistente en `storage/app`; nada funcional bajo `public/`;
- `GOTENBERG_URL` apuntando al servicio interno;
- `AI_DRIVER=disabled` mientras no haya proveedor de chat aprobado;
- `SIGNATURE_REQUIRE_HOLDER_MATCH=true` y, si se exige, `SIGNATURE_TRUSTED_CA_BUNDLE`;
- correo, respaldo, retención y observabilidad resueltos antes de producción.

Después:

```bash
php artisan optimize
php artisan route:list --except-vendor
php artisan migrate:status
```

Use `php artisan optimize:clear` al volver al ambiente local. No copie `.env` ni salidas
con secretos a la evidencia.

## 3. Salud HTTP y cabeceras

Con el candidato en ejecución:

```bash
curl --silent --show-error --dump-header - --output /dev/null \
  http://127.0.0.1:8000/health/ready
```

Espere `200` y estas cabeceras: `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, CSP con
`object-src 'none'` y `Cross-Origin-Resource-Policy: same-origin`. Readiness no crea
cookies de sesión ni XSRF. HSTS solo aparece en producción y por HTTPS; el proxy debe
conservar el esquema. La respuesta no revela versiones ni topología.

Las rutas públicas de logos (`logos.institution` y `logos.faculty`) son la única
excepción: responden `Cross-Origin-Resource-Policy: cross-origin` para que la SPA las
muestre desde otro origen. No aplica a documentos, descargas ni JSON.

## 4. Cola Redis real

Despache un trabajo de prueba y procese su cola:

```bash
php artisan platform:smoke-job --key=release-smoke-local
php artisan queue:work redis --queue=critica --stop-when-empty --tries=3
```

El worker debe mostrar el trabajo como terminado y el log debe registrar
`platform-smoke-ok` con la clave usada.

En ejecución continua el worker escucha, por prioridad:

```text
critica,notificaciones,documentos,ia,integraciones,general
```

Los trabajos actuales usan `critica`, `documentos`, `ia` y `general`. Después de un
despliegue ejecute `php artisan queue:restart` (en Dokploy, reiniciar el contenedor
basta). PostgreSQL conserva el estado; vaciar Redis no debe borrar evidencia funcional.

## 5. Respaldo y restauración técnica

El ensayo solo acepta una base fuente cuyo nombre termine en `_test` o
`_restore_source`. Crea un volcado temporal, inicia un clúster PostgreSQL aislado por
socket Unix, restaura en una transacción, compara conteos estructurales y borra los
temporales al salir.

```bash
DB_DATABASE=silabos_ueb_test php artisan migrate:fresh --seed --force
composer verify:restore
```

Si las credenciales de prueba difieren, use `RESTORE_CHECK_DB_HOST`,
`RESTORE_CHECK_DB_PORT`, `RESTORE_CHECK_DB_DATABASE`, `RESTORE_CHECK_DB_USERNAME` y
`RESTORE_CHECK_DB_PASSWORD` solo en el proceso local. No las registre.

Un resultado correcto demuestra que el procedimiento funciona con datos sintéticos. No
confirma cifrado, ubicación, retención, RPO, RTO ni restauración de archivos privados
(`PV-11`, `PV-12`).

## 6. Línea base de readiness

Con la aplicación local levantada, en otra terminal:

```bash
composer benchmark:readiness
```

El script solo admite HTTP en loopback y por defecto envía 500 peticiones con
concurrencia 50. Registra fallos, p95, tasa y duración. Para otra URL local:

```bash
READINESS_BENCHMARK_URL=http://127.0.0.1:18765/health/ready \
  composer benchmark:readiness
```

El último resultado y su alcance están en `docs/quality/performance.md`. Es una línea
base del endpoint de salud, no la aceptación de RNF-011..015.

## 7. Criterios de avance y reversión

No promueva el candidato si falla una migración, la puerta canónica, readiness, la cola,
la restauración, una autorización crítica o una revisión de seguridad. Registre el fallo
sin datos sensibles.

Conserve el artefacto anterior y un camino de corrección hacia adelante. Las migraciones
aplicadas no se editan ni se revierten de forma destructiva. Una restauración real exige
autorización del responsable, punto de recuperación acordado, resguardo de la base
afectada y conciliación de archivos y colas; el script local no reemplaza ese
procedimiento institucional.


## Activación inicial de respuestas protegidas (ADR-0012)

El despliegue no sustituye la plantilla activa. Cuando todavía no exista ningún
sílabo, se puede aplicar la actualización inicial conservando los IDs de los campos
y los valores de las fichas:

```bash
php artisan template:protect-responses
php artisan template:protect-responses --apply
```

El primer comando solo muestra las condiciones. Antes de usar `--apply`, verifique
la base de Sílabos mediante su identidad y las tablas `silabos`, `usuarios` y
`migraciones`; compruebe un respaldo y ejecute como propietario de la aplicación.
Use las credenciales locales solo en memoria desde `.pgpass`. La acción bloquea
los procesos y la plantilla dentro de una transacción, exige cero sílabos en toda la
base y rechaza procesos abiertos, catálogos en conflicto y horas personalizadas.
La auditoría identifica el origen como mantenimiento CLI ejecutado por Sistema.

Después compare los valores de ficha, los IDs de campos existentes, el esquema y
los permisos antes/después. Solo se agrega el catálogo de unidades, se sustituye el
contenido de planificación y se activa `response_policy: protected`. Las demás
secciones y personalizaciones se conservan. La convocatoria sigue pausada.
Coordinación debe completar las unidades de cada materia antes de reanudar.

La migración `2026_10_26_000230_units_from_subject_profile` ejecuta la misma acción
al desplegar, para pasar de la primera versión (tabla de unidades aparte) al bloque
`unidades` con datos de la ficha. Si no puede aplicarse, no cambia nada y escribe el
motivo en el registro de la migración.

Si ya existen sílabos, este comando se detiene. Use el flujo administrativo habitual:
pausa, edición y confirmación al reanudar con el conteo del trabajo que se rehace.
No use una importación completa del JSON para simular esta actualización inicial:
la importación conserva historia mediante campos archivados y puede crear otros IDs.
