# Decisiones vigentes

Lista de lo que está decidido hoy. Todo lo que aparece aquí está `CONFIRMADO`. Lo que
falta decidir está `POR VALIDAR` y vive solo en `docs/plans/pendientes.md`. El historial
de cada cambio está en git (etiqueta `docs-antes-de-limpieza` para la versión larga).

## Alcance

- El sistema cubre el ciclo del sílabo: elaboración, revisión, aprobación, firma y
  descarga. Admite varias facultades y carreras.
- Fuera de alcance: EVEA, matrículas, notas, horarios, integración con sistemas
  institucionales y decisiones académicas automáticas. Única excepción: la consulta de
  solo lectura al catálogo Koha de la biblioteca para buscar libros (2026-10-31).

## Roles y cuentas

- Hay cuatro roles: Administrador, Coordinador, Docente y Bibliotecario. No existe
  Decanato (I-192).
- Coordinador y Docente tienen carrera; Administrador y Bibliotecario son globales
  (I-165).
- Cada carrera tiene una sola coordinación activa. Quien tiene una sola asignación
  vigente entra directo a su Panel; solo elige en `/rol` quien tiene varios roles o carreras
  (2026-10-31).
- Un rol vale mientras esté activo; no tiene fechas de vigencia (I-29, I-51).
- No hay registro público. Administración crea las cuentas y la persona la activa con
  una invitación por correo.
- Administración puede quitar un rol a una cuenta, salvo el único que le queda (I-197).
- En «Usuarios y roles», la columna «Roles» muestra los roles vigentes de cada cuenta.
- Coordinación solo agrega docentes a su carrera y puede quitarlos de ella sin tocar la
  cuenta (I-54, I-170, I-175). Si la cuenta ya existe, en cualquier estado, solo le agrega
  el rol docente de esa carrera; si no existe, se crea con el enlace de registro como en el
  alta de Administración. No edita nombre ni correo, no activa ni elimina cuentas;
  eliminar una cuenta pendiente es solo de Administración (auditoría de seguridad,
  2026-11-03). Reenviar la invitación solo se puede cuando venció sin aceptarse, y lo
  pueden hacer Administración y la Coordinación de una carrera donde la persona es
  docente; el enlace siempre llega al correo del titular. Las listas muestran «Invitación
  vencida».
- Administración y Biblioteca no pueden ser docentes ni coordinadores, en ningún orden; se
  valida en la aplicación al asignar roles, sin restricción en la base (2026-11-03).
- El coordinador puede ser docente de un paralelo de su carrera y aprobar ese sílabo.
- Una cuenta puede borrarse aunque tenga auditoría (I-194).
- Cada persona guarda en su cuenta sus preferencias de accesibilidad.

## Estructura académica

- La jerarquía es Facultad → Carrera (I-48).
- Cada facultad elige un campus de una lista fija y tiene un logo. Se sube como esté (PNG, JPG o WebP): el navegador lo recorta, lo deja en 600 × 180 px y, si hace falta, quita el fondo blanco (el blanco encerrado por el logo se conserva), con vista previa; el servidor sigue pidiendo un PNG con transparencia (2026-11-01). Es obligatorio. Solo Administración lo carga (I-106, I-123, I-125).
- El código de carrera es obligatorio y único (I-107).
- Una carrera tiene un solo coordinador. Se asigna en «Usuarios y roles»; si ya tiene uno, se
  confirma el reemplazo (2026-11-01). En la convocatoria en curso no queda firma del saliente: lo que
  aprobó vuelve a «En coordinación» para que el entrante lo firme, y biblioteca verifica de nuevo.
- Facultades y carreras no se eliminan: se desactivan y se reactivan, y conservan su historial
  (2026-11-01). Una facultad se desactiva cuando no le quedan carreras activas; una carrera,
  cuando no tiene usuarios activos ni una convocatoria en curso. Los períodos sí se eliminan
  mientras no tengan convocatoria.
- Cada carrera tiene una sola malla, sin versiones (I-82). Las asignaturas tienen campos
  fijos: código, nombre, ciclo, unidad de organización, modalidad, créditos y horas ACD,
  APE y AA (I-75).
- Cada asignatura indica si «Puede generar sílabos» (por defecto, sí). Una que no genera
  no recibe sílabo, no exige paralelos, docente ni proyecto de carrera para abrir la convocatoria y no
  aparece en Proyecto de carrera ni en el Excel de proyectos de carrera. Cambiarlo es un cambio de
  malla: con sílabos en curso exige pausa y confirmación; los sílabos de una materia
  que vuelve a generar se crean al reanudar (2026-10-26).
- Los paralelos son fijos por asignatura y no dependen del período. Cada uno tiene
  código, jornada y como máximo un docente activo (I-209).
- Crear un paralelo exige código, jornada y docente de la carrera con cuenta activa
  y correo verificado, también en acciones masivas. El paralelo y su asignación se
  guardan juntos: si falla la asignación, no se crea el paralelo.
