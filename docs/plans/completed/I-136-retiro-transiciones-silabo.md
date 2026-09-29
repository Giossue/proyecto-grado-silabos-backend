# I-136 — Retiro de transiciones_silabo

## Estado

Implementado el 24 de septiembre de 2026.

## Objetivo

Decisión del usuario: `transiciones_silabo` duplicaba `eventos_auditoria` y la
aplicación nunca la leía. Se elimina y la auditoría queda como único registro de los
cambios de estado del sílabo.

## Decisiones

- Migración `2026_09_24_000104`: elimina la tabla (sus triggers caen con ella) y
  redefine `validar_relaciones_flujo` sin la rama `transiciones_silabo`. Ninguna otra
  función de PostgreSQL la referenciaba (verificado en `pg_proc`). `down` es
  irreversible (lanza `RuntimeException`), como las demás migraciones que borran historial.
- Se eliminan `RecordSyllabusTransition`, el modelo `SyllabusTransition`,
  `Syllabus::transitions()` y el borrado en `InProgressWork`.
- `SubmitSyllabus`, `RequestSyllabusCorrection`, `ApproveSyllabus` y `ReopenSyllabus`
  añaden `from_state` y `to_state` a los metadatos de su evento de auditoría.
- Pruebas: `ReviewWorkflowTest` verifica el evento `silabo.enviado` con estados; la
  prueba de transición inválida se sustituye por el CHECK `silabos_estado_check`.

## Verificación

- `pint`, `phpstan`, suite completa (BD `silabos_ueb_test_trans`, `migrate:fresh`).
