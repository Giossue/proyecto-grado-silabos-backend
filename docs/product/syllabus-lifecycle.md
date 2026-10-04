# Ciclo de vida del sílabo

## Máquina de estados

```text
Sin iniciar
    │ crear borrador
    ▼
Borrador ── enviar revisión ──► En revisión
   ▲                              │
   │                              ├─ aprobar ──► Aprobado
   │                              │                │
   │ solicitar corrección         │                └─ reabrir
   └──── Corrección solicitada ◄──┘                     │
              │                                         ▼
              └─ reenviar nueva revisión ─► En revisión / nueva revisión editable
```

La reapertura crea una nueva línea editable vinculada a la revisión aprobada; no vuelve
mutable el documento aprobado.

## Transiciones permitidas

| Origen | Acción | Actor | Destino | Efecto obligatorio |
| --- | --- | --- | --- | --- |
| Sin iniciar | Crear borrador | Docente asignado/sistema | Borrador | Fija plantilla y contexto académico. |
| Borrador | Enviar | Docente autorizado | En revisión (etapa biblioteca) | Valida, crea revisión inmutable, abre el envío a biblioteca y audita. |
| En revisión · biblioteca | Aprobar bibliografía | Bibliotecario | En revisión (etapa coordinación) | Marca `verificado_por/en` en `envios_biblioteca`; avisa a coordinación. |
| En revisión · biblioteca | Comentar | Bibliotecario | Corrección solicitada | columnas `correccion_*` de la revisión con `correccion_etapa = 'biblioteca'`; todo el sílabo es editable. |
| En revisión · coordinación | Observar / solicitar corrección | Coordinador de alcance | Corrección solicitada | Exige observaciones abiertas; `correccion_etapa = 'coordinacion'`. |
| En revisión · coordinación | Aprobar | Coordinador de alcance | En revisión (etapa decanato) | Crea `revisiones_coordinacion` (una por revisión, inmutable); da por resueltas las observaciones; avisa al decano. |
| En revisión · decanato | Solicitar corrección | Decano de la facultad | Corrección solicitada | `correccion_etapa = 'decanato'`, con justificación. |
| En revisión · decanato | Aprobar | Decano de la facultad | Aprobado | Fija `aprobaciones` sobre la revisión inmutable y solicita el PDF. |
| Corrección solicitada | Reenviar | Docente autorizado | En revisión (etapa biblioteca) | Crea nueva revisión; la cadena vuelve a empezar por biblioteca. |
| Aprobado | Reabrir | Coordinador o decano | Corrección solicitada | Registra causa (`origen_reapertura` coordinación o decanato), restaura la fotografía y anula PDF y firmas. |

La **etapa** dentro de «En revisión» no es una columna: se deriva de la revisión vigente
(`ReviewStage`): sin verificación de biblioteca → biblioteca; verificada y sin
`revisiones_coordinacion` → coordinación; con revisión de coordinación y sin aprobación
→ decanato. Solo el revisor de la etapa vigente decide; los demás consultan.

ADR-0005 fija provisionalmente `Corrección solicitada` tras una reapertura. DT-07 exige
validar ese lenguaje con usuarios; un cambio futuro afectará trabajo nuevo sin alterar la
revisión ni la aprobación conservadas.

## Firmas (I-139, I-173)

El PDF se genera **al aprobar el decanato** (exportación automática) y se firma de forma
incremental en este orden: docente → coordinación → biblioteca → decanato
(`firmas_documento.firmado_como`). Cada revisión tiene su propio PDF y sus propias
firmas: cualquier cambio de contenido es una revisión nueva (reenvío o reapertura), la
anterior queda `anulado` y se vuelve a firmar desde cero. Biblioteca revisa la
bibliografía al **inicio** de la cadena, no antes de firmar; firma al final porque su
firma da fe de la bibliografía que ya aprobó.

## Relevo del docente responsable

El sílabo pertenece a la asignatura y el periodo, no a quien lo redacta, así que cambiar
de responsable no crea un expediente nuevo. El relevo cierra la vigencia de quien sale y
abre la de quien entra sobre los mismos paralelos, en una sola transacción, y exige la
referencia del acto que lo respalda.

| Estado al relevar     | Qué ocurre                                                                                        |
| --------------------- | ------------------------------------------------------------------------------------------------- |
| Sin iniciar           | Solo cambia el responsable.                                                                       |
| Borrador              | Se descarta el contenido y el nuevo docente empieza limpio. El avance perdido queda en auditoría. |
| En revisión           | No se traspasa. Primero se resuelve la revisión.                                                  |
| Corrección solicitada | Se conserva el contenido: hubo envío, así que es evidencia.                                       |
| Aprobado              | Se reabre conservando la revisión aprobada intacta; el nuevo docente hereda el contenido.         |

El descarte del borrador viene de DT-08 y no se deshace. La herencia del trabajo enviado
viene de A1. Ambas están registradas en `references/entrevista-2026-08-26-hallazgos.md`.

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
tanto la plantilla vuelve a ser editable. Coordinación pausa su convocatoria y solo los
docentes de esa carrera se detienen; entre tanto la malla y los datos curriculares de
la carrera vuelven a ser editables según sus permisos y confirmaciones de regeneración.
Los borradores se conservan tal cual. Ambas pausas exigen
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
- Las observaciones apuntan a la revisión observada.
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
- Corrección de plantilla o base académica con el alcance pausado: previa confirmación,
  guarda el cambio y regenera desde cero los expedientes afectados en curso sin
  aprobación conservada, incluidos los enviados y sus revisiones, IA y exportaciones,
  en una sola transacción (I-118, aclarada en I-144 el 2026-09-27). Conserva los
  aprobados y los expedientes reabiertos que contienen una revisión aprobada, así como
  los procesos cerrados. La corrección de resultados de aprendizaje se limita a los
  expedientes elegibles de la asignatura modificada.
- Confirmación de datos curriculares: afecta las nuevas fotografías y los expedientes
  regenerados según el alcance permitido; no reescribe historia protegida.
- Edición o desactivación de la malla: bloquea trabajo nuevo cuando queda inactiva, pero
  no cambia el contexto académico fijado en sílabos y revisiones existentes.
- Reintento de exportación: no duplica aprobación ni cambia revisión.
- Sesión revocada durante edición: se detiene la mutación y se conserva un mensaje claro.
