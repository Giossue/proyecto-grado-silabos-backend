# Hardening por frontera

## Identidad y sesión

- Fortify maneja contraseñas, recuperación y verificación en dos pasos; no hay hash, reset
  ni 2FA hechos a mano.
- No hay registro público: las cuentas las crea el Administrador o la Coordinación y la
  persona las activa desde su correo.
- Cookies `HttpOnly`, `SameSite=lax` y `Secure` en producción; CSRF y rotación de sesión
  al autenticar, al pasar los dos pasos y al cambiar de rol.
- Límites por minuto: inicio de sesión (5), dos pasos (10), recuperación (3), importaciones
  de Excel (10), importación de fichas (20), firmas (20) y chat de IA (6 por persona y
  sílabo).
- Una cuenta desactivada pierde la sesión en su siguiente petición (`EnsureActiveUser`).
  Cada persona puede cerrar sus otras sesiones.
- En ambientes expuestos, `SESSION_ENCRYPT=true`. Los hosts aceptados son el de `APP_URL`
  y sus subdominios, más `127.0.0.1` y `localhost` para el health check interno de
  Dokploy; así los enlaces de recuperación no aceptan un `Host` manipulado.
- El scheduler ejecuta `auth:clear-resets` cada quince minutos; debe estar activo en el
  despliegue (el contenedor lo levanta con `supervisord`).
- Nadie elimina su propia cuenta. El Administrador solo elimina cuentas sin activar y sin
  actividad; las demás se desactivan y conservan su historial.
- Los mensajes de recuperación no confirman si la cuenta existe.

## Autorización

- Policies, Gates y filtros de consulta por rol activo y carrera.
- El rol que envía el cliente no se cree: el servidor resuelve el rol activo y su alcance.
- Los identificadores son UUID, pero cada lectura sigue pasando por permiso.
- Descargas, reportes, conteos y auditoría aplican el mismo alcance.

## Entrada, salida y formularios

- Form Requests con límites de tamaño y listas permitidas.
- El texto enriquecido del sílabo se guarda como documento estructurado, no como HTML
  libre. La SPA no inserta HTML crudo y pinta las respuestas de la IA como nodos de texto.
- Orden y filtros solo sobre columnas declaradas.
- Sin overposting: los estados cambian solo por acciones específicas.
- Los errores públicos no muestran SQL, rutas, trazas ni secretos.

## Base de datos

- Consultas parametrizadas y restricciones en la base para los invariantes.
- La auditoría y las aprobaciones son de solo inserción (triggers en PostgreSQL).
- Red restringida y TLS según la infraestructura.
- El contenedor migra al arrancar con la misma cuenta de la aplicación. Separar un usuario
  sin permisos DDL exige poner `RUN_MIGRATIONS=false` y migrar con otra cuenta.
- Respaldos cifrados, acceso auditado y restauración probada: pendiente de PV-11
  (`composer verify:restore` prueba la restauración en local).

### Migrar a mano una base remota

El despliegue normal no lo necesita: `docker/app/entrypoint.sh` ejecuta
`db:rename-migrations-table` y `migrate --force --isolated` en cada arranque. Si hay que
hacerlo desde una estación autorizada:

1. Guarda la credencial en `~/.pgpass` (dueño el usuario, permisos `0600`), con una línea
   `<host>:<puerto>:<base>:<usuario>:<contraseña>`. No la copies al repositorio ni al
   historial del shell.
2. Pasa `DB_PASSWORD='(null)'` para que libpq tome la contraseña de `.pgpass` y no la del
   `.env` local.
3. Ejecuta, en este orden:

```bash
export DB_CONNECTION=pgsql DB_HOST=<host> DB_PORT=<puerto> \
  DB_DATABASE=<base> DB_USERNAME=<usuario> DB_PASSWORD='(null)'

php artisan migrate:status
php artisan db:rename-migrations-table --force
php artisan migrate --force --isolated
php artisan migrate:status
```

`db:rename-migrations-table` va antes del migrador porque la tabla de control se llama
`migraciones`: si no existiera, Laravel la crearía vacía y daría por pendiente todo el
historial. Toma un respaldo (`pg_dump`) antes de migrar una base con datos y reinicia el
worker de colas después.

## Volumen de almacenamiento en el contenedor

