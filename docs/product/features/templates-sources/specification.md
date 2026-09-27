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
- Desde I-124 la convocatoria usa todas las fuentes activas de la carrera; se retiró
  la selección por convocatoria. La selección de documentos de I-145 pertenece a una
  operación de importación curricular y no cambia ese alcance de asistencia. La evidencia
  de IA guarda su propia fotografía (nombre, extracto y huella del
  contenido), por lo que editar la fuente después no reescribe análisis pasados.
- Coordinación carga un PDF privado para obtener el documento mediante OpenParser con
  `paddleocr-vl-1.6` y salida `openparser@1`.
  La ruta devuelve `202`, el resultado conserva procedencia por página y luego
  se indexa en `documentos_ia` para búsqueda y citas (I-135).
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
- La plantilla tiene tres piezas (I-138): **texto fijo** que nadie llena, **datos
  automáticos** (variables del catálogo, incluidas las de firma que se completan al
  exportar) y **campos del docente**. El bloque «Contenido fijo (el docente no llena)»
  nace sin campos; una tabla del editor se convierte en contenido fijo con «Convertir en
  contenido fijo». Indicadores de evaluación y escala de valoración vienen fijos con los
  valores UEB. Las tablas del docente son fijas, con filas o por unidad.
- Todo el sílabo lo llena el docente salvo el contenido fijo: no existe «Obligatorio»
  configurable ni límites de caracteres o filas. Al enviar, cada campo vacío bloquea con
  ««Campo» está vacío. Complétalo antes de enviar.»; el borrador se guarda siempre.

## Criterios críticos

- I-144 añade al catálogo automático resultados de aprendizaje de la asignatura,
  nombre de su fuente y ubicación. Administración puede insertarlos en contenido fijo;
  el valor sale de la fotografía académica del sílabo. Los resultados se muestran
  numerados en su orden confirmado. No se convierte automáticamente una parte editable
  existente ni se sustituye una plantilla durante la migración.
- Si una plantilla utiliza esa variable sin resultados fotografiados, la validación
  indica que Coordinación debe corregir la base.
- I-145 permite importar conceptos documentales de texto/lista/tabla desde PDF/XLSX
  y fuentes de la carrera. La propuesta conserva evidencia, materia y excepciones;
  solo la selección confirmada se convierte en contenido vigente. Agregar un concepto
  admitido amplía el catálogo automático `curricular_{clave}` sin código por campo.
  Los datos automáticos anteriores de resultados/fuente/ubicación priorizan la lista
  importada `resultados_aprendizaje` de la asignatura dentro de la fotografía; si falta,
  conservan el origen I-144. Esto permite usar las plantillas existentes sin duplicar
  resultados en ambas bases.
- Administración puede vincular campos de selección a una o varias listas curriculares.
  El catálogo docente se resuelve desde la fotografía de su sílabo, permite combinar
  listas de asignatura y carrera y elimina opciones repetidas. Si falta un origen
  requerido, la validación informa el dato curricular pendiente y no ofrece una unión
  incompleta. El servidor rechaza valores fuera de las opciones fotografiadas.
- Las propuestas muestran el dato vigente y el candidato para revisar reemplazos.
  Evidencia inválida, tipos incompatibles y conflictos estructurales bloquean su fila;
  una materia ambigua permite asociación explícita dentro de la carrera. Una
  discrepancia documental exige elegir una fila y aceptar la decisión. Esto no fija
  precedencia institucional ni resuelve cardinalidad de habilidades o reglas APE.

- No se abre un proceso con marcador DOCX obligatorio sin mapeo.
- Un sílabo histórico sigue renderizando después de editar la plantilla viva.
- La evidencia de IA nunca usa una fuente inactiva ni ajena a la convocatoria.
- Modificar la plantilla con un proceso institucional abierto falla en servidor.
- Un campo opcional vacío no bloquea el envío; un texto sobre su máximo o una lista fuera
  de su rango de filas sí, con un mensaje que nombra el campo y el valor actual.
- Solo la Coordinación de la carrera crea o edita sus fuentes; otro rol recibe 403.
