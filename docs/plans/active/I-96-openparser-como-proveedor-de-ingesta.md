# I-96 — OpenParser como proveedor de ingesta

## Estado

Implementado y verificado el 19 de septiembre de 2026. Modifica I-95 únicamente en la
frontera del proveedor alojado; el documento JSON, la persistencia y los embeddings no
cambian.

## Objetivo

Procesar los PDF académicos con OpenParser Studio/API usando `paddleocr-vl-1.6` y el
contrato versionado `openparser@1`.

## Decisión técnica

- Laravel usa `POST /parse/async`, consulta `GET /jobs/{id}` y obtiene el resultado con
  `GET /jobs/{id}/result?format=openparser@1`.
- El grafo de páginas, orden de lectura, elementos y tablas se transforma al documento
  ProseMirror/Tiptap ya existente.
- Las figuras con recurso se materializan en almacenamiento privado. Si OpenParser solo
  entrega coordenadas, el recorte se obtiene del PDF privado y se inserta en el mismo
  orden lógico.
- No se usa `/extract` ni otro LLM: OpenParser aloja PaddleOCR-VL 1.6 y normaliza su
  salida.
- No se requiere una migración de base de datos.

## Alcance

- RF-037..054; CU-07 y CU-08; UI-COR-11.
- RN-015, RN-016, RN-020..024 y RN-028..030.
- RNF-013, RNF-016, RNF-017, RNF-035 y RNF-036.
- PV-12 y PV-14 siguen siendo compuertas para el envío al proveedor externo.

## Entregables

- [x] Configuración y adaptador HTTP de OpenParser.
- [x] Conversión de `openparser@1` con tablas e imágenes en orden.
- [x] Recorte privado de figuras cuando no exista un recurso remoto.
- [x] Pruebas de envío, sondeo, estructura, tablas, imágenes y fallos.
- [x] Interfaz, despliegue, trazabilidad y decisiones actualizadas.
- [x] Corrección del 20 de septiembre de 2026: las celdas vacías se representan como
  párrafos sin nodos de texto, la normalización elimina entradas vacías y la migración
  `000090` repara documentos ya procesados sin alterar su texto, fragmentos o embeddings.
- [x] Corrección visual del 20 de septiembre de 2026: los niveles Markdown que ya
  entrega OpenParser se conservan sin duplicar `#`; la hoja A4 pagina todos los bloques
  Tiptap y las celdas fijan texto oscuro sobre el papel blanco.
