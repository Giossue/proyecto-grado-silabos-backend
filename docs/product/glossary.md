# Glosario

| Término | Definición |
|---|---|
| Período académico | Fechas de inicio y fin de clases. Su estado (próximo, en curso, finalizado) se calcula con la fecha actual. |
| Convocatoria | Ventana institucional para elaborar sílabos de un período. Hay una por período. La crea Administración con fecha de inicio y de entrega. Estados: abierto, pausado, cerrado. |
| Convocatoria de carrera | Parte de la convocatoria que corresponde a una carrera. La inicia Coordinación y crea un sílabo por paralelo. |
| Malla | Estructura curricular de una carrera: ciclos, asignaturas y requisitos. Hay una por carrera. |
| Ciclo | Posición de una asignatura en la malla. No es una fecha. |
| Asignatura | Materia de la malla, con código, horas (ACD, APE, AA), créditos y unidad curricular. |
| Paralelo | Grupo de una asignatura (código y jornada). Es fijo: se mantiene entre períodos hasta que Coordinación lo cambie. |
| Asignación docente | Docente vigente de un paralelo. Hay como máximo una activa por paralelo. |
| Proyecto de carrera | Pantalla de Coordinación con la ficha de cada asignatura de su malla. |
| Ficha de la asignatura | Valores de los campos de la plantilla que llena Coordinación (por ejemplo, descripción u objetivos). El sílabo copia la ficha al crearse. |
| Importación de ficha | Carga de un Excel generado desde las materias y campos actuales de Coordinación. Se completa el contenido y se revisa antes de aplicar. La vista previa se descarta al cerrar o recargar. |
| Plantilla | Definición única del sílabo: papel, estilo, variables, bloques, secciones y campos. Es un dato, no código (ADR-0011). |
| Sección | Parte numerada del sílabo, con título y orientación (vertical u horizontal). |
| Campo | Contenido dentro de una sección. Indica quién lo llena: docente, coordinación (en pantalla, «Carrera») o institucional («Fijo»). |
| Variable | Dato del sistema que se inserta en la plantilla, como el nombre de la carrera o las horas de la asignatura. |
| Bloque | Pieza con diseño, datos y reglas propias, por ejemplo `unidades` (semanas del período) o `identificacion`. |
| Sílabo | Expediente de un paralelo dentro de una convocatoria. Estados: sin iniciar, borrador, en revisión, corrección solicitada, aprobado. |
| Borrador | Contenido editable aún no enviado. Se guarda solo. |
| Revisión | Copia inmutable del sílabo creada en cada envío o reenvío. |
| Etapa | Quién tiene la revisión: coordinación o biblioteca. Se deduce de los registros; no es una columna. |
| Revisión de coordinación | Registro de que Coordinación aprobó su etapa. |
| Envío a biblioteca | Registro que pone una revisión en la bandeja de Biblioteca. |
| Corrección solicitada | Devolución al docente. Coordinación deja un comentario general; Biblioteca elige motivos predefinidos. |
| Aprobación | Decisión final sobre una revisión concreta, registrada cuando Biblioteca aprueba la bibliografía. Guarda la huella del contenido. |
| Exportación | PDF generado desde la revisión aprobada, con los sellos de las firmas. |
| Firma | Firma electrónica con un certificado `.p12` de una declaración que da el servidor, al decidir: docente al primer envío, Coordinación y Biblioteca al aprobar. Una por persona y sílabo. |
| Huella | SHA-256 que permite comprobar que un contenido o archivo no cambió. |
| Pausa | Estado que detiene la edición y el envío. Permite corregir plantilla, malla o fichas. |
| Rehacer sílabos | Borrar y volver a crear sin iniciar los sílabos afectados por un cambio confirmado durante una pausa. |
| Validación | Regla fija del sistema, como campos obligatorios u horas y semanas del bloque `unidades`. Siempre da el mismo resultado. |
| Chat de IA | Ayuda opcional para redactar. No escribe en el sílabo ni decide nada. |
| Rol activo | Rol y carrera con los que se trabaja en la sesión. Solo hay uno a la vez. |
| Ayuda de pantalla | Texto de ayuda de cada pantalla. Es fijo y está en el código del backend. |

Usa estos términos en código, interfaz y documentación. Di «revisión» para lo enviado y
«borrador» para lo editable; no uses «versión» para ninguno de los dos.
