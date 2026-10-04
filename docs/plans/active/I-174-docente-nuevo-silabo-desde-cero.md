# I-174 — Un docente nuevo empieza el sílabo desde cero

## Pedido del responsable, 2026-10-04

Al asignar un docente a un paralelo, el docente nuevo no hereda nada del anterior: el
sílabo del paralelo se borra por completo (borrador, revisiones, observaciones,
aprobaciones, documentos, análisis de IA) y se genera otro en blanco. Sin archivo ni
copia para auditoría. Aplica en cualquier estado, incluso aprobado o firmado, y también
cuando se vuelve a asignar al mismo docente.

Ajusta la regla de I-130: el sílabo sigue perteneciendo al paralelo y su docente sigue
siendo quien tiene la asignación vigente, pero cambiar de docente reinicia el sílabo.

## Reglas

- Un paralelo tiene a lo sumo un docente activo (índice único parcial
  `asignaciones_paralelo_paralelo_activo_unico`, migración 000091). Jornadas distintas
  son paralelos distintos.
- Quitar al docente no toca el sílabo; mientras no haya docente nadie lo edita.
- Asignar un docente (`POST coordination/academic/asignacion_docente`) reinicia el
  sílabo del paralelo en cada convocatoria donde exista.

## Cambios

- `InProgressWork::restartForParallel()` borra con `deleteAggregates` y regenera con
  `GenerateConvocationSyllabi::generateForParallel()`.
- `CreateAcademicRecord::createTeacherAssignment()` lo invoca dentro de la transacción.
- Sin migración.

## Verificación

- `ParallelTeacherChangeTest`: borrador y sílabo enviado se descartan; reasignar al mismo
  docente también reinicia; sigue un solo docente activo por paralelo.