- Al agregar o editar un paralelo, el selector de docente permite buscar por nombre
  sin distinguir mayúsculas ni tildes. La selección se guarda al confirmar el paralelo.
- El período recibe su nombre automático (por ejemplo, `Agosto-Diciembre 2026`) y sus
  semanas se calculan desde las fechas. Empieza hoy o después (I-108, I-109, I-112).
- Administración carga cuentas y carreras por Excel; Coordinación carga docentes y la
  malla. Cada carga muestra una vista previa y se aplica todo o nada (I-198).
- Las plantillas de cuentas, docentes, carreras y malla se descargan protegidas, como la
  del proyecto de carrera: solo se escriben las filas de datos; encabezados, indicaciones
  y listas quedan bloqueados. La malla trae «Genera sílabo» con solo Sí o No; vacío vale
  Sí y las plantillas anteriores siguen sirviendo. Cada nombre admite hasta 45 caracteres,
  como el formulario, y la vista previa de docentes marca las cuentas que no se pueden
  incorporar (deshabilitadas o de Administración).

## Convocatorias y períodos

- Administración abre una convocatoria institucional por período, con fecha de inicio
  y fecha de entrega. Solo puede haber una abierta o pausada a la vez (I-31, I-121).
- El día de negocio lo decide la institución (`DISPLAY_TIMEZONE`, por defecto
  `America/Guayaquil`), no el servidor en UTC ni el navegador. El servidor es la
  autoridad: `App\Support\InstitutionDate` es el único origen de la fecha de hoy y de
  la fecha a la que corresponde un instante, y la SPA usa la fecha que publica la
  sesión.
- Las fechas de inicio y de entrega son fechas de calendario. Se guardan con los
  límites del día en la zona institucional y la API las publica como `YYYY-MM-DD`.
  Un plazo de entrega cierra al final del día local, no a las 19:00.
- La cola de revisión de Coordinación solo trae lo que espera su decisión (en revisión y todavía sin pasar a Biblioteca); lo demás (en biblioteca, devuelto, aprobado) está en Seguimiento, cuyas filas abren la revisión (2026-10-31). Coordinación no ve el comentario ni quién verificó de Biblioteca: solo que lo devolvió; Biblioteca ve únicamente su bibliografía.
- Coordinación tiene una sola acción para descartar un sílabo, «Reiniciar sílabo» (2026-10-31), solo con el sílabo aprobado y la convocatoria abierta (en pausa tampoco, 2026-11-02), con motivo obligatorio que ve el docente y queda en la auditoría y, si está aprobado (borra todo y no hay reapertura), el nombre de la materia. Se retiró «Eliminar sílabo» (`DELETE /coordination/syllabi/{syllabus}`); `POST .../reset` borra el sílabo aprobado y crea uno nuevo para el paralelo.
- El historial de un sílabo para Coordinación es una pantalla de versiones como la de Google Docs (documento a la izquierda, panel de versiones agrupadas por día a la derecha; los cambios respecto a la versión anterior se marcan solos), no una lista de revisiones (2026-10-31): envíos, correcciones de Coordinación con su comentario, aprobación de Coordinación y aprobación final. De lo que hace Biblioteca solo se ve «Biblioteca lo devolvió al docente», sin nombre ni comentario. Cada envío a partir del segundo ofrece «Ver qué cambió», y el detalle puede resaltar en el documento los campos cambiados desde el envío anterior.
- Con el plazo de entrega vencido (2026-10-31) el docente queda en solo lectura: el servidor
  rechaza iniciar, editar y enviar, y la API explica el motivo en `edit_block_reason`.
  Coordinación y Biblioteca siguen revisando lo ya enviado. Solo Administración prorroga; al
  hacerlo, todo se reabre. El aviso de plazo vencido sale en rojo.
- Cada persona elige qué avisos recibe por correo (2026-11-02), en Configuración › Notificaciones,
  no Administración: Docente (convocatoria abierta y reanudada, corrección solicitada, devuelto a
  borrador, aprobado, cierre de la convocatoria), Coordinación (resumen diario, convocatoria reanudada por Administración y cierre de la convocatoria) y Biblioteca (sílabo por revisar y reenviado,
  que antes no llegaban por correo). Sin elegir, llegan todos. Administración conserva solo el
  interruptor general (que también apaga los resúmenes) y la hora del resumen. Coordinación ya no
  recibe «sílabo enviado» ni «reenviado» en la campana: los ve en Revisión y en el resumen.
  Todos los roles tienen un resumen diario como el de Coordinación, de lunes a viernes y solo si hay
  pendientes: Docente, sus sílabos por elaborar o corregir y el plazo; Biblioteca, lo que espera
  su revisión (`avisos:resumen-diario`).
