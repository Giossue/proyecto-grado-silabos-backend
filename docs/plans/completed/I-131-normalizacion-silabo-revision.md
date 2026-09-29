# I-131 — Normalización de sílabo y registros de revisión

## Estado

Implementado el 24 de septiembre de 2026. Sin cambio de comportamiento observable ni de
contratos HTTP.

## Objetivo

Retirar columnas y tablas derivables alrededor de `silabos` para que cada hecho viva en
un solo lugar, manteniendo las garantías de integridad en PostgreSQL.

## Alcance y trazabilidad

- RF-003..016, RN-001..008, CU-02, CU-03, DOC-01; no cambia permisos, estados ni
  respuestas de la API.
- Complementa I-130 (el sílabo sigue al paralelo).

## Decisiones

- Migración `000099`:
  - `GenerateConvocationSyllabi` crea exactamente un alcance por sílabo, así que
    `alcances_silabo` se reemplaza por `silabos.paralelo_id` (FK a `paralelos`,
    obligatorio, único con `convocatoria_id`, indexado). La materia programada se
    deriva de `paralelos.programacion_asignatura_id`. La migración falla si algún sílabo
    no tiene exactamente un alcance.
  - La vista `docentes_vigentes_silabo` se recrea sobre `silabos.paralelo_id`.
  - `silabos.carrera_id` se retira: su único consumo era el filtro de fuentes de IA y la
    notificación de regeneración; ambos usan la carrera de la convocatoria. El trigger
    `validar_evidencia_ia` compara con `convocatorias_carreras.carrera_id`.
    `Syllabus::career()` pasa a `HasOneThrough` por la convocatoria.
  - `aprobaciones`, `reaperturas`, `solicitudes_correccion` y `artefactos_exportacion`
    pierden `silabo_id`; el sílabo se obtiene por `revisiones_silabo`
    (`revision_silabo_id` o `revision_aprobada_id`). Antes de borrar, la migración
    verifica que la copia coincida. Las unicidades `(silabo_id, clave_idempotencia)` de
    aprobaciones y reaperturas pasan al trigger `validar_relaciones_flujo`, serializado
    por el bloqueo del sílabo que ya toman `ApproveSyllabus` y `ReopenSyllabus`.
  - Se retira la rama muerta de `respuestas_observacion` del trigger (tabla eliminada en
    `000097`).
- `solicitud_correccion_observaciones` se conserva: `RequestSyllabusCorrection` admite
  cualquier subconjunto no vacío de observaciones abiertas de la revisión vigente, por
  lo que la relación no es derivable.
- Los contratos JSON conservan `parallels` como lista (ahora de un elemento).

## Verificación

- `pint`, `phpstan`, pruebas de `tests/Feature/{Syllabus,Documents,AiAssistance,Operations,Academic}`
  y `SpanishSchemaTest` sobre una base aislada.
