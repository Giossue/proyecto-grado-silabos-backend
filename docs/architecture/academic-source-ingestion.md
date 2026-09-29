# Documentos internos de ingesta curricular

## Estado

I-146 retira el CRUD/editor de Fuentes académicas y el revisor con recuperación
vectorial. La lectura documental implementada en I-95/I-96 permanece como infraestructura
interna de la importación curricular I-145, sin borrado de evidencia histórica.
El proveedor permanece desactivado
por defecto (`OPENPARSER_ENABLED=false`) hasta resolver PV-12 y PV-14.

## Decisión

- El PDF original se conserva privado e inmutable en `objetos_almacenados`.
- OpenParser procesa el documento con `paddleocr-vl-1.6` y devuelve el contrato
  versionado `openparser@1`.
- El formato canónico interno no es Markdown: es un documento JSON versión 1 compatible
  con ProseMirror/Tiptap. Conserva el orden de bloques, imágenes y tablas con `rowspan` y
  `colspan`.
- Después de OpenParser, cuando `AI_SOURCE_NORMALIZATION_ENABLED=true` con driver
  `deepseek`, `deepseek-flash` normaliza el texto
  por lotes mediante un prompt editorial. Los marcadores de tablas e imágenes se
  protegen y se restauran byte a byte para que DeepSeek no pueda eliminarlos ni alterar
  sus combinaciones o posición.
- No se usa `/extract`, Mistral, Docling ni un proveedor de respaldo. Si DeepSeek está
  configurado para la normalización y devuelve un resultado vacío, inválido o incompleto,
  la ingesta falla y conserva el documento anterior.
- `contenido_fuente_academica` es texto derivado para extracción y compatibilidad; el
  documento interno canónico es `documento_fuente_academica`.

## Flujo

1. Coordinación carga un PDF mediante una importación curricular. Laravel valida MIME, tamaño, huella y alcance, y lo guarda
   en el disco privado.
2. `RequestAcademicSourceOcr` crea una `ejecuciones_trabajo` idempotente con la huella,
   modelo y versión del adaptador.
3. `SubmitAcademicSourceOcrJob` envía el archivo a `POST /parse/async` con el modelo
   `paddleocr-vl-1.6`, formato `openparser@1` e `Idempotency-Key`.
4. `PollAcademicSourceOcrJob` consulta `GET /jobs/{id}` mediante trabajos demorados.
   Ningún worker queda ocupado haciendo espera activa.
5. Al terminar, el adaptador obtiene `GET /jobs/{id}/result?format=openparser@1` y valida
   páginas, orden de lectura, elementos, tablas, figuras y recursos.
6. Si la normalización está habilitada con el driver `deepseek`, el normalizador envía páginas en lotes al endpoint
   `chat/completions` y valida el JSON recibido, los índices y los marcadores protegidos.
7. El conversor genera bloques Tiptap ordenados. Prioriza las celdas estructuradas para
   conservar combinaciones; HTML y Markdown son respaldos. Si el grafo no contiene
   elementos usa la proyección Markdown canónica del proveedor. Las figuras con recurso se materializan directamente; si
   solo tienen coordenadas, Poppler renderiza la página y el backend recorta la figura
   desde el PDF privado.
8. Las imágenes se validan y guardan como objetos privados `academic_source_image` en su
   posición lógica.
9. Una transacción guarda el documento interno completo y deriva su texto. Los trabajos
   comprueban que el archivo y la solicitud siguen vigentes para evitar resultados tardíos
   que sobrescriban otro documento.
10. I-146 elimina la indexación textual/vectorial y el despacho de embeddings. La lectura
    alimenta exclusivamente la propuesta curricular de I-145.

### Idempotencia y reprocesamiento

Laravel calcula la huella SHA-256 del PDF antes de enviarlo. La ejecución interna usa
una clave compuesta por la fuente, la huella, el modelo OCR y la versión del adaptador y
normalizador; repetir la misma solicitud con esa combinación devuelve la ejecución
existente y no despacha otro trabajo.

