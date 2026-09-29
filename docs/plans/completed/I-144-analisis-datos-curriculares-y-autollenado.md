# I-144 — Datos curriculares y llenado automático del sílabo

## Estado

Primera unidad implementada, verificada localmente y subida a ambos repositorios;
migración remota aplicada y comprobada el 2026-09-27. El responsable del producto autorizó guardar y
subir el estado anterior y comenzar este plan. El punto previo quedó en `4761c10d`.
La primera unidad implementa registro de resultados de aprendizaje por asignatura,
confirmación de Coordinación y uso como dato automático de plantilla. La propuesta
completa de catálogos, condiciones e IA continúa en preparación.

La [matriz de Calidad de Software](I-144-matriz-calidad-software.md) contiene las doce
áreas del sílabo, procedencia y cuatro resultados de muestra documental. No se cargan
datos reales automáticamente ni se presupone la autoridad de los documentos adjuntos.

## Primera unidad implementada

1. En **Coordinación → Malla → acciones de una materia → Resultados de aprendizaje**,
   registrar el documento, página/apartado y resultados ordenados. Guardar exige
   confirmar que se contrastaron con esa fuente; editar invalida esa confirmación en UI.
2. El servidor exige Coordinación de la carrera, valida filas y pertenencia de IDs,
   detecta escrituras concurrentes con huella y bloquea edición con convocatoria abierta.
   La consulta permanece disponible en modo lectura.
3. La base curricular se guarda en cabecera y filas relacionadas, sin EAV ni nuevos
   campos arbitrarios de malla. Mantiene identidad de filas al reordenar, confirmador,
   fecha y procedencia; auditoría conserva huellas/conteos, no textos completos.
4. Administración puede insertar **Resultados de aprendizaje de la asignatura** desde
   **Dato automático**, en un bloque de contenido fijo, además de fuente y ubicación.
   El cambio de una plantilla existente usa su procedimiento normal de edición y pausa;
   la migración no convierte respuestas docentes ni reemplaza el formato guardado.
5. Al generar el sílabo se fotografía la base validada. El documento usa esa copia; los
   cambios posteriores del origen vivo no alteran la lectura/exportación histórica.
   Si el formato utiliza el dato y falta, la validación bloquea el envío y remite a
   Coordinación. Un formato que no lo utiliza conserva su funcionamiento anterior.
6. Una corrección con expedientes en curso exige la confirmación de regeneración. Se
   añadió alcance opcional por asignatura al servicio existente para preservar las otras
   materias, tanto al borrar como al volver a generar; un paralelo incompleto ajeno
   no bloquea la corrección. Los expedientes aprobados y la evidencia aprobada tras reapertura se
   conservan conforme a la resolución de I-118 indicada más abajo.

### Contrato, migración y límites

Este contrato GET/PUT fue retirado por I-147. El texto siguiente documenta la primera
entrega I-144 y no representa una ruta vigente; las tablas y fotografías perduran.

- GET/PUT `/api/v1/coordination/academic/subjects/{subject}/learning-outcomes`.
- PUT: `source_name`, `source_locator`, `outcomes: [{id?, text}]`, `confirmed: true`,
  `expected_fingerprint` (nulo en primer registro); `confirm_purge` en correcciones que
  requieran regenerar. Una huella obsoleta devuelve 409 y
  `conflicto_resultados_aprendizaje`.
- GET devuelve datos, orden/IDs, confirmación, huella y permiso efectivo de edición.
  OpenAPI se genera desde `ExportOpenApi`; no se edita el JSON manualmente.
- Migración nueva `2026_09_27_000144_create_subject_learning_outcomes`: cabecera
  `bases_resultados_asignatura` y filas `resultados_aprendizaje_asignatura`. FK, orden
  único por base, textos no vacíos y fechas UTC. Aplicada en bases locales de
  prueba/demostración y en la base remota `silabos_ueb_db`, lote 72.
