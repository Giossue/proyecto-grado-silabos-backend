# I-144 — Matriz documental de Calidad de Software

## Estado y propósito

Matriz preparada el 2026-09-27 como apoyo del
[plan I-144](I-144-analisis-datos-curriculares-y-autollenado.md). Expone datos
observados y propuestas de tratamiento para revisar con Coordinación. No declara
vigentes las copias recibidas, no aprueba nuevos permisos ni modifica las reglas
académicas. Los ejemplos transcritos no son semillas ni cargas de producción.

La primera unidad vertical que se propone es registrar los resultados de aprendizaje
de una asignatura, vincularlos con su fuente y ubicación y permitir su confirmación
por Coordinación antes de precargarlos. El resto de la matriz identifica dependencias
y decisiones para incrementos posteriores.

Trazabilidad relacionada: RF-008..016, RF-017..033, RF-034..045; RN-005..016,
RN-020..024; RNF-001..010 y RNF-024..036; CU-03..07; COR-11, COR-13, DOC-04,
UI-01..04. Las familias CP-F de estructura, fuentes, elaboración y alcance se
concretarán al especificar la implementación. PV-02 afecta la precedencia editorial;
PV-07, el formato y la evaluación; PV-16 sigue sin conceder edición excepcional de
contenido docente. El plan principal conserva la trazabilidad completa.

## Fuentes y forma de lectura

| Clave | Documento local examinado | Localización usada |
|---|---|---|
| P | `Proyecto de Carrera Actualizado.pdf` | Páginas físicas del PDF de 34 páginas; tabla microcurricular con cabecera en p. 14 y Calidad de Software en p. 22, fila 40. |
| S | `SILABOCALIDAD_SWPAO_II_2626_signed-signed (2).pdf` | Páginas físicas 1–10, coincidentes con las impresas; Calidad de Software, agosto–diciembre de 2026. |
| H | `Guía Habilidades Blandas SOF&TI + RCD.pdf` | Tabla de Software en pp. 3–4; procedimiento de selección en p. 22. |
| X | `TIC, TAC, IA, BIBLIOGRAFÍA SW.xlsx` | Hojas y celdas indicadas en cada fila; los encabezados de `Bibliografia!B2:C2` distinguen básica y complementaria. |

Las rutas locales de consulta están registradas en el plan principal. No se incorporan
los archivos originales, firmas, nombres de responsables del ejemplar, correos,
currículos personales ni información individual sobre discapacidad a esta matriz.
Una ausencia en estas fuentes no equivale a «no aplica» ni autoriza completar el dato
mediante IA.

## Alcance y responsabilidades de la propuesta

«Reutilizable» significa candidato a dato estructurado confirmado; «selección» exige
una elección dentro de opciones pertinentes; «elaboración» requiere redacción o
planificación. Son tratamientos propuestos, salvo cuando se cita una regla vigente.

Conforme a [roles y permisos](../../product/roles-and-permissions.md), Administración
mantiene catálogos institucionales y la estructura de plantilla; Coordinación mantiene
materias, programación y fuentes de su carrera; Docencia elabora el sílabo asignado en
estado editable. La atribución de un futuro registro estructurado a Coordinación se
propone por esa responsabilidad sobre materias y fuentes; no supone que ese registro
o una acción de confirmación ya existan.

Se conservan los bloqueos del proceso y convocatoria. Cambiar una base que alimenta
el proceso vigente debe respetar I-118, aclarada el 2026-09-27 en el plan I-144 mediante
la resolución delegada por el responsable del producto: se regeneran, previa
confirmación, los expedientes en curso sin aprobación conservada, incluidos los
enviados. Los aprobados y sus revisiones enlazadas tras reapertura se preservan, así
como los procesos cerrados. Corregir resultados de aprendizaje limita el reinicio a
su asignatura. Esta decisión de producto no constituye una aprobación institucional
de la matriz ni de las fuentes. Confirmar un resultado académico no equivale
a editar directamente contenido de un sílabo ni elude PV-16. El ámbito inmediato es
Software; el diseño por carrera no confirma un despliegue en otras carreras.

## Matriz de datos y tratamiento

