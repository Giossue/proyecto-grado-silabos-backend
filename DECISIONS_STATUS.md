# Decisiones vigentes

Lista de lo que está decidido hoy. Todo lo que aparece aquí está `CONFIRMADO`. Lo que
falta decidir está `POR VALIDAR` y vive solo en `docs/plans/pendientes.md`. El historial
de cada cambio está en git (etiqueta `docs-antes-de-limpieza` para la versión larga).

## Alcance

- El sistema cubre el ciclo del sílabo: elaboración, revisión, aprobación, firma y
  descarga. Admite varias facultades y carreras.
- Fuera de alcance: EVEA, matrículas, notas, horarios, integración con sistemas
  institucionales y decisiones académicas automáticas.

## Roles y cuentas

- Hay cuatro roles: Administrador, Coordinador, Docente y Bibliotecario. No existe
  Decanato (I-192).
- Coordinador y Docente tienen carrera; Administrador y Bibliotecario son globales
  (I-165).
- Cada carrera tiene una sola coordinación activa. El coordinador elige su carrera al
  entrar, aunque tenga una sola. Quien tiene un único rol de otro tipo entra directo.
- Un rol vale mientras esté activo; no tiene fechas de vigencia (I-29, I-51).
- No hay registro público. Administración crea las cuentas y la persona la activa con
  una invitación por correo.
- Administración puede quitar un rol a una cuenta, salvo el único que le queda (I-197).
- En «Usuarios y roles», la columna «Roles» muestra los roles vigentes de cada cuenta.
- Coordinación crea o incorpora docentes en su carrera, corrige su nombre y su correo
  (el correo solo antes de activar la cuenta) y puede quitarlos de la carrera sin tocar
  la cuenta (I-54, I-170, I-175).
- El coordinador puede ser docente de un paralelo de su carrera y aprobar ese sílabo.
- Una cuenta puede borrarse aunque tenga auditoría (I-194).
- Cada persona guarda en su cuenta sus preferencias de accesibilidad.

## Estructura académica

- La jerarquía es Facultad → Carrera (I-48).
- Cada facultad elige un campus de una lista fija y tiene un logo PNG con transparencia
  obligatorio. Solo Administración lo carga (I-106, I-123, I-125).
- El código de carrera es obligatorio y único (I-107).
- Cada carrera tiene una sola malla, sin versiones (I-82). Las asignaturas tienen campos
  fijos: código, nombre, ciclo, unidad de organización, modalidad, créditos y horas ACD,
  APE y AA (I-75).
- Cada asignatura indica si «Puede generar sílabos» (por defecto, sí). Una que no genera
  no recibe sílabo, no exige paralelos, docente ni ficha para abrir la convocatoria y no
  aparece en Proyecto de carrera ni en el Excel de fichas. Cambiarlo es un cambio de
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
- Para abrirla, la plantilla debe tener contenido, cada carrera activa debe tener
  coordinación y el período no debe haber terminado.
- Estados: abierto ⇄ pausado → cerrado. Administración cierra la convocatoria y con ella
  las de cada carrera (I-231). Al cerrar se congela también el documento de los
  borradores y sílabos sin iniciar; las revisiones enviadas conservan su fotografía
  existente (ADR-0012). Una convocatoria cerrada queda solo para consulta: sus
  sílabos ya no se editan, envían ni revisan, pero los aprobados todavía se firman, y
  después se puede abrir la del período siguiente.
- Un cambio de docente solo rehace el sílabo del paralelo en la convocatoria en curso;
  los de convocatorias cerradas se conservan (I-231).
- En el historial, un sílabo de un período terminado pertenece a quien lo envió; si
  nunca se envió, al docente actual del paralelo (I-231).
- Administración solo prorroga la fecha de entrega (I-114). Cerrar es la acción final:
  una convocatoria cerrada queda solo para consulta y no se elimina nunca. Eliminar
  borra de verdad y solo se admite mientras ningún docente haya enviado su sílabo; con
  sílabos enviados, la única salida es cerrar. Un período tiene una sola convocatoria,
  para siempre, y no se elimina si ya tiene una. No hay restaurar ni borrado
  definitivo (I-231).
- Cada carrera abre su propia convocatoria dentro de la institucional. Coordinación
  solo puede abrirla si hay asignaturas, cada una con paralelo, cada paralelo con
  docente, la ficha completa, el logo de la facultad y el plazo vigente. El rechazo
  lista todo lo que falta.
- Al abrirla se crea un sílabo por paralelo. Coordinación puede pausarla y reanudarla.
- Con la convocatoria institucional abierta no se cambia la plantilla. Con la de una
  carrera abierta no se cambian su malla ni sus fichas.

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
  `text`, `number`, `choice`, `object` y `list`; propiedades solo de texto; condiciones
  simples (`equals`, `empty`, `zero`). Se retiraron `from`, `row`, `columns`, las
  condiciones compuestas y los bloques `si_no` y `repetible`. La sección 6 conserva el
  formato oficial de una tabla por unidad y el docente escribe cada unidad.
- Administración la crea en blanco o con el formato oficial. La edita en un editor
  visual tipo Word. «Editar» es la acción principal; «Opciones avanzadas» contiene
  «Editar JSON», que abre el diálogo de edición, exportación e importación JSON.
  Importar reemplaza la plantilla entera, pero las fichas pasan a los campos de
  Coordinación con la misma clave (2026-10-27).
  La barra de acciones se alinea con el borde derecho de la primera hoja según
  su papel y orientación, limitada al ancho disponible de pantalla.
- La letra, los colores, los márgenes y la alineación los fija la plantilla. El Word
  numera las páginas.
- Todo campo del docente es obligatorio. No hay límites de caracteres ni opción
  «Obligatorio» por campo.
