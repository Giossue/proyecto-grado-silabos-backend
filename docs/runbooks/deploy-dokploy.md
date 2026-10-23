# Despliegue en Dokploy

El sistema se despliega como dos aplicaciones de Dokploy y un servicio auxiliar:

- **Backend** (`Giossue/proyecto-grado-silabos-backend`): una imagen con cuatro procesos
  bajo `supervisord`: Nginx, PHP-FPM, el worker de colas y el planificador. Escalar la
  aplicación escala los cuatro.
- **Frontend** (`Giossue/proyecto-grado-silabos-frontend`): la SPA servida por Nginx.
- **Gotenberg**: convierte el Word del sílabo en PDF con el mismo formato.

PostgreSQL y Redis no van en la imagen: el backend se conecta a los del servidor con
variables de entorno. Un push a `main` despliega y el arranque aplica las migraciones.

## 1. Crear las aplicaciones

Cree dos recursos **Application** con origen **GitHub**, rama `main`, **Dockerfile**
(`./Dockerfile`) y puerto `8080`. Como los repositorios son privados, autorice antes la
cuenta de GitHub en Dokploy. Cada aplicación tiene su propio dominio HTTPS.

En el frontend configure solo `PUBLIC_API_URL=https://<dominio-api>` (origen sin ruta).
No copie secretos del backend a esa aplicación. La SPA responde `GET /health/live`.

Sanctum autentica con cookie `HttpOnly`, CORS con credenciales y CSRF; no hay tokens
personales. Por eso el backend necesita el origen completo de la SPA en `FRONTEND_URL`,
su host sin esquema en `SANCTUM_STATEFUL_DOMAINS` y el dominio compartido en
`SESSION_DOMAIN`.

La imagen compila `pdo_pgsql` contra `libpq-dev` y luego lo quita. No use el paquete
`postgresql-dev`: arrastra herramientas del servidor PostgreSQL que la aplicación no usa.

## 2. Variables del backend

`APP_KEY` es obligatoria; sin ella el contenedor no arranca. Genérela con
`php artisan key:generate --show` y copie el valor completo, con el prefijo `base64:`.

```text
APP_NAME="Sílabos UEB"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://<dominio-api>
APP_TIMEZONE=UTC
DISPLAY_TIMEZONE=America/Guayaquil
APP_LOCALE=es

FRONTEND_URL=https://<dominio-spa>
SANCTUM_STATEFUL_DOMAINS=<dominio-spa>
SESSION_DOMAIN=<dominio compartido>
TRUSTED_PROXIES=<IP del proxy de Dokploy>

DB_CONNECTION=pgsql
DB_HOST=<host de PostgreSQL>
DB_PORT=5432
DB_DATABASE=<base>
DB_USERNAME=<usuario>
DB_PASSWORD=<secreto>
DB_TIMEZONE=UTC

REDIS_CLIENT=predis
REDIS_HOST=<host de Redis>
REDIS_PORT=6379
REDIS_PASSWORD=<secreto>

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_QUEUE_RETRY_AFTER=960
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true

FILESYSTEM_DISK=private
LOG_CHANNEL=stderr
LOG_LEVEL=warning

GOTENBERG_URL=http://<nombre-interno-gotenberg>:3000
GOTENBERG_TIMEOUT=90

AI_DRIVER=disabled
AI_BASE_URL=
AI_MODEL=
AI_API_KEY=

OPENPARSER_ENABLED=false
OPENPARSER_API_KEY=

SIGNATURE_TRUSTED_CA_BUNDLE=
SIGNATURE_REQUIRE_HOLDER_MATCH=true

MAIL_MAILER=log
```

