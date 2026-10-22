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
- Los paralelos son fijos por asignatura y no dependen del período. Cada uno tiene
  código, jornada y como máximo un docente activo (I-209).
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
  las de cada carrera (I-231). Una convocatoria cerrada queda solo para consulta: sus
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
- La plantilla es solo datos (ADR-0011, I-229). `plantilla_silabo.definicion_plantilla`
  guarda el estilo del documento, las variables, los bloques (valor, reglas y diseño),
  las secciones obligatorias, las de biblioteca y las plantillas listas. Las secciones y
  los campos van en sus tablas. Manual: `docs/architecture/template-json.md`.
- Cada campo dice quién lo llena: `docente`, `coordinacion` o `institucional`. Su
  contenido es un documento TipTap (I-210).
- Administración la crea en blanco o con el formato oficial. La edita en un editor
  visual tipo Word o con el diálogo «Editar JSON».
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
- Coordinación puede importar la ficha desde documentos de la carrera (PDF o Excel). La
  IA propone valores solo para los campos de ficha de la plantilla; OpenParser lee los
  PDF si está activo y, si no, `pdftotext`. Coordinación revisa y aplica. Los archivos se
  borran al aplicar o descartar. Hay una importación por carrera a la vez.
- Cada campo de la ficha tiene el interruptor «El docente puede editarlo». El sílabo
  copia el valor y el interruptor al crearse. Un campo vacío con el interruptor
  encendido no deja la ficha incompleta (I-230).
- Cambiar la malla o una ficha con sílabos existentes exige pausar la convocatoria de la
  carrera y confirmar. Los sílabos afectados se rehacen desde cero, incluso los
  aprobados.

## Elaboración del sílabo

- Cada paralelo tiene su sílabo. Su docente es quien tiene la asignación vigente.
- Estados: Sin iniciar, Borrador, En revisión, Corrección solicitada y Aprobado.
- El docente edita en Borrador y en Corrección solicitada: sus campos y los de la ficha
  con el interruptor encendido. Ve una vista previa del documento.
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
  predefinidos (I-177).
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
  por defecto (I-73).
- El docente tiene un chat que usa los datos de la carrera, de la asignatura y lo que ya
  escribió en el sílabo (contexto `free-text-v3`).
- La IA también propone valores de ficha al importar documentos (solo `openai` o
  `deepseek`).
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
- Solo se avisa a quien debe actuar o cuyo trabajo cambió (I-186).
- Administración edita la ayuda de cada pantalla.
- Los archivos son privados y registran quién los subió (I-180).
- Un push a `main` despliega en Dokploy y ejecuta las migraciones.