- Primer registro manual estructurado; no se añadió extracción semántica automática de
  PDF/Excel. El nombre y localizador son procedencia declarada por Coordinación, sin
  validar automáticamente vigencia/aprobación de ese documento.
- Las tres variables nuevas usan el catálogo existente. No se implementó todavía un
  catálogo universal de conceptos nuevos configurable sin código.
- No se agregaron límites académicos de habilidades, reglas APE ni cantidad de unidades.
  Tampoco cambió el alcance de lectura de IA: los datos automáticos en bloques fijos no
  entran necesariamente en su contexto; la generación de unidades será otra unidad.

### Resolución del alcance de I-118, 2026-09-27

El responsable del producto delegó resolver las decisiones de implementación. Con esa
instrucción se concilia I-118 con la conservación de evidencia aprobada: las correcciones
confirmadas durante una pausa regeneran los expedientes afectados en curso que no
conservan una aprobación. Los enviados sin aprobación siguen dentro del alcance.
Se preservan los aprobados, sus revisiones y exportaciones, incluidos los expedientes
reabiertos que conservan una revisión aprobada; también los procesos cerrados.
La corrección de resultados de aprendizaje se limita a la asignatura modificada.

Esta resolución delegada sustituye las frases documentales que incluían aprobados en
la purga. No se atribuye al tutor ni a una autoridad institucional y no resuelve
cardinalidad de habilidades, reglas APE o cantidad de unidades. Las comprobaciones y
el estado de despliegue se registran en la sección de verificación, con su evidencia.

## Trazabilidad

- RF-008..016: estructura académica; RF-017..033: plantilla y fuentes;
  RF-034..045: elaboración; RF-046..054: IA.
- RN-005..016, RN-020..024, RN-028..030.
- RNF-001..010: alcance y privacidad; RNF-024..028: integridad y trazabilidad;
  RNF-029..036: mantenibilidad e IA.
- CU-03..08; ADM-06, COR-11, COR-13, DOC-04, DOC-06; UI-01..04.
- Familias CP-F01..35 y CP-N01..16: asignar los casos específicos del Plan Maestro
  cuando se acuerden criterios nuevos; no se inventan correspondencias individuales.
  IA-NEG-01..09 sigue siendo referencia para robustez del agente.
- PV-01: autoridad de plantilla; PV-02: precedencia de fuentes; PV-07: formato y
  reglas de salida; PV-14/PV-18: corpus/modelo y aceptación de IA; PV-16 continúa
  sin autorizar edición excepcional de sílabos por Coordinación.

## Resultado demostrable del análisis

Explicar con Calidad de Software qué se puede recuperar de los documentos, qué requiere
selección o elaboración y qué necesita el sistema para incorporar nuevos contenidos
configurables. Mantener el piloto en Software; otras carreras son una consideración del
modelo, no una ampliación confirmada del despliegue.

## Fuentes examinadas

Páginas físicas del PDF; en el sílabo coinciden con las impresas. Las rutas siguientes
son referencias locales de la sesión, no archivos incorporados al repositorio.

1. `temp/SILABOCALIDAD_SWPAO_II_2626_signed-signed (2).pdf`.
2. `/home/giossue/Descargas/Proyecto de Carrera Actualizado.pdf`.
3. `/home/giossue/Descargas/Guía Habilidades Blandas SOF&TI + RCD.pdf`.
4. `/home/giossue/Descargas/TIC, TAC, IA, BIBLIOGRAFÍA SW.xlsx`.

La reunión relatada por el usuario aporta hipótesis de cambio. No convierte los detalles
ambiguos en reglas institucionales confirmadas. No se midió el porcentaje de contenido
copiado.

## Evidencia y límites

