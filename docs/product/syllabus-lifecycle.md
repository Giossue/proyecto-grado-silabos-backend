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

| Origen                | Acción               | Actor                    | Destino                          | Efecto obligatorio                                 |
| --------------------- | -------------------- | ------------------------ | -------------------------------- | -------------------------------------------------- |
| Sin iniciar           | Crear borrador       | Docente asignado/sistema | Borrador                         | Fija plantilla y contexto académico.               |
| Borrador              | Enviar               | Docente autorizado       | En revisión                      | Valida, crea revisión inmutable y audita.          |
| En revisión           | Solicitar corrección | Coordinador de alcance   | Corrección solicitada            | Selecciona observaciones y habilita nueva edición. |
| Corrección solicitada | Reenviar             | Docente autorizado       | En revisión                      | Crea nueva revisión; Coordinación verifica sobre ella. |
| En revisión           | Aprobar              | Coordinador autorizado   | Aprobado                         | Fija aprobación sobre revisión inmutable.          |
| Aprobado              | Reabrir              | Coordinador autorizado   | Borrador o Corrección solicitada | Registra causa y crea revisión enlazada.           |
| Aprobado y enviado a biblioteca | Comentar bibliografía | Bibliotecario | Corrección solicitada | Reapertura de origen `biblioteca`; solo la bibliografía es editable (I-165). |
| Corrección solicitada de biblioteca | Reenviar bibliografía | Docente autorizado | Aprobado | Nueva revisión = copia de la aprobada con la bibliografía nueva; aprobación heredada. |

ADR-0005 fija provisionalmente `Corrección solicitada` tras una reapertura. DT-07 exige
validar ese lenguaje con usuarios; un cambio futuro afectará trabajo nuevo sin alterar la
revisión ni la aprobación conservadas.

## Firmas y verificación de biblioteca (I-139, I-165)

Biblioteca revisa **antes** de las firmas, para que un comentario no anule firmas ya
hechas. Tras la aprobación, el docente pulsa «Enviar a biblioteca» (`envios_biblioteca`,
uno por revisión). El bibliotecario revisa la bibliografía fotografiada:

- si los libros están, **aprueba la bibliografía** (`verificado_por`/`verificado_en`);
  entonces se genera el PDF y se firma en orden docente → coordinación → biblioteca;
- si no, **comenta**. El comentario es una reapertura con `origen_reapertura =
  'biblioteca'` que restaura la fotografía aprobada y deja el sílabo en «Corrección
  solicitada» con solo la sección `bibliografia` editable.

Al reenviar, el servidor arma la revisión N+1 copiando la fotografía aprobada y
reemplazando solo los valores de `bibliografia`, con una aprobación heredada
(`aprobacion_base_id`, mismo aprobador): no hay nueva revisión de coordinación. El
sílabo vuelve a «Aprobado» y el docente la envía otra vez a biblioteca. Esta corrección
no exige convocatoria en curso ni plazo abierto, para que ningún sílabo quede atascado.

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