- Avisos de la campana revisados (2026-11-02): cada uno abre su pantalla. Plazo extendido lleva a
  Mis sílabos; la reanudación de Administración, a la bandeja de revisiones de Coordinación o
  Biblioteca; la exportación fallida, a Auditoría filtrada. Si se reinician varios sílabos de un
  docente a la vez, recibe un solo aviso con todas las materias; cambiar el código o la jornada
  de un paralelo con el sílabo enviado o aprobado también le avisa. Coordinación recibe un aviso
  (solo campana) cuando Biblioteca aprueba un sílabo de su carrera o pide corregir la bibliografía.
  Los reinicios de sílabo (por Coordinación, por la malla o el paralelo, o por la plantilla) llegan
  también por correo al docente, con un solo interruptor «Sílabo reiniciado». Los correos siguen
  abriendo la página de inicio, sin enlace al aviso.
- Notificaciones, Asistente de IA y Biblioteca se configuran en Administración › Configuración ›
  Sistema (2026-11-02), tabla `ajustes_sistema`. Lo guardado manda sobre la variable de entorno,
  que queda como respaldo mientras el ajuste no se guarde; se aplica al arrancar y antes de cada
  tarea en cola. La clave de la IA se guarda cifrada con `APP_KEY` y nunca vuelve al navegador,
  ni en parte; si `APP_KEY` cambia, hay que escribirla de nuevo. Guardar IA o
  Biblioteca pide la contraseña actual y cada cambio queda en la auditoría sin el valor de la clave.
- Las confirmaciones delicadas piden escribir una frase en vez de esperar una cuenta
  regresiva (2026-11-02): «devolver a borrador» (cambios que devuelven sílabos a
  borrador), «abrir convocatoria», «editar plantilla», «finalizar convocatoria», «rehacer
  sílabos» (reanudar con la plantilla cambiada) y «eliminar convocatoria». Se acepta sin
  importar mayúsculas, tildes ni espacios de más.
- Al reanudar una convocatoria (2026-11-02) se avisa a los docentes de cada convocatoria
  que vuelve a quedar abierta («convocatoria.reanudada», también por correo); si quien
  reanuda es Administración, Coordinación y Biblioteca reciben «proceso.reanudado» (también por
  correo si no lo apagaron).
- En pausa (de la carrera o institucional) nadie decide (2026-11-02): Coordinación y Biblioteca no
  aprueban, firman, piden corrección ni devuelven, igual que el docente no edita ni envía. La API
  dice el motivo en `decision_block` y los resúmenes diarios no cuentan convocatorias en pausa.
  «Reiniciar sílabo» tampoco se permite en pausa (2026-11-02): es una decisión más.
  Pausar y finalizar siguen sin aviso al momento; el cierre se anuncia antes (ver finalización
  automática). El plazo informa `paused_by` (Administración o
  Coordinación), y Administración recibe plazo solo con su convocatoria en pausa.
- Para abrirla, la plantilla debe tener contenido, cada carrera activa debe tener
  coordinación y el período no debe haber terminado.
- Estados: abierto ⇄ pausado → cerrado (en la interfaz, «Finalizado»), y una prórroga
  vuelve a abrir una cerrada (2026-11-03). Administración cierra la convocatoria y con ella
  las de cada carrera (I-231). Al cerrar se congela también el documento de los
  borradores y sílabos sin iniciar; las revisiones enviadas conservan su fotografía
  existente (ADR-0012). Una convocatoria cerrada queda solo para consulta: sus
  sílabos ya no se editan, envían, revisan ni firman, y después se puede abrir la del
  período siguiente.
- Un cambio de docente solo rehace el sílabo del paralelo en la convocatoria en curso;
  los de convocatorias cerradas se conservan (I-231).
- En el historial, un sílabo de un período terminado pertenece a quien lo envió; si
  nunca se envió, al docente actual del paralelo (I-231).
- La acción manual de cierre se llama «Finalizar» (2026-11-02); la ruta sigue siendo `cerrar`
  y el estado `cerrado`. Antes de confirmar muestra cuántos sílabos quedan sin aprobar, por
  estado, y pide «finalizar convocatoria». Se puede finalizar abierta o pausada.
- Finalización automática (2026-11-03, reemplaza el cierre automático del 2026-11-02, que
  finalizaba el día después del último día de clases): si Administración no la finaliza, el
  sistema finaliza la convocatoria abierta o pausada el día después de su fecha de entrega
  (`fecha_entrega`, día de la institución), a las 00:10 de la hora de la institución
  (`convocatorias:cerrar-vencidas`), con el mismo efecto que Finalizar. Se finaliza aunque haya
  sílabos «En revisión» o «Corrección solicitada», que quedan como estén. Queda en la auditoría
  como acción del «Sistema» (`automatic: true`) y no avisa al cerrar. 3 días antes de la fecha
  de entrega, a la hora del resumen diario, avisa «La convocatoria se cierra pronto»
  (`proceso.cierre_proximo`, campana y correo, que cada uno puede apagar como los demás avisos;
  el texto dice «se finaliza al terminar el …, último día de entrega») a los docentes con sílabos
  sin aprobar y a Coordinación de las carreras con sílabos sin aprobar, también en pausa; un
  solo aviso por convocatoria, persona y fecha de entrega, así que una prórroga trae un aviso
  nuevo (`convocatorias:avisar-cierre`). El aviso sale solo ese día: si el programador no corre,
  no se repite después.
