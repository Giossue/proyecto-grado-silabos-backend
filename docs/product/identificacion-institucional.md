# Ficha de identificación institucional

> Plantilla oficial fiel al PDF de referencia (2026-10-07): la sección 1 es «Identificación Institucional»
> y muestra **solo la tabla oficial**. Discapacidad (tipo y breve descripción) y «Formación y experiencia académica –
> investigativa» son tres campos `texto` con `config.inside = identification_card`: el docente los escribe y sus valores se
> inyectan en la tabla (`IdentificationCard::INPUT_KEYS`), pero no se repiten como campos aparte en la hoja del constructor,
> en las revisiones ni en el Word (`sectionFields` y `WordFieldRenderer::renderTyped`). En la pantalla del docente se piden
> justo debajo de la tabla, con una nota de que se imprimen dentro de ella. Sin la pregunta Sí/No, «Estudiantes con
> discapacidad» marca Sí si hay tipo y No si no. La API de plantilla entrega `document` de la tabla con
> `IdentificationCard::sample()` para dibujarla en la hoja. El diseño oficial «Identificación Institucional» del catálogo de
> «Nueva sección» crea la tabla y los tres campos juntos. Pendiente: editar esos tres datos directamente en las celdas.
>
> I-195, etapa 5: en la plantilla inicial oficial la ficha es un campo `automatico` (fuente
> `identification_card`, sin valor guardado). Discapacidad (tipo y adaptación) y formación y
> experiencia son campos `texto` sueltos de la misma sección, siempre visibles; ya no hay tabla
> editable ni campo condicional. Lo que sigue describe la fuente de datos (`IdentificationCard`),
> que no cambia, y el diseño editable anterior, que se retira en la etapa 8.

La primera tabla parte del formato institucional. Administración puede modificarla
desde «Editar diseño» (I-56), sin cambiar código para mover celdas o etiquetas. Las
variables se llenan automáticamente; los espacios de discapacidad y formación los
completa Docencia. El diseño queda guardado dentro de la plantilla única.

## Fuentes de datos y formato inicial

`app/Modules/Syllabus/Application/IdentificationCard.php`

- `build(...)`: reúne los **valores** (de dónde sale cada dato).
- `grid(...)`: coloca los valores en la **cuadrícula** del formato oficial (9 columnas,
  celdas combinadas). Cada fila es una lista de celdas con texto, columnas que abarca
  (`span`), filas que abarca (`rows`) y estilo.
- `WIDTHS`: anchos de las 9 columnas, ajustados para que las etiquetas largas (Campus, Modalidad) no se partan tanto.

`TemplateDocumentDefaults::identification` adapta esa cuadrícula inicial a un documento
editable, reemplazando datos por variables y campos. Se usa sin escritura cuando no
hay diseño guardado. I-148 retira los lectores de cuadrícula fija (`IdentificationCard.vue`,
el lector Word de identificación y la cuadrícula en las API de docente y revisión): el
documento, la revisión y la exportación dibujan solo el diseño guardado en la plantilla.
`grid` queda como generador de la plantilla inicial y del ejemplo del constructor.

## De dónde sale cada dato

| Celda del formato | Clave en `build` | Origen |
|---|---|---|
| Facultad | `faculty` | `carreras.facultad_id` → `facultades.nombre` |
| Carrera | `career` | `mallas.carrera_id` → `carreras.nombre` |
| Modalidad de estudio | `modality` | `programaciones_asignatura.modalidad` (valor fijo del RRA, heredado de la materia o de la carrera al programarla; I-35, I-37, I-62) |
| Campus universitario | `campus` | Fotografía del campus de la facultad de la carrera, tomada al crear o regenerar el sílabo (I-164) |
| Asignatura | `subject` | `asignaturas.nombre_asignatura` |
| Periodo académico | `period` | `programaciones_asignatura.periodo_academico_id` → `periodos_academicos.codigo_periodo_academico` |
| Ciclo | `cycle` | `asignaturas.ciclo_asignatura` (número → «Séptimo») |
| Paralelo | `parallel` | `paralelos.codigo_paralelo` del paralelo del expediente (`silabos.paralelo_id`) |
| Jornada | `shift` | `paralelos.jornada_paralelo` (matutina, vespertina, nocturna) |
| Código | `code` | `asignaturas.codigo_asignatura` |
| Prerrequisitos | `prerequisites` | `requisitos_asignatura` con `tipo_requisito_asignatura = prerrequisito` → código de la materia requerida |
| Correquisitos | `corequisites` | `requisitos_asignatura` con `tipo_requisito_asignatura = correquisito` |
| Unidad de organización curricular | `organization_unit` | `asignaturas.unidad_organizativa_curricular_asignatura` (enum `CurricularUnit`: `basica`, `profesional`, `integracion_curricular`); la fotografía guarda la etiqueta y la ficha marca X en la fila que corresponda |
| Horas de docencia (ACD) | `hours_ac` | `asignaturas.horas_ac` |
| Horas prácticas (APE) | `hours_pae` | `asignaturas.horas_pae` |
| Horas autónomas (AA) | `hours_aa` | `asignaturas.horas_aa` |
| Total de horas por periodo | `total_hours` | `asignaturas.total_horas_asignatura` |
| Total, créditos | `credits` | `asignaturas.creditos_asignatura` |
| Nombre del docente | `teacher` | `docentes_vigentes_silabo` → `usuarios.nombre_usuario` |
| Correo institucional | `email` | `docentes_vigentes_silabo` → `usuarios.correo_electronico` |
| Estudiantes con discapacidad (Sí/No, tipo, adaptación) | `disability`, `disability_type`, `disability_description` | Lo escribe el docente: campos `discapacidad_tiene`, `discapacidad_tipo`, `discapacidad_adaptacion` del bloque de identificación (`valores_campo`) |
| Formación y experiencia académica-investigativa | `formation` | Lo escribe el docente: campo `formacion_experiencia` del mismo bloque; última fila de la tabla |

