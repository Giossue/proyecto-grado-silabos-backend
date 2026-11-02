# Sílabos UEB — API

Sistema web para elaborar, revisar, aprobar y firmar los sílabos de la Universidad
Estatal de Bolívar. Admite varias facultades y carreras.

- **Docente:** llena el sílabo de cada paralelo, con ayuda opcional de IA, y lo envía.
- **Coordinación:** prepara la malla, los paralelos, los docentes y el proyecto de carrera de cada
  asignatura; abre la convocatoria de su carrera y revisa los sílabos.
- **Biblioteca:** revisa la bibliografía.
- **Administración:** gestiona cuentas, facultades, carreras, períodos, convocatorias y
  la plantilla del sílabo.

Docente, coordinación y biblioteca firman con su certificado `.p12` al decidir (enviar o
aprobar). Al aprobarse, el sistema genera un solo PDF con los sellos de las tres firmas.

Este repositorio es la API Laravel 13 (JSON bajo `/api/v1`). La interfaz es una SPA Vue
3 en `proyecto-grado-silabos-frontend`. PostgreSQL guarda todos los datos y atiende
las colas y la caché.

## Requisitos

- PHP 8.4 o superior, Composer 2 y las extensiones usuales de Laravel con `pdo_pgsql`.
- Docker Compose (o compatible) para PostgreSQL.

## Puesta en marcha

```bash
docker compose up -d
composer setup
php artisan serve
```

`composer setup` instala dependencias, copia `.env.example` a `.env`, genera la clave y
crea la base con datos de prueba (`migrate:fresh --seed`).

En otra terminal, el worker de colas:

```bash
php artisan queue:work --queue=critica,documentos,ia,general --tries=3
```

- `critica`: verificación de plataforma.
- `documentos`: PDF del sílabo.
- `ia`: chat del docente. La carga de proyectos de carrera usa Excel estructurado sin cola.
- `general`: correos de activación de cuenta.

Para el día a día, `scripts/dev.sh` levanta todo con un solo comando: base de datos,
Gotenberg (`127.0.0.1:3010`), migraciones, API (`127.0.0.1:8000`, con subidas de hasta 32 MB),
worker y frontend (`http://127.0.0.1:4173`). Ctrl+C lo apaga todo. Usa Podman si está instalado
y, si no, Docker.

Compose publica PostgreSQL en `127.0.0.1:55432`. También
crea la base `silabos_ueb_test` para las pruebas. Las credenciales de `.env.example` son
solo para desarrollo.

## Variables de entorno

Las más importantes (ver `.env.example`):

| Variable | Uso |
|---|---|
| `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` | Dirección de la SPA (por defecto `localhost:5173`) |
| `DB_*` | Conexión a PostgreSQL |
| `QUEUE_CONNECTION`, `CACHE_STORE`, `QUEUE_RETRY_AFTER` | Cola y caché en PostgreSQL (`database`); `QUEUE_RETRY_AFTER` en segundos (960 por defecto) |
| `DISPLAY_TIMEZONE` | Zona horaria de la institución (por defecto `America/Guayaquil`); fija la fecha de hoy que usa el sistema |
| `AI_DRIVER`, `AI_BASE_URL`, `AI_MODEL`, `AI_API_KEY` | Proveedor de IA: `disabled` (por defecto), `openai`, `claude`, `deepseek` o `baseline` (pruebas) |
| `GOTENBERG_URL`, `GOTENBERG_TIMEOUT` | Conversión de Word a PDF; sin ella el PDF sale como texto simple |
| `SIGNATURE_TRUSTED_CA_BUNDLE`, `SIGNATURE_REQUIRE_HOLDER_MATCH` | Validación de la firma electrónica |
| `MAIL_MAILER`, `RESEND_API_KEY` | Correo (por defecto `log`) |

`GOTENBERG_URL` no está en `.env.example`; agréguela si tiene Gotenberg
(`docs/architecture/deployment.md`).

## Cuentas de prueba

El seeder crea datos sintéticos de la carrera `SOFTWARE`:

| Rol | Correo | Contraseña |
|---|---|---|
| Administrador | `admin@silabos.test` | `Demo-2026!` |
| Coordinador | `coordinador@silabos.test` | `Demo-2026!` |
| Docente | `docente@silabos.test` | `Demo-2026!` |
| Bibliotecario | `bibliotecario@silabos.test` | `Demo-2026!` |

No hay registro público; las cuentas se crean desde la aplicación.

## Verificación

Con PostgreSQL activo:

```bash
composer verify
```

Revisa secretos, Pint, PHPStan nivel 7 y las pruebas Pest. La verificación de Vue y
TypeScript se hace en el repositorio frontend.

Salud del servicio: `/health/live` y `/health/ready`. Prueba rápida de la cola:

```bash
php artisan platform:smoke-job
php artisan queue:work --queue=critica --stop-when-empty
```

Otros comandos de release (`composer audit`, `composer verify:restore`,
`composer benchmark:readiness`) se explican en `docs/runbooks/release-verification.md`.

## Despliegue

Un push a `main` despliega en Dokploy y ejecuta las migraciones. Ver
`docs/runbooks/deploy-dokploy.md`.

## Documentación

- Índice: [docs/README.md](docs/README.md)
- Mapa técnico: [ARCHITECTURE.md](ARCHITECTURE.md)
- Decisiones vigentes: [DECISIONS_STATUS.md](DECISIONS_STATUS.md)
- Pendientes: [docs/plans/pendientes.md](docs/plans/pendientes.md)
- Guía para agentes: [AGENTS.md](AGENTS.md)