- Reabrir con una prórroga (2026-11-03): «Prorrogar plazo» también se usa sobre una
  convocatoria finalizada, la haya finalizado el sistema o Administración, y la vuelve a abrir
  con los mismos límites (como máximo 2 prórrogas, una fecha posterior a la entrega vigente, no
  en el pasado y sin pasar del último día de clases). No se reabre si hay otra convocatoria
  abierta o pausada: «Hay otra convocatoria en curso; finalícela o elimínela antes de reabrir
  esta», también en `extension_block_reason`. Al finalizar se guardan la fecha
  (`finalizada_en`) y el estado de cada convocatoria de carrera, abierta o pausada
  (`estado_previo_cierre_convocatoria_carrera`); la migración los tomó de la auditoría para las
  ya finalizadas. Al reabrir, la convocatoria vuelve a «abierto», cada convocatoria de carrera
  vuelve a su estado previo y los sílabos dejan la fotografía `closed_document` y vuelven a
  verse en vivo; queda en la auditoría `proceso_silabos.reabierto`. Si la plantilla cambió
  después de finalizar (o durante la pausa en la que se finalizó), pide la misma confirmación
  que la reanudación (422 `plantilla_cambiada` con el conteo y `confirmar_rehacer`) y adapta
  los sílabos como al reanudar.
- Administración solo prorroga la fecha de entrega (I-114), como máximo 2 veces por
  convocatoria (2026-11-02, constante en código `SyllabusProcess::MAX_EXTENSIONS`, no un ajuste
  del sistema) y sin pasar del último día de clases del período (`fecha_fin`). El contador
  `cantidad_prorrogas` se llenó desde la auditoría; el servidor rechaza la tercera prórroga y
  la fecha posterior al último día, y la pantalla muestra «Prórroga N de 2» y desactiva
  «Prorrogar plazo» con el motivo. Una convocatoria finalizada
  queda solo para consulta y no se elimina mientras siga así; solo una prórroga la reabre. Eliminar
  (2026-11-02, reemplaza «solo mientras ningún docente haya enviado su sílabo») se admite
  con la convocatoria abierta o pausada aunque tenga sílabos enviados, en revisión o
  aprobados, y borra de verdad la convocatoria, las de cada carrera y todos sus sílabos con
  su historial. Antes se ve cuántos sílabos se borran por estado y se escribe «eliminar
  convocatoria» y un mensaje obligatorio. El mensaje llega como aviso «proceso.eliminado» y
  por correo, que no se apaga en las preferencias, a los docentes con sílabo, a Coordinación
  de sus carreras y a Biblioteca. El período queda libre para abrir otra convocatoria y no
  se elimina mientras tenga una. No hay restaurar (I-231).
- Cada carrera abre su propia convocatoria dentro de la institucional. Coordinación
  solo puede abrirla si hay asignaturas, cada una con paralelo, cada paralelo con
  docente, el proyecto de carrera completo, el logo de la facultad y el plazo vigente. El rechazo
  lista todo lo que falta.
- Al abrirla se crea un sílabo por paralelo. Coordinación puede pausarla y reanudarla.
- Con la convocatoria institucional abierta no se cambia la plantilla. Con la de una
  carrera abierta no se cambian su malla ni sus proyectos de carrera.

## Plantilla

- Hay una sola plantilla. Se edita en el sitio, sin versiones ni publicación (I-32).
- La plantilla la define Administración, y el Word que genera es el formato oficial del
  sílabo. No hay otra autoridad que la apruebe (antes PV-01 y PV-07; confirmado por el
  responsable del producto el 2026-10-26).
- La plantilla es solo datos (ADR-0011, I-229). `plantilla_silabo.definicion_plantilla`
  guarda el estilo del documento, las variables, los bloques (valor, reglas y diseño),
  las secciones obligatorias, las de biblioteca y las plantillas listas. Las secciones y
  los campos van en sus tablas. Manual: `docs/architecture/template-json.md`.
- Cada campo dice quién lo llena: `docente`, `coordinacion` o `institucional`. Su
  contenido es un documento TipTap (I-210).
- Las plantillas nuevas o importadas en formato 2 protegen el formato de Administración
  (ADR-0012). Docencia y Coordinación completan párrafos y celdas vacíos; texto,
  encabezados, columnas y estilos se conservan. Una tabla con encabezados y una sola
  fila vacía permite variar filas; las demás tablas son fijas. No hay permisos nuevos
  por celda. Las definiciones anteriores se mantienen hasta actualizar la plantilla.
  La plantilla activa de Sílabos se actualizó el 2026-10-26 conservando campos y valores;
  la convocatoria sigue pausada mientras Coordinación completa las unidades.
