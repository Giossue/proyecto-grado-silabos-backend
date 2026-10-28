# Despliegue en Dokploy

El sistema se despliega como dos aplicaciones de Dokploy y un servicio auxiliar:

- **Backend** (`Giossue/proyecto-grado-silabos-backend`): una imagen con cuatro procesos
  bajo `supervisord`: Nginx, PHP-FPM, el worker de colas y el planificador. Escalar la
  aplicación escala los cuatro.
- **Frontend** (`Giossue/proyecto-grado-silabos-frontend`): la SPA servida por Nginx.
- **Gotenberg**: convierte el Word del sílabo en PDF con el mismo formato.

PostgreSQL no va en la imagen: el backend se conecta al del servidor con
variables de entorno. Cola, caché y bloqueos usan esa misma base; no hace falta Redis.
Un push a `main` despliega y el arranque aplica las migraciones.

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

CACHE_STORE=database
QUEUE_CONNECTION=database
QUEUE_RETRY_AFTER=960
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


SIGNATURE_TRUSTED_CA_BUNDLE=
SIGNATURE_REQUIRE_HOLDER_MATCH=true

MAIL_MAILER=log
```

- `TRUSTED_PROXIES` vale `*` si no se define; fije la IP real del proxy.
- `SESSION_SECURE_COOKIE=true` exige HTTPS. Sin certificado nadie puede iniciar sesión.
- `QUEUE_RETRY_AFTER` debe ser mayor que el timeout del trabajo más largo:
  `GenerateSyllabusExportJob`, con 120 s. `.env.example` conserva 960 s.
- Las claves (`APP_KEY`, `DB_PASSWORD`, `AI_API_KEY`,
  `RESEND_API_KEY`) se cargan como secretos de Dokploy. No van al
  repositorio, a la base, a los logs ni a comandos de diagnóstico.
- Tras cambiar variables, redespliegue o reinicie el contenedor: el arranque rehace la
  caché de configuración y reinicia el worker.

La base debe existir antes del primer despliegue: el contenedor migra, pero no la crea.
Una instalación desde cero necesita las extensiones `btree_gist` y `vector` disponibles
en PostgreSQL: las migraciones las crean, aunque una posterior quita `vector`. La imagen
local `pgvector/pgvector:0.8.6-pg18-trixie` ya trae ambas.

### Proveedor de IA

La IA parte apagada (`AI_DRIVER=disabled`) y su ausencia no bloquea el flujo del sílabo.
El **asistente de unidades** del docente admite `openai`, `claude` y `deepseek`.
La importación de fichas usa Excel estructurado y no utiliza este proveedor.

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
autorizado del sílabo. Antes de usarlo con
información institucional deben acordarse proveedor, región, retención y tratamiento de
datos (`PV-12`, `PV-14`).

### Importación de fichas

Se genera y lee una plantilla Excel en el servidor, sin proveedor externo ni cola.
El flujo antiguo de documentos y su configuración fueron retirados.

### Gotenberg

Sin `GOTENBERG_URL` el sistema genera un PDF de texto simple. Para el PDF con el formato
oficial y los sellos de firma, conviene tenerlo.

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

Hasta instalar el sistema en la UEB (diciembre de 2026) el servidor de pruebas envía con **Resend**
y se conserva. En la universidad el correo saldrá por **su servidor SMTP**, no por Resend. El enlace de las invitaciones usa
`FRONTEND_URL` y caduca en 24 horas. Mientras `PV-15` siga abierta se deja `MAIL_MAILER=log`:
los mensajes se escriben en los logs de Dokploy sin salir del sistema.

**Producción: SMTP institucional.** TIC debe entregar el servidor, el puerto, la cuenta y la
dirección de envío:

```text
MAIL_MAILER=smtp
MAIL_HOST=<servidor SMTP de la UEB>
MAIL_PORT=587
MAIL_SCHEME=null
MAIL_USERNAME=<cuenta de envío>
MAIL_PASSWORD=<clave de la cuenta>
MAIL_FROM_ADDRESS="<dirección institucional, por ejemplo no-responder@ueb.edu.ec>"
MAIL_FROM_NAME="${APP_NAME}"
```

- Puerto 587 con `MAIL_SCHEME=null` usa STARTTLS. Si TIC indica el puerto 465 (TLS directo), use
  `MAIL_SCHEME=smtps`.
- El servidor de correo debe aceptar enviar desde `MAIL_FROM_ADDRESS`; si no, los mensajes se
  rechazan. El valor de `.env.example` (`no-responder@devs-ueb.tech`) es del servidor de pruebas:
  cámbielo.
- Si el servidor SMTP está en la red interna, el contenedor de la app debe poder alcanzarlo.
- Los envíos pasan por la cola `general`: el worker debe estar activo. Si un envío falla tras sus
  reintentos, solo queda en los logs (no se guardan trabajos fallidos) y no se ve en la pantalla del usuario.

**Pruebas, hasta la instalación en la UEB: Resend**, cuyo transporte ya está instalado:

```text
MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS="no-responder@devs-ueb.tech"
MAIL_FROM_NAME="${APP_NAME}"
```

Antes hay que verificar el dominio en el panel de Resend con los registros DNS que pida. Sin eso
los mensajes se rechazan o llegan a spam, y el fallo solo se ve en el panel del proveedor.

Además de las invitaciones, llegan por correo pocos avisos del sistema, solo los que piden
actuar. Hoy son tres, para el docente: convocatoria abierta, corrección solicitada y sílabo
aprobado. Se manda un correo por aviso y persona, solo a cuentas activas con correo
verificado; los demás avisos quedan en la campana.

| Variable | Por defecto | Efecto |
|---|---|---|
| `NOTIFICATION_EMAILS` | `true` | `false` apaga estos correos; las invitaciones de activación no cambian. |
| `NOTIFICATION_EMAIL_EVENTS` | `convocatoria.abierta,silabo.correccion_solicitada,silabo.aprobado` | Tipos de aviso que se envían por correo, separados por coma. Vacía o con valores que no son tipos de aviso (como `TRUE`) usa la lista por defecto. Normalmente no hace falta definirla. |
| `NOTIFICATION_DIGEST` | `true` | `false` apaga el resumen diario de Coordinación. |
| `NOTIFICATION_DIGEST_TIME` | `08:00` | Hora del resumen (`HH:MM`), en la hora de la institución. Vacía o con otro formato vale 08:00. |

Coordinación no recibe correo por cada aviso: recibe un resumen al día, de lunes a viernes, y
solo si tiene sílabos por revisar. Requiere que el programador (`schedule:work`) y
el worker estén activos. Con `MAIL_MAILER=log` ninguno sale del sistema. Los tipos disponibles
están en `NotificationDestination` (`silabo.enviado`, `coordinacion.aprobado`, etc.).

## 3. Qué hace el arranque

`docker/app/entrypoint.sh`, en cada arranque:

1. aborta con un mensaje claro si falta `APP_KEY`;
2. rehace las cachés de configuración, rutas, vistas y eventos, para que tomen las
   variables actuales;
3. espera hasta 60 segundos a que PostgreSQL responda;
4. ejecuta `db:rename-migrations-table --force` (renombra la tabla de control a
   `migraciones` si hace falta) y `migrate --force --isolated`, con bloqueo para que dos
   réplicas no migren a la vez. Si la tabla del bloqueo (`bloqueos_temporales`) todavía no
   existe, migra sin bloqueo. Con `RUN_MIGRATIONS=false` se omiten ambos pasos y las
   migraciones se aplican a mano según `docs/security/hardening.md`;
5. prepara `storage/app` y crea el enlace de almacenamiento público.

El worker escucha `critica,documentos,ia,general` con
`--tries=3`. Hoy se usan `critica` (trabajo de prueba), `documentos` (PDF), `ia`
(asistente del docente) y `general` (correo de activación).

En una instalación nueva, cargue la plantilla oficial una vez:

```bash
php artisan db:seed --class=SyllabusTemplateSeeder --force
```

El seeder no sobrescribe una plantilla con contenido. También puede cargarse desde
Administración → Plantilla → **Opciones avanzadas → Editar JSON** con `resources/templates/silabo-oficial.json`.

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

`storage/app` guarda los archivos privados: PDF de los sílabos y logos. Sin un volumen, cada redespliegue los pierde. En Dokploy
añada un **volume mount** hacia `/var/www/html/storage/app`.
