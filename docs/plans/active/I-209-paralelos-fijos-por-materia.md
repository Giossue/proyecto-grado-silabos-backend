# I-209 — Paralelos fijos por materia, sin período

## Pedido del responsable, 2026-10-09

Materias, paralelos y docentes no dependen de un período académico. El modal «Asignar
materias» sobra; la tabla de materias sigue la malla y permite acciones masivas.

## Decisiones

- `paralelos.periodo_academico_id` desaparece (migración `000218`). Código único por materia sin
  distinguir mayúsculas. El período solo vive en la convocatoria y, por ella, en el sílabo.
- La migración fusiona los paralelos repetidos entre períodos: conserva el del período más
  reciente, reapunta sus sílabos y asignaciones docentes y descarta las asignaciones del
  duplicado. No se pierden sílabos; un paralelo con historia se conserva.
- Se retiran `PreparePeriod`, `CarryOverPeriodSetup` (I-199) y la ruta `prepare-period`. Abrir
  la convocatoria ya no copia nada; `GenerateConvocationSyllabi` usa todos los paralelos de la
  carrera. `AcademicContextSnapshot` recibe el período de la convocatoria.
- Editar jornada o docente de un paralelo ya no queda bloqueado por períodos finalizados ni por
  tener sílabos antiguos; el bloqueo de la carrera mientras hay convocatoria activa se mantiene.
  Eliminar un paralelo sigue exigiendo que no tenga sílabo ni docente. Ya no se exige conservar
  al menos un paralelo por materia.
- `scheduledSubjects` deja de depender del período: una fila por materia activa (o con paralelos).
- Interfaz COR-14: se restaura la tabla anterior con casillas y barra de **Acciones masivas**
  (agregar paralelo con jornada, asignar jornada, asignar docente). El modal por materia
  «Paralelos y docentes» se conserva. La preparación por ciclos (I-208) y la edición en la tabla
  quedan reemplazadas.

- `contexto_academico` (versión 6) guarda `parallel.{id,code,shift}`; el sílabo lee de ahí
  (`academicParallelCode/Shift`) y cae al paralelo vivo si es un sílabo anterior. Cambiar el
  código o la jornada de un paralelo no altera sílabos ya creados.

## Verificación

Backend: suite de pruebas sin regresiones (la prueba del tablero del docente ya fallaba antes del
cambio), prueba de migración con duplicados, contrato OpenAPI regenerado. Frontend: typecheck,
ESLint y Vitest; acciones masivas comprobadas en Chromium con la API simulada.