- El lenguaje de bloques se limita a lo que usa la plantilla oficial (2026-10-27): datos
  de texto, elección, número, grupo y lista con el vocabulario de JSON Schema (`string`,
  `oneOf`, `number`, `object`, `array`, `title`, `maxLength`…), más `multiline`, `initial`
  y `keep_if`; la pantalla obedece a `multiline`. Los nombres anteriores se traducen al
  leer. El formato se publica como JSON Schema (`/plantilla.schema.json`, `$schema` en la
  exportación). Propiedades solo de texto; condiciones simples (`equals`, `empty`, `zero`). Se retiraron `from`, `row`, `columns`, las
  condiciones compuestas y los bloques `si_no` y `repetible`. La sección 6 conserva el
  formato oficial de una tabla por unidad y el docente escribe cada unidad.
- Administración la crea en blanco o con el formato oficial. La edita en un editor
  visual tipo Word. «Editar» es la acción principal; «Opciones avanzadas» contiene
  «Diseño» y «Editar JSON» (este abre el diálogo de edición, exportación e
  importación JSON). Diseño se abre en un diálogo desde ese menú (2026-10-31). «Listas» se
  retiró (2026-11-02): los límites de unidades y semanas quedan en la definición, para que
  Administración no los cambie por error.
  Importar reemplaza la plantilla entera, pero los proyectos de carrera pasan a los campos de
  Coordinación con la misma clave (2026-10-27).
  La barra de acciones se alinea con el borde derecho de la primera hoja según
  su papel y orientación, limitada al ancho disponible de pantalla.
- La letra, los colores, los márgenes y la alineación los fija la plantilla. El Word
  numera las páginas.
- Todo campo del docente es obligatorio. No hay límites de caracteres ni opción
  «Obligatorio» por campo.
- Cada revisión enviada congela la definición de la plantilla que usó.
- Para cambiar la plantilla con sílabos existentes, Administración pausa la convocatoria
  institucional. Al reanudar confirma, y los sílabos afectados se adaptan a la plantilla:
  cada campo del docente conserva la respuesta del campo con su misma clave si todavía encaja en él (si no encaja, solo ese campo queda vacío); los de Coordinación vuelven del proyecto de carrera y los campos quitados se descartan. Los enviados o aprobados vuelven a borrador sin historial ni firmas, porque
  deben cumplir la plantilla vigente. Las convocatorias cerradas no se tocan (I-118).

## Proyecto de carrera

- El proyecto de carrera de cada asignatura reúne los campos que llena Coordinación. Sus valores se
  guardan en `valores_campo` con `asignatura_id` (I-204).
- Coordinación importa proyectos de carrera con un Excel generado automáticamente desde las materias
  activas de su carrera y todos los campos vigentes asignados a Coordinación. El libro
  protege títulos, códigos y nombres; solo se completa el contenido. Las tablas llevan
  las columnas de la plantilla. No requiere IA, OCR ni cola.
- «Importar desde Excel» permite descargar, completar, cargar y revisar una vista previa.
  Coordinación selecciona materias y campos antes de aplicar; las celdas vacías
  conservan los valores actuales. Archivo y vista previa se mantienen solo mientras
  el diálogo está abierto; cerrar o recargar exige volver a subir el Excel. No hay
  importaciones persistidas, caché ni contenido de vista previa en sesión.
- El botón «Importar desde Excel» de Proyecto de carrera usa la misma variante verde
  `success` que las demás entradas de importación Excel.
- El servidor valida el alcance, la estructura y su vigencia al cargar y al aplicar.
  Si cambian los campos o las materias, exige descargar una plantilla nueva. Rechaza
  fórmulas y modificaciones de identificación; la protección del Excel no sustituye
  la autorización. No hay versiones de plantilla: se compara una huella de estructura.
- Se reutilizan la comparación con valores actuales, los permisos, la transacción y
  la confirmación para regenerar sílabos en curso. Se eliminaron la carga antigua de
  documentos, los lectores OCR, los gateways de extracción de proyectos de carrera y sus trabajos
  de cola. Se elimina `importaciones_ficha`, incluidos sus registros históricos, con
  una nueva migración y respaldo previo. La aplicación relee el mismo Excel al
  confirmar, compara sus huellas y guarda únicamente la selección en `valores_campo`.
- Los campos del proyecto de carrera son solo de Coordinación: el docente no los edita y el proyecto de carrera
  está completa solo si todos tienen texto. Se eliminó el interruptor «El docente puede
  editarlo» (PV-21, 2026-10-30; reemplaza I-230).
- El docente declara de 1 a 8 unidades (empieza con 4) y escribe el nombre y el
  resultado de aprendizaje de cada una dentro de su tabla del formato oficial, y
  completa la planificación semanal. Para enviar, cada unidad debe tener nombre y
  resultado, y las unidades suman las semanas de clase del período (2026-10-27: el
  Reglamento de Régimen Académico del CES no fija unidades ni semanas fuera de Salud,
  arts. 10 y 113; el período guarda sus semanas de clase, calculadas con las fechas y
  corregibles al crearlo). El proyecto de carrera ya no tiene «Unidades y resultados de aprendizaje»
  (decisión del responsable del producto del 2026-10-27, como en los antecedentes). Las horas ACD, APE y AA de toda la
  planificación deben sumar las de la asignatura para enviar. Activar el nuevo formato
  en una plantilla existente exige pausa y las confirmaciones de regeneración vigentes;
  el despliegue no modifica por sí solo la plantilla activa.
