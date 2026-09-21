# Despliegue en Dokploy

> I-84 divide el producto en dos aplicaciones y repositorios. Este documento describe
> ambos despliegues; Laravel no compila ni sirve la SPA.

El backend se despliega como **una imagen** construida desde
`Giossue/proyecto-grado-silabos-backend`.
PostgreSQL y Redis no viajan en ella: la imagen se conecta a los que ya existen en el
servidor mediante variables de entorno.

Dentro del contenedor conviven cuatro procesos bajo `supervisord`: Nginx, PHP-FPM, el
worker de colas y el planificador. Escalar la aplicación escala el conjunto; separar el
worker exigiría otra aplicación en Dokploy apuntando a la misma imagen con otro comando.

La segunda aplicación se construye desde `Giossue/proyecto-grado-silabos-frontend`. La
SPA expone el puerto `8080`, `GET /health/live` y recibe
`PUBLIC_API_URL=https://<dominio-api>` en tiempo de ejecución. Laravel debe recibir el
origen completo de la SPA en `FRONTEND_URL`, su host sin esquema en
`SANCTUM_STATEFUL_DOMAINS` y el dominio compartido en `SESSION_DOMAIN`. No se usa token
personal: Sanctum autentica con cookie `HttpOnly`, credenciales CORS y CSRF.

## 1. Crear las aplicaciones

En Dokploy crea dos recursos **Application** con origen **GitHub** y rama `main`:

1. `Giossue/proyecto-grado-silabos-backend` para API, worker y scheduler;
2. `Giossue/proyecto-grado-silabos-frontend` para la SPA.

Como son privados, autoriza la cuenta de GitHub desde Dokploy antes de crearlos.

En ambos casos usa **Dockerfile**, ruta `./Dockerfile`, y puerto `8080`. Cada aplicación
debe tener su propio dominio HTTPS. En el frontend configura únicamente
`PUBLIC_API_URL=https://<dominio-api>`; no copies secretos del backend a esa aplicación.

La imagen final fija la rama de Alpine y compila `pdo_pgsql` contra `libpq-dev`. No debe
usarse el paquete virtual `postgresql-dev`: incluye herramientas de desarrollo del
servidor PostgreSQL y puede incorporar toolchains grandes como LLVM sin que la aplicación
los necesite.

## 2. Variables de entorno

`APP_KEY` es obligatoria y el contenedor se niega a arrancar sin ella. Genera una con:

```
php artisan key:generate --show
```

Copia el valor completo, incluido el prefijo `base64:`.

```
APP_NAME="Sílabos UEB"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://tu-dominio
APP_TIMEZONE=UTC
DISPLAY_TIMEZONE=America/Guayaquil
APP_LOCALE=es

DB_CONNECTION=pgsql
DB_HOST=...        # host del PostgreSQL del servidor
DB_PORT=5432
DB_DATABASE=silabos_ueb
DB_USERNAME=...
DB_PASSWORD=...
DB_TIMEZONE=UTC

REDIS_CLIENT=predis
REDIS_HOST=...
REDIS_PORT=6379
REDIS_PASSWORD=...

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true

FILESYSTEM_DISK=private
LOG_CHANNEL=stderr
LOG_LEVEL=warning

AI_DRIVER=disabled
AI_BASE_URL=
AI_MODEL=
AI_API_KEY=
```

### Proveedor de IA

La asistencia parte desactivada. Para habilitarla se conservan cuatro variables: el
driver y la URL base, modelo y clave elegidos. No se configuran niveles de razonamiento.
La URL debe usar HTTPS y puede ser la base o el endpoint completo; Laravel añade la ruta
propia del protocolo cuando haga falta.

OpenAI:

```text
AI_DRIVER=openai
AI_BASE_URL=https://api.openai.com/v1
AI_MODEL=<modelo elegido>
AI_API_KEY=<secreto de OpenAI>
```

Claude:

