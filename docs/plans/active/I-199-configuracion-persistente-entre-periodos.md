# I-199 — Paralelos y docentes persistentes entre períodos

## Pedido del responsable, 2026-10-07

Materias y asignaciones casi no cambia entre ciclos: debe mantenerse y no reiniciarse, para
que los cambios sean rápidos.

## Decisión

- Copia automática (sin cambio de esquema) al **abrir la convocatoria institucional**:
  `CarryOverPeriodSetup`, llamado desde `CreateSyllabusProcess` en la misma transacción.
- Por carrera activa se toma el último período anterior con paralelos; se copian código y
  jornada de las materias activas que aún no tienen paralelos en el período.
- El docente se mantiene solo si sigue activo, verificado y con rol docente vigente en la
  carrera; si no, el paralelo queda sin docente.
- «Preparar período» sigue para las materias nuevas de la malla. Se audita
  `academico.periodo_arrastrado`.

## Verificación

`SyllabusProcessTest::test_opening_the_institutional_process_carries_parallels_and_teachers_from_the_previous_period`.

> Reemplazado por I-209: los paralelos y docentes son fijos por materia y ya no se copian entre períodos.
