# Estado de decisiones

Fecha de corte: **4 de octubre de 2026**.

## Etiquetas

- `CONFIRMADO`: acordado por los autores o establecido en la línea base.
- `PROPUESTO`: decisión técnica razonada que puede ajustarse mediante ADR.
- `POR VALIDAR`: requiere entrevistas, autoridad institucional o evidencia técnica.
- `DERIVADO`: conclusión coherente con fuentes confirmadas; debe conservar trazabilidad.

## Confirmado

- Producto centralizado, configurable y trazable para el ciclo de vida del sílabo.
- Alcance inicial: Carrera de Software UEB.
- Roles: Administrador, Coordinador, Docente y, desde I-165 (29 de septiembre de 2026), por instrucción del responsable: nuevo rol
**Bibliotecario**, sin carrera y para toda la universidad. Tras la aprobación, el docente
envía el sílabo a biblioteca, que revisa la bibliografía **antes** de las firmas (así un
comentario no anula firmas): la aprueba o comenta. Si comenta, el sílabo se reabre solo
para la bibliografía; el docente la reenvía sin nueva revisión de coordinación (revisión
nueva = copia de la aprobada con la bibliografía cambiada y aprobación heredada,
`aprobacion_base_id`) y vuelve a biblioteca. Aprobada la bibliografía, el PDF se firma en
orden docente → coordinación → biblioteca. La bibliografía la propone el docente (campos
de Texto libre). La plantilla gana `firma_docente`, `firma_coordinador` y
`firma_bibliotecario`, que marcan dónde va cada sello. Migraciones `000162` (corrige el
trigger `validar_bloque_definicion_campo` que dejó I-164), `000163` y `000164`, aplicadas
en la base remota con respaldo previo. Pendiente en la remota: cambiar la sección
Bibliografía a Texto libre y crear la cuenta de biblioteca.

I-170 (4 de octubre de 2026), por instrucción del responsable del producto, amplía I-54:
el Coordinador corrige **nombre y correo** de los docentes vigentes de su carrera activa
desde COR-15 (acción **Editar docente**), nada más. Reutiliza las reglas administrativas:
el correo solo cambia mientras la cuenta esté pendiente de activación, y al cambiarlo se
invalida la invitación anterior y se emite otra al correo corregido; una cuenta activada
conserva su correo. No alcanza a administradores ni a cuentas ajenas a la carrera, no
toca estado, roles ni paralelos, y queda en auditoría con los valores anteriores.

I-166 (29 de septiembre de 2026), por pedido de los docentes vía el responsable: lo que va
dentro de cada sección de la plantilla se llama **campo**, no «parte», en la interfaz, los
mensajes y la base de datos. La migración `000165` renombra `bloques_plantilla` a
`campos_plantilla` y `definiciones_campo.bloque_plantilla_id` a `campo_plantilla_id`, y da
nombres explícitos a las columnas de `campos_plantilla` y `secciones_plantilla`.

I-163 (29 de septiembre de 2026), por instrucción del responsable: los avisos solo
informativos (cuenta activada, plazo extendido, proceso o convocatoria en pausa, proceso
cerrado) no llevan enlace; los que piden actuar conservan su destino. Los pasos de
«Puesta en marcha» se actualizan al sistema vigente: logo obligatorio, plantilla con Texto
libre y Contenido de la plantilla, datos del Proyecto de carrera, borrador que se inicia
solo y un paso nuevo para firmar el sílabo aprobado. Sin cambios de esquema ni de API.
El mismo día, el responsable decide que Texto libre no lleva hoja ni marcas (solo lo
escribe el docente) y que la otra forma de campo se llama «Personalizado» (antes
«Contenido de la plantilla»): ahí van tablas, datos automáticos, huecos Docente y
Proyecto de carrera.

I-158 (29 de septiembre de 2026), por instrucción del responsable: «Propiedades del
campo» solo cambia nombre y orientación. La condición «pedir solo si…» se define en la
ficha de la hoja (atributo `showWhen`) y sustituye la configuración de condiciones y
nombres de preguntas de I-153 en Propiedades. Sin cambios de esquema.

I-157 (29 de septiembre de 2026), por instrucción del responsable, simplifica COR-16:
una sola vista con el Proyecto de carrera como tabla (por asignatura y de toda la
carrera), sin pestañas, historial ni etiquetas. «Importar documentos» abre el paso a
paso en un modal que retoma la importación en curso y permite descartarla. Sin
cambios de API.

I-156 (29 de septiembre de 2026), por instrucción del responsable y su tutor, deja dos
formas de campo: **Texto libre** (el docente escribe con párrafos, negrita, cursiva,
viñetas, numeración y tablas simples; se guarda como documento JSON validado) y
**Contenido de la plantilla** (hoja vacía que Administración arma con texto, tablas,
listas y las marcas de I-155). Se retira «Filas que agrega el docente». La planificación
de unidades se va: cada docente la escribe a mano, sin sumas ni revisiones automáticas
(sustituye I-57, I-58, I-63, I-66 e I-143; I-109 se conserva para las pantallas
académicas). Segunda etapa aplicada: el formato oficial usa Texto libre en todas las
campos del docente, se retiran las filas repetibles (tabla `filas_repetibles`, tipo
`repetible`, tablas con `repeatKey`), las reglas de planificación y los atajos de
teclado. La migración `000157` convirtió lo ya escrito (una columna → lista, varias →
tabla) y se aplicó en la base remota el 29 de septiembre de 2026, con respaldo previo.
Las fotografías de revisiones anteriores no se modifican.

I-155 (29 de septiembre de 2026), por instrucción del responsable, sustituye «¿Quién
llena este campo?» (I-151, I-154) por marcas en la hoja. Un campo nuevo solo elige su
forma: párrafo, lista o tabla. En la hoja se mezclan texto fijo, **dato automático**
(definido en el código), **!Docente** (lo escribe el docente), **?Docente** (responde Sí
o No y puede condicionar otros campos) y **@ProyectoCarrera** (lo llena Coordinación al
importar). Las marcas se ponen sin preguntas: su nombre interno sale del contexto y todo
@ProyectoCarrera es por asignatura. La importación busca solo los @ProyectoCarrera que
usa la plantilla, con sus secciones como contexto. Esos datos son obligatorios como la
malla: sin su valor confirmado para la carrera o para cada materia programada no se
abre la convocatoria. Por ahora no se crean preguntas en las que el docente elige de una
lista (habilidades se tratan como @ProyectoCarrera). Sin cambios de esquema.