- Cambiar la malla o un proyecto de carrera con sílabos existentes exige pausar la convocatoria de la
  carrera y conserva lo escrito por el docente (PV-21, 2026-10-30). Solo cambian los
  sílabos cuyo documento muestra el dato: horas, créditos, modalidad, nombre, código,
  ciclo y unidad de su materia, y los códigos de sus requisitos. Los enviados, en
  corrección o aprobados vuelven a Borrador sin revisiones ni firmas, tras confirmar
  escribiendo «devolver a borrador», y el docente recibe un aviso por correo. Activar o
  desactivar «Puede generar sílabos» rehace solo los de esa materia.

## Documentos de apoyo

- Coordinación sube documentos de apoyo para los docentes de su carrera: PDF, Word
  (`doc`, `docx`) o Excel (`xls`, `xlsx`) de hasta 20 MB, con un nombre. Son de la carrera,
  no de una asignatura ni de un período, y no dependen de la convocatoria (2026-11-02).
- Los ven Coordinación y los docentes con rol activo en esa carrera. Se abren en una
  ventana aparte como PDF; no se descarga el original ni se muestra su tipo.
- Word y Excel se pasan a PDF con Gotenberg en la cola `documentos`; antes, cada hoja de
  Excel se ajusta al ancho de la página (horizontal si pasa de 8 columnas) y salen todas
  las hojas. Sin Gotenberg o si la conversión falla, quedan sin vista y hay que volver a subirlos.
- Están en la pestaña «Documentos de apoyo» de Proyecto de carrera; el docente los ve en
  su propia entrada del menú.
- Eliminar borra el documento y sus archivos del servidor (original y vista), para
  ocupar solo el espacio necesario. Es la única excepción a la inmutabilidad de
  `archivos` (2026-11-02).

## Elaboración del sílabo

- Catálogo de la biblioteca (POR VALIDAR con la biblioteca, 2026-10-31): el docente busca un libro
  por autor, título, ISBN o palabra clave en el catálogo Koha de la UEB y elige uno para llenar
  la fila de la bibliografía. Es solo lectura, solo de datos del libro (nada de préstamos ni
  ejemplares) y el docente nunca llama a Koha: lo hace el servidor. El resultado va en una tabla con
  paginación de 20 libros por página (hasta 100 páginas); cada palabra se busca como prefijo y todas deben aparecer.
  Cada facultad guarda en qué bibliotecas de Koha buscan sus docentes los libros físicos (vacío: todas); Administración
  las elige por su nombre desde la lista que publica Koha (`/api/v1/public/libraries`), sin escribir códigos, en el
  formulario de la facultad con casillas que listan las bibliotecas disponibles: pestaña «Bibliotecas» al editar y segundo
  paso al crear (todas marcadas al empezar). Marcar todas equivale a «todas» (se guarda vacío y entran también las que se
  agreguen después); al menos una debe quedar marcada. Se guarda con el resto de la facultad, así que sigue el mismo
  bloqueo de edición. Los libros digitales no
  están en Koha y los escribe el docente (2026-11-01). Va detrás de
  `BibliographicCatalog` y `CATALOG_DRIVER` vale `disabled` por defecto; `koha` usa las consultas
  públicas del catálogo y no necesita usuario. La bibliografía básica y la complementaria son el
  bloque `bibliografia` de la plantilla: cada columna tiene un nombre interno estable y la plantilla
  declara con `catalog` qué dato del libro va a cada una, así el buscador no depende del texto de
  los encabezados. «Código» dice qué tipo de material es, como en los sílabos de referencia. Koha solo tiene
  material físico y el tipo sale de su campo 942c: Libros y Referencia → «Libro físico», Tesis → «Tesis física»,
  Recursos continuos → «Revista física». Los resultados muestran ese tipo en la columna «Tipo»; los
  digitales («e-libro») no están en Koha y el docente los escribe. Al elegir un libro, el dato que Koha no tiene
  (ciudad, editorial, ISBN…) queda en «N/A» (2026-11-02). Falta el acceso por API y HTTPS (PV-22).
- Cada paralelo tiene su sílabo. Su docente es quien tiene la asignación vigente.
- Estados: Sin iniciar, Borrador, En revisión, Corrección solicitada y Aprobado.
- El docente edita en Borrador y en Corrección solicitada solo sus campos. Ve una vista
  previa del documento.
- Al abrir su sílabo, el docente hereda sus campos del último sílabo suyo de esa
  asignatura en una convocatoria cerrada (aunque cambie de paralelo), si la plantilla no
  se modificó después de que empezó ese sílabo. Nace en Borrador sin firmas; no hay aviso
  ni botón para descartar. Un docente nuevo en el paralelo empieza de cero (PV-21,
  2026-10-30).
- Abrir la convocatoria y entrar a editar la plantilla piden confirmar con una espera de
  unos segundos y explican que un cambio de plantilla hace empezar de cero.
