# I-207 — Endurecimiento tras la auditoría de seguridad (2026-10-10)

Auditoría de lectura de código (autorización, sesión, archivos e inyección, frontend) y `composer audit` /
`npm audit`. No hubo hallazgos críticos; la autorización por carrera y rol activo está bien aplicada.

## Hecho

- Rutas por defecto de Fortify desactivadas (`Fortify::ignoreRoutes()`); solo existe `/api/v1/authentication/*`.
- Restablecer, activar o cambiar la contraseña cierra las demás sesiones y rota el token de «recordarme»
  (`RevokeUserSessions`); desactivar una cuenta también rota ese token. Cambiar el rol activo regenera la sesión.
- Activar o desactivar 2FA exige la contraseña actual (la SPA la pide en un diálogo). El desafío 2FA caduca a los
  10 min y limita por cuenta (5 intentos por 5 min), sin depender de la IP.
- Login: se verifica siempre un hash (tiempo uniforme) y se limita por cuenta (20 por hora) además de por correo+IP.
- Verificación de correo: límite de intentos y rechazo de cuentas inactivas.
- Configuración: `TRUSTED_PROXIES` por entorno, cookie de sesión segura por defecto en producción, Sanctum sin
  `localhost` en producción, CORS con cabeceras explícitas.
- Autorización: el campo de un sílabo debe pertenecer a la plantilla vigente; un coordinador no cambia datos de un
  docente verificado que tiene roles fuera de su carrera; retirar un docente solo cierra sesiones si no le quedan roles.
- Abuso: límites de intentos en importaciones, logo, exportaciones y firma.
- Archivos e IA: ILIKE con comodines escapados, extensión real del archivo, lector Excel acotado (solo Xlsx/Xls, sin
  celdas de datos, máx. de hojas, filas y tamaño descomprimido), evidencia de fichas delimitada como no confiable,
  valores acotados, URL del proveedor validada (HTTPS, sin redirecciones), logos con `nosniff`.
- Frontend: CSP con el origen exacto de la API, HSTS y `server_tokens off`, QR de 2FA como imagen (sin `v-html`),
  componentes de Markdown y fuentes sin uso retirados, sin mapas de código ni `console` en producción.
- Dependencias: `laravel/framework`, `league/commonmark` y `league/flysystem` actualizados; `source-map-js` corregido.

## Pendiente

- `node-forge` (vía `@signpdf/signer-p12`): aviso de verificación RSA sin parche; la SPA solo firma localmente.
- Producción: definir `TRUSTED_PROXIES` con el proxy real, `SESSION_SECURE_COOKIE=true` y `APP_DEBUG=false`.
- Revisar que el verificador de firma de PDF ancle el certificado a una autoridad de confianza.
- Rotar la clave de la cuenta de demo si existe en algún entorno real (estaba en un script ya retirado).