I-152 (28 de septiembre de 2026), por instrucción del responsable, vuelve temporales los
documentos de importación curricular. La lectura (`fuentes_academicas`), el original y
las imágenes de OCR viven mientras la importación está abierta; al guardar o descartar se
eliminan, salvo que otra importación abierta los use. `objetos_almacenados` sigue siendo
de solo agregar por regla de la base: su ficha (nombre, tamaño y huella, sin contenido)
permanece como constancia. Se agrega «Descartar importación» y se retira «Reutilizar
documentos». Sustituye la conservación de documentos de ingesta de I-145 y la descarga
permanente de originales de I-146; los datos confirmados conservan extracto, ubicación y
nombre del documento.

I-151 (28 de septiembre de 2026), por instrucción del responsable, adopta «la plantilla
manda»: Administración declara en ADM-06 qué datos salen de los documentos de carrera
(campo **Sale sola de los documentos de la carrera**) y la importación de COR-16 solo
extrae esos datos. ADM-06 pregunta «¿Quién llena este campo?» en lenguaje simple y deja
de ofrecer Sí/No para campos nuevos. Sin cambios de esquema.

I-149 (28 de septiembre de 2026), por instrucción del responsable, saca de la malla el
asistente curricular y crea **Coordinación → Proyecto de carrera** (COR-16), una pantalla
propia con Datos confirmados, Importar e Historial. La revisión relaciona una sola vez
cada materia nombrada de otra forma en el documento. API, permisos y alcance por carrera
no cambian. Sustituye el ajuste de I-145 que situaba el botón en Malla.

I-148 (28 de septiembre de 2026), por instrucción del responsable, dibuja la ficha de
identificación solo desde el diseño de la plantilla; se retiran la cuadrícula fija del
Word y de las respuestas de docente y revisión.

I-147 (27 de septiembre de 2026), por instrucción del responsable, retira de la malla
el editor manual de «Resultados de aprendizaje». El asistente curricular de Malla
presenta los datos confirmados que usará un sílabo nuevo, incluida su procedencia.
Para resultados de una asignatura, el valor documental I-145 prevalece; cuando no
existe, se muestra la base I-144 como «Registro anterior». La base, los expedientes y
revisiones históricos se conservan. Las antiguas rutas GET/PUT de edición manual se
retiran; la confirmación documental queda como único recorrido de actualización.

Ajuste de I-145 (27 de septiembre de 2026), por instrucción del responsable, sitúa el único
acceso al asistente curricular de I-145 en **Coordinación → Malla**. El botón deja de
ser global en las pantallas de Coordinación; permisos y alcance por carrera no cambian.

I-146 (27 de septiembre de 2026) sustituye el alcance activo de I-46/I-92/I-95/I-98/I-116/I-119/I-124
relativo al CRUD de fuentes, su requisito para convocar y el revisor con RAG. Se retiran
sus pantallas, rutas de gestión, análisis por campo, revisión global, aplicación y
feedback del revisor. Los párrafos históricos que describen esas funciones no son
requisitos vigentes. La orden procede del responsable del producto, sin atribuirla al
tutor ni a la institución.

I-145 continúa recibiendo PDF/XLSX. I-152 retira la reutilización de documentos y los vuelve temporales.
Los nombres de contrato `sources`/`source_ids` se conservan por compatibilidad; ya no
representan un CRUD de Fuentes académicas. Los originales tienen descarga privada por
importación. I-146 no eliminó evidencia; I-168 borró después la tabla `documentos_ia`, la
extensión `vector` y el código del revisor. El contenido curricular confirmado se conserva.

El chat conservado ayuda a elaborar contenido y unidades desde carrera, asignatura,
horas, semanas, datos curriculares confirmados y tablas de unidades/planificación del
sílabo. I-168 eliminó la casilla `ia_habilitada`; las preguntas nuevas
usan `curricular-units-v1` y no envían al proveedor el historial anterior. No consulta el índice
vectorial ni ejecuta revisión académica. La configuración del proveedor sigue disponible;
no se cambian permisos de aprobación ni validaciones determinísticas. La verificación
operativa y el estado de entrega se registran en el plan I-146.


I-145 (27 de septiembre de 2026), autorizado con «ok veamos», corrige el recorrido
principal de I-144: Coordinación entrega PDF/XLSX o reutiliza documentos de ingesta ya cargados;
el asistente extrae una propuesta de datos para su carrera y asignaturas. La revisión
por lote muestra procedencia y excepciones, sin exigir transcribir cada resultado.
La extracción no modifica datos vigentes antes de la confirmación humana.

El contenido documental admite conceptos configurables de texto, lista o tabla y
valores relacionados con carrera/asignatura. Esto aclara la frontera de I-75: los
atributos académicos estructurales siguen tipados; los conceptos documentales no crean
columnas en asignaturas ni una entidad específica por campo. La clave, tipo, ámbito y
columnas de un concepto compartido permanecen estables. Administración vincula los
conceptos en la plantilla única como datos automáticos o como opciones de selección
docente; una selección puede combinar listas de asignatura y carrera.

La aplicación comprueba pertenencia, tipos, evidencia, huellas y pausa del proceso.
Una materia sin coincidencia se resuelve mediante elección explícita. Ante datos
distintos entre fuentes, Coordinación selecciona un candidato y confirma la
discrepancia de esa fila; esto no establece una jerarquía institucional de fuentes
ni cierra PV-02. Evidencia inválida y conflictos estructurales impiden aplicar esa
fila. La fotografía del sílabo conserva definición, valor y procedencia; la
regeneración mantiene la protección de aprobaciones de I-144 y se limita al ámbito
afectado. Cantidad de unidades, cardinalidad de habilidades, APE cero y generación
de planificación siguen pendientes de decisión académica; no se deducen del número
de filas extraídas. La autorización cubre esta implementación local, sin afirmar
despliegue ni validación institucional de los documentos de ejemplo.