Además, `SubmitAcademicSourceOcrJob` envía a OpenParser un `Idempotency-Key` derivado
de la huella del PDF y de la versión del adaptador OCR. Por eso, si el mismo archivo se
sube otra vez —incluso desde otra ejecución interna causada por cambiar el normalizador—
OpenParser devuelve el mismo job `opj_...` en lugar de crear otro Parse. El dashboard de
OpenParser muestra entonces un solo job aunque PostgreSQL pueda conservar varias
ejecuciones internas.

La normalización DeepSeek ocurre después de recuperar el resultado de OpenParser y no
forma parte de ese job externo; puede ejecutarse nuevamente en una ejecución interna
nueva. Su proveedor, modelo, versión, páginas y lotes quedan en
`ejecuciones_trabajo.resultado_ejecucion_trabajo.normalization`. Un PDF con bytes
distintos produce otra huella y otro job de OpenParser.

La descarga del original se autoriza desde
`GET /api/v1/coordination/curricular-imports/{import}/documents/{source}/original`.
La importación debe pertenecer a la carrera activa y el documento a esa importación.
No se exponen las rutas privadas del almacenamiento ni se recupera el CRUD documental.

## Persistencia

`fuentes_academicas` conserva su nombre físico por compatibilidad: metadatos, documento
JSON completo, texto derivado, PDF vigente y estado de ingesta. Desde I-146 representa
un documento interno de la importación; no un catálogo editable por una pantalla pública.
`documentos_ia` y sus índices anteriores permanecen como estructuras históricas inactivas;
esta ingesta no los reconstruye ni los consulta.

Los trabajos usan `ejecuciones_trabajo`. PDF, XLSX e imágenes reutilizan
`objetos_almacenados` privados. La retirada no elimina documentos, evidencia ni tablas.

## Extracción de datos curriculares (I-145)

El asistente de Coordinación recibe PDF/XLSX o IDs de documentos internos ya ingeridos de su carrera.
Se admite un máximo de 10 archivos de 20 MB cada uno, con 30 MB combinados para respetar el
límite HTTP/PHP de 32 MB del despliegue. Los documentos ya almacenados no suman a ese
volumen. La misma validación se presenta antes de subir en la SPA.
`RequestCurricularImport` conserva archivos privados, comprueba capacidades y registra
una operación idempotente en `importaciones_curriculares`. El PDF reutiliza la ingesta
anterior; `CurricularWorkbookReader` lee XLSX directamente, conserva hoja/fila/celdas y
valores almacenados, sin ejecutar fórmulas ni macros. El libro original queda en el
almacén privado. Los tipos admitidos se anuncian mediante `capabilities` en el listado.

`PrepareCurricularImportJob`, en la cola `integraciones`, espera la lectura de todos
los documentos y avanza por lotes acotados. `CurricularSourceBlocks` identifica bloques
y filas de tablas con localizadores. El gateway recibe carrera, catálogo de materias,
conceptos conocidos e instrucciones de importación, y propone valores con citas a esos
bloques. Desde I-151 los conceptos conocidos son la lista cerrada que Administración
declaró en la plantilla: el agente no propone conceptos nuevos, la preparación toma la
estructura del catálogo y descarta valores de conceptos no declarados, que quedan como
observación visible. Sin conceptos declarados no se puede solicitar una importación. Cada lote actualiza cursor y progreso en `ejecuciones_trabajo`;
solo el recorrido completo publica la propuesta. Un reintento explícito de una
importación fallida usa `POST /coordination/curricular-imports/{id}/retry`.

Desde I-152 los documentos son temporales. `ReleaseCurricularImportDocuments` se ejecuta
después de confirmar una aplicación y al descartar una importación (`DELETE
/coordination/curricular-imports/{id}`, solo en estado `lista` o `fallida`). Elimina las
filas de `fuentes_academicas` y los archivos físicos (original, PDF de OCR e imágenes
`academic-sources/{id}/`) que ninguna otra importación abierta use. Las fichas de
`objetos_almacenados` permanecen porque la tabla es de solo agregar. Un nuevo envío del
mismo archivo tras una importación aplicada crea otra importación; solo un reenvío
mientras sigue abierta devuelve la existente.