| Parte del sílabo | Origen y datos disponibles | Tratamiento propuesto | Alcance del dato | Quién lo mantiene según el reparto vigente | Ausencia, conflicto o decisión pendiente |
|---|---|---|---|---|---|
| 1. Identidad institucional y académica | P p. 1 y S p. 1: UEB, facultad, Software y modalidad presencial. P p. 22 y S p. 1: Calidad de Software, ciclo octavo. | Reutilizable desde los registros institucionales y la materia identificada; el documento respalda su revisión. | Institución, carrera y asignatura. | Administración: institución/carrera; Coordinación: materia de su carrera. | El nombre visible sirve para la revisión, pero la asociación futura debe usar la identidad de carrera y asignatura; no emparejar automáticamente materias homónimas. |
| 1. Código y relaciones entre materias | S p. 1: `SW-IC8-039`; prerrequisito Seguridad del Software; correquisito Ninguno. P p. 22 no contiene estos campos. | Reutilizable después de verificar contra la estructura académica vigente. | Asignatura y relaciones con otras asignaturas. | Coordinación, como mantenimiento de materias. | No interpretar la fila 40 de P como código institucional ni inferir el prerrequisito desde la cercanía de filas. El ejemplar de sílabo no demuestra por sí solo autoridad del dato. |
| 1. Unidad de organización curricular | P p. 22 y H p. 4: Integración Curricular; S p. 1: Unidad Profesional marcada. | Reutilizable solo tras conciliación editorial. | Asignatura dentro de la carrera. | Coordinación mantiene el atributo de la materia. | Conflicto abierto. DT-15 exige una unidad por materia y no permite deducirla del ciclo. PV-02 conserva la decisión editorial de la fuente que corresponde aplicar. |
| 1. Horas y créditos | P p. 22 y S p. 1: ACD 32, APE 32, AA 32, total 96. S p. 1: 2 créditos. | Reutilizable desde la materia confirmada; aplicar la regla vigente de I-57 para horas/créditos. | Asignatura; fotografía en el sílabo. | Coordinación mantiene la materia; Docencia distribuye las horas sin alterar los totales académicos. | La coincidencia de esta asignatura no resuelve la discrepancia entre los totales globales de P pp. 2 y 22. |
| 1. Periodo y oferta | S p. 1: agosto–diciembre de 2026, paralelo A, jornada vespertina. | Reutilizable desde periodo, programación y paralelo actuales; no copiar el periodo del PDF a futuras convocatorias. | Periodo y oferta de la asignatura. | Administración: periodo/proceso; Coordinación: programación y paralelo de su carrera. | Las fechas precisas y la oferta vigente se obtienen del contexto académico del sistema; el ejemplar solo acredita su propia oferta. |
| 2. Descripción | S p. 1 presenta el carácter teórico/práctico, calidad, procesos, métricas y satisfacción del cliente; integra habilidades blandas. La fila de P no aporta una descripción de la materia. | Elaboración docente con resultados, mínimos y selecciones confirmados como contexto. | Asignatura y contenido del sílabo para su periodo. | Docencia elabora; Coordinación revisa conforme al flujo vigente. | No se ha acreditado un texto común obligatorio. El texto del ejemplar puede servir de referencia, sin convertirlo en precarga institucional. |
| 3. Objetivos | S p. 2 contiene un objetivo general y cuatro específicos. P p. 6 contiene objetivos de la carrera, no esos objetivos particulares de la asignatura. | Elaboración docente; mantener diferenciados objetivos de carrera y de asignatura. | Carrera para los objetivos del proyecto; asignatura/sílabo para los del ejemplar. | Coordinación mantiene la fuente de carrera; Docencia elabora los objetivos del sílabo. | No sustituir objetivos de la materia por los de carrera ni deducirlos de los resultados como hechos ya aprobados. |
| 4. Resultados de aprendizaje de la asignatura | P p. 22 y S p. 2 coinciden en cuatro resultados; transcripción abajo. | Reutilizable. Primera vertical propuesta: registro ordenado, fuente/localización, revisión y confirmación de Coordinación antes de precargar. | Asignatura de una carrera; copia en el contexto del sílabo que corresponda. | Coordinación como responsable propuesta del registro curricular, sin conceder edición excepcional del sílabo. | P p. 2 mantiene vacíos fecha/número de resolución. La coincidencia de textos no acredita vigencia. Si falta confirmación, no presentar el dato como curricular confirmado. |
| 5. Habilidades blandas | H p. 4 agrupa Liderazgo, Autocontrol y Toma de Decisiones en Integración Curricular, e indica cuatro transversales. S p. 2 enumera Trabajo en equipo y Toma de decisiones; pp. 1–2 mencionan también Comunicación efectiva. | Reutilizable para definiciones y elegibilidad una vez revisadas; selección docente para la asignatura, descrita en H p. 22. | Catálogo/agrupación por carrera; selección por asignatura y periodo. | Coordinación mantiene la fuente y revisaría las asociaciones propuestas; Docencia selecciona y planifica. | No asociar cada habilidad a una materia según la alineación de una celda combinada. Límite «1 o 2», inclusión de transversales y efecto del conflicto de unidad curricular pendientes. |
| 6. Contenidos mínimos y unidades | P p. 22 enumera seis mínimos. S pp. 3–6 los desarrolla en cuatro unidades, con un resultado por unidad; detalle abajo. | Reutilizable para mínimos confirmados; elaboración para organización, temas, subtemas, semanas y actividades. | Mínimos de la asignatura; planificación del sílabo en un periodo. | Coordinación mantiene la fuente académica; Docencia planifica según I-57 vigente. | Seis mínimos no equivalen a seis unidades. P no fija un número; I-57 admite cualquier cantidad. Una cantidad institucional fija requeriría resolver explícitamente ese cambio. |
| 6 y 8. APE | S pp. 3–6: horas APE por unidad 10, 10, 12 y 0; pp. 3 y 5 mencionan guías PAE 1 y 2; p. 8 incluye 2 puntos APE por parcial. | Reutilizable para el total de la materia; elaboración para prácticas y distribución. Una futura condición se definiría sobre un dato preciso y una regla aprobada. | Total de asignatura; planificación/evaluación del sílabo. | Coordinación mantiene horas de la materia; Docencia planifica y completa evaluación según el formato vigente. | «Guía APE = 0» no identifica si son horas, cantidad de guías o aplicabilidad. Cero en una unidad no elimina APE de toda la asignatura. Redistribución de puntos pendiente; no implementarla por inferencia. |
| 7. Metodología y ambientes | S p. 7: clase magistral, aprendizaje cooperativo, gamificación, aula invertida, aprendizaje basado en problemas; aula, biblioteca y aula virtual. P pp. 11–12 contiene orientaciones generales. | Reutilizable para orientaciones de carrera; selección y elaboración de su aplicación a la materia. | Carrera para orientaciones; asignatura/sílabo para aplicación. | Coordinación mantiene fuentes; Docencia elabora conforme al formato. | La presencia en el ejemplar no prueba que todos los métodos sean obligatorios en cada materia ni que deba copiarse su redacción. |
| 7. TIC/TAC | X `Herramientas de aprendizaje!A44:D44`: ofimática, CASE, IDE, simuladores, Internet, portátiles, proyector y EVEA, con descripción/orientaciones. S p. 7: portátil, teléfonos, EVEA, Lucidchart, Word y Excel. | Reutilizable para un catálogo y asociaciones revisadas; selección de los recursos aplicables pendiente de confirmar como regla del producto. | Recursos asociados a la asignatura dentro de la carrera. | Coordinación mantiene la fuente; Docencia elabora el apartado del sílabo. | La fila no establece por sí sola obligatoriedad de toda su lista. X `Herramientas de aprendizaje!C3` menciona Administración de Empresas en línea: revisar el encabezado antes de atribuirlo a Software. |
| 7. Herramientas de IA para enseñanza | X `Inteligencia artificial!A42:C42`: Google Gemini y descripción. S p. 7: Gemini para investigar. | Reutilizable como recurso académico documentado, sujeto a vigencia y aplicabilidad. | Asignatura/carrera como recurso; selección en el sílabo. | Coordinación mantiene la fuente; Docencia elabora su uso en clase. | Este recurso no selecciona el modelo ni el proveedor del agente del sistema. La descripción de capacidades del Excel no se verificó contra el servicio actual. |
| 8. Evaluación | S pp. 7–8: por parcial, ACD 2.5, APE 2.0, AA 2.0 y evaluación parcial 3.5; total 10. Presenta ponderaciones y escala cualitativa. | Elaboración conforme al formato vigente; el ejemplar aporta evidencia para revisar futuras reglas. | Esquema de evaluación del sílabo/periodo. | Docencia completa los campos; Administración configura la estructura de plantilla con la autoridad pendiente de PV-01/PV-07. | `syllabus-sections.md` establece que no se precargan porcentajes del PDF. No convertir estos números en fórmula institucional, calificaciones estudiantiles ni regla APE. |
| 9. Perfil de egreso | P pp. 7–8 aporta resultados de carrera; S p. 8 reutiliza dos y relaciona los cuatro resultados de asignatura con contribución baja, media, alta y alta. | Reutilizable para resultados de carrera; selección/valoración para relaciones y nivel de contribución, con autoridad específica por acordar. | Resultados de carrera y relación con la asignatura. | Coordinación mantiene la fuente; Docencia elabora el apartado permitido del sílabo. | La fila de P p. 22 no fija los niveles de contribución. No trasladar las valoraciones del ejemplar a otras materias. |
| 10. Conducta y ética | S pp. 8–9 contiene compromisos y remite a código de ética y políticas institucionales. | Candidato a texto institucional reutilizable si se aporta y confirma la fuente; elaboración para acuerdos permitidos por la plantilla. | Institución para políticas; sílabo para compromisos específicos. | Administración configura la plantilla; Coordinación mantiene las fuentes académicas; Docencia completa sus partes editables. | No se consultó el código referido. No convertir sanciones o redacciones del ejemplar en regla institucional vigente. |
| 11. Bibliografía | X `Bibliografia!A42:C42` contiene cuatro obras; S p. 9 contiene tres, con coincidencias y diferencias de categoría detalladas abajo. | Reutilizable para registros de obras; asociación y selección básica/complementaria sujetas a revisión. | Obra bibliográfica reutilizable y asociación con asignatura/carrera. | Coordinación mantiene la fuente; Docencia elabora el apartado del sílabo según el permiso que se acuerde. | PV-02: confirmar qué listado es obligatorio, sugerido o vigente. No elegir silenciosamente entre Excel y sílabo ni intercambiar ediciones. |
| 12. Revisión y aprobación | S p. 10 muestra espacios de revisión/aprobación del ejemplar. | Metadatos del flujo del sistema conforme a `syllabus-sections.md`; no contenido precargado desde firmas del PDF. | Revisión concreta del sílabo. | Docencia envía; Coordinación revisa y aprueba dentro de su alcance. | Una fuente firmada no aprueba un nuevo sílabo ni autoriza reproducir firmas. Los datos personales del ejemplar no se transcriben aquí. |