I-144 (27 de septiembre de 2026), autorizado por el responsable del producto, inicia
datos curriculares estructurados con resultados de aprendizaje por asignatura.
Coordinación registra lista ordenada, documento/página de procedencia y confirma la
revisión de los datos. Administración puede incorporar esos resultados como dato
automático en la plantilla única. Cada sílabo conserva una fotografía independiente
del origen vivo; la ausencia bloquea el envío solo si el formato utiliza ese dato.
La implementación local agrega cabecera y filas relacionadas, no atributos arbitrarios
de malla ni versiones de fuentes. No confirma cantidad de unidades, límite de habilidades,
reglas APE ni generación de unidades mediante IA. I-145 incorpora el recorrido
documental general; el registro manual de I-144 y sus rutas fueron retirados por I-147.
La base histórica permanece como respaldo de lectura.
Los aliases anteriores de resultados/fuente/ubicación priorizan el concepto genérico
`resultados_aprendizaje` (lista/asignatura) confirmado en la fotografía y conservan
el origen I-144 cuando aquel no existe; no se duplican datos vigentes.

El 2026-09-27, ante la instrucción del responsable del producto de resolver las
decisiones de implementación, se concilia I-118 preservando la evidencia aprobada:
una corrección confirmada solo regenera expedientes en curso que no conservan una
aprobación; los enviados sin aprobación siguen dentro del alcance. La reapertura
conserva la revisión aprobada y sus vínculos, por lo que no habilita el borrado de ese
expediente mediante un reinicio. Corregir resultados de aprendizaje afecta únicamente
a la asignatura modificada; las otras materias y los procesos cerrados se conservan.
Esta resolución delegada de producto reemplaza las frases que incluían aprobados en
la purga; no se atribuye al tutor ni a una autoridad académica y no resuelve las reglas
pendientes de habilidades, APE o unidades.

I-54 (2026-09-06), por solicitud explícita del responsable del producto, permite al
Coordinador crear o incorporar cuentas como Docente exclusivamente en su carrera activa.
Administración conserva todas sus capacidades. No delega otros roles, reactivaciones ni
gestión global de cuentas; la corrección de nombre/correo de docentes de la carrera se
delegó después en I-170. La incorporación reutiliza
cuentas activas por correo, sin sobrescribir identidad o acceso; asignar paralelos sigue
siendo otra operación con sus bloqueos académicos.

I-84 (16 de septiembre de 2026) reemplaza la entrega conjunta basada en Inertia: Laravel
expone JSON versionado y conserva dominio, políticas, colas y persistencia; la interfaz
es una SPA Vue independiente que usa Sanctum con cookie y CSRF. Las decisiones del
starter Inertia se conservan únicamente como historial en ADR-0001 y ADR-0004.

I-06 implementa el contrato, persistencia, cola y control humano de IA con un simulador
técnico determinista y un cliente HTTP exclusivamente local. Esto demuestra integración
y degradación segura, pero no confirma motor, modelo, hardware, corpus, precedencia ni
umbrales: `PV-02`, `PV-13`, `PV-14` y `PV-18` permanecen `POR VALIDAR`.

I-72 (14 de septiembre de 2026) fija por decisión explícita del responsable del producto
la configuración de alcance de IA: Administración define en cada campo el valor
predeterminado; la Coordinación de cada carrera puede sobrescribirlo y conserva esa
preferencia hasta que cualquier configuración de la plantilla cambie, momento en que
vuelve a heredarse el valor administrativo. Coordinación también elige antes de iniciar
su alcance qué fuentes activas de su carrera alimentarán la IA. Esta última decisión
reemplaza únicamente la selección automática de todas las fuentes establecida en I-46;
no modifica su congelación durante un alcance abierto ni la evidencia histórica. Chat y
revisión integral usarán el mismo contexto autorizado, con citas a fuentes y secciones,
y continuarán siendo asistencia opcional que no bloquea el envío. La implementación está
planificada en `docs/plans/completed/I-72-asistente-ia-contextual.md`; no cierra `PV-02`,
`PV-13`, `PV-14` ni `PV-18`.

I-73 (14 de septiembre de 2026) incorpora, por decisión explícita del responsable del
producto, adaptadores de inferencia para OpenAI, Claude y DeepSeek. El operador elige el
protocolo con `AI_DRIVER` y configura únicamente URL base, modelo y API key; no existen
controles de razonamiento y DeepSeek se fuerza al modo sin pensamiento. El soporte
técnico no selecciona ni valida un modelo definitivo, no habilita un proveedor por
defecto y no cambia el control humano, por lo que `PV-13`, `PV-14` y `PV-18` conservan su
estado `POR VALIDAR`. La activación con datos institucionales requiere además resolver
el tratamiento externo aplicable bajo `PV-12`.

I-95 (19 de septiembre de 2026) reemplaza I-26, I-49, I-91 e I-94 en el formato de las
fuentes: PDF privado → OpenParser (`paddleocr-vl-1.6`, `openparser@1`) → documento JSON ProseMirror/Tiptap →
fragmentos y embeddings. Las tablas conservan combinaciones y las imágenes se insertan
en su orden como objetos privados. `ejecuciones_trabajo` sustituye la tabla especializada
de extracciones. La etapa OCR no usa Mistral; la normalización posterior opcional de
DeepSeek queda definida por I-99. La activación real continúa condicionada a PV-12 y
PV-14; la migración es `000089`.

I-97 (20 de septiembre de 2026) presenta COR-11 como documento editable continuo, sin
simular hojas A4 ni saltos físicos. El JSON ProseMirror/Tiptap sigue siendo canónico;
las celdas estructuradas de OpenParser tienen prioridad sobre sus proyecciones HTML y
Markdown, y el PDF original se conserva como vista privada de contraste. La paginación
queda reservada para el archivo original, las plantillas y las exportaciones.

