# I-166 — «Campo» en lugar de «parte»

## Pedido del responsable, 2026-09-29

Los docentes comentaron que «parte» suena mal. El elemento que va dentro de cada sección
de la plantilla (Texto libre, Personalizado, Sí/No, selección) pasa a llamarse **campo**,
en la interfaz y en la base de datos.

## Alcance

- Migración `000165`: `bloques_plantilla` → `campos_plantilla`, con columnas explícitas
  (`clave_campo_plantilla`, `titulo_campo_plantilla`, `posicion_campo_plantilla`,
  `configuracion_campo_plantilla`); `definiciones_campo.bloque_plantilla_id` →
  `campo_plantilla_id`; restricciones, índice y trigger (`tocar_revision_por_campo`)
  renombrados. `secciones_plantilla` recibe también columnas explícitas
  (`clave_seccion_plantilla`, `titulo_seccion_plantilla`, `descripcion_seccion_plantilla`,
  `posicion_seccion_plantilla`), lo que cierra la falla I-78 de `SpanishSchemaTest`.
- Backend: persistencia de `SyllabusTemplate`, acciones que usaban `bloque_plantilla_id`
  y todos los mensajes al usuario («Campo agregado», «Elija un tipo de campo», etc.).
- Frontend: todos los textos visibles, `aria-label` y pruebas.
- Documentación de producto, arquitectura y esquema.
- `definiciones_campo` conserva su nombre: es cada pregunta o dato que guarda un campo.
- Los identificadores internos en inglés (`TemplateBlock`, `blocks`, claves JSON de la API)
  no cambian; no son visibles.
- Se retira `TemplateJsonMigrationTest`: probaba el viaje de ida y vuelta de la migración
  `000094`, imposible desde que `000161` eliminó `documento_plantilla` y declaró su reversa
  como no soportada. Con esto se cierran las dos incidencias I-164/I-78 que registraba
  `docs/plans/pending-work.md`; la puerta completa vuelve a pasar (385/385).

## Verificación

- Migración `000165` probada en local con subida, reversa y nueva subida.
- `composer verify` y `npm run typecheck`, `npm run lint`, `npx vitest run`.
- Remota: respaldo previo y `migrate` según `docs/security/hardening.md`.