`storage/app` se monta como volumen en Dokploy para conservar logos, documentos y firmas
entre despliegues. El volumen llega con el dueño del servidor, así que el `entrypoint` crea
`private/` y `public/` y los entrega a `www-data` en cada arranque; sin eso PHP-FPM no
puede escribir. Tras cambiar un volumen en Dokploy hay que redesplegar.

Los cuerpos de petición grandes pasan por `/var/lib/nginx/tmp`. El `Dockerfile` entrega
`/var/lib/nginx` a `www-data`, porque los workers de nginx corren con ese usuario; sin eso
nginx responde 500 a cualquier subida antes de llegar a PHP. Prueba rápida tras desplegar:
un `POST` a la API con un cuerpo de 16 KB no debe devolver 500.

## Redis y trabajos

- Redis sin exposición pública; autenticación o red privada.
- Los trabajos llevan solo identificadores, nunca secretos ni documentos completos.
- Cada trabajo tiene timeout, intentos e idempotencia (`RunsOnce`, `ShouldBeUnique`).
- Colas separadas: `critica`, `documentos` e `ia`.

## Archivos

- Disco privado, nombres generados, tipo real, tamaño máximo y huella SHA-256.
- Tipos aceptados: Excel para importaciones, PDF o Excel para fichas (hasta 6 de 20 MB),
  PNG para logos y PDF para firmas.
- Las descargas de sílabos pasan por la API autenticada: cada una se autoriza y se
  audita. No hay enlaces públicos; solo los logos institucionales se sirven sin sesión.
- Los documentos subidos para la ficha se borran al aplicar o descartar la importación.
- La plantilla es solo datos (JSON); no se ejecuta como código.
- La conversión a PDF corre en un servicio Gotenberg aparte.

## Firma electrónica

- El archivo `.p12` y su clave se usan solo en el navegador; nunca llegan al servidor.
- El servidor acepta el PDF firmado solo si parte exacto del PDF vigente, agrega una sola
  firma que cubre todo el documento, la firma CMS es válida y el certificado está vigente.
- El titular del certificado debe coincidir con la cuenta que firma
  (`SIGNATURE_REQUIRE_HOLDER_MATCH`).
- Con `SIGNATURE_TRUSTED_CA_BUNDLE` cargado, la cadena debe llegar a una entidad
  certificadora de la lista. Sin ese archivo no se verifica la cadena (DT-16).
- Ver `docs/runbooks/firma-electronica-entidades.md`.

## IA

- Desactivada por defecto (`AI_DRIVER=disabled`). El proveedor alojado se elige con
  `AI_DRIVER` (`openai`, `claude` o `deepseek`; la lectura de fichas solo funciona con
  `openai` o `deepseek`) y la clave vive en un secreto del despliegue.
- La URL del proveedor debe ser HTTPS, sin credenciales embebidas y sin redirecciones.
- Los documentos y el contenido del sílabo se envían como datos delimitados; el modelo no
  tiene herramientas ni acciones.
- La salida se valida: el chat solo cita evidencias autorizadas y la ficha propuesta la
  aplica una persona.
- Límites de tokens, tamaño, tiempo, frecuencia y mensajes por sílabo (`config/ai.php`).
- OpenParser (OCR de PDF de fichas) está desactivado por defecto y solo usa HTTPS.

## Cabeceras y transporte

- HTTPS y redirección segura en ambientes expuestos.
- `AddSecurityHeaders` aplica `nosniff`, `X-Frame-Options: DENY`, CSP con
  `frame-ancestors`, `base-uri`, `form-action` y `object-src`, política de referencia,
  permisos y `Cross-Origin-Resource-Policy`. HSTS se emite solo bajo HTTPS en producción.
- CORS limitado a `FRONTEND_URL`; Sanctum solo trata como stateful los dominios de
  `SANCTUM_STATEFUL_DOMAINS`.
- `/health/ready` no inicia sesión ni emite cookies.

## Verificación de release

- auditoría de dependencias (`composer audit` y `npm audit` en el frontend);
- secretos y configuración;
- pruebas de autorización y abuso;
- subidas y descargas;
- política de logs;
- respaldo y restauración;
- revisión de migraciones e infraestructura.

`composer verify` ejecuta `composer security:scan` antes de las pruebas. El scanner busca
claves privadas, patrones de credenciales, dumps de base y un `.env` versionado; muestra
solo los archivos afectados y excluye dependencias y artefactos generados. No hay CI que
lo ejecute: se corre en local. No sustituye el secret scanning del proveedor, un escaneo
dinámico ni la revisión humana.