I-98 (20 de septiembre de 2026) concentra la ingesta PDF en la creación de la fuente.
COR-11 ya no permite cargar ni reprocesar archivos: después de crear los metadatos, un
diálogo bloqueante muestra el progreso real de `ejecuciones_trabajo` y abre el documento
automáticamente únicamente cuando OpenParser termina. Un fallo conserva reintento y
salida explícitos. El documento conserva un separador horizontal entre páginas del PDF;
PV-12 y PV-14 siguen condicionando la activación externa.

I-99 (20 de septiembre de 2026) añade una normalización editorial posterior a OpenParser.
Cuando `AI_SOURCE_NORMALIZATION_ENABLED=true` con driver `deepseek`, `deepseek-flash` recibe
las páginas OCR en lotes y devuelve Markdown por página mediante JSON. Tablas HTML e imágenes se envían como marcadores
protegidos y se restauran en el mismo lugar; una respuesta incompleta o que altere esos
marcadores hace fallar la ingesta, sin fallback silencioso. El normalizador no reemplaza
al OCR ni cambia el contrato de persistencia o embeddings.

I-74 (14 de septiembre de 2026) normaliza roles y responsabilidades académicas: la
única relación RBAC es `asignaciones_rol(usuario_id, rol, carrera_id)`. Se elimina
`asignaciones_coordinador`, porque duplicaba el rol `coordinador` con alcance de carrera.
La responsabilidad operativa de un paralelo no es un rol: `docentes_paralelo` referencia
la asignación RBAC `docente` que la respalda. PostgreSQL impide más de una coordinación
ejercible por carrera mediante trigger; una cuenta inactiva conserva el historial de sus
roles, pero no bloquea un reemplazo. La entrega requiere las migraciones `000056` a
`000059` junto con el despliegue de aplicación; hasta entonces la fotografía remota
continúa en el esquema anterior.

I-75 (14 de septiembre de 2026) fija la estructura académica de la malla: ninguna
carrera puede inventar campos para sus materias. Código, nombre, ciclo, unidad de
organización curricular, modalidad, créditos y componentes horarios son atributos
tipados de `asignaturas`; ACD, APE, AA, créditos y total se muestran como conjunto
fijo. Se retiran `definiciones_campo_malla` y `valores_campo_asignatura`; requisitos,
programaciones, paralelos y responsabilidades docentes se mantienen como relaciones.

I-78 (14 y 15 de septiembre de 2026) reemplaza los atributos físicos genéricos por
nombres que expresan su concepto y entidad: entre ellos, `codigo_facultad`,
`codigo_campus`, `codigo_carrera`, `codigo_asignatura`, `nombre_usuario` y
`usuario_activo`. Los campos transversales, claves técnicas y códigos ya inequívocos
por su tabla se conservan; no se prefijan artificialmente.

I-79 (15 de septiembre de 2026) renombra la tabla física de responsabilidad docente
`docentes_paralelo` a `asignaciones_paralelo`. La relación sigue vinculando un paralelo
con la asignación RBAC docente que la respalda; conserva datos, restricciones y claves
foráneas, sin modificar los nombres funcionales de la interfaz o las rutas.

I-80 (15 de septiembre de 2026) simplifica los roles fijos del dominio: Administrador,
Coordinador y Docente son valores cerrados de `asignaciones_rol.rol`, protegidos por
`CHECK`; se elimina la tabla `roles`, su clave foránea y las consultas al catálogo. La
asignación conserva usuario, carrera, estado e historial; el trigger de coordinación
consulta el valor de la propia fila y sigue permitiendo una sola coordinación ejercible
por carrera.

I-81 (15 de septiembre de 2026) retira `facultades.ruta_logo_facultad`. Un logo de
facultad es un archivo privado y se referencia mediante `facultades.logo_objeto_id` a
`objetos_almacenados`; el objeto conserva disco, ruta interna, MIME, tamaño, huella y
clasificación. Cada reemplazo genera un objeto inmutable nuevo, sin perder la referencia
histórica del archivo anterior.

I-82 (15 de septiembre de 2026) elimina la entidad `mallas`: el producto no admite
versiones, activación, desactivación ni agrupación de varias mallas por carrera.
`carreras.codigo_malla` y `carreras.cantidad_ciclos_malla` guardan la única estructura
curricular; `asignaturas.carrera_id` y `silabos.carrera_id` sustituyen sus antiguas
claves foráneas. El nombre visible **Malla** se conserva solo para el editor curricular.

I-107 (22 de septiembre de 2026), por instrucción explícita del responsable del
producto, reemplaza los códigos opcionales de facultad y el código obligatorio de malla:
se elimina `facultades.codigo_facultad` y `carreras.codigo_malla`; `carreras.codigo_carrera`
es obligatorio y único. La estructura curricular conserva su cantidad de ciclos además
de las materias y relaciones asociadas. Los sílabos existentes conservan su contexto
inmutable; los nuevos ya no incluyen código de malla.

I-108 (23 de septiembre de 2026), por instrucción explícita del responsable del
producto, retira el código manual del formulario de período. El servidor genera la única
etiqueta visible con el rango de meses y el año, por ejemplo `Agosto-Diciembre 2026`;
el código técnico se conserva para referencias e historial.

I-109 (23 de septiembre de 2026) aclara que `semanas_lectivas` no se captura en el
formulario. El servidor cuenta las semanas de lunes a domingo que toca el rango de
fechas, aunque la primera o la última sea parcial, y guarda ese resultado entre 1 y 52.

I-110 (23 de septiembre de 2026) simplifica la configuración inicial de convocatorias:
Administración las abre desde la fila del período académico, con el período fijado y
nombre/plantilla automáticos; solo ajusta las fechas de elaboración. La creación y
apertura son atómicas. Coordinación conserva su convocatoria propia dentro
del proceso abierto, hereda período, plantilla y fechas, y elige sus fuentes activas según
I-72. Las tablas y las reglas de unicidad permanecen separadas.

I-113 (23 de septiembre de 2026), por instrucción explícita del responsable del
producto, precisa que las fechas de la convocatoria institucional no se copian del
período académico. Al abrir una convocatoria nueva, Administración debe elegir el
inicio de elaboración y la fecha límite de entrega; Coordinación hereda esos valores
elegidos. La edición conserva las fechas ya guardadas.