| Aspecto | Evidencia | Consecuencia |
|---|---|---|
| Proyecto de carrera | Contiene identidad, objetivos, perfil de egreso y descripción microcurricular. Calidad de Software está en p. 22, fila 40; cabecera de la tabla en p. 14. | Es fuente de datos de carrera y asignatura. Esta copia conserva fecha/número de aprobación vacíos en p. 2: no afirmar vigencia o aprobación sin comprobarla. |
| Resultados y horas | Los cuatro resultados de Calidad de Software coinciden con el sílabo p. 2. Ambos documentos indican ACD 32, APE 32, AA 32 y total 96. | Candidatos claros a importación validada y precarga determinística. |
| Contenidos y unidades | El proyecto p. 22 enumera seis contenidos mínimos. El sílabo pp. 3–6 desarrolla cuatro unidades: Conceptos fundamentales; Modelos de calidad de software; Métricas de calidad; Técnicas y herramientas de calidad de software. | El proyecto no establece explícitamente cantidad de unidades ni equivalencia contenido = unidad. No contar viñetas o resultados para imponer unidades. |
| Perfil de egreso | El sílabo p. 8 reutiliza resultados del proyecto pp. 7–8. La fila de la asignatura no fija los niveles de contribución alta/media/baja. | Separar el catálogo de resultados de la relación y valoración por asignatura. |
| Habilidades elegibles | Guía pp. 3–6: habilidades por carrera y unidad de organización curricular, más transversales. P. 4 ubica Calidad en Integración Curricular; celda combinada con Liderazgo, Autocontrol y Toma de Decisiones. | Modelar el grupo y sus relaciones; no asociar habilidades a materias por mera alineación visual de una celda combinada. |
| Habilidades transversales | Trabajo en Equipo, Comunicación Efectiva, Resolución de Problemas y Gestión del Tiempo. P. 22 dispone selección por asignatura. | Ofrecer opciones pertinentes y conservar la selección del sílabo; elegibilidad no equivale a elección automática. |
| Cantidad de habilidades | La guía dice «la o las habilidades»; no se localizó máximo dos. El sílabo enumera dos en p. 2, pero también menciona Comunicación efectiva en otros textos. | Validar si 1–2 aplica a cada sílabo, asignatura o docente, y si incluye transversales. |
| Recursos por materia | Excel: `Herramientas de aprendizaje!A44:D44`, `Inteligencia artificial!A42:C42`, `Bibliografia!A42:C42` corresponden a Calidad. | Separar herramientas, obras y asociaciones a asignatura; conservar tipo, orden y procedencia. La herramienta IA de aprendizaje de una materia no configura el agente del sistema. |
| APE | Sílabo: horas APE por unidad 10 + 10 + 12 + 0; evaluación p. 8 conserva 2 puntos APE por parcial. | Cero por unidad no elimina evaluación APE de la asignatura. «Guía APE = 0» necesita definición; no inventar redistribución de puntos. |
| Cuerpo de conocimiento | El sílabo p. 3 menciona PMBOK dentro de Gestión de la calidad de los proyectos. | Confirmar si es la referencia anotada como «bmbok», además de edición, materias y uso. No aplicarla universalmente por nombre de carrera. |

### Conflictos entre documentos que requieren resolución editorial

- Proyecto p. 22 y guía p. 4: Integración Curricular; sílabo p. 1: Unidad Profesional
  marcada. PV-02 impide seleccionar silenciosamente qué fuente manda.
- Excel `Herramientas de aprendizaje!C3` menciona Administración de Empresas en línea,
  aunque las filas contienen materias de Software. No inferir la carrera del encabezado.
- La bibliografía de Calidad en el Excel y en el sílabo p. 9 difiere en títulos y
  clasificación básica/complementaria. Validar obligatoriedad, vigencia y posibilidad de
  selección antes de imponerla.
- Las tres hojas del Excel contienen 43 materias; faltan TIC/TAC en dos, IA en seis y
  bibliografía en cinco. Ausencia no demuestra «no aplica».
- El resumen global del proyecto p. 2 y los totales de su tabla p. 22 difieren en
  componentes horarios. Una carga de toda la carrera necesita conciliación previa.

## Capacidades reales del sistema revisado

- El constructor permite estructura variable y respuestas tipadas. I-138 distingue
  texto fijo, datos automáticos y campos del docente.
- `config/syllabus_variables.php` es un catálogo PHP cerrado; añadir un campo visual
  no registra automáticamente un nuevo origen académico.
