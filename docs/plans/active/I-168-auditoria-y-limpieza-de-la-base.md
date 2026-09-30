# I-168 — Auditoría y limpieza de la base de datos

## Pedido del responsable, 2026-09-30

Tras muchos cambios quedaron restos sin tratar. Se auditó la base remota (solo lectura,
migración `000165`) y se pidió corregir todo lo encontrado. El detalle de los 48 puntos
está en `AUDITORIA_BD.md` (raíz del backend).

## Migraciones

- `000166_retire_unused_schema_remains`: elimina `documentos_ia`, su trigger y la
  extensión `vector`; `bases_resultados_asignatura` y `resultados_aprendizaje_asignatura`;
  tipos, sesiones e índices del revisor en `historial_ia`; el trigger vacío
  `validar_solicitud_correccion`; `revision_configuracion` y sus triggers; las columnas
  que nadie leía; la fila huérfana de `migraciones`. Simplifica
  `validar_artefacto_exportacion`.
- `000167_add_missing_integrity_rules`: trigger append-only en `firmas_documento`;
  `CHECK` de modalidad, tipo de requisito y campo heredado; par único de requisitos;
  `asignado_en` obligatorio; vocabularios sin valores muertos.
- `000168_tidy_indexes_and_constraint_names`: quita índices sobrantes, recrea la vista
  `docentes_vigentes_silabo` sin `DISTINCT` y renombra restricciones e índices heredados.
- `000169_rename_ambiguous_columns`: nombres completos para columnas ambiguas; los
  modelos conservan el alias corto.
- `000170_store_template_fields_once`: el JSON de la hoja guarda solo la clave de cada
  campo (`TemplateFieldAttributes`); FK compuestas por plantilla; triggers que protegen
  `mostrar_si`; precisión de fechas unificada.

Ninguna se revierte: la reversa exige restaurar el respaldo previo.

## Decisiones

- **Se conserva** `aprobaciones.aprobacion_base_id` (trazabilidad de la corrección de
  bibliografía), `secciones_plantilla.descripcion_seccion_plantilla` (se muestra al
  docente), `reaperturas.revision_aprobada_id` (lo protege un trigger) y
  `valores_curriculares.carrera_id` (desnormalización protegida por FK compuesta).
- **No se renombra** la tabla `fuentes_academicas` ni sus columnas: sería un cambio amplio
  de bajo valor; queda documentado como nombre histórico.
- `silabos.contexto_academico_silabo` es una lectura sincronizada, no evidencia: se
  corrige la documentación en lugar del código.
- `mostrar_si` sigue apuntando por clave; la integridad la dan triggers en vez de una FK.

## Verificación

- `php artisan test` (379/379), PHPStan, Pint, OpenAPI regenerado.
- Frontend: `vue-tsc`, `npm run lint`, `vitest` (272).
- Migraciones aplicadas en la base local de desarrollo.
- **Pendiente:** aplicar en la remota con respaldo previo, siguiendo
  `docs/security/hardening.md`, solo con autorización del responsable.