I-114 (23 de septiembre de 2026), por instrucción explícita del responsable del
producto, limita la prórroga institucional a la fecha límite de entrega. El inicio no
es una etapa prorrogable: Administración solo puede corregirlo mediante la edición del
proceso en pausa. `fechas_limite_convocatoria` conserva `inicio` y
`borrador` para validar la ventana de envío, sin cambio de esquema.

Antecedente sustituido por I-146 en el alcance de lectura y revisión de IA:
I-116 (23 de septiembre de 2026), por instrucción explícita del responsable del
producto, deja en Administración la decisión de qué campos usan IA. Coordinación ya no
guarda excepciones por carrera: la IA solo sugiere y el docente decide si aplica cada
sugerencia. Se eliminan `preferencias_ia_carrera`,
`definiciones_campo.ia_coordinacion_configurable` y las rutas
`/coordination/ai-configuration`; sustituye las decisiones 1 a 3 de I-72.

Antecedente sustituido por I-146 en el alcance de lectura de IA; el comportamiento de
listas y totales se conserva:
I-119 (23 de septiembre de 2026), por instrucción explícita del responsable del
producto, precisa que la casilla de IA de un campo decide si el agente lo lee como
referencia para sus sugerencias. Administración la decide en todo campo salvo el estado
de revisión, incluidos la ficha institucional y los datos heredados; la ficha se lee sin
datos personales del docente. Las listas se completan como filas de una columna, la
fila de totales solo admite datos numéricos y se retira el formulario separado de
estructura de tablas.

I-121 (23 de septiembre de 2026), por instrucción explícita del responsable del
producto, hace que Coordinación y Docencia elijan siempre su ámbito al iniciar sesión,
aunque tengan uno solo, en una pantalla sin menú lateral; sustituye para Docencia la
activación automática de I-13. Desde esa pantalla se leen los avisos. Abrir uno ya no
navega a una ruta calculada para otro rol: `POST /notifications/{id}/open` elige en el
servidor la asignación vigente de la persona que corresponde al aviso, la activa con
auditoría y devuelve el destino. No concede acceso nuevo.

I-117 (23 de septiembre de 2026), por instrucción explícita del responsable del
producto, permite a Administración eliminar una convocatoria institucional solo cuando
ningún docente inició uno de sus sílabos. La comprobación incluye el evento inmutable
`silabo.borrador_iniciado`, incluso si el expediente volvió después a «Sin iniciar».
El borrado transaccional retira los sílabos sin actividad y los alcances asociados, y
conserva su propio evento de auditoría; no requiere migración.

I-07 implementa un puerto de lectura, fixture sintético versionado, staging inmutable,
simulación y exclusión humana. Demuestra idempotencia, conflicto y cero mutación del
catálogo, pero no incorpora esquema, credenciales, red, reglas de identidad ni aplicador:
`PV-09`, `PV-10` y `PV-12` permanecen `POR VALIDAR`.

I-08 completa el carril técnico de candidato: CI y audits, reglas estáticas de
accesibilidad, cabeceras/readiness sin sesión, smoke de colas nombradas, restore
PostgreSQL efímero y baseline local reproducible. Esto no cierra los CP funcionales/no
funcionales por número —sus enunciados no constan en el repositorio— ni las pruebas con
participantes, documentos oficiales, infraestructura o datos reales. Todas las puertas PV
conservan su autoridad y estado `POR VALIDAR`.

I-09 redistribuye y verifica la gestión de estructura por decisión explícita del
responsable del producto: las entidades institucionales y las cuentas permanecen bajo
Administración, mientras la operación académica pertenece a la Coordinación vigente de
cada carrera. ADM-04 refleja la jerarquía Facultad → Carreras y separa campus,
modalidades y periodos en vistas propias, sin modificar el esquema normalizado. Sus filas
permiten editar y eliminar registros sin dependencias, con autorización y auditoría en
servidor. Este cambio
no resuelve ni modifica `PV-16`, que trata la edición excepcional de contenido docente.

I-10 unifica la presentación de listados por decisión explícita del responsable del
producto: el fondo se conserva y las superficies usan tokens diferenciados; ADM-04 se
navega mediante el submenú Estructura académica con rutas propias; toda barra de consulta
ordena búsqueda, filtros y acción; y las 24 superficies tabulares comparten el mismo pie
de paginación. Las acciones operativas de Administrador y Coordinador se agrupan detrás
de un botón accesible de tres puntos sin cambiar autorización ni ciclo de vida. Las 29
páginas operativas y el layout compartido de Configuración usan el mismo encabezado con
icono, título, descripción y espaciado responsive. No cambia el esquema normalizado, el
alcance por rol ni una puerta `PV`.

I-11 alinea el esquema académico con la fuente institucional SIANET a partir del acceso
concedido al respaldo del 23 de junio de 2025. El análisis se hizo sobre datos y no sobre
el DDL. Agrega el nivel `escuelas`, inicialmente volvió el periodo dependiente de la carrera,
separa la
identidad oculta de la asignatura de su código visible, renombra `nivel` a `ciclo`,
incorpora el desglose de horas y traduce el texto libre de campus y modalidad mediante
`alias_institucionales`, sin copiar los defectos de la fuente. `PV-09` y `PV-10` quedan
`CONFIRMADO` por decisión explícita del responsable del producto; con la identidad
institucional confirmada, la importación ya propone alta, cambio o sin cambio en lugar de
declarar todo conflicto. La importación permanece en modo simulación y `PV-12`
conserva su autoridad y estado `POR VALIDAR`; `PV-08` se cerró después en I-57.
I-41 reemplaza específicamente esa pertenencia por carrera: en el producto el período es
institucional, de código único, y un proceso de sílabos lo fija para todas las convocatorias
que cuelgan de él.

I-44 (3 de septiembre de 2026) congela la estructura que sostiene el trabajo docente:
proceso y convocatoria abiertos bloquean cambios institucionales y de carrera,
respectivamente. Administración pausa el proceso y Coordinación pausa su convocatoria
para corregir. El cambio estructural muestra sus consecuencias y, tras confirmación,
elimina únicamente sílabos sin envío ni evidencia de IA. No existe «Archivar» para
catálogos académicos: se elimina lo que no tiene dependencias y se protege la historia.

