# I-175 — Quitar un docente de la carrera

## Pedido del responsable, 2026-10-04

Coordinación puede quitar a un docente de su carrera desde COR-15 (Docentes). Es un
retiro de la carrera, no de la cuenta: la cuenta y sus otros roles siguen iguales.

## Reglas

- `DELETE /api/v1/coordination/academic/teachers/{user}` (política `updateCareerTeacher`:
  solo docentes vigentes de la carrera activa).
- Cierra el rol docente de esa carrera (`asignaciones_rol.activo = false`) y borra sus
  asignaciones de paralelo vigentes, con el mismo límite que quitar docente de un
  paralelo: un período finalizado lo impide.
- Los sílabos de esos paralelos se conservan sin docente; al asignar otro docente
  aplican las reglas de I-174 (empieza desde cero).
- Volver a crear/incorporar el mismo correo reactiva la fila cerrada (antes se
  rechazaba pidiendo revisión de Administración).
- Auditoría: `usuario.docente_retirado` con carrera y número de paralelos liberados.

## Verificación

- `CareerTeacherTest`: retiro con paralelo liberado y lista sin el docente; segundo
  retiro 403; docente de otra carrera 403; reincorporación reactiva la fila.
- Frontend `TeacherAssignmentsPanel.test.ts`: acción **Quitar de la carrera** y
  confirmación.