## Muestra para revisar los resultados de aprendizaje

Los siguientes textos corresponden a P p. 22 y S p. 2. Se conservan las palabras y
el orden de la fuente; los saltos de línea de la maquetación se sustituyen por espacios.
La numeración es una referencia de esta matriz, no un identificador curricular nuevo.

1. Comprende los fundamentos teóricos de la calidad del software.
2. Selecciona las técnicas de calidad más apropiadas para la valoración del software.
3. Selecciona y evalúa las métricas, modelos e indicadores que le permiten controlar la calidad del software a desarrollar
4. Evalúa la calidad del software.

Para la primera vertical se propone que cada registro conserve: la asignatura
identificada y su carrera; texto y orden; referencia a la fuente; ubicación verificable
en esa fuente; y evidencia de revisión/confirmación por Coordinación. Un documento
extraído o una coincidencia de nombres no constituye esa confirmación. Los nombres
de campos, estados, contrato y persistencia se concretan en la especificación de la
vertical; esta matriz no impone una tabla o máquina de estados.

La revisión debe establecer que P corresponde a la materia y es aplicable al periodo
de trabajo. S sirve como evidencia de coincidencia, sin sustituir la decisión editorial.
Si una fuente posterior difiere, se presenta la discrepancia para resolverla; no se
mezclan automáticamente resultados ni se reemplazan revisiones históricas fuera del
procedimiento vigente. La primera vertical toma la fotografía al generar el sílabo;
su corrección usa el alcance por asignatura de I-118 y preserva la evidencia aprobada
según la resolución I-144 descrita arriba.