I-45 (3 de septiembre de 2026) concentra la preparación inicial de materias en una
hoja lateral amplia. Para el período elegido, Coordinación solo ve materias activas de
su malla que aún no están programadas y define uno o más paralelos, cada uno con su propia
jornada. La acción de cada materia queda para agregar paralelos más tarde. El envío no
elimina por omisión ni altera la historia.

I-12 renombra «contexto» a rol en todo el sistema —clases, props, rutas, vistas, columna
de auditoría, documentación y pruebas— por decisión explícita del responsable del
producto, que reserva la palabra «contexto» para las fuentes académicas que alimentan al
asistente. No cambia autorización, alcance ni ciclo de vida.

I-13 ajusta la experiencia de uso sin tocar autorización ni ciclo de vida: el rol único
se activa solo y el selector queda para quien acumula roles; el panel sustituye los
accesos duplicados por indicadores calculados con el alcance del rol activo; el menú
lateral se vuelve reactivo, despliega sus subopciones y las ofrece en un menú flotante
cuando está reducido; y la superficie visual pasa a tokens de elevación, anillo y sombra
definidos por tema. Corrige tres defectos con prueba de regresión propia.

El trabajo pendiente del proyecto está consolidado en `docs/plans/pending-work.md`, que
separa lo que depende de una revisión manual de lo que espera decisión de la UEB.

I-14 renombra «campaña» a «convocatoria» en todo el sistema —base de datos, clases,
rutas, vistas, auditoría y documentación— por decisión explícita del responsable del
producto. No altera comportamiento.

I-16 completa la edición de mallas, materias programadas, paralelos y asignaciones docentes
por decisión explícita del responsable del producto. Coordinación solo modifica registros
de su carrera y cada cambio queda auditado; I-20 reemplaza la inmutabilidad de la malla,
mientras que el historial de sílabos se conserva. Nombre y correo permanecen exclusivamente bajo
Administración: Coordinadores y Docentes no pueden corregirlos, ni siquiera en la cuenta
propia. Este cambio no amplía `PV-16`, que sigue referido al contenido del sílabo.

I-18 incorpora Vue Flow como motor de presentación del constructor de mallas. La
configuración pertenece a la malla actual y admite ciclos, campos tipados, totales,
reubicación y relaciones explícitas; PostgreSQL y los casos de uso Laravel permanecen
como fuente de verdad. La alternativa de formulario mantiene las mismas operaciones y
I-20 permite editarlas sobre el mismo agregado. I-57 cerró después `PV-08` sin deducir
fórmulas ni siglas del color o del aspecto del PDF.

I-19 confirma que una persona puede coordinar varias carreras y vuelve explícita la
selección de carrera al iniciar como Coordinador, incluso si solo existe una opción. El
ámbito activo puede cambiarse desde el menú de usuario mediante el mismo caso de uso
auditado. Materias deja de ser una colección independiente en navegación: I-20 abre una
sola Malla directamente y reúne allí el desglose académico y el constructor visual; la
URL anterior de Materias redirige a Malla. No cambia permisos ni elimina historia.

I-20 reemplaza el versionado visible de mallas definido en I-16, I-18 e I-19: la
navegación muestra una sola **Malla** por carrera, sin buscador, filtros, cards,
paginación, publicación ni número de versión. La fila técnica histórica se conserva
solo para mantener referencias existentes; una restricción parcial garantiza una única
malla actual. Coordinación edita la actual activa o inactiva, la deshabilita para
bloquear procesos nuevos y solo puede eliminarla sin dependencias. Cada sílabo fija su
contexto académico y lo incorpora a sus revisiones inmutables.

I-28 (1 de septiembre de 2026) deja el esquema físico y los valores almacenados 100 %
en español por decisión explícita del responsable del producto, en la línea de I-12 e
I-14: los timestamps se tradujeron inicialmente a `creado_en`/`actualizado_en` (convención
reemplazada después por I-52), columnas de `usuarios`
(`correo_electronico`, `contrasena`…), `ejecuciones_trabajo` completa,
`eventos_salientes` (antes `eventos_outbox`), familia `version_bloqueo`, estados y
discriminadores (`pendiente`, `borrador`, `administrador`…), colas Redis y CHECK
constraints; las tablas de framework vivas se renombraron por configuración
(`sesiones`, `trabajos_fallidos`, `restablecimientos_contrasena`, `migraciones`) y las
muertas (`jobs`, `job_batches`, `cache`, `cache_locks`) se eliminaron. Los límites que
quedan (columnas internas de drivers de Laravel, claves internas de JSONB sellados,
contrato del gateway de IA) están registrados en `docs/plans/technical-debt.md`. No
altera autorización ni ciclo de vida; corrige de paso el worker de producción, que no
escuchaba las colas nombradas.

I-29 (2 de septiembre de 2026) elimina la vigencia programada de los roles de cuenta:
`asignaciones_rol` queda efectiva únicamente mientras `activo` sea verdadero y su retiro
es manual. I-52 extendió después esa misma regla a las asignaciones docentes y de
coordinación, conservando por separado su evidencia funcional.

I-31 (2 de septiembre de 2026) traslada el calendario a Administración por decisión
explícita del responsable del producto: `procesos_silabos` fija plantilla, inicio y
entrega para toda la universidad y solo puede haber uno en curso. `convocatorias`
cuelga de un proceso obligatorio, hereda su plantilla y sus fechas y gana el estado
`pausada`. Una convocatoria está en curso solo si ella está abierta y el proceso
también; esa condición habilita el trabajo docente y congela plantilla (proceso),
malla y fuentes (carrera). I-114 reemplaza la prórroga por carrera de I-15 con una
prórroga institucional limitada a la entrega. No resuelve `PV-01`: decide cuándo puede
cambiarse la plantilla, no quién la aprueba.

