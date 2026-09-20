# I-96 — OpenParser como proveedor de ingesta

## Estado

Implementado y verificado el 19 de septiembre de 2026. El 20 de septiembre se añadió
una normalización editorial opcional posterior a OpenParser; el documento JSON, la
persistencia y los embeddings no cambian.

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
- No se usa `/extract`, Mistral, Docling ni otro proveedor OCR. Si
  `AI_SOURCE_NORMALIZATION_ENABLED=true` con driver `deepseek`, `deepseek-flash` recibe
  las páginas OCR en lotes mediante `chat/completions` y solo normaliza el Markdown de
  cada página; no resume, traduce ni inventa contenido.
- Las tablas HTML y las imágenes se reemplazan por marcadores protegidos antes de llamar
  a DeepSeek y se restauran después en el mismo lugar. Si la respuesta omite, duplica o
  altera un marcador, la ingesta falla de forma explícita y no se aplica un fallback
  silencioso.
- La ingesta es idempotente por huella SHA-256: la solicitud interna se deduplica por
  fuente, archivo, modelo y versiones, mientras que el `Idempotency-Key` enviado a
  OpenParser deduplica el job externo por archivo y adaptador OCR. Por eso dos
  ejecuciones internas pueden compartir un único job `Parse`; DeepSeek se ejecuta después
  y puede volver a normalizar el resultado sin repetir el OCR.
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
- [x] Normalizador DeepSeek por lotes, con prompt editorial y preservación verificada de
  páginas, tablas, imágenes y su posición.
- [x] Interfaz, despliegue, trazabilidad y decisiones actualizadas.
- [x] Corrección del 20 de septiembre de 2026: las celdas vacías se representan como
  párrafos sin nodos de texto, la normalización elimina entradas vacías y la migración
  `000090` repara documentos ya procesados sin alterar su texto, fragmentos o embeddings.
- [x] Corrección visual del 20 de septiembre de 2026: los niveles Markdown que ya
  entrega OpenParser se conservan sin duplicar `#`; la hoja A4 pagina todos los bloques
  Tiptap y las celdas fijan texto oscuro sobre el papel blanco.
- [x] I-97 reemplaza la hoja A4 de COR-11 por un documento continuo, prioriza celdas
  estructuradas sobre HTML auxiliar y añade la vista privada del PDF original.
