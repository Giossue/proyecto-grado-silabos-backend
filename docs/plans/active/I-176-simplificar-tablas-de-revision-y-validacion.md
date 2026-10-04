# I-176 — Simplificar tablas de revisión y validación

## Pedido del responsable, 2026-10-04

Cuatro tablas no justifican su existencia: `solicitudes_correccion`, `reaperturas`,
`validaciones_silabo` y `resultados_validacion`. Se reemplazan por columnas o por cálculo.
Las cuatro estaban vacías en la base remota, así que la migración no pierde datos.

## Decisión

| Tabla retirada | Reemplazo |
|---|---|
| `validaciones_silabo`, `resultados_validacion` | Se calcula al validar o enviar (`DraftValidation`); no se guarda. Solo se leía la última corrida y la auditoría ya registra `silabo.validado`. |
| `solicitudes_correccion` | Columnas `correccion_justificacion`, `correccion_etapa`, `correccion_solicitada_por` y `correccion_solicitada_en` en `revisiones_silabo` (relación 1 a 1). |
| `reaperturas` | Columnas `causa_reapertura`, `origen_reapertura`, `reabierta_por`, `reabierta_en` y `clave_reapertura` en `aprobaciones` (relación 1 a 1). |

Además se elimina `revisiones_silabo.reapertura_id`: la revisión que sigue a una
reapertura ya apunta a la aprobada con `revision_anterior_id`. De 39 tablas quedan 35.

## Inmutabilidad (ADR 0002)

`revisiones_silabo` y `aprobaciones` son append-only. Las columnas nuevas son de
**escritura única**: pasan de NULL a un valor una vez, y un CHECK exige que el grupo esté
completo o vacío. El resto de la fila sigue inmutable y no se borra. Lo refuerzan el trait
`ImmutableRecord` (`writeOnceColumns`) y el trigger `proteger_registro_con_campos_unicos`.
Se enmendó el ADR 0002.

## Cambios

- Migración `000184` (no se revierte): copia datos, agrega restricciones, reemplaza los
  triggers `validar_relaciones_revision`, `validar_relaciones_flujo` y los de protección, y
  elimina las cuatro tablas y `reapertura_id`.
- Backend: `ValidateDraft` devuelve `DraftValidation`; `RequestSyllabusCorrection` y
  `ReopenSyllabus` escriben las columnas nuevas; `LibraryReviews`, `SignatureWorkflow`,
  controladores de revisión y sílabo y `InProgressWork` leen las columnas nuevas.
- API: el detalle del sílabo del docente ya no trae `validation` (se obtiene al validar);
  `POST teacher/syllabi/{id}/validate` conserva su respuesta. No cambia el resto.
- Auditoría: la corrección registra `revision_silabo` y la reapertura `aprobacion` como
  tipo de recurso; los eventos anteriores conservan los tipos antiguos.

## Verificación

- `ReviewWorkflowTest`: reapertura con aprobación intacta y revisión enlazada; escritura
  única y grupos completos en modelo y base (`test_cp_f_reopening_and_correction_columns_are_write_once_and_complete`).
- `ConvocationAndDraftTest`: validación determinística leída de la respuesta.
- `LibraryReviewTest`, `DocumentSignatureTest`; suite completa, Pint, PHPStan y contrato OpenAPI.
