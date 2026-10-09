# I-209 — Configurar paralelos en la tabla de materias

## Pedido del responsable, 2026-10-09

Los coordinadores encontraron complicado el modal «Asignar materias». Debe desaparecer: la
tabla de materias sigue la malla y ahí mismo se configuran paralelos, jornada y docente.

## Decisiones

- Guardado automático por cambio (confirmado por el responsable), con aviso breve.
- Se quita el modal `PeriodPreparationSheet`, `ParallelCreationSheet` y `periodPreparation`;
  I-208 (ciclos y acciones masivas del modal) queda sin efecto.
- `ScheduledSubjectsTab` lista todas las materias activas de la malla en el período (con o
  sin paralelos) y usa `SubjectParallelsEditor`: código (al salir del campo o Enter),
  jornada y docente (al elegir), **+** con tooltip y papelera. Usa los endpoints existentes
  de paralelos y asignaciones docentes; respeta `editable` y `deletable` y el bloqueo de
  la carrera o el período finalizado.
- La pestaña **Docentes** no cambia.
- Ya no se propone copiar paralelos y docentes del período anterior al abrir el modal.
  Queda como mejora posible (acción «Copiar del período anterior»), sin decidir.
- El endpoint `POST /coordination/academic/prepare-period` queda sin uso en la SPA; su retiro
  en backend queda pendiente de decisión.

## Verificación

Frontend: typecheck, ESLint y Vitest en verde; comprobado en Chromium con la API simulada
(crear, cambiar jornada, asignar docente, renombrar y quitar paralelo).
