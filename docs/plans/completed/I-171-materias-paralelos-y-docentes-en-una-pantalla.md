# I-171 — Materias, paralelos y docentes en una pantalla

## Decisión

COR-15 (Asignación docente) se integra en COR-14 (Materias y paralelos) como pestaña
**Docentes**. Solo se muestra el período en curso.

## Alcance (solo frontend)

- `ScheduledSubjectsTab`: columna «Paralelos y docentes» con jornada y docente por paralelo.
- `ParallelCreationSheet`: selector de docente por paralelo (asignar o retirar con las
  operaciones existentes `asignacion_docente`).
- `TeacherAssignmentsPanel`: una fila por docente con sus paralelos del período, estado,
  Editar docente y Reenviar invitación.
- Página: pestañas Materias y Docentes; botón principal **Preparar período** mientras
  haya materias pendientes, luego **Gestionar docente**.
- Sidebar sin «Asignación docente»; `/coordinacion/asignaciones-docentes` redirige.

## Verificación

- Lint, typecheck y suite frontend completa (247 pruebas) en verde.
- Documentación de pantallas, decisión y trazabilidad actualizadas.
