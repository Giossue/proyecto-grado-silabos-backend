# Ciclo de vida del sílabo

## Máquina de estados

```text
Sin iniciar
    │ crear borrador
    ▼
Borrador ── enviar revisión ──► En revisión
   ▲                              │
   │                              ├─ aprobar ──► Aprobado ── eliminar (Coordinación) ──► Sin iniciar
   │                              │
   │ solicitar corrección         │
   └──── Corrección solicitada ◄──┘
              │
              └─ reenviar nueva revisión ─► En revisión
```

Un sílabo aprobado no se reabre ni se modifica (I-178). Si tiene un error, Coordinación lo
elimina con todo su historial y el paralelo recibe un sílabo nuevo sin iniciar.

## Transiciones permitidas

| Origen | Acción | Actor | Destino | Efecto obligatorio |
| --- | --- | --- | --- | --- |
| Sin iniciar | Crear borrador | Docente asignado/sistema | Borrador | Fija plantilla y contexto académico. |
| Borrador | Enviar | Docente autorizado | En revisión (etapa coordinación) | Valida, crea revisión inmutable, avisa a coordinación y audita. |
| En revisión · coordinación | Aprobar | Coordinador de alcance | En revisión (etapa biblioteca) | Registra `revisiones_coordinacion`, crea el envío a biblioteca (`envios_biblioteca`) y avisa a biblioteca. |
| En revisión · coordinación | Solicitar corrección | Coordinador de alcance | Corrección solicitada | Un comentario general (sin observaciones por campo); `correccion_etapa = 'coordinacion'`. |
| En revisión · biblioteca | Aprobar bibliografía | Bibliotecario | Aprobado | Marca `verificado_por/en`, fija `aprobaciones` sobre la revisión inmutable, solicita el PDF y avisa al docente. |
| En revisión · biblioteca | Devolver con motivos | Bibliotecario | Corrección solicitada | Elige uno o más motivos predefinidos (sin texto libre; ver `LibraryCommentReasons`); se guarda como comentario en las columnas `correccion_*` con `correccion_etapa = 'biblioteca'`; todo el sílabo es editable. |
| Corrección solicitada · devuelta por coordinación | Reenviar | Docente autorizado | En revisión (etapa coordinación) | Crea nueva revisión; vuelve a coordinación. |
| Corrección solicitada · devuelta por biblioteca | Reenviar | Docente autorizado | En revisión (etapa biblioteca) | Crea nueva revisión y su envío a biblioteca; no pasa de nuevo por coordinación. |
| Aprobado | Eliminar | Coordinador de alcance | Sin iniciar (sílabo nuevo) | Borra el sílabo con revisiones, aprobación, PDF y firmas, crea otro sin iniciar para el paralelo, audita `silabo.eliminado` y avisa al docente. Solo con la convocatoria abierta o en pausa. |

La **etapa** dentro de «En revisión» no es una columna: se deriva de la revisión vigente
(`ReviewStage`): con envío a biblioteca y sin aprobación → biblioteca; sin
envío a biblioteca → coordinación. Solo el revisor de la etapa vigente decide; los demás consultan.

La reapertura de aprobados se retiró en I-178 (ADR-0005 reemplazado); DT-07 ya no aplica.

## Firmas (I-139, I-192)

El PDF se genera **al aprobar Biblioteca** (exportación automática) y se firma de forma
incremental en este orden: docente → coordinación → biblioteca
(`firmas_documento.firmado_como`). Cada revisión tiene su propio PDF y sus propias
firmas: cualquier cambio de contenido es una revisión nueva (reenvío), la
anterior queda `anulado` y se vuelve a firmar desde cero. Biblioteca aprueba la
bibliografía al **final** de la revisión, antes de generar el PDF; firma al final porque su
firma da fe de la bibliografía que ya aprobó.

## Relevo del docente responsable