## Contenidos mínimos frente a planificación observada

P p. 22 enumera estos seis contenidos mínimos:

1. Fundamentos de la calidad del software
2. Técnicas de la calidad
3. Mediciones de calidad del software
4. Modelos y características de la calidad del software
5. Procesos de gestión de la calidad del software
6. Aseguramiento de la calidad del software

La planificación observada en S es:

| Unidad | Nombre | Página | Resultado asociado de la muestra | ACD | APE | AA |
|---|---|---:|---:|---:|---:|---:|
| 1 | Conceptos fundamentales | 3 | 1 | 11 | 10 | 11 |
| 2 | Modelos de calidad de software | 4 | 2 | 11 | 10 | 10 |
| 3 | Métricas de calidad | 5 | 3 | 6 | 12 | 7 |
| 4 | Técnicas y herramientas de calidad de software | 6 | 4 | 4 | 0 | 4 |
| Total observado | | | | 32 | 32 | 32 |

Son los subtotales declarados en el ejemplar. No se concluye que cada mínimo sea una
unidad ni que cada resultado deba generar exactamente una unidad. Una futura ayuda de
IA podría proponer subtemas y actividades vinculados a mínimos/resultados confirmados;
no inventaría contenidos curriculares aprobados. S p. 3 menciona PMBOK en un tema de
gestión de calidad de proyectos: la anotación «bmbok», su referencia y uso por carrera
permanecen por aclarar.