I-32 (2 de septiembre de 2026) retira las versiones por decisión explícita del
responsable del producto: `versiones_plantilla` desaparece —secciones, bloques y campos
cuelgan de `plantillas_silabo`, que gana `mapeo_documento`— y `versiones_malla` pasa a
`mallas`, una por carrera, sin `numero_version` ni `es_actual`. Ya no hay «publicar» ni
«crear versión»: la estructura se comprueba al abrir o reanudar el proceso. Regla de
borrado, sustituida después por I-118: con la convocatoria pausada, un cambio estructural
en la plantilla o en la malla exige confirmación con la cifra afectada. Los sílabos de
procesos cerrados no se tocan.

I-118 (23 de septiembre de 2026), por instrucción explícita del responsable del
producto, corrige el alcance de I-32. Su alcance vigente incorpora la resolución
delegada de I-144 del 27 de septiembre de 2026: la plantilla es única para todas las
carreras y una corrección de plantilla o base académica exige pausar el ámbito
correspondiente y confirmar la consecuencia. El sistema guarda la corrección, elimina
el expediente elegible completo —borrador, revisiones, observaciones, IA y
exportaciones— y lo genera desde las fuentes vigentes en una sola transacción.
Son elegibles los expedientes afectados en curso sin aprobación conservada, incluidos
los enviados. Se preservan los aprobados, la evidencia aprobada tras reapertura y los
procesos cerrados. Sus fotografías pueden conservar el formato anterior; la plantilla
vigente continúa siendo una sola. El reinicio de resultados de aprendizaje se limita
a la materia corregida.

I-121 (23 de septiembre de 2026), por instrucción explícita del responsable del
producto, retira el estado institucional `preparacion`. La convocatoria institucional
nace `abierto` en la misma transacción que valida la plantilla, la estructura académica
y la unicidad del proceso vigente; después solo transita `abierto ⇄ pausado → cerrado`.
PostgreSQL admite como máximo una convocatoria abierta o pausada, mientras las cerradas
se conservan como historial. Si ya hubo actividad docente, una equivocación se corrige
pausando y regenerando los sílabos afectados; no se elimina el proceso.

I-46 (4 de septiembre de 2026) precisa dos alcances del mismo ciclo: Administración
abre un único proceso institucional por período académico; Coordinación no
prepara ni configura otra convocatoria, sino que inicia o pausa el alcance de su carrera
desde ese proceso visible. Iniciar exige malla activa, materias programadas con paralelos y docentes
vigentes, y fuentes académicas activas; genera atómicamente un sílabo por paralelo. Las
fuentes activas de toda la carrera se fijan de forma automática y se sincronizan al
reanudar. PostgreSQL impide más de un proceso por período y más de un alcance por
carrera/período.

I-47 (5 de septiembre de 2026) retira la procedencia SIANET que no interviene en los
casos de uso actuales: código externo de escuela, período, malla y asignación docente;
año del período, descripción externa de malla y código oculto de asignatura. Se conservan
los códigos visibles que usa el producto. La migración es irreversible y exige un
respaldo lógico previo.

I-48 (5 de septiembre de 2026) retira el nivel `escuelas` que provenía de SIANET: no
representa una extensión ni una entidad operativa de sílabos. El producto queda como
Facultad → Carrera; `campus` conserva la ubicación física de las materias programadas. La migración
es irreversible y exige un respaldo lógico previo.

I-50 (5 de septiembre de 2026) simplifica la cuenta gestionada: `activo` conserva el
estado presente, mientras activaciones y desactivaciones se preservan como eventos de
auditoría. Se retiran `desactivado_en`, `actualizado_en` y la cédula de la integración
SIANET ya eliminada. La migración es irreversible y exige un respaldo lógico previo.

I-51 (5 de septiembre de 2026) retira `vigente_desde` y `vigente_hasta` de las cuentas:
esas fechas no se conocen de forma estable al administrarlas. La disponibilidad depende
de la cuenta activa y de sus roles/asignaciones. I-52 aplicó después el mismo criterio a
la coordinación. La migración es irreversible y exige un respaldo lógico previo.

I-52 (5 de septiembre de 2026) retira de todas las tablas propias las marcas genéricas
`creado_en`, `actualizado_en` y `registrado_en` cuando solo duplican auditoría técnica.
Las fechas funcionales permanecen con nombres de dominio: `asignado_en`, `encolado_en`,
`notificado_en`, `almacenado_en` y `observado_en`, además de las ya explícitas como
`guardado_en`, `ocurrido_en` o `aprobado_en`. No crea triggers de auditoría todavía; esa
decisión queda para una revisión posterior. La migración `000046` es irreversible, tuvo
respaldos lógicos verificados y fue aplicada local y remotamente.

La misma revisión I-52 retira `plantillas_silabo.es_institucional`: el producto admite
una única plantilla, por lo que el campo no distinguía estados posibles. El índice
`plantillas_silabo_unica` garantiza directamente como máximo una fila. La migración
`000047` es irreversible, tuvo respaldos verificados y fue aplicada local y remotamente.
I-52 también retira
`asignaciones_coordinador.vigente_desde` y `vigente_hasta`: `activo` expresa quién
coordina actualmente y un índice parcial garantiza una sola coordinación activa por
carrera. `calidad` distingue si la designación administrativa es titular o encargada.
La migración `000048` fue aplicada local y remotamente después de respaldos verificados.
El 6 de septiembre I-52 retira además `sustento_tipo`, `sustento_numero` y
`sustento_fecha` de esa asignación: la coordinación no conserva información documental y
solo Administración designa y define la calidad. La migración `000049` fue respaldada y
aplicada local y remotamente.

I-62 (9 de septiembre de 2026) reserva **oferta académica** para el conjunto de carreras
y programas aprobados por el CES y registrados en el SNIESE. La operación de una materia
en un período se denomina **programación de asignatura**; contiene uno o más paralelos y
cada paralelo conserva su asignación docente y genera su propio sílabo. Coordinación la
presenta como **Materias y paralelos**. El esquema usa `programaciones_asignatura` y
`programacion_asignatura_id`; las fotografías nuevas usan `scheduled_subject` y los
lectores mantienen compatibilidad con la clave histórica `offering`. No cambian permisos,
estados ni cardinalidades.