- El docente llena un formulario con los componentes del sistema, generado desde la
  plantilla; el aspecto del Word queda solo en la vista previa (2026-10-27).
- Solo puede enviar entre la fecha de inicio y la de entrega, con la convocatoria sin
  pausa.
- Puede copiar el contenido desde otro paralelo suyo de la misma asignatura, si el
  destino no se ha enviado (I-167).
- Cambiar el docente o crear, editar o quitar paralelos no exige pausa. Un docente
  nuevo empieza el sílabo desde cero (I-174, I-224).
- Biblioteca (2026-11-02): la bandeja muestra un sílabo por fila, con su envío más reciente, y la columna «Responsable». El bibliotecario que devuelve un sílabo queda como su responsable (reserva blanda): al reenviarse, la pantalla dice quién lo atiende y pide confirmar a cualquier otro bibliotecario antes de devolverlo o firmarlo; no se bloquea nada, y lo que se haga queda en la auditoría con su autor.
- Estados con un solo vocabulario (2026-11-02): Biblioteca muestra sus envíos con las palabras del sílabo (En revisión, Aprobado, Corrección solicitada; Anterior si hay una versión más nueva) y no con otras. La nota del coordinador dice «Corrección solicitada por biblioteca».
- Cambiar el nombre, campus o logo de una facultad, o el nombre, código, modalidad o facultad de una carrera, con sílabos enviados o aprobados en la convocatoria actual pide confirmación y los devuelve a Borrador conservando lo escrito, como la malla (2026-11-01). Administración puede hacerlo siempre, también con la convocatoria de la carrera abierta: no espera a que Coordinación la pause (2026-11-02).
- Cambiar código o jornada de un paralelo con el sílabo enviado o aprobado pide
  confirmación y lo rehace desde cero (I-224).
- Coordinación solo reinicia un sílabo aprobado, con la convocatoria abierta o pausada
  (2026-11-01; antes también en Borrador, En revisión o Corrección solicitada, I-178). No
  hay reapertura: el docente empieza de nuevo.

## Revisión

- El orden es Docente → Coordinación → Biblioteca → Aprobado (I-220).
- Coordinación aprueba o pide corrección con un comentario general (I-177).
- Biblioteca revisa la bibliografía: la aprueba o devuelve el sílabo marcando motivos
  predefinidos (I-177): libro no disponible, código incorrecto, ISBN incorrecto, edición
  desactualizada y datos incompletos (confirmados por Biblioteca el 2026-10-26). Si marca
  «Otro motivo», lo escribe; el texto se une a los motivos marcados en la misma
  justificación de la revisión, sin columnas nuevas (2026-10-27).
- Si Biblioteca devuelve, el reenvío del docente va directo a Biblioteca (I-220).
- Una revisión enviada o aprobada no se modifica (ADR-0002).
- Con la convocatoria cerrada, la revisión queda solo para consulta.
- Biblioteca ve dos estados en su bandeja (2026-10-28, firmas al decidir): «Por revisar» y
  «Atendidos». «Atendido» junta lo firmado y aprobado por biblioteca, lo devuelto al docente y lo
  reemplazado por una revisión nueva; la API lo detalla en `outcome` (`firmado`, `devuelto`,
  `reemplazado`). Ya no existe «Por firmar» (`por_firmar`).

## Firmas y documentos

- El PDF usa solo los tamaños de letra de la plantilla: título, secciones y contenido. Las tablas no achican la letra ni ponen encabezados en
  vertical; una palabra que no cabe se parte en su celda, como en la vista previa
  (2026-11-02).
- Ningún PDF lleva número de página ni la línea «Revisión · asignatura · huella ·
  generado», ni el de Gotenberg ni el de texto simple; la vista previa tampoco numera
  las páginas (2026-11-02).
- Firmas al decidir (2026-10-28, reemplaza la firma secuencial del PDF ya aprobado): cada
  persona firma una sola vez por sílabo con su `.p12` en el navegador, en el momento de
  decidir. El docente firma al primer envío («Firmar y enviar»); los reenvíos tras una
  corrección no se firman. Coordinación firma al aprobar («Firmar y enviar a biblioteca»)
  y Biblioteca al aprobar («Firmar y aprobar»), con lo que el sílabo queda aprobado. Pedir
  corrección o devolver no se firma. Reiniciar o eliminar el sílabo borra sus firmas.
- Se firma una declaración de texto que da el servidor
  (`silabos-ueb|silabo:{id}|accion:{envio_docente|aprobacion_coordinacion|aprobacion_biblioteca}|huella:{sha256}`)
  con firma CMS/PKCS#7 separada (SHA-256). La huella es la del contenido que se decide: la
  fotografía que envía el docente o la `hash_contenido` de la revisión. Se guarda en
  `firmas_silabo` y se audita como `silabo.firmado` (I-227).
- El titular del certificado debe coincidir con el nombre de la cuenta. Si se configura
  la lista de entidades certificadoras (DT-16), la cadena debe llegar a una de ellas.