- `TRUSTED_PROXIES` vale `*` si no se define; fije la IP real del proxy.
- `SESSION_SECURE_COOKIE=true` exige HTTPS. Sin certificado nadie puede iniciar sesión.
- `REDIS_QUEUE_RETRY_AFTER` debe ser mayor que el `timeout` del trabajo más largo:
  `PrepareFichaImportJob`, con 900 s. `.env.example` trae 960. Un valor explícito de 180
  tampoco cubre `ExtractSubjectFichaJob`, con 240 s. Con un valor menor y más de
  un worker, Redis entrega de nuevo una importación en curso y esta termina marcada como
  fallida.
- Las claves (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `AI_API_KEY`,
  `OPENPARSER_API_KEY`, `RESEND_API_KEY`) se cargan como secretos de Dokploy. No van al
  repositorio, a la base, a los logs ni a comandos de diagnóstico.
- Tras cambiar variables, redespliegue o reinicie el contenedor: el arranque rehace la
  caché de configuración y reinicia el worker.

La base debe existir antes del primer despliegue: el contenedor migra, pero no la crea.
Una instalación desde cero necesita las extensiones `btree_gist` y `vector` disponibles
en PostgreSQL: las migraciones las crean, aunque una posterior quita `vector`. La imagen
local `pgvector/pgvector:0.8.6-pg18-trixie` ya trae ambas.

### Proveedor de IA

La IA parte apagada (`AI_DRIVER=disabled`) y su ausencia no bloquea el flujo del sílabo.
Se usa en dos lugares:

- **Asistente de unidades** del docente: admite `openai`, `claude` y `deepseek`.
- **Importación de la ficha** en Proyecto de carrera: admite solo `openai` y `deepseek`.

Se configuran cuatro variables. La URL debe ser HTTPS y puede ser la base o el endpoint
completo.

| Proveedor | `AI_DRIVER` | `AI_BASE_URL`                  |
| --------- | ----------- | ------------------------------ |
| OpenAI    | `openai`    | `https://api.openai.com/v1`    |
| Claude    | `claude`    | `https://api.anthropic.com/v1` |
| DeepSeek  | `deepseek`  | `https://api.deepseek.com`     |

`AI_MODEL` es el modelo elegido y `AI_API_KEY` su clave. `AI_CONNECT_TIMEOUT` (2 s) y
`AI_TIMEOUT` (20 s) tienen valores por defecto.

Activar un proveedor envía datos a un tercero: la pregunta del chat con el contexto
autorizado del sílabo y el texto de los documentos de la ficha. Antes de usarlo con
información institucional deben acordarse proveedor, región, retención y tratamiento de
datos (`PV-12`, `PV-14`).

### Lectura de documentos de la ficha

La importación acepta PDF y Excel. El Excel se lee en el servidor. El PDF:

- primero se intenta leer en el servidor con `pdftotext`, conservando sus páginas;
- si falta texto legible y están `OPENPARSER_ENABLED=true` y `OPENPARSER_API_KEY`, se
  envía al OCR de OpenParser (modelo `paddleocr-vl-1.6`, espera hasta
  `OPENPARSER_MAX_WAIT=780` s). Sin OCR se conserva el texto local disponible y se
  rechaza el archivo si no contiene texto.

`pdftotext` viene de `poppler-utils`, instalado en la imagen. También se usa para ubicar
el sello de cada firma en el PDF. OpenParser es otro tercero: aplica la misma condición
de `PV-12`.

### Gotenberg

Sin `GOTENBERG_URL` el sistema genera un PDF de texto simple. Como las firmas se hacen
sobre el PDF, conviene tenerlo.

1. En el proyecto de Dokploy cree un servicio **Aplicación → Docker** con imagen
   `gotenberg/gotenberg:8` y puerto interno `3000`. No le ponga dominio ni lo publique.
2. Despliéguelo y copie el nombre interno que Dokploy le asigna.
3. En el backend defina `GOTENBERG_URL=http://<nombre-interno>:3000` y
   `GOTENBERG_TIMEOUT=90`. El worker corre en el mismo contenedor y toma la misma
   variable.
4. Redespliegue el backend.