- Cada revisión enviada congela la definición de la plantilla que usó.
- Para cambiar la plantilla con sílabos existentes, Administración pausa la convocatoria
  institucional. Al reanudar confirma, y los sílabos afectados se rehacen desde cero,
  incluso los aprobados. Las convocatorias cerradas no se tocan (I-118).

## Proyecto de carrera (ficha)

- La ficha de cada asignatura reúne los campos que llena Coordinación. Sus valores se
  guardan en `valores_campo` con `asignatura_id` (I-204).
- Coordinación importa fichas con un Excel generado automáticamente desde las materias
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
  documentos, los lectores OCR, los gateways de extracción de fichas y sus trabajos
  de cola. Se elimina `importaciones_ficha`, incluidos sus registros históricos, con
  una nueva migración y respaldo previo. La aplicación relee el mismo Excel al
  confirmar, compara sus huellas y guarda únicamente la selección en `valores_campo`.
- Cada campo de la ficha tiene el interruptor «El docente puede editarlo». El sílabo
  copia el valor y el interruptor al crearse. Un campo vacío con el interruptor
  encendido no deja la ficha incompleta (I-230).
- El docente escribe el nombre y el resultado de aprendizaje de cada una de las cuatro
  unidades en su sílabo, dentro de la tabla de cada unidad del formato oficial, y
  completa la planificación semanal. Para enviar, las cuatro unidades deben tener
  nombre y resultado. La ficha ya no tiene «Unidades y resultados de aprendizaje»
  (decisión del responsable del producto del 2026-10-27, como en los antecedentes). Las horas ACD, APE y AA de toda la
  planificación deben sumar las de la asignatura para enviar, y las unidades suman 16
  semanas (confirmado por el responsable del producto el 2026-10-26). Activar el nuevo formato
  en una plantilla existente exige pausa y las confirmaciones de regeneración vigentes;
  el despliegue no modifica por sí solo la plantilla activa.
- Cambiar la malla o una ficha con sílabos existentes exige pausar la convocatoria de la
  carrera y confirmar. Los sílabos afectados se rehacen desde cero, incluso los
  aprobados.

## Elaboración del sílabo

- Cada paralelo tiene su sílabo. Su docente es quien tiene la asignación vigente.
- Estados: Sin iniciar, Borrador, En revisión, Corrección solicitada y Aprobado.
- El docente edita en Borrador y en Corrección solicitada: sus campos y los de la ficha
  con el interruptor encendido. Ve una vista previa del documento.
- El docente llena un formulario con los componentes del sistema, generado desde la
  plantilla; el aspecto del Word queda solo en la vista previa (2026-10-27).
- Solo puede enviar entre la fecha de inicio y la de entrega, con la convocatoria sin
  pausa.
- Puede copiar el contenido desde otro paralelo suyo de la misma asignatura, si el
  destino no se ha enviado (I-167).
- Cambiar el docente o crear, editar o quitar paralelos no exige pausa. Un docente
  nuevo empieza el sílabo desde cero (I-174, I-224).
- Cambiar código o jornada de un paralelo con el sílabo enviado o aprobado pide
  confirmación y lo rehace desde cero (I-224).
- Coordinación puede reiniciar un sílabo en Borrador, En revisión o Corrección
  solicitada. No hay reapertura: un sílabo aprobado solo se elimina y el docente empieza
  de nuevo (I-178).

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

## Firmas y documentos

- Cuando Biblioteca aprueba, el sistema genera el Word y el PDF en cola. Gotenberg
  convierte el Word en PDF; sin Gotenberg el PDF sale como texto simple.
- El PDF se firma en el navegador con un archivo `.p12`, en orden docente →
  coordinación → biblioteca (I-227).
- El titular del certificado debe coincidir con el nombre de la cuenta. Si se configura
  la lista de entidades certificadoras (DT-16), la cadena debe llegar a una de ellas.
- Un documento firmado que cambia no se vuelve a firmar ni tiene verificación pública de
  alteración, por pedido de la UEB.
- Word y PDF se descargan solo con las tres firmas; el PDF es el firmado. Cada descarga
  queda en auditoría.

## IA

- El proveedor se elige con `AI_DRIVER` (`openai`, `claude` o `deepseek`) y está apagado
  por defecto (I-73). En el piloto se usa DeepSeek (antes PV-14; confirmado el 2026-10-26).
- El docente tiene un chat que usa los datos de la carrera, de la asignatura y lo que ya
  escribió en el sílabo (contexto `free-text-v3`).
- La IA se limita al chat del docente. La ficha se importa exclusivamente por Excel
  estructurado; se retiró su configuración de OCR y extracción por IA.
- La IA nunca aprueba, bloquea ni escribe sola. Si falla, solo se pierde la ayuda de IA
  (ADR-0003).

## Operación y datos

- La API JSON y la SPA son proyectos separados (ADR-0008).
- El esquema está en español. La llave primaria se llama como la entidad
  (`valor_campo_id`) (I-28, I-179).
- La auditoría no depende de las cuentas: guarda una copia del nombre, rol y carrera del
  actor y no se modifica (I-194).
- No hay tabla de trabajos. Cada recurso guarda su estado; los trabajos de cola son
  únicos e idempotentes (`RunsOnce`) (I-221, I-226).
- Solo se avisa a quien debe actuar o cuyo trabajo cambió (I-186). Cada aviso guarda su
  período académico; la campana y Notificaciones muestran solo los del período en curso
  (2026-10-27). Administración solo recibe el fallo al generar Word y PDF.
- Administración edita la ayuda de cada pantalla.
- Los archivos son privados y registran quién los subió (I-180).
- Un push a `main` despliega en Dokploy y ejecuta las migraciones.
