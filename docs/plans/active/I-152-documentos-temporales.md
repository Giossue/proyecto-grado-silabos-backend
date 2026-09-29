# I-152 — Documentos de importación temporales

## Decisión del responsable, 2026-09-28

No se conservan los documentos subidos para la importación curricular. Solo hacen falta
mientras la importación está abierta: la lectura OCR (PaddleOCR-VL mediante OpenParser)
es asíncrona y las citas de la IA se verifican al preparar y al guardar. Los datos
confirmados ya guardan extracto, ubicación y nombre del documento.

## Alcance y trazabilidad

- I-145, I-146, I-149, I-151; RF-017..026, RF-034..045, RNF de privacidad y minimización
  (`docs/security/principles.md` §11), COR-16.
- Al aplicar (después del commit) y al descartar se eliminan la lectura
  (`fuentes_academicas`) y los archivos físicos: original, PDF de OCR e imágenes. Un
  documento que otra importación abierta usa se conserva.
- `objetos_almacenados` es de solo agregar por disparador de la base: se conserva su ficha
  (nombre, tamaño, huella) sin contenido. No se modifica esa protección.
- Nuevo `DELETE /coordination/curricular-imports/{id}` para importaciones `lista` o
  `fallida`, auditado como `academico.importacion_curricular.descartada`.
- Se retira la reutilización (`source_ids` y `sources` del índice). La idempotencia solo
  devuelve una importación abierta; tras aplicar, el mismo archivo se analiza de nuevo.
- Sin migración. Los documentos de la base de pruebas pertenecen a la importación
  abierta y se eliminarán cuando se guarde o descarte.

## Trabajo

- [x] Acción de liberación, descarte y ruta; aplicación libera tras el commit.
- [x] Retiro de reutilización en backend, OpenAPI y frontend.
- [x] Botón «Descartar importación» con confirmación en COR-16.
- [x] Pruebas backend (liberación, descarte, documento compartido, reenvío) y frontend.
- [x] Documentación: AGENTS, decisiones, pantallas, arquitectura y trazabilidad.