- `app/Modules/Configuration/Application/TemplateVariables.php` resuelve rutas
  previamente permitidas dentro de la fotografía del sílabo.
- `app/Modules/Syllabus/Application/InheritMasterValues.php` contempla herencia
  explícita desde `asignaturas`; no convierte fuentes documentales en relaciones
  curriculares.
- I-142 condiciona una parte a la igualdad de otra respuesta Sí/No o selección. No
  constituye un motor de condiciones sobre horas, carrera, fuentes o catálogos.
- Las fuentes se conservan como documentos estructurados con índice de búsqueda.
  Extraer un PDF a JSON visual no produce entidades de asignatura, habilidad u obra.
- El código ya tiene chat y contexto entre campos, aunque la especificación conserva
  texto antiguo que los presenta como pendientes. `BuildSyllabusChatContext` recupera
  campos habilitados y fuentes de la carrera. `RequestAiAnalysis::assertEligible`
  limita las sugerencias aplicables por campo a texto corto/largo editable no heredado;
  no aplica directamente una propuesta estructurada a la tabla de planificación.
  Generar esa planificación exige definir contrato y criterios de aceptación propios.

## Diseño propuesto, sujeto a validación

### Datos compartidos y relaciones

Conservar el núcleo relacional existente de carreras, asignaturas, programaciones y
paralelos. Estudiar entidades para resultados de aprendizaje, contenidos mínimos,
habilidades, herramientas y obras bibliográficas, con asociaciones explícitas y alcance
de carrera/asignatura. Una misma obra o habilidad puede asociarse a varias materias sin
duplicar su definición. No agregar listas separadas por comas ni una tabla por carrera.

Separar los contenidos mínimos de las unidades de planificación: estas pueden cubrir
varios contenidos y requieren orden, horas y actividades. La autoridad para definirlas
está pendiente. No reintroducir versiones de malla o fuentes retiradas por I-32.

Conservar documento original y ubicación de origen para cada dato importado, junto con
la validación editorial. La extracción propone; los datos validados alimentan el
llenado. Ausencias o discrepancias quedan pendientes, sin completar hechos mediante IA.

### Formato y enlaces a datos

Mantener una plantilla institucional y resolver sus valores con el contexto de carrera,
asignatura y periodo. Definir enlaces configurables desde una parte de la plantilla a
un origen permitido: dato de carrera, dato de asignatura, catálogo filtrado, selección
docente o cálculo admitido. La autoridad de configuración necesita acuerdo; hoy la
estructura de plantilla pertenece a Administración.

Para cada contenido configurable, identificar nombre, tipo, ámbito, origen, condición,
tratamiento de ausencia y permiso de edición. Tipos adicionales sencillos podrían
registrarse como definiciones/valores tipados; esto debe acordarse expresamente por su
relación con I-75. Las entidades que requieren relaciones e invariantes mantienen su
modelo relacional; JSON se reserva a estructura variable y fotografías.

Ejemplo futuro: «Competencias digitales» como selección de un catálogo autorizado por
carrera y asignatura. Registrar catálogo, asociaciones y enlace al formato permitiría
reutilizar los tipos y operaciones ya implementados. Crear solo la etiqueta no determina
qué competencias corresponden ni llena el dato.

La promesa sin cambios de código se limita a los tipos, relaciones y operaciones que el
motor soporte. Un nuevo proceso académico, integración, tipo de dato o fórmula fuera
de ese conjunto sí requiere desarrollo. Normalizar la BD por sí solo no implementa
lectura, filtros, permisos, validación ni renderizado.

### Papel de la IA

Usar datos estructurados para recuperar contenido establecido. Proponer IA para temas,
subtemas, actividades y recursos de las unidades dentro del contenido curricular
validado. El contexto incluiría carrera, asignatura, resultados, contenidos mínimos,
horas y referencias autorizadas; el nombre de la carrera por sí solo es insuficiente.