- Cuando Biblioteca aprueba, el sistema genera en cola un solo PDF (el Word es un paso
  interno y no se guarda ni se descarga). Gotenberg convierte el Word en PDF; sin
  Gotenberg el PDF sale como texto simple. El cuadro de firmas lleva un sello visible por
  persona: «Firmado electrónicamente por NOMBRE · ROL · fecha · certificado N.º · emisor».
- Si la bibliografía cambió después de la firma del docente o de Coordinación, el PDF
  igual lleva sus sellos: no se vuelve a firmar (decisión del usuario).
- El PDF se descarga apenas existe. Cada descarga queda en auditoría.

## IA

- El proveedor se elige con `AI_DRIVER` (`openai`, `claude` o `deepseek`) y está apagado
  por defecto (I-73). En el piloto se usa DeepSeek (antes PV-14; confirmado el 2026-10-26).
- El docente tiene un chat que usa los datos de la carrera, de la asignatura y lo que ya
  escribió en el sílabo (contexto `free-text-v3`).
- La IA se limita al chat del docente. El proyecto de carrera se importa exclusivamente por Excel
  estructurado; se retiró su configuración de OCR y extracción por IA.
- La IA nunca aprueba, bloquea ni escribe sola. Si falla, solo se pierde la ayuda de IA
  (ADR-0003).

## Operación y datos

- Los buscadores de tablas no distinguen tildes ni mayúsculas. En el servidor se resuelve
  con `App\Support\Search\AccentInsensitive`, sin extensiones de la base. Biblioteca busca por
  asignatura, código, carrera o docente; Auditoría no tiene búsqueda de texto.
- La API JSON y la SPA son proyectos separados (ADR-0008).
- El esquema está en español. La llave primaria se llama como la entidad
  (`valor_campo_id`) (I-28, I-179).
- La auditoría no depende de las cuentas: guarda una copia del nombre, rol y carrera del
  actor y no se modifica (I-194).
- No hay tabla de trabajos funcionales ni de trabajos fallidos. Cada recurso guarda su estado;
  los trabajos de cola son únicos e idempotentes (`RunsOnce`) (I-221, I-226).
- No hay Redis (2026-10-27). Colas, caché y bloqueos usan PostgreSQL con los drivers `database`
  de Laravel (`cola_trabajos`, `datos_temporales`, `bloqueos_temporales`): con decenas de
  docentes, un servicio menos que instalar en la UEB pesa más que la velocidad de Redis. Un
  servidor que aún diga `QUEUE_CONNECTION=redis` o `CACHE_STORE=redis` usa `database`. Los trabajos
  que estuvieran en Redis al cambiar se pierden; el estado funcional sigue en PostgreSQL.
- Los textos de «Ayuda de esta pantalla» son fijos y viven en `ScreenHelpCatalog` (2026-10-28).
  No hay tabla `ayudas_pantalla` ni pantalla de Administración para editarlos: cambiar un texto
  es un cambio de código. Las etiquetas de auditoría `ayuda.guardada` y `ayuda.eliminada` se
  conservan solo para mostrar los eventos ya registrados.
- Solo se avisa a quien debe actuar o cuyo trabajo cambió (I-186). Cada aviso guarda su
  período académico; la campana y Notificaciones muestran solo los del período en curso
  (2026-10-27). Administración solo recibe el fallo al generar el PDF.
- Por correo llegan pocos avisos (2026-10-27): solo los que piden actuar y ocurren poco,
  en esta lista inicial para el docente: convocatoria abierta, corrección solicitada y sílabo
  aprobado (PDF listo). Un correo por aviso y por persona, nunca repetido, y solo a
  cuentas activas con correo verificado. La lista se ajusta con `NOTIFICATION_EMAIL_EVENTS`
  y se apaga con `NOTIFICATION_EMAILS=false`.
- Coordinación recibe un solo correo al día, de lunes a viernes a las 08:00 (hora de la
  institución), y solo si tiene algo pendiente: sílabos por revisar de las
  convocatorias abiertas o pausadas de su carrera. Con la convocatoria cerrada no insiste.
  Se ajusta con `NOTIFICATION_DIGEST_TIME` y se apaga con `NOTIFICATION_DIGEST=false`.
  Administración y Biblioteca no reciben correo de avisos. Todo es provisional hasta
  resolver PV-15.
- Administración edita la ayuda de cada pantalla.
- Interfaz (2026-10-27): los campos y selects usan el borde de las tablas (el alto contraste
  lo refuerza); no hay tooltips nativos del navegador, solo el `Tooltip` del sistema; los
  loadings imitan la pantalla real. Revisión de Coordinación y Mis sílabos no tienen
  indicadores arriba. Los campos obligatorios vacíos no muestran el globo del navegador:
  el campo se marca en rojo y el mensaje sale debajo (`lib/nativeValidation.ts`).
- Los archivos son privados y registran quién los subió (I-180).
- Un push a `main` despliega en Dokploy y ejecuta las migraciones.
