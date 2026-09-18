# I-88: Activación de cuentas mediante invitación

## Estado

Implementado y verificado; la puerta completa queda condicionada al servicio Redis
local, que rechazó la conexión durante `RedisConnectionTest`.

## Trazabilidad

RF-003..007; RN-001..004; CU-02, CU-03; ADM-02, COR-15; RNF-001..010 y
RNF-018..023; CP-F identidad/alcance/atomicidad y CP-N seguridad/UX; PV-12/PV-15.

## Resultado demostrable

Una cuenta nueva recibe un enlace de activación de un solo uso y con caducidad de 24
horas. La persona verifica su correo y define su contraseña desde ese enlace. Ningún
administrador, coordinador ni correo recibe una contraseña temporal. Administración puede
reenviar invitaciones de cualquier cuenta pendiente; Coordinación solo puede hacerlo para
docentes pendientes de su carrera activa.

## Decisiones y supuestos

- El token se genera automáticamente, se envía una sola vez en claro y solo se conserva
  su hash SHA-256 en PostgreSQL.
- Cada reenvío invalida invitaciones anteriores; activar la cuenta invalida las demás.
- La recuperación de contraseña de cuentas activas permanece separada.
- Las cuentas heredadas con contraseña temporal pendiente se vuelven no verificadas para
  que completen el nuevo flujo; las cuentas ya verificadas se conservan.

## Cambios

- Dominio: `ActivationInvitation`, emisión, activación y autorización por carrera.
- Backend: endpoints públicos de activación y autenticados de reenvío; correo en cola;
  eliminación de contraseña temporal y middleware de cambio obligatorio.
- Datos: `invitaciones_activacion`, eliminación de `usuarios.debe_cambiar_contrasena`.
- Frontend: formulario de activación, alta sin contraseña, reenvío para Administración y
  Coordinación.
- Seguridad/auditoría: expiración, uso único, rate limit, auditoría sin token ni secreto.

## Pruebas

- Alta administrativa y delegada crea invitación, no acepta contraseña del cliente y no
  envía credenciales.
- Activación válida establece contraseña y verifica correo; token usado o vencido falla.
- Reenvío invalida el token anterior y respeta el alcance de Coordinación.
- Cambiar correo de una cuenta pendiente emite invitación al correo nuevo.
- Recuperación normal de contraseña y sesiones existentes siguen funcionando.

## Pasos

- [x] Migración y modelo de invitaciones.
- [x] Flujo backend de emisión, activación, reenvío y autorización.
- [x] Flujo SPA y acciones visibles por rol.
- [x] Pruebas focalizadas y sincronización del contrato/documentación.
- [x] Ejecutar la puerta completa: 266 pruebas backend pasan; la única excepción es
      `RedisConnectionTest` por Redis no disponible en `127.0.0.1:56379`. Pint,
      PHPStan, ESLint, `vue-tsc`, Vitest (79 pruebas), build y contrato OpenAPI pasan.

## Riesgos y reversión

La migración conserva usuarios y roles; solo elimina la bandera técnica obsoleta y crea
una tabla con borrado en cascada para invitaciones. El `down` restaura la columna antigua,
pero no reconstruye contraseñas temporales. Antes de desplegar en una base compartida se
debe respaldar y ejecutar la secuencia de migración documentada en `hardening.md`.