Mantener citas, previsualización, aceptación humana y degradación cuando falla la IA.
La búsqueda externa no queda autorizada ni definida por este análisis: acordar corpus
y referencias antes de incorporarla al producto.

## Decisiones anteriores afectadas

| Decisión actual | Cambio que se discute | Resolución necesaria |
|---|---|---|
| I-57 permite cualquier cantidad de unidades al docente. | La reunión sugiere cantidad derivada del proyecto. | Definir quién determina número/agrupación y con qué evidencia; el PDF examinado no resuelve la regla. |
| I-75 fija atributos de asignatura y retiró campos arbitrarios de malla. | Nuevos datos académicos configurables. | Acordar frontera entre nuevas entidades normalizadas, contenido curricular ampliable y atributos fijos. |
| I-138 exige completar campos docentes visibles y retiró reglas de cardinalidad configurables. | Selección de habilidades limitada y mayor precarga. | Definir origen, edición y validación de cada parte antes de modificar el motor. |
| Plantilla única administrada por Administración; Coordinación administra fuentes de su carrera. | Configuración de nuevos datos por carrera. | Distinguir mantenimiento de catálogos/contenido de cambios de estructura; no conceder permisos implícitos. |
| I-116/I-119 gobiernan lectura de campos por IA; recorrido actual analiza contenido existente. | Generación inicial de unidades. | Especificar entradas, salida editable, evidencia y confirmación. |

Se detectaron además descripciones documentales antiguas: `AGENTS.md` habla de fuentes
Markdown, mientras `DECISIONS_STATUS.md` y la especificación de fuentes describen JSON
estructurado; `overview.md` conserva referencias a versiones de plantilla contrarias a
I-32. La especificación de IA también describe chat/contexto cruzado como pendientes,
aunque existen en el código. Se registran sin reformar esas decisiones como parte de
esta lectura. La siguiente implementación debe conciliar la documentación afectada.

## Pasos

- [x] Leer los cuatro archivos y contrastar Calidad de Software.
- [x] Inspeccionar plantilla, variables, herencia, condiciones y decisiones vigentes.
- [x] Registrar discrepancias y límites de automatización.
- [x] Conciliar I-118 mediante resolución delegada: preservar evidencia aprobada y
      limitar el reinicio de resultados de aprendizaje a la materia corregida.
- [ ] Acordar fuente autoritativa, alcance de edición y reglas de unidades/habilidades/APE.
- [x] Elaborar una matriz de campos de una sola asignatura: origen, valor, condición,
      permiso, ausencia y evidencia. La aceptación académica de la matriz está pendiente.
- [x] Implementar la primera unidad vertical: resultados de aprendizaje registrados,
      confirmados por Coordinación y disponibles para el formato de la materia correcta.
- [ ] Tras resolver las decisiones afectadas, especificar migración, contrato API,
      pantallas, pruebas y actualización de trazabilidad; implementar en otro incremento
      concreto o actualizar este plan con alcance aprobado.

## Verificación y riesgos

El análisis inicial se verificó mediante lectura cruzada de documentos e inspección del
código. Verificación de la primera unidad el 2026-09-27:

- `composer verify`: escaneo de secretos, Pint, PHPStan y **381 pruebas / 4.443
  aserciones** aprobados en `silabos_ueb_i144_test`, PostgreSQL/Redis locales.
- Regresión final de resultados, procesos y generación: **70 casos / 738 aserciones**;
  incluye preservar aprobaciones históricas y corregir una materia aunque otra tenga
  un paralelo incompleto. Fotografía, variables y validación curricular cubiertas
  en `ConvocationAndDraftTest`.
- SPA: TypeScript aprobado; **193 pruebas aprobadas y una omitida** por la suite;
  contrato 110 consumidores / 121 operaciones; build aprobado.
- `npm run lint` completo aprobado. Los scripts de depuración `.tmp-dbg.mjs` y
  `.tmp-shot.mjs` quedaron parametrizados y sin credenciales incrustadas; se corrigieron
  las variables sin uso y se incluyeron en la comprobación global de ESLint.
