# I-135 — Persistencia de IA en dos tablas

## Estado

Implementado el 2026-09-24 con la migración
`2026_09_24_000103_replace_ai_persistence_with_history_and_documents`. La base remota no
tenía filas en ninguna tabla de IA: no hay migración de datos de historial. Los
fragmentos locales, si existen, se copian a `documentos_ia` conservando su embedding.
Pendiente: aplicar la migración en la remota según `docs/security/hardening.md`.

## Objetivo

Reemplazar once tablas de IA (`ejecuciones_ia`, `revisiones_ia_silabo`,
`recomendaciones_ia`, `evidencias_ia`, `recomendacion_evidencias_ia`,
`retroalimentacion_ia`, `conversaciones_ia`, `mensajes_ia`, `evidencias_mensaje_ia`,
`citas_mensaje_ia`, `fragmentos_fuente_academica`) por el patrón estándar de memoria de
agente + almacén vectorial, sin cambiar lo que ve la persona usuaria.

## Decisiones

1. `historial_ia (id uuid, sesion_id text, mensaje_ia jsonb, enviado_en timestamptz)`.
   Sesiones `silabo:{id}:campo:{campo}` (análisis y decisiones), `silabo:{id}:revision`
   (revisión integral) y `silabo:{id}:usuario:{usuario}` (chat y ocultamientos). La
   forma de cada tipo está en `docs/architecture/production-database-schema.md`.
2. `documentos_ia (id uuid, contenido_documento text, metadatos_documento jsonb,
   embedding vector(1536))`, HNSW coseno, índice por
   `metadatos_documento->>'fuente_academica_id'` y GIN textual en español.
3. Nombres en español con sufijo explícito: I-52/I-78 prohíben `creado_en`, `mensaje`,
   `contenido` y `metadatos` como columnas; por eso `enviado_en`, `mensaje_ia`,
   `contenido_documento` y `metadatos_documento`.
4. Invariantes que antes daban las FK y triggers de cada tabla:
   - `mensaje_ia.entrada` es inmutable y un mensaje terminal no cambia
     (`proteger_historial_ia`); solo el reinicio institucional borra.
   - Idempotencia por índices únicos parciales (análisis, revisión por versión,
     pregunta de chat, decisión y una sola aplicación por recomendación).
   - Cascadas por trigger: borrar un sílabo borra sus sesiones; borrar una fuente borra
     sus documentos.
5. El progreso técnico sigue en `ejecuciones_trabajo` (`ia.analisis`, `ia.chat`) con
   `recurso_id` = id del mensaje. La revisión integral no crea trabajo propio: agrega
   el estado de sus análisis.
6. Las decisiones humanas son mensajes `decision` agregados; el análisis terminal no se
   reescribe. Limpiar el chat agrega un mensaje `ocultamiento`.
7. Contrato HTTP sin cambios: mismas rutas y formas de respuesta. `{recommendation}` es
   el id de la recomendación dentro del JSON; `conversation.id` pasa a ser el
   `sesion_id`. `apply` devuelve el texto aplicado desde la recomendación persistida.

## Lo que se pierde o cambia

- Sin FK hacia `silabos`, `definiciones_campo`, `usuarios`, `asignaciones_rol`,
  `fuentes_academicas` ni `ejecuciones_trabajo`: los ids viven en JSON. Se compensa con
  el formato de `sesion_id` (CHECK), triggers de limpieza y la inmutabilidad.
- Ya no se valida en BD que la evidencia citada pertenezca al mismo análisis; lo
  garantizan `AiResultContract` (análisis) y `AnswerSyllabusChatJob` (chat), que
  descartan referencias fuera de la evidencia fijada.
- `metadatos_entrada.too_many_evidence` se retiró: siempre era falso.

## Verificación

- Pint, PHPStan, suite backend completa en `silabos_ueb_test_ia`.
- Frontend: `vue-tsc` y Vitest sin cambios de código.