En la pantalla del docente, mientras el sílabo se puede editar (borrador o corrección solicitada y sin
convocatoria en pausa), la etiqueta «FORMACIÓN Y EXPERIENCIA ACADÉMICA – INVESTIGATIVA:» de la última fila es un
botón con aspecto de enlace (`aria-label` «Editar formación y experiencia») que abre un modal con el mismo
campo del Perfil (`TeacherFormationField`, `useTeacherFormation`). Guardar llama a `PUT authentication/formation`
(`UpdateTeacherFormation`), que actualiza el perfil y los sílabos aún editables, y la página recarga el sílabo para
mostrar el texto nuevo. En revisión, enviado o aprobado la etiqueta sigue siendo texto plano.

Los campos que llena el docente viven en el mismo bloque «Identificación institucional»
de la plantilla (`IdentificationCard::INPUT_KEYS`). Con diseño guardado se completan
dentro de sus celdas; el lector anterior los sigue mostrando debajo de la ficha.

Los datos de la malla y la programación llegan por `contexto_academico` (copiado al abrir la
convocatoria por `AcademicContextSnapshot`). El código y la jornada del paralelo se guardan en esa
fotografía (`parallel`, desde I-209; los sílabos anteriores los leen del paralelo vivo); los
docentes se leen del expediente en el momento. Al enviar una revisión, la ficha ya armada se guarda dentro
de la copia (`fotografia.identification`). Además se guardan el diseño de cada bloque
y `fotografia.template_variables`; la revisión y el Word no consultan el catálogo vivo
para reemplazar las variables de una revisión histórica.

## Cómo cambiar algo

- **Etiqueta, color o celdas**: Administración abre «Editar diseño», cambia el contenido
  o combina/separa celdas y pulsa «Guardar diseño».
- **Dato automático**: seleccionar una celda y usar **Insertar → Dato automático**;
  por ejemplo, `nombre_carrera`, `nombre_facultad`, `nombre_docente` o
  `correo_docente`. Se representa con `@` y el docente no lo escribe.
- **Respuesta manual**: usar **Insertar → Campo del docente** para reutilizar uno o
  crear un campo con nombre descriptivo y tipo de respuesta. La clave técnica se genera.
- **Condición Sí/No u otra selección**: crear o reutilizar un campo de selección y usar
  **Insertar → Marca condicional** en la celda que debe mostrar `X`. La condición solo
  compara una opción; no acepta fórmulas ni código.
- **Nueva variable disponible**: editar únicamente `config/syllabus_variables.php` si
  la fuente ya está en la identificación o en el contexto académico. Cada entrada
  define clave descriptiva, etiqueta y `source`, por ejemplo `identification.career`.
  `equals` permite marcas X de opciones ya existentes. Reconstruir la caché de
  configuración en despliegue si está habilitada. No se cambia código Vue.
- **Nuevo dato aún inexistente**: primero incorporarlo a la fuente y a su snapshot;
  agregarlo al catálogo no inventa información ni autoriza consultas arbitrarias.
- **Verificar**: `TemplateDocumentTest`, `IdentificationCardTest` y `ReviewWorkflowTest`
  cubren cuadrícula inicial, datos, guardado y copias históricas.
