# Ingesta de fuentes académicas desde PDF

## Estado

Implementación backend disponible desde la migración `000086`. El proveedor permanece
desactivado por defecto (`MISTRAL_OCR_ENABLED=false`) hasta que el despliegue resuelva
PV-12 y PV-14.

## Decisión propuesta

- El PDF original se conserva como objeto privado e inmutable.
- La única etapa PDF → Markdown será Mistral OCR 4.1 mediante su API oficial, usando el
  identificador fijo mistral-ocr-4-1 para que el resultado sea reproducible.
- Mistral devolverá el Markdown estructurado por página, además de las tablas, imágenes,
  bloques y metadatos de confianza que la respuesta proporcione.
- No se incorporan pdfplumber, DeepSeek, otro LLM de normalización ni un extractor de
  respaldo para páginas vacías. Un error o resultado insuficiente queda como extracción
  fallida/revisión requerida.
- DeepSeek continúa siendo un proveedor posible para asistencia IA general según I-73,
  pero no participa en la ingesta de fuentes.

Mistral documenta que OCR 4.1 expone Markdown por página, extracción de tablas e
imágenes, bloques estructurales y puntuaciones de confianza. La documentación oficial
también indica que mistral-ocr-latest apunta actualmente a OCR 4.1; se prefiere el
identificador fijo en producción para evitar cambios silenciosos.

## Flujo objetivo

1. Coordinación carga un PDF privado. Laravel valida tamaño, MIME real, huella y alcance
   por carrera; el archivo no se hace público.
2. Se registra una ejecución asíncrona idempotente con la huella del archivo, el modelo
   y las opciones OCR. La carga y la ejecución no bloquean la petición web.
3. Un adaptador HTTP autorizado envía el documento a POST /v1/ocr de Mistral. La
   integración usa el mecanismo seguro de archivo definido para producción; no se
   guardan URLs públicas permanentes ni credenciales en el documento.
4. La petición solicita table_format='markdown', bloques estructurales y confianza
   por bloque. La primera versión procesa el PDF completo en una ejecución.
5. Laravel conserva el Markdown y los metadatos de cada página en la extracción, y une
   sus páginas ordenadas en el contenido Markdown de la fuente. Los marcadores de tabla
   se reemplazan por el Markdown de la tabla devuelto por Mistral.
6. Las imágenes se solicitan con `include_image_base64`, se validan como raster y se
   guardan como objetos privados inmutables. El Markdown referencia una ruta autenticada
   de la fuente; no se guarda Base64 en PostgreSQL ni en los fragmentos vectoriales.
7. Al finalizar correctamente, se reconstruye fragmentos_fuente_academica. Los
   fragmentos son un índice derivado y reemplazable para búsquedas y evidencias; el
   Markdown generado/revisado y el PDF original no dependen de ellos.
8. Una ejecución correcta reemplaza el contenido Markdown de la fuente e indexa sus
   fragmentos; una ejecución fallida conserva el contenido anterior y no publica un
   resultado parcial. La Coordinación puede revisar o corregir el Markdown desde el
   editor existente.

## Contrato mínimo de extracción

La respuesta de Mistral debe conservar, como mínimo:

- identificador del modelo y de la ejecución remota;
- página, Markdown y orden de página;
- tablas, imágenes, hipervínculos y bloques cuando estén disponibles;
- confianza de página/bloque/palabra según la granularidad configurada;
- estado, tiempos, conteo de páginas y error seguro;
- huella del PDF, opciones usadas y versión del adaptador.

El sistema no persiste en logs el PDF completo, el Markdown completo, claves ni
respuestas sensibles. La evidencia de IA mantiene su fotografía inmutable como ya exige
el modelo de fuentes.

## Tablas, imágenes y páginas sin contenido

- table_format='markdown' permite que las tablas entren en el mismo documento que el
  texto. Los marcadores que devuelve Mistral se sustituyen por su contenido antes de
  indexar la fuente.
- Las imágenes raster se guardan en `objetos_almacenados` con clasificación
  `academic_source_image` y solo se sirven a Coordinación dentro del alcance de la
  fuente. OCR puede leer texto que forme parte de una imagen, pero no garantiza
  interpretar correctamente diagramas, gráficos, fórmulas o el significado visual de
  una fotografía.
- No habrá fallback para una página sin texto o con respuesta vacía. La ejecución se
  marca para revisión/reintento controlado con la misma integración; no se sustituye
  silenciosamente por otra librería o modelo.

## Persistencia implementada

La base conserva el contenido Markdown en `fuentes_academicas` y los fragmentos derivados
en `fragmentos_fuente_academica`. La migración `000086` relaciona el PDF privado y la
ejecución OCR mediante:

- referencia al objeto_almacenado original;
- estado de extracción y timestamps funcionales;
- huella, modelo, opciones y versión del adaptador;
- resultado por página y causa segura de fallo;
- relación con `ejecuciones_trabajo` para observar la cola y el resultado.

Las imágenes extraídas reutilizan `objetos_almacenados`; su ruta contiene la fuente y su
clasificación permite autorizarlas sin crear una tabla de recursos visuales separada.
El Base64 solo vive durante la ejecución del trabajo y no se persiste en
`paginas_extraidas`.

La ruta `POST /api/v1/coordination/sources/{source}/pdf` acepta el PDF, devuelve `202`
y despacha `ExtractAcademicSourceJob` en la cola `integraciones`. El archivo nunca se
transporta dentro del payload del trabajo.

## Operación

- Usar Redis/PostgreSQL para cola y estado; el PDF, el payload de Mistral y el resultado
  no deben viajar completos dentro del job.
- La clave idempotente incluye fuente, huella del PDF y modelo; versión del adaptador y
  opciones usadas quedan persistidas para auditar y reproducir la ejecución.
- Para documentos masivos puede evaluarse Batch Inference; para un documento individual
  se mantiene el trabajo asíncrono normal.
- Reintentar debe ser acotado y observable. Un fallo definitivo conserva la causa segura
  y no activa la fuente.
- El proveedor externo debe estar desactivado por defecto en despliegues que no hayan
  resuelto PV-12. La API de Mistral es un adaptador reemplazable, no una dependencia
  del núcleo transaccional.

## Referencias oficiales

- https://docs.mistral.ai/models/ocr-4-1
- https://docs.mistral.ai/api/endpoint/ocr
- https://docs.mistral.ai/studio/document-processing/basic_ocr
- https://docs.mistral.ai/resources/cookbooks/mistral-ocr-batch_ocr
- https://docs.mistral.ai/resources/changelogs
