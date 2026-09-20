# Ingesta estructurada de fuentes académicas

## Estado

Implementada por I-95 y la migración `000089`. El proveedor permanece desactivado por
defecto (`PADDLEOCR_ENABLED=false`) hasta resolver PV-12 y PV-14.

## Decisión

- El PDF original se conserva privado e inmutable en `objetos_almacenados`.
- La API oficial alojada de PaddleOCR procesa el documento con el modelo fijo
  `PaddleOCR-VL-1.6`.
- El formato canónico no es Markdown: es un documento JSON versión 1 compatible con
  ProseMirror/Tiptap. Conserva el orden de bloques, imágenes y tablas con `rowspan` y
  `colspan`.
- No intervienen Mistral, DeepSeek, Docling ni otro proveedor de respaldo. Un resultado
  vacío o inválido falla y conserva el documento anterior.
- `contenido_fuente_academica` es texto derivado para búsqueda y compatibilidad; la
  fuente de verdad editable es `documento_fuente_academica`.

## Flujo

1. Coordinación carga un PDF. Laravel valida MIME, tamaño, huella y alcance, y lo guarda
   en el disco privado.
2. `RequestAcademicSourceOcr` crea una `ejecuciones_trabajo` idempotente con la huella,
   modelo y versión del adaptador.
3. `SubmitAcademicSourceOcrJob` envía el archivo a `POST /api/v2/ocr/jobs` y termina sin
   esperar el procesamiento remoto.
4. `PollAcademicSourceOcrJob` consulta el estado con trabajos demorados. Ningún worker
   queda ocupado haciendo espera activa.
5. Al terminar, se descarga el JSONL firmado. El adaptador toma `markdown.text`,
   `markdown.images` y `prunedResult` de cada página.
6. El conversor genera bloques Tiptap ordenados. Las tablas HTML se convierten a nodos
   de tabla conservando combinaciones; las imágenes se validan y guardan como objetos
   privados `academic_source_image` en su posición lógica.
7. Una transacción reemplaza el documento completo, deriva texto y reconstruye
   `fragmentos_fuente_academica` con página y bloques de origen.
8. Si `EMBEDDINGS_DRIVER=openai`, la cola `ia` genera embeddings
   `text-embedding-3-small` de 1536 dimensiones. Un fallo de embeddings no altera el
   documento canónico.

## Persistencia

Solo existen dos tablas específicas del módulo:

- `fuentes_academicas`: metadatos, documento JSON completo, texto derivado, PDF vigente
  y estado de ingesta;
- `fragmentos_fuente_academica`: índice reemplazable textual/vectorial con procedencia.

Las ejecuciones usan la tabla operativa compartida `ejecuciones_trabajo`; no existe una
tabla de extracciones. Los PDF y las imágenes reutilizan `objetos_almacenados` y se
entregan únicamente por rutas autenticadas y acotadas a la carrera.

## Configuración

```text
PADDLEOCR_ENABLED=false
PADDLEOCR_BASE_URL=https://paddleocr.aistudio-app.com
PADDLEOCR_MODEL=PaddleOCR-VL-1.6
PADDLEOCR_ACCESS_TOKEN=
PADDLEOCR_POLL_DELAY=5
PADDLEOCR_MAX_POLL_MINUTES=20
```

La clave vive únicamente en el entorno. No se registran el PDF, el JSONL, el documento
completo, URLs firmadas ni credenciales.

## Referencias oficiales

- https://github.com/PaddlePaddle/PaddleOCR/blob/main/docs/version3.x/inference_deployment/serving/paddleocr_official_api/python.en.md
- https://github.com/PaddlePaddle/PaddleOCR/blob/main/paddleocr/_api_client/_http.py
- https://github.com/PaddlePaddle/PaddleOCR/blob/main/paddleocr/_api_client/_poller.py