I-64 (9 de septiembre de 2026) confirma que **Materias y paralelos** muestra un período
a la vez y conserva los anteriores como historial. El estado Próximo/En curso/Finalizado
se deriva de las fechas en `America/Guayaquil`; un período finalizado es de solo consulta
y el servidor bloquea cambios en sus programaciones, paralelos y asignaciones docentes.

I-76 (14 de septiembre de 2026) simplifica el relevo docente: `docentes_paralelo` no
conserva el tipo, número ni fecha de un documento de sustento. La responsabilidad se
expresa mediante la asignación RBAC docente, el paralelo, su estado activo y
`asignado_en`; el relevo conserva su trazabilidad operativa en auditoría.

I-77 (14 de septiembre de 2026) simplifica `periodos_academicos`: el código único es la
única etiqueta visible y se retiran `nombre` y `activo`. Próximo, En curso y Finalizado
se derivan exclusivamente de las fechas. `semanas_lectivas` se conserva porque expresa
las semanas que distribuye el sílabo y se calcula desde el rango calendario conforme a
I-109.

I-106 (22 de septiembre de 2026) retira `campus` como entidad y catálogo independiente.
La facultad conserva obligatoriamente una sola opción de campus, elegida de una lista fija: Campus Matriz
Dr. Gabriel Galarza López, Campus Laguacoto, Campus CENI (Centro de Eventos Nacionales
e Internacionales), Extensión San Miguel y Sede Chimbo. Las carreras heredan esa opción
de su facultad y cada programación conserva el nombre elegido como fotografía textual;
el sílabo conserva su propia fotografía inmutable. No existe código de campus. La
migración mapea el catálogo existente y falla de forma íntegra si una facultad combina
campus distintos o una referencia no puede mapearse, para permitir su conciliación antes
de retirar los datos originales.

I-111 (23 de septiembre de 2026) confirma que la plantilla institucional es siempre un
único recurso y simplifica su persistencia. La tabla física pasa a llamarse
`plantilla_silabo`; `documento_plantilla` JSONB guarda la composición visual completa
de secciones, bloques y tablas. `componentes_plantilla` se retira. Las
`definiciones_campo` permanecen relacionales porque valores, validaciones, IA y
evidencia histórica las referencian; `bloque_plantilla_id` conserva el UUID del bloque
embebido y PostgreSQL verifica su pertenencia al documento. Cada revisión enviada
continúa guardando su fotografía completa e inmutable. Esta decisión reemplaza la
persistencia de componentes de I-85, sin cambiar la plantilla única definida en I-32.

I-112 (23 de septiembre de 2026), por instrucción explícita del responsable del
producto, exige que un período académico nuevo empiece hoy o en una fecha posterior,
tomando `America/Guayaquil` como día visible. La fecha final permanece igual o posterior
a la inicial. La regla se aplica al alta y conserva la edición de períodos históricos.

I-123 (23 de septiembre de 2026), por corrección explícita del responsable del producto,
establece que el logo de una facultad es un dato de administración institucional. Solo el
Administrador puede cargarlo o reemplazarlo al crear o editar la facultad en ADM-04.
Coordinación deja de tener el paso, diálogo, dato de Panel y endpoint de carga que había
introducido I-68. La existencia del logo continúa siendo requisito para iniciar el alcance
de la carrera; si falta, Coordinación recibe una validación que remite a Administración.

I-125 (23 de septiembre de 2026), por instrucción explícita y corrección inmediata del
responsable del producto, vuelve obligatorio el logo al crear una facultad en ADM-04. El
formulario indica como medida recomendada 600 × 180 px y exige un PNG sin fondo, con
transparencia; el servidor conserva la proporción y lo centra sobre un lienzo transparente
a esa medida. Al editar una
facultad que ya tiene logo, reemplazarlo sigue siendo opcional; un registro anterior sin
logo exige cargarlo antes de guardar cualquier edición. El logo institucional mantiene
el mismo requisito de PNG con transparencia.

## Propuesto

I-52, actualización del 6 de septiembre: se retira `calidad` de las asignaciones de
coordinación mediante `000050`, sustituyendo la decisión anterior de conservarla.
Administración asigna a la persona sin distinguir titular de encargado.

- PHP 8.3 o superior como mínimo del proyecto.
- UUID generados por la aplicación para claves primarias internas.
- PostgreSQL con `timestamptz` en UTC; presentación en `America/Guayaquil`.
- Laravel Fortify mediante el starter oficial; registro público desactivado si la
  institución administra las cuentas.
- Horizon para observar colas Redis cuando sea útil en el despliegue.
- Estructura `app/Modules/*` con capas ligeras por módulo.
- Outbox transaccional para eventos que deben llegar a trabajos/integraciones.
- Pruebas de arquitectura para evitar dependencias entre módulos no permitidas.

Estas propuestas pueden confirmarse al crear el repositorio. Si cambian, crea un ADR.

## Por validar antes de codificar la parte afectada

Consulta los códigos y puertas en `docs/plans/decisions-pending.md`. Incluyen:

- autoridad institucional que aprueba y firma;
- formato oficial Word/PDF y firmas/sellos;
- matriz final de permisos y edición excepcional del coordinador (`PV-16`);
- frecuencia de actualización de datos institucionales; el origen, el esquema y la
  calidad quedaron confirmados en I-11;
- SSO, registro público y ciclo de vida de cuentas;
- infraestructura, almacenamiento, copias, retención y recuperación;
- motor/modelo local de IA, umbrales y hardware;
- corpus autorizado, tratamiento de datos y criterios de aceptación;
- resultados de entrevistas a docentes, coordinación y personal técnico.

## Trabajo permitido mientras se valida

- bootstrap reproducible y CI;
- estructura modular y convenciones;
- autenticación base sin fijar SSO;
- modelo de roles configurable;
- prototipos y componentes compartidos;
- migraciones de entidades ya confirmadas;
- estados y revisión inmutable confirmados;
- pruebas unitarias de invariantes confirmadas;
- adaptadores con interfaces y dobles de prueba;
- documentación, trazabilidad y planes.

No programes como irreversible una decisión institucional que sigue abierta.
