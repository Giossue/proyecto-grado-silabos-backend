# Despliegue

Los pasos concretos en Dokploy están en
[deploy-dokploy.md](../runbooks/deploy-dokploy.md). Este documento describe las piezas,
las variables y los límites que impone el código.

## Piezas

```text
proxy con TLS (Dokploy)
├── frontend: Nginx con la SPA            (repositorio proyecto-grado-silabos-frontend)
├── backend: un contenedor con supervisord (este repositorio)
│   ├── php-fpm + nginx  → API en el puerto 8080
│   ├── queue:work       → colas en Redis
│   └── schedule:work    → tareas programadas
├── PostgreSQL 18
├── Redis 8
└── Gotenberg 8 (interno, sin dominio público)
```

- Un push a `main` dispara el despliegue y el arranque aplica las migraciones.
- Frontend y backend son imágenes distintas. Se despliegan versiones compatibles de
  ambas.
- El proveedor de IA del chat es un servicio externo por HTTPS.

## Arranque del backend

`docker/app/entrypoint.sh` hace, en orden:

1. falla si no hay `APP_KEY`;
2. `config:cache`, `route:cache`, `view:cache`, `event:cache`;
3. espera a PostgreSQL (hasta 30 intentos);
4. si `RUN_MIGRATIONS` es `true` (por defecto): `db:rename-migrations-table --force` y
   `migrate --force --isolated`;
5. prepara `storage/app/private` y `storage/app/public`, y ejecuta `storage:link`.

Como la configuración queda en caché, un cambio de variables exige redesplegar.

## Archivos persistentes

Exportaciones, PDF firmados, logos y el paquete de entidades
certificadoras viven en `storage/app/private` (disco `private`). Monte un volumen
persistente en `/var/www/html/storage/app`; sin él, cada despliegue los borra.

## Salud

| Ruta | Qué comprueba |
|---|---|
| `GET /health/live` | El proceso responde. Lo usa el `HEALTHCHECK` de la imagen. |
| `GET /health/ready` | PostgreSQL responde a `select 1`. Si no, `503`. |
| `GET /health/live` (frontend) | Nginx responde. |

Redis, Gotenberg y la IA no hacen que el backend quede «no listo».

## Colas y worker

Un solo proceso `queue:work` atiende todas las colas, en este orden de prioridad:

```text
critica, documentos, ia, general
```

`critica` es el trabajo de prueba, `documentos` el Word y el PDF, `ia` el asistente del
docente y `general` el correo de activación.

| Trabajo | Cola | `timeout` | Intentos |
|---|---|---:|---:|
| `PlatformSmokeJob` | `critica` | 30 s | 3 |
| `GenerateSyllabusExportJob` | `documentos` | 120 s | 3 |
| `AnswerSyllabusChatJob` | `ia` | 60 s | 3 |

Detalle de cada trabajo: [queues-and-jobs.md](queues-and-jobs.md).

### Requisito: `retry_after` mayor que el `timeout` más largo

Redis vuelve a entregar un trabajo reservado cuando pasan `retry_after` segundos
(`REDIS_QUEUE_RETRY_AFTER`). Laravel exige que ese valor sea mayor que el `timeout` del
trabajo más largo. Si no, el trabajo puede ejecutarse dos veces o marcarse como fallido
mientras sigue corriendo.

- El trabajo más largo actual es `GenerateSyllabusExportJob` con 120 s.
- `.env.example` y `config/queue.php` conservan 960 s.
- `REDIS_QUEUE_RETRY_AFTER` debe superar 120 s. El retiro del OCR no requiere cambiar
  una configuración que ya cumpla esta condición.

### Otros límites del worker

- Un solo worker: mientras corre una exportación (hasta 120 s), el chat y los demás
  trabajos esperan.
- `supervisord` espera 65 s (`stopwaitsecs`) al detener el worker. Un despliegue
  durante un trabajo largo lo corta; el trabajo se reintenta o queda `fallido` según
  sus intentos.
- `--max-time=3600`: el worker se reinicia cada hora.

## Tareas programadas

`schedule:work` corre `auth:clear-resets` cada 15 minutos.

## Variables de entorno

Partir de `.env.example`. Las claves solo van en las variables de Dokploy; nunca en la
imagen, el repositorio o el frontend.