El sílabo pertenece al paralelo y a la convocatoria, no a quien lo redacta. Cuando un docente se
retracta (por ejemplo tras el sorteo de materias) Coordinación quita o reemplaza al docente del
paralelo, también **con la convocatoria abierta**, sin pausarla. El nuevo docente **no hereda nada**:
el sílabo del paralelo se borra con todo su historial (borrador, revisiones, aprobación, firmas,
exportaciones e IA) y se crea uno nuevo «Sin iniciar» para quien entra. Vale en cualquier estado,
incluido el aprobado, y reasignar al mismo docente también empieza de cero.

Crear, editar y eliminar paralelos también se puede con la convocatoria abierta: pausarla frenaría a
todos los docentes de la carrera por el cambio de unos pocos. Un paralelo nuevo no tiene sílabo hasta
que se le asigna docente. Cambiar el código o la jornada de un paralelo actualiza la identificación de su
sílabo sin perder lo escrito; si el sílabo ya está en revisión o aprobado se rechaza, porque la revisión
enviada es inmutable (hay que quitar y volver a asignar al docente para rehacerlo). La pausa solo sigue
siendo necesaria para la malla y las fichas.

El descarte no se deshace. La interfaz avisa que cambiar o quitar al docente borra el sílabo del
paralelo.

## Plazos

El proceso institucional fija dos fechas —inicio de la elaboración y entrega del
borrador— y cada convocatoria las hereda como sus dos etapas con vencimiento. Antes del
inicio y después de la entrega, el envío se rechaza. Solo Administración prorroga la
fecha límite de entrega, sobre el proceso y sin pedir motivo; la prórroga alcanza a
todas las convocatorias del proceso y únicamente hacia adelante: adelantar la fecha
dejaría fuera de plazo a quien ya estaba dentro. La fecha anterior se conserva en el
evento de auditoría. El inicio se corrige mediante la edición del proceso cuando este se
encuentra en pausa; no forma parte de la acción de prórroga.

Administración puede eliminar una convocatoria institucional completa mientras ningún
docente haya iniciado un sílabo. La regla consulta tanto el estado actual como el evento
inmutable de inicio, de modo que un reinicio posterior no borra esa huella. La operación
elimina los alcances y expedientes todavía sin actividad y registra la eliminación en
auditoría; desde el primer inicio docente, el proceso se conserva como historial.

## Inicio institucional y alcance de carrera

Administración abre una sola convocatoria institucional vigente para toda la universidad.
La creación se inicia desde la fila del período académico y queda abierta en la misma
transacción: el período queda fijo, el nombre y la plantilla son automáticos y
Administración solo confirma las fechas de elaboración. Cada coordinador la ve en su área y solo puede iniciar el
alcance de su carrera: no define nombre, plantilla, período ni modo de agrupación.
El inicio comprueba logo propio de la facultad, malla activa, materias programadas con
paralelos y docente vigente en cada paralelo. Si todo pasa, crea la convocatoria de
carrera y exactamente un sílabo por paralelo en una transacción. I-146 retira la
selección y el requisito de Fuentes académicas, también al reanudar. La base impide
repetir proceso por período o alcance por carrera/período.

## Pausas

Una pausa detiene el reloj para el alcance que la decide. Administración pausa el
proceso y ningún docente de la universidad edita ni envía hasta que lo reanude; entre
tanto la plantilla vuelve a ser editable. Cada cambio de plantilla queda asociado a esa
pausa. Si la plantilla cambió, antes de reanudar Administración consulta el conteo por
estado y confirma **Reanudar y rehacer sílabos**. La confirmación elimina todo el
historial de los sílabos de ese proceso, incluidos borradores, revisiones, aprobaciones,
firmas y exportaciones, y crea expedientes sin iniciar con la plantilla vigente. Sin
confirmación el proceso permanece pausado. Si no hubo cambios, reanudar no reinicia
expedientes. Cambiar las fechas de entrega tampoco reinicia sílabos. Coordinación pausa su convocatoria y solo los
docentes de esa carrera se detienen; entre tanto la malla y los datos curriculares de
la carrera vuelven a ser editables según sus permisos y confirmaciones de regeneración.
Los borradores se conservan tal cual durante pausas sin cambio de plantilla. Ambas pausas exigen
motivo y quedan en auditoría; reanudar una convocatoria requiere que el proceso esté
abierto. Cerrar el proceso es definitivo: los expedientes se conservan y ya no admiten
envíos.

