# I-97 — Vista continua de fuentes académicas

## Estado

Implementado el 20 de septiembre de 2026 como ajuste de COR-11 e I-96.

## Objetivo

Mostrar la fuente extraída como un documento editable continuo, sin simular hojas ni
saltos A4 que no forman parte del documento canónico, y conservar una vista privada del
PDF original para contrastar el resultado.

## Alcance y trazabilidad

- RF-027..033; RN-013..016; CU-05; COR-11; CP-F fuente/evidencia.
- No cambia el esquema ni la fragmentación vectorial.
- PV-12 y PV-14 continúan siendo las compuertas para usar OpenParser con documentos
  institucionales.

## Decisión técnica

- Tiptap continúa editando el JSON ProseMirror canónico, pero COR-11 deja de usar
  `PaginatedDocument` y presenta un lienzo continuo y responsive.
- Las tablas se desplazan horizontalmente dentro del documento cuando lo requieren y
  no ensanchan la página completa.
- El adaptador prioriza las celdas estructuradas de `openparser@1`; usa el HTML y luego
  el Markdown únicamente como respaldo. Si el grafo no contiene elementos, usa la
  proyección Markdown canónica del proveedor.
- El PDF vigente se entrega mediante una ruta autenticada y se consulta desde una
  pestaña de solo lectura. Las páginas físicas se conservan allí y no se recrean en el
  editor.

## Entregables

- [x] Editor continuo sin paginación A4.
- [x] Tablas contenidas y legibles en pantallas estrechas.
- [x] Prioridad correcta de celdas estructuradas y respaldo Markdown.
- [x] Vista autenticada del PDF original.
- [x] Pruebas de interfaz, conversión y autorización.
- [x] Contrato OpenAPI y documentación actualizados.
