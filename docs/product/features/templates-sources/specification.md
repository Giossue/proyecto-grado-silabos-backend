# Plantilla y datos curriculares

## Trazabilidad

- RF-017 a RF-026 y CU-04; I-145 conserva la procedencia de importaciones.
- RF-027 a RF-033, CU-05 y COR-11 corresponden al CRUD de fuentes retirado en I-146.
- RN-009 a RN-016.
- ADM-05 a ADM-07; asistente de importación curricular de Coordinación.
- PV-01, PV-02 y PV-07. PV-08 quedó cerrada en I-57 el 2026-09-08.

## Comportamiento

- La UEB mantiene una sola plantilla institucional de sílabo, común a todas las carreras.
  La carrera se fija en la convocatoria y en sus datos curriculares, no en la estructura
  del documento. No existen catálogo, versiones ni publicación de plantillas.
- Una plantilla borrador se compone de bloques y campos tipados. En la interfaz, un
  **bloque** es una parte principal del documento (por ejemplo, «Evaluación») y un
  **campo** es un contenido dentro de ese bloque (por ejemplo, «Criterios de
  evaluación»). Las entidades de persistencia conservan su estructura interna sin
  exponerla a quien configura la plantilla.
- Las definiciones dinámicas nunca crean o eliminan tablas físicas.
- La previsualización usa datos de prueba. Cada revisión enviada conserva una fotografía
  inmutable del documento, sus campos y valores.
- I-146 retira Fuentes académicas como módulo de gestión, editor y requisito para abrir
  una convocatoria. No hay CRUD público de fuentes ni recuperación vectorial del revisor.
- Los PDF/XLSX entregados al asistente I-145 y sus documentos JSON internos son
  temporales (I-152): existen mientras la importación está abierta y se eliminan al
  guardar o descartar, salvo que otra importación abierta los use. No se reutilizan en
  otras importaciones; cada dato confirmado conserva extracto, ubicación y nombre del
  documento.
- I-147 concentra la consulta de datos efectivos en «Datos confirmados» del asistente.
  Los resultados importados prevalecen sobre el registro I-144; este permanece visible
  como respaldo donde aún no haya importación. El menú de materia ya no ofrece su
  editor manual. La procedencia antigua muestra documento y ubicación sin inventar
  un extracto.
- Coordinación confirma por lote los datos extraídos; la evidencia original y las
  selecciones se conservan para revisión y auditoría. La descarga del original está
  autorizada mediante la importación y la carrera activa.
- ADM-06 edita la única plantilla mediante un constructor visual. Su composición se
  guarda en `plantilla_silabo.documento_plantilla`; las definiciones de campo permanecen
  en `definiciones_campo` y conservan los UUID de sus bloques embebidos (I-111).
- La plantilla tiene tres piezas (I-138): **texto fijo** que nadie llena, **datos
  automáticos** (variables del catálogo, incluidas las de firma que se completan al
  exportar) y **campos del docente**. El bloque «Contenido fijo (el docente no llena)»
  nace sin campos; una tabla del editor se convierte en contenido fijo con «Convertir en
  contenido fijo». Indicadores de evaluación y escala de valoración vienen fijos con los
  valores UEB. Las tablas del docente son fijas, con filas o por unidad.
- El docente completa los campos editables; el contenido fijo y los datos automáticos
  se resuelven desde la plantilla y la fotografía académica: no existe «Obligatorio»
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
  y documentos previos de ingesta de la carrera. La propuesta conserva evidencia, materia y excepciones;
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
- Modificar la plantilla con un proceso institucional abierto falla en servidor.
- Los campos editables vacíos bloquean el envío; un borrador incompleto puede guardarse.
- Solo Coordinación solicita y confirma importaciones dentro de su carrera. La retirada
  del CRUD de fuentes no concede acceso a archivos ni importaciones de otra carrera.