La validación comprueba tipos, coincidencia de materias y que el contenido citado
exista en los bloques. La comparación normalizada exige texto no vacío y palabras
completas; «10» no valida una fuente que solo dice «100». Un código/nombre ambiguo,
valor incompatible o evidencia insuficiente queda como excepción visible. Una
discrepancia entre fuentes conserva candidatos separados: la confirmación selecciona
uno y exige aceptación explícita de esa discrepancia, sin decidir automáticamente
qué documento tiene autoridad académica.

Coordinación revisa fuente, localizador, extracto y comparación con el contenido
vigente, selecciona filas y confirma. `ApplyCurricularImport` coteja las huellas de
los documentos actuales, alcance, tipos y estado del proceso dentro de la transacción.
La huella documental es la del JSON canónico después de la ingesta; la huella del
archivo original se conserva aparte. Un cambio del documento interno invalida la propuesta anterior.
La lectura de un archivo o la respuesta del modelo nunca escribe valores curriculares
confirmados por sí sola. Catálogo, relaciones y fotografía están descritos en
`database.md`; el contrato HTTP se genera en `openapi.json`.

La extracción de I-145 tiene configuración independiente:

```text
CURRICULAR_EXTRACTION_ENABLED=false
CURRICULAR_EXTRACTION_BASE_URL=https://api.deepseek.com
CURRICULAR_EXTRACTION_MODEL=deepseek-flash
CURRICULAR_EXTRACTION_API_KEY=
```

Si esas variables no existen, `config/curricular_import.php` hereda las equivalentes
de normalización editorial. `.env.example` las declara explícitamente desactivadas.
La configuración fija límites de lote, respuesta y archivos; los servicios externos
reciben contenido de los documentos cuando se habilitan. La indisponibilidad del servicio
impide preparar nuevas propuestas y permite conservar las ya confirmadas. Habilitar
un proveedor no constituye evidencia de precisión; la prueba con documentos reales
se registra por separado en el plan I-145.

## Configuración

```text
OPENPARSER_ENABLED=false
OPENPARSER_BASE_URL=https://api.openparser.dev
OPENPARSER_API_KEY=
OPENPARSER_OCR_MODEL=paddleocr-vl-1.6
OPENPARSER_POLL_DELAY=5
OPENPARSER_MAX_POLL_MINUTES=20
OPENPARSER_FIGURE_RENDER_DPI=144
OPENPARSER_FIGURE_RENDER_TIMEOUT=60
OPENPARSER_ALLOWED_ASSET_HOSTS=api.openparser.dev

# Normalización editorial opcional posterior a OpenParser, independiente de AI_DRIVER.
AI_SOURCE_NORMALIZATION_ENABLED=true
AI_SOURCE_NORMALIZATION_DRIVER=deepseek
AI_SOURCE_NORMALIZATION_BASE_URL=https://api.deepseek.com
AI_SOURCE_NORMALIZATION_MODEL=deepseek-flash
AI_SOURCE_NORMALIZATION_API_KEY=
AI_SOURCE_NORMALIZATION_BATCH_INPUT_CHARACTERS=100000
AI_SOURCE_NORMALIZATION_MAX_OUTPUT_TOKENS=20000
```

La clave vive únicamente en el entorno. No se registran el PDF, la respuesta completa,
URLs firmadas ni credenciales. `OPENPARSER_ALLOWED_ASSET_HOSTS` limita cualquier recurso
remoto; las figuras sin un host permitido se obtienen por recorte local cuando existen
coordenadas.

La normalización habilitada envía a DeepSeek el texto OCR y, cuando están disponibles como recurso
seguro, las imágenes detectadas. No se envía el PDF completo en esa segunda etapa. El
prompt y el contenido no se escriben en logs; solo se conserva el proveedor, modelo,
versión, cantidad de lotes y páginas en la ejecución.

## Referencias oficiales

- https://www.openparser.dev/api
- https://www.openparser.dev/openparser-schema
- https://www.openparser.dev/paddleocr-api
- https://github.com/eigenpal/openparser/tree/main/packages/sdk-typescript
- https://github.com/eigenpal/openparser/blob/main/packages/schema/src/parsed-document.ts
