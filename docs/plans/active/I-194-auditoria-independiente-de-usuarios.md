# I-194 Auditoría independiente de las cuentas

Pedido del responsable, 2026-10-06: no poder borrar un usuario por tener auditoría es absurdo;
la auditoría es un registro y no debe depender de que la cuenta siga existiendo.

Revisión de la base remota (solo lectura): las llaves hacia `usuarios` en aprobaciones,
firmas, revisiones, archivos y exportaciones son evidencia del acto de una persona y se
mantienen `RESTRICT` (esas cuentas se desactivan). La auditoría era la única que ataba un
registro de solo lectura a la vida de la cuenta.

- Migración `000201`: quita las llaves de `eventos_auditoria` hacia `usuarios` y
  `asignaciones_rol`; agrega `actor_nombre`, `actor_rol` y `actor_carrera`.
- `RecordAuditEvent` guarda esa copia al registrar. Los eventos anteriores no se modifican
  (trigger append-only) y se resuelven por id; si la cuenta ya no existe se muestra
  "Cuenta eliminada".
- `DeleteManagedUser` ya no se bloquea por eventos de auditoría.
- Corrige `docs/architecture/database.md` (antes: cuentas con historia se desactivan "porque
  su identidad participa en auditoría").
- Pendiente: aplicar en la remota con el deploy; después borrar `admin@mail.com` y sus 40
  eventos (no será necesario borrar eventos: el usuario podrá borrarse sin tocarlos, tras
  quitar su asignación de rol).