Para comprobarlo, apruebe un sílabo de prueba y revise su PDF. Si sale con tablas y logos
igual al Word, funciona. Si sale como texto simple, revise la URL, el puerto y los logs
del servicio `gotenberg`.

### Firma electrónica

`SIGNATURE_REQUIRE_HOLDER_MATCH=true` exige que el titular del certificado coincida con
el nombre de la cuenta. `SIGNATURE_TRUSTED_CA_BUNDLE` vacío acepta cualquier emisor; para
aceptar solo entidades acreditadas en Ecuador siga
`docs/runbooks/firma-electronica-entidades.md`.

### Correo

Las invitaciones de activación usan el correo de Laravel; el enlace usa `FRONTEND_URL` y
caduca en 24 horas. Mientras `PV-15` siga abierta se deja `MAIL_MAILER=log`: los mensajes
se escriben en los logs de Dokploy sin salir del sistema.

Para enviar con Resend, cuyo transporte ya está instalado:

```text
MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS="no-responder@devs-ueb.tech"
MAIL_FROM_NAME="${APP_NAME}"
```

Antes hay que verificar el dominio en el panel de Resend con los registros DNS que pida.
Sin eso los mensajes se rechazan o llegan a spam, y el fallo solo se ve en el panel del
proveedor. El destino a largo plazo es el SMTP institucional (`MAIL_MAILER=smtp` y sus
credenciales), que es lo que `PV-15` debe resolver.

## 3. Qué hace el arranque

`docker/app/entrypoint.sh`, en cada arranque:

1. aborta con un mensaje claro si falta `APP_KEY`;
2. rehace las cachés de configuración, rutas, vistas y eventos, para que tomen las
   variables actuales;
3. espera hasta 60 segundos a que PostgreSQL responda;
4. ejecuta `db:rename-migrations-table --force` (renombra la tabla de control a
   `migraciones` si hace falta) y `migrate --force --isolated`, con bloqueo para que dos
   réplicas no migren a la vez. Con `RUN_MIGRATIONS=false` se omiten ambos pasos y las
   migraciones se aplican a mano según `docs/security/hardening.md`;
5. prepara `storage/app` y crea el enlace de almacenamiento público.

El worker escucha `critica,notificaciones,documentos,ia,integraciones,general` con
`--tries=3`. Hoy se usan `critica` (trabajo de prueba), `documentos` (Word y PDF), `ia`
(asistente e importación de la ficha) y `general` (correo de activación).

En una instalación nueva, cargue la plantilla oficial una vez:

```bash
php artisan db:seed --class=SyllabusTemplateSeeder --force
```

El seeder no sobrescribe una plantilla con contenido. También puede cargarse desde
Administración → Plantilla → **Editar JSON** con `resources/templates/silabo-oficial.json`.

## 4. Comprobación

- `GET /health/live` responde sin tocar la base; sirve al chequeo de vida.
- `GET /health/ready` consulta la base y responde `{"status":"ready"}`, o `503` con
  `{"status":"unavailable"}`.

## 5. Primer administrador

El seeder de demostración no se ejecuta en producción: crea cuentas con una contraseña
conocida. Cree la primera cuenta desde la consola del contenedor con `php artisan tinker`
(usuario más asignación de rol `administrador`). Las cuentas creadas desde el panel nacen
pendientes y reciben un enlace de activación de un solo uso.

En un servidor de pruebas, `php artisan pruebas:preparar --carrera=<código>` crea una
coordinación, dos docentes, paralelos y la convocatoria. Lee la contraseña de la variable
`PRUEBAS_CONTRASENA` y necesita una cuenta de Administración activa.

## 6. Almacenamiento persistente

`storage/app` guarda los archivos privados: Word y PDF, PDF firmados, logos y documentos
de importación de la ficha. Sin un volumen, cada redespliegue los pierde. En Dokploy
añada un **volume mount** hacia `/var/www/html/storage/app`.
