# Identificación institucional

La sección 1 de la plantilla oficial es un solo campo con el bloque `identificacion`: la
tabla oficial con celdas combinadas. Casi todo sale del sistema; el docente solo responde
la pregunta de discapacidad. El diseño está en `blocks.identificacion` de
`resources/templates/silabo-oficial.json` y se cambia como el resto de la plantilla
(ver `docs/architecture/template-json.md`). El título y los logos no son parte de este
bloque.

## De dónde sale cada dato

| Dato | Variable | Origen |
|---|---|---|
| Facultad, carrera | `nombre_facultad`, `nombre_carrera` | Carrera de la asignatura y su facultad. |
| Modalidad | `modalidad_estudio` | Modalidad de la asignatura o, si no tiene, la de la carrera. |
| Campus | `nombre_campus` | Campus de la facultad. |
| Asignatura, código, ciclo | `nombre_asignatura`, `codigo_asignatura`, `ciclo_asignatura` | Malla. |
| Período académico | `nombre_periodo_academico` | Período de la convocatoria. |
| Paralelo y jornada | `codigo_paralelo`, `jornada_paralelo` | Paralelo del sílabo. |
| Prerrequisitos y correquisitos | `prerrequisitos_asignatura`, `correquisitos_asignatura` | Requisitos de la malla; «Ninguno» si no hay. |
| Unidad de organización curricular | `marca_unidad_*` | Unidad de la asignatura; marca una X en la fila que corresponde. |
| Horas ACD, APE, AA, total y créditos | `horas_*`, `creditos_asignatura` | Malla. |
| Nombre y correo del docente | `nombre_docente`, `correo_docente` | Docente vigente del paralelo. |
| Formación y experiencia | `formacion_docente` | Perfil del docente (Configuración › Perfil). |
| Estudiantes con discapacidad | — | Lo responde el docente: Sí/No; con Sí, tipo y breve descripción (hasta 500 caracteres). Con No se borran. |

Los datos de malla, período y paralelo se copian al sílabo cuando se crea
(`silabos.datos_academicos_silabo`). Docente y formación se leen del docente vigente.
Al enviar, la revisión congela todos los valores; Word y PDF usan esa copia.