```text
AI_DRIVER=claude
AI_BASE_URL=https://api.anthropic.com/v1
AI_MODEL=<modelo elegido>
AI_API_KEY=<secreto de Anthropic>
```

DeepSeek:

```text
AI_DRIVER=deepseek
AI_BASE_URL=https://api.deepseek.com
AI_MODEL=deepseek-flash
AI_API_KEY=<secreto de DeepSeek>
```

Con `AI_SOURCE_NORMALIZATION_ENABLED=true`, la ingesta PDF usa DeepSeek de forma
independiente del proveedor configurado en `AI_DRIVER` para normalizar por lotes el
resultado de OpenParser. Puede ajustar sus límites sin cambiar el contrato:

```text
AI_SOURCE_NORMALIZATION_ENABLED=true
AI_SOURCE_NORMALIZATION_DRIVER=deepseek
AI_SOURCE_NORMALIZATION_BASE_URL=https://api.deepseek.com
AI_SOURCE_NORMALIZATION_MODEL=deepseek-flash
AI_SOURCE_NORMALIZATION_API_KEY=<secreto de DeepSeek>
AI_SOURCE_NORMALIZATION_BATCH_INPUT_CHARACTERS=100000
AI_SOURCE_NORMALIZATION_MAX_OUTPUT_TOKENS=20000
AI_SOURCE_NORMALIZATION_TIMEOUT=120
```

`AI_API_KEY` y `AI_SOURCE_NORMALIZATION_API_KEY` se configuran como secretos en Dokploy:
no se escriben en el repositorio, la base, logs ni comandos de diagnóstico. Tras cambiar
estas variables hay que redesplegar o reiniciar el contenedor completo para reconstruir
la caché de configuración y para que los workers usen el proveedor nuevo. Cada URL y
modelo debe corresponder al proveedor de su bloque de configuración.

Activar un proveedor alojado envía el campo del sílabo y los extractos de las fuentes
seleccionadas a ese tercero. Antes de hacerlo con información institucional deben estar
acordados el proveedor, región, retención y tratamiento de datos. Un error del proveedor
solo deshabilita temporalmente las recomendaciones; no bloquea el flujo del sílabo.

### Embeddings de fuentes académicas

Los embeddings se guardan en la columna vectorial de
`fragmentos_fuente_academica`; no se crea una tabla adicional. El valor seguro por
defecto mantiene este proceso desactivado:

```text
EMBEDDINGS_DRIVER=disabled
EMBEDDINGS_BASE_URL=https://api.openai.com/v1
EMBEDDINGS_MODEL=text-embedding-3-small
EMBEDDINGS_API_KEY=<secreto de embeddings>
EMBEDDINGS_CONNECT_TIMEOUT=5
EMBEDDINGS_TIMEOUT=60
```

Para activarlo con OpenAI, cambia `EMBEDDINGS_DRIVER=openai` y configura la clave como
secreto. La migración `000087` requiere la extensión `vector` de pgvector; la imagen
local del repositorio ya usa `docker.io/pgvector/pgvector:0.8.6-pg18-trixie`. En PostgreSQL
administrado hay que habilitar pgvector antes de migrar. Tras activar el driver,
redespliega para que la caché de configuración y el worker de la cola `ia` reciban el
cambio.

OpenParser es el paso anterior para convertir cada PDF en un documento JSON estructurado;
se usa `paddleocr-vl-1.6` y la salida `openparser@1`. El documento ordenado permanece en
`fuentes_academicas`; los fragmentos derivados y sus vectores se reconstruyen en
`fragmentos_fuente_academica`. Configura `OPENPARSER_ENABLED=true` y
`OPENPARSER_API_KEY` solo después de resolver PV-12/PV-14. La imagen necesita
`poppler-utils` para recortar del PDF privado las figuras que solo traigan coordenadas.

### Correo

El envío de invitaciones de activación usa el sistema de correo de Laravel, así que el
proveedor se cambia con variables y no tocando código. El enlace usa `FRONTEND_URL` y
caduca en 24 horas.

