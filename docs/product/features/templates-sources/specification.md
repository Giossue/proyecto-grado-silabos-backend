# Plantillas y fuentes

## Trazabilidad

- RF-017 a RF-033; CU-04 y CU-05.
- RN-009 a RN-016.
- ADM-05 a ADM-07; COR-11.
- PV-01, PV-02 y PV-07. PV-08 quedó cerrada en I-57 el 2026-09-08.

## Comportamiento

- La UEB mantiene una sola plantilla institucional de sílabo, común a todas las carreras.
  La carrera se fija en la convocatoria y en sus fuentes académicas, no en la estructura
  del documento. No existen catálogo, versiones ni publicación de plantillas.
- Una plantilla borrador se compone de bloques y campos tipados. En la interfaz, un
  **bloque** es una parte principal del documento (por ejemplo, «Evaluación») y un
  **campo** es un contenido dentro de ese bloque (por ejemplo, «Criterios de
  evaluación»). Las entidades de persistencia conservan su estructura interna sin
  exponerla a quien configura la plantilla.
- Las definiciones dinámicas nunca crean o eliminan tablas físicas.
- La previsualización usa datos de prueba. Cada revisión enviada conserva una fotografía
  inmutable del documento, sus campos y valores.
- Una fuente académica es un documento de la Coordinación de la carrera (I-95): nombre,
  descripción y contenido JSON estructurado. No tiene versiones ni conflictos; sus
  fragmentos de búsqueda son un índice derivado, y Administración no participa.
- Eliminar una fuente sin referencias la borra físicamente. Si forma parte de una
  convocatoria o evidencia histórica, la operación se rechaza para no romperla.
- El contenido se redacta en COR-11 como una hoja de documento visual con cinta de
  opciones (encabezados, énfasis, listas, cita, tabla con selector de tamaño, código,
  enlace, divisor y operaciones de tabla). Se guarda como JSON ProseMirror/Tiptap; el
  formato se aplica y se renderiza en tiempo real.
- Antes de iniciar su alcance, Coordinación elige un subconjunto no vacío de las fuentes
  activas de su carrera. La convocatoria fija exactamente esa selección, que no puede
  cambiarse después de iniciada; al reanudar, todas deben continuar activas. La evidencia
  de IA guarda su propia fotografía (nombre, extracto y huella del
  contenido), por lo que editar la fuente después no reescribe análisis pasados.
- Coordinación carga un PDF privado para obtener el documento mediante OpenParser con
  `paddleocr-vl-1.6` y salida `openparser@1`.
  La ruta devuelve `202`, el resultado conserva procedencia por página y luego
  se indexa en fragmentos_fuente_academica para búsqueda y citas.
- La ingesta calcula una huella SHA-256 y es idempotente. Repetir el mismo PDF no crea
  otro job Parse en OpenParser: se reutiliza el trabajo externo existente. PostgreSQL
  puede registrar una nueva ejecución interna cuando cambia la versión del normalizador,
  y DeepSeek puede volver a procesar el resultado sin repetir el OCR.
- OpenParser realiza la extracción OCR y de layout. Cuando
  `AI_SOURCE_NORMALIZATION_ENABLED=true` usa el driver `deepseek`, la
  salida se normaliza después con `deepseek-flash` por lotes y mediante un prompt que
  conserva el texto, el idioma, el orden y los saltos de página sin resumir ni inventar.
  Las tablas conservan combinaciones y las imágenes raster se conservan como recursos
  privados referenciados desde la fuente; no se promete interpretar el significado
  visual de diagramas, gráficos, fórmulas o fotografías. Si la respuesta del
  normalizador es incompleta o altera sus marcadores protegidos, la ingesta falla sin
  fallback silencioso.
- La ingesta alimenta el editor estructurado: una extracción correcta actualiza el
  contenido y la Coordinación puede revisarlo o corregirlo; una extracción fallida
  conserva el contenido anterior.
- En ADM-05 y COR-11, el listado es la vista inicial y la creación se abre desde
  una acción principal en un panel lateral derecho; los errores permanecen visibles
  hasta corregirse y el panel solo se cierra después de una respuesta exitosa.
- ADM-06 edita la única plantilla mediante un constructor visual. Su composición se
  guarda en `plantilla_silabo.documento_plantilla`; las definiciones de campo permanecen
  en `definiciones_campo` y conservan los UUID de sus bloques embebidos (I-111).

## Criterios críticos

- No se abre un proceso con marcador DOCX obligatorio sin mapeo.
- Un sílabo histórico sigue renderizando después de editar la plantilla viva.
- La evidencia de IA nunca usa una fuente inactiva ni ajena a la convocatoria.
- Modificar la plantilla con un proceso institucional abierto falla en servidor.
- Solo la Coordinación de la carrera crea o edita sus fuentes; otro rol recibe 403.
