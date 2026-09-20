# I-95 — Fuentes estructuradas con PaddleOCR

## Estado

Implementado y verificado el 19 de septiembre de 2026. Sustituye I-26, I-49, I-91 e
I-94 en lo relativo al formato canónico. I-96 sustituyó posteriormente la conexión
directa al proveedor por OpenParser sin cambiar ese formato.

## Objetivo

Procesar cada PDF académico con la API oficial de PaddleOCR-VL 1.6 y conservar un
documento JSON editable, ordenado y apto para tablas complejas e imágenes privadas.

## Decisión técnica

- El formato canónico es JSON compatible con ProseMirror/Tiptap, versión 1.
- `fuentes_academicas` conserva el documento completo y
  `fragmentos_fuente_academica` el índice textual/vectorial derivado.
- `ejecuciones_trabajo` registra envío, consulta y resultado del proveedor; se retira
  `extracciones_fuente_academica`.
- El PDF y las imágenes continúan en `objetos_almacenados`, siempre privados.
- PaddleOCR-VL 1.6 se consulta mediante trabajos cortos de envío y sondeo. No hay
  espera activa en un worker ni proveedor alternativo.
- Los embeddings siguen usando `text-embedding-3-small` con 1536 dimensiones.

## Alcance

- RF-037..054; CU-07 y CU-08; UI-COR-11.
- RN-015, RN-016, RN-020..024 y RN-028..030.
- RNF-013, RNF-016, RNF-017, RNF-035 y RNF-036.
- PV-12 continúa siendo la compuerta para enviar documentos al proveedor externo.

## Entregables

- [x] Migración del esquema y datos Markdown existentes a documento JSON.
- [x] Adaptador HTTP oficial de PaddleOCR y trabajos asíncronos de envío/sondeo.
- [x] Materialización privada de imágenes y conversión de tablas con combinaciones.
- [x] Fragmentación estructurada y regeneración de embeddings.
- [x] Contrato API y editor Tiptap de fuentes.
- [x] Pruebas, OpenAPI, esquema de producción y trazabilidad actualizados.