El respaldo normativo está en `references/normativa-silabo.md`: el reglamento vigente de
la UEB exige que la planificación microcurricular esté programada antes de iniciar el
periodo académico.

## Precondiciones comunes

- usuario activo, rol, alcance y asignación vigentes;
- convocatoria y periodo compatibles;
- al iniciar el alcance: logo propio de la facultad, malla activa, materias programadas,
  paralelos y docentes vigentes; I-146 retira la existencia de fuentes como requisito;
- malla actual activa al programar materias y abrir un proceso nuevo;
- control de concurrencia superado;
- plantilla institucional completa al abrir el proceso; la revisión enviada se renderiza desde su propia copia;
- validaciones determinísticas obligatorias ejecutadas antes del envío;
- transacción atómica y evento de auditoría.

## Revisión frente a borrador

- El borrador representa trabajo editable y puede autoguardarse.
- La revisión representa evidencia enviada y no se edita.
- El comentario de devolución (biblioteca o coordinación) queda en la revisión devuelta.
- La corrección parte de una copia controlada y produce otra revisión al reenviar.
- Un coordinador siempre revisa el contenido exacto enviado, no el borrador posterior.
- Copiar entre paralelos (I-167): si el docente tiene la misma asignatura en más de un
  paralelo, puede copiar a un sílabo «Sin iniciar» o «Borrador» lo que escribió en otro
  paralelo suyo ya iniciado. Solo se copian los campos que llena el docente; cada sílabo
  conserva su envío, revisión y firmas. Un sílabo ya enviado no se sobrescribe.

## IA en el ciclo

I-146 conserva el chat para proponer contenido y unidades desde el contexto académico
y curricular fotografiado y las tablas de planificación. La respuesta no modifica el
sílabo ni bloquea el envío. El usuario puede continuar si falla. El revisor automático,
sus recomendaciones aplicables y la consulta vectorial están retirados; su historia se
conserva.

## Casos de carrera

- Doble clic/reintento de envío: una sola revisión mediante idempotencia.
- Edición concurrente: conflicto visible; no sobrescritura silenciosa.
- Cambio de plantilla institucional: editar requiere el proceso pausado; el cambio se
  registra durante la pausa y reanudar exige confirmación con conteos por estado para
  regenerar todos los sílabos del proceso en una transacción. Se borra el historial,
  incluso de aprobados y firmados; los procesos cerrados se conservan (I-188).
- Corrección de base académica (malla o ficha de la asignatura) con el alcance pausado:
  previa confirmación, regenera desde cero los expedientes afectados del período en curso,
  en cualquier estado, incluidos los enviados y los aprobados con sus revisiones,
  aprobaciones, firmas, IA y exportaciones, en una sola transacción (I-118, aclarada en
  I-144 y ampliada el 2026-10-08 a los aprobados, igual que el cambio de plantilla). Los
  procesos cerrados se conservan. Cambiar la ficha de una asignatura rehace solo los
  sílabos de esa asignatura.
- Confirmación de datos curriculares: afecta las nuevas fotografías y los expedientes
  regenerados según el alcance permitido; no reescribe historia protegida.
- Edición o desactivación de la malla: bloquea trabajo nuevo cuando queda inactiva, pero
  no cambia el contexto académico fijado en sílabos y revisiones existentes.
- Reintento de exportación: no duplica aprobación ni cambia revisión.
- Sesión revocada durante edición: se detiene la mutación y se conserva un mensaje claro.