## Habilidades: opciones y selección no son el mismo dato

En H p. 4, Calidad de Software figura en el grupo de Integración Curricular, cuya celda
combinada enumera Liderazgo, Autocontrol y Toma de Decisiones. Las transversales son
Trabajo en Equipo, Comunicación Efectiva, Resolución de Problemas y Gestión del Tiempo.
La tabla establece agrupaciones; su alineación visual no justifica asignar una única
habilidad a cada materia.

H p. 22 señala que el docente selecciona «la o las habilidades» para cada asignatura
durante el periodo. La reunión aporta «1 o 2», pero falta confirmar si el límite es por
docente, asignatura o sílabo y si cuenta las transversales. S enumera dos habilidades en
su apartado específico y menciona Comunicación efectiva en otros textos; ese ejemplo
no resuelve la cardinalidad ni reemplaza la regla que debe aclararse.

## Bibliografía: diferencias localizadas

La tabla resume las obras sin incorporar datos personales del ejemplar. No comprueba
disponibilidad actual de enlaces, licencias ni validez bibliográfica externa.

| Obra identificada en la fuente | Excel X, fila 42 | Sílabo S, p. 9 | Revisión necesaria |
|---|---|---|---|
| Folleto complementario docente: calidad de software, 1.ª ed., 2024 | Básica, `B42` | No figura | Determinar si se incorpora obligatoriamente, se ofrece como opción o pertenece a una actualización posterior. |
| Calidad de sistemas de información, 5.ª ed., 2019 | Básica, `B42` | Complementaria | Resolver la categoría de la asociación con esta asignatura; no es una diferencia del título de la obra. |
| Principios de aseguramiento de calidad para el diseño de software: innovación de procesos en las tecnologías de información, 1.ª ed., 2024 | Complementaria, `C42` | No figura | Confirmar aplicabilidad y obligatoriedad. |
| Proceso de desarrollo de software: basado en la articulación de RUP y CMMI priorizando su calidad, 2013 | Complementaria, `C42` | Básica | Resolver categoría; conservar identificación de la misma obra sin duplicarla por categoría. |
| Calidad de sistemas de información, 4.ª ed., 2018 | No figura | Básica | Mantener distinguida de la 5.ª edición; no fusionarlas ni sustituirlas automáticamente. |

## Validaciones pendientes y comprobación de esta entrega

- Coordinación debe confirmar fuente y aplicabilidad de los cuatro resultados antes
  de tratarlos como datos curriculares confirmados. Esa primera vertical no depende
  de resolver el límite de habilidades, número de unidades ni fórmula APE.
- La discrepancia de unidad curricular bloquea la asociación automática de habilidades
  que dependa de ella; no bloquea revisar los textos coincidentes de resultados.
- Un límite de unidades nuevo requiere resolver su diferencia con I-57; «1 o 2»
  habilidades y «APE = 0» requieren definición del ámbito y dato que condicionan.
- Bibliografía y herramientas necesitan revisión de autoridad, alcance y selección;
  un encabezado equivocado o una celda vacía no decide el valor por defecto.
- Las capacidades configurables futuras deben respetar I-75: esta matriz no habilita
  a una carrera a crear libremente atributos de materias.

Esta entrega se verifica contrastando las páginas y celdas citadas, revisando roles y
decisiones vigentes y ejecutando `git diff --check`. No cambia comportamiento, esquema,
contratos ni datos; las pruebas de aplicación corresponden al incremento que implemente
la vertical, con casos de alcance entre carreras, fuente/confirmación ausentes, orden,
precarga y conservación de la fotografía según el procedimiento vigente.