Mientras `PV-15` —correo institucional y contenido de avisos— siga abierta, se deja en
`log`: los mensajes se escriben en los registros de Dokploy tal como se enviarían, sin
salir del sistema.

```
MAIL_MAILER=log
```

Para enviar de verdad con Resend, cuyo transporte ya está instalado:

```
MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS="no-responder@devs-ueb.tech"
MAIL_FROM_NAME="${APP_NAME}"
```

Antes hay que verificar el dominio en el panel de Resend añadiendo los registros DNS que
indique. Sin eso los mensajes se rechazan o acaban en spam, y el fallo no se ve desde la
aplicación: hay que mirarlo en el panel del proveedor.

Enviar desde un dominio propio hacia buzones `@ueb.edu.ec` puede resultar sospechoso para
el servidor institucional. El destino a largo plazo es su propio SMTP, que es lo que
`PV-15` debe resolver; para eso basta con cambiar `MAIL_MAILER=smtp` y sus credenciales.

`SESSION_SECURE_COOKIE=true` requiere HTTPS. Actívalo solo cuando el dominio tenga
certificado, o nadie podrá iniciar sesión.

La base de datos debe existir antes del primer despliegue; el contenedor migra, pero no
crea la base.

## 3. Qué hace el arranque

`docker/app/entrypoint.sh`, en cada arranque y de forma idempotente:

1. verifica `APP_KEY` y aborta con un mensaje claro si falta;
2. borra `public/hot` por si el marcador del servidor de desarrollo llegara por otra vía;
3. reconstruye las cachés de configuración, rutas, vistas y eventos —se rehacen siempre,
   porque congelan las variables de entorno y un cambio en Dokploy no tendría efecto de
   otro modo—;
4. espera hasta 60 segundos a que PostgreSQL acepte conexiones;
5. ejecuta `db:rename-migrations-table --force` (idempotente: renombra la tabla de
   control `migrations` → `migraciones` de I-28 solo si aún conserva el nombre viejo) y
   después `migrate --force --isolated`, con bloqueo para que dos réplicas no migren a
   la vez. Ambos pasos se desactivan con `RUN_MIGRATIONS=false`; en ese caso las
   migraciones se aplican a mano con el procedimiento de `docs/security/hardening.md`;
6. crea el enlace de almacenamiento público.

En una instalación nueva, instala además la única plantilla institucional una sola vez
con `php artisan db:seed --class=SyllabusTemplateSeeder --force`. El seed es idempotente
y no sobrescribe una plantilla ya editada; no se ejecuta automáticamente en cada release.

Desde I-28 los nombres de cola son `critica`, `notificaciones`, `documentos`, `ia`,
`integraciones` y `general`; el worker de `supervisord` ya los escucha y tras aplicar
las migraciones en remoto hay que redesplegar (o reiniciar el contenedor) para que el
worker viejo no quede escuchando las colas anteriores.

## 4. Comprobación

`GET /health/live` responde sin tocar la base: sirve para el chequeo de vida del
orquestador. `GET /health/ready` verifica base y Redis, y responde `{"status":"ready"}`.

## 5. Primer administrador

El seeder de demostración **no** se ejecuta en producción. Crea la primera cuenta desde la
consola del contenedor con `php artisan tinker`, o ejecuta el seeder solo si el entorno es
de prueba. Las cuentas creadas desde el panel nacen pendientes de activación y reciben un
enlace de un solo uso para definir su contraseña
y su titular debe cambiarla antes de operar.

## 6. Almacenamiento persistente

`storage/app` guarda los documentos exportados. Sin un volumen montado ahí, cada
redespliegue los pierde. En Dokploy, añade un **volume mount** hacia
`/var/www/html/storage/app`.

## Fuera de alcance

El despliegue no elige automáticamente un modelo ni valida su calidad académica. `PV-13`,
`PV-14` y `PV-18` siguen abiertas; hasta que se resuelvan, `AI_DRIVER=disabled` continúa
siendo el valor seguro por defecto.