- Navegador real SPA → API, base `silabos_ueb_i144_preview_test` aislada: guardar,
  reabrir los cuatro resultados de la matriz y comprobar desmarcado de confirmación al
  editar/reordenar. Sin errores JavaScript ni respuestas HTTP fallidas. Escritorio
  1440 px y móvil 360 px, claro/oscuro, sin desbordamiento horizontal. Capturas y reporte
  locales en `/tmp/i144-preview-artifacts/`; no son activos del repositorio.
- Comprobación pura de exportación: cuatro resultados producen cuatro textos y tres
  saltos de línea en Word, y cuatro líneas en PDF de texto; las variables históricas
  se resuelven exclusivamente desde la fotografía.
- `git diff --check` aprobado.

### Migración remota y push autorizados, 2026-09-27

El responsable autorizó resolver los pendientes de esta entrega, migrar si fuera
necesario y subir ambos repositorios. La comprobación previa de la conexión remota confirmó
`current_database() = silabos_ueb_db` y `current_user = silabos_ueb_app`, 43 sílabos,
cero aprobaciones y la migración I-144 pendiente. La base de Calidad Software es
otra aplicación y no participa en este despliegue.

Se obtuvo un respaldo PostgreSQL en formato custom fuera del repositorio, con permisos
`0600`, actualizado antes de ejecutar la migración en
`~/.local/state/silabos/backups/silabos_ueb_db-pre-i144-direct-20260927.dump`.
Archivo de 490.118 bytes; `pg_restore --list` leyó su índice correctamente. SHA-256:
`706c9091e2e1c0382864915e9485e814025b2e67ea4bc653cb0456202a40c95b`.
Esto verifica la lectura del respaldo, no un ensayo de restauración.

El usuario corrigió el procedimiento: este proyecto migra directamente desde la
estación autorizada mediante Laravel y `~/.pgpass`, conforme a
`docs/security/hardening.md`. Se había aplicado indebidamente la regla de ejecución
en contenedor de Calidad Software a Sílabos. Se restauró la sección de migraciones
remotas al contenido anterior a `34055f01`; SSH y el acceso al contenedor de Dokploy
no son requisitos para esta migración.

La ejecución usa `DB_PASSWORD='(null)'` para que libpq resuelva la autenticación desde
`~/.pgpass`, comprueba el destino y sigue estado → renombrado idempotente de la tabla
de control → migrador con bloqueo → estado. El comando de renombrado confirmó que
`migraciones` ya existía; `php artisan migrate --force --isolated` ejecutó únicamente
I-144 y terminó correctamente. `migrate:status` posterior la muestra aplicada en
el lote 72. La conexión efectiva de Laravel coincidió con la base y el usuario
comprobados mediante PostgreSQL.

La comprobación posterior verificó las dos tablas, sus claves primarias y foráneas,
unicidad y restricciones de contenido/posición. Ambas tablas nuevas están vacías;
se conservan los conteos anteriores de 43 sílabos y cero aprobaciones. La migración
no carga contenidos ni modifica registros de negocio existentes.

Código subido y verificado contra `origin/main`: backend `34055f01`, frontend
`940f983`. Esta evidencia acredita el push y la migración de base; no acredita
la versión servida por HTTP ni la salud del despliegue de aplicación.

Una futura implementación debe probar alcance entre carreras, coincidencia por identidad
de asignatura (no solo nombre), diferencias de fuentes, ausencia de datos, cardinalidad
de selecciones, condiciones en servidor/exportación y fotografías históricas. Respetar
el flujo normal de inmutabilidad y la excepción administrativa explícita I-118.

Riesgos principales: convertir ejemplos en reglas, interpretar mal celdas combinadas,
importar una versión no validada, asignar datos a otra carrera y prometer generación
automática de hechos que la fuente no contiene. La migración es aditiva; no cambia datos
curriculares existentes ni siembra contenidos. La recuperación en entornos compartidos
se realiza mediante corrección hacia delante, sin rollback destructivo automático.