| Grupo | Variables |
|---|---|
| Aplicación | `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_TIMEZONE=UTC`, `DISPLAY_TIMEZONE=America/Guayaquil`, `TRUSTED_PROXIES`, `RUN_MIGRATIONS` |
| SPA y sesión | `FRONTEND_URL` (origen permitido por CORS), `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `SESSION_ENCRYPT=true` |
| Base de datos | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_TIMEZONE=UTC` |
| Redis | `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_QUEUE_RETRY_AFTER` (ver arriba) |
| Correo | `MAIL_MAILER` (`resend` o `smtp`), `RESEND_API_KEY`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `NOTIFICATION_EMAILS`, `NOTIFICATION_EMAIL_EVENTS` |
| Archivos | `FILESYSTEM_DISK=private` |
| PDF | `GOTENBERG_URL`, `GOTENBERG_TIMEOUT` (90 por defecto) |
| Firma | `SIGNATURE_TRUSTED_CA_BUNDLE`, `SIGNATURE_REQUIRE_HOLDER_MATCH` |
| IA | `AI_DRIVER`, `AI_BASE_URL`, `AI_MODEL`, `AI_API_KEY`, `AI_CONNECT_TIMEOUT`, `AI_TIMEOUT` |

El frontend solo necesita `PUBLIC_API_URL` (origen de la API, sin ruta).

### Firma electrónica

| Variable | Por defecto | Efecto |
|---|---|---|
| `SIGNATURE_REQUIRE_HOLDER_MATCH` | `true` | El titular del certificado debe coincidir con el nombre de la cuenta (mismas palabras, sin importar orden, tildes ni mayúsculas). |
| `SIGNATURE_TRUSTED_CA_BUNDLE` | vacío | Ruta a un PEM con raíces e intermedias reconocidas. Vacío: solo se verifica la integridad de la firma, no la cadena. |

El PEM se arma con:

```bash
php artisan signatures:build-trust-bundle <certificados .cer/.crt/.pem> \
  --output=storage/app/private/ca-confiables.pem
```

El comando muestra la huella de cada certificado para compararla con la que publica
la entidad. Qué entidades cargar: [firma-electronica-entidades.md](../runbooks/firma-electronica-entidades.md).

## Gotenberg (PDF igual al Word)

Gotenberg convierte el Word del sílabo en un PDF con el mismo formato: tablas, colores
y logos. La firma se hace sobre ese PDF. Sin `GOTENBERG_URL`, el sistema genera un PDF
de texto simple: sirve para pruebas locales, no para firmar en serio. Si
`GOTENBERG_URL` está definido y Gotenberg falla, la exportación falla y se reintenta;
no cae al PDF simple.

Pasos en Dokploy:

1. En el mismo proyecto, crea un servicio **Aplicación → Docker** con la imagen
   `gotenberg/gotenberg:8`, nombre sugerido `gotenberg`, puerto interno `3000`. No le
   pongas dominio ni lo publiques a internet. No necesita volúmenes.
2. Opcional: limita recursos (LibreOffice usa cerca de 1 GB de RAM al convertir) y pasa
   `--api-timeout=120s` como argumento del contenedor.
3. Despliega el servicio y espera a que quede en verde.
4. Copia el nombre interno que Dokploy asignó al servicio (por ejemplo
   `silabos-gotenberg-abc123`).
5. En las variables del backend agrega:
   ```text
   GOTENBERG_URL=http://<nombre-interno>:3000
   GOTENBERG_TIMEOUT=90
   ```
   El PDF lo genera el worker. Si algún día el worker corre en otra aplicación, esa
   aplicación también necesita estas variables.
6. Redespliega el backend.

Comprobar:

1. Desde el contenedor del backend, `curl http://<nombre-interno>:3000/health` responde
   `{"status":"up"}`.
2. Aprueba un sílabo de prueba y, como docente, abre el PDF que se va a firmar. Si
   tiene tablas y logos igual al Word, Gotenberg funciona.
3. Si sale como texto simple, revisa `GOTENBERG_URL` (nombre y puerto) y los registros
   del servicio `gotenberg`.

La conversión usa `POST /forms/libreoffice/convert`. El servidor fija las fechas y el
identificador del PDF a partir del Word para que el resultado sea estable.
