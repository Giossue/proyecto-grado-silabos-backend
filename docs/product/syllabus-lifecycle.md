# Ciclo de vida del sílabo

## Estados

```text
                 reiniciar (Coordinación)
        ┌──────────────────────────────────────────────┐
        ▼                                              │
Sin iniciar ── abrir o copiar ──► Borrador ── enviar ──► En revisión
    ▲                                ▲          ┌── etapa coordinación
    │                                │          └── etapa biblioteca
    │                                │                 │
    │                                └── reenviar ── Corrección solicitada
    │                                                  │
    │                         Biblioteca aprueba ──► Aprobado
    │                                                  │
    └──────────── eliminar (Coordinación) ─────────────┘
```

Estados guardados (`silabos.estado_silabo`): `sin_iniciar`, `borrador`, `en_revision`,
`correccion_solicitada`, `aprobado`. Un aprobado no se reabre.

## Transiciones

| Desde | Acción | Quién | Hacia | Efecto |
|---|---|---|---|---|
| — | Abrir la convocatoria de la carrera | Coordinación | Sin iniciar | Un sílabo por paralelo; copia la ficha y el contexto académico; avisa a los docentes. |
| Sin iniciar | Abrir el sílabo | Docente | Borrador | El borrador se inicia solo al abrirlo. |
| Sin iniciar o Borrador | Copiar desde otro paralelo propio | Docente | Borrador | Copia los campos del docente y los de la ficha marcados como editables. |
| Borrador o Corrección solicitada | Enviar | Docente | En revisión | Valida, crea una revisión inmutable con huella y avisa. |
| En revisión · coordinación | Aprobar | Coordinación | En revisión · biblioteca | Registra la revisión de coordinación y el envío a biblioteca; avisa a Biblioteca. |
| En revisión · coordinación | Solicitar corrección | Coordinación | Corrección solicitada | Comentario general de 10 a 10 000 caracteres. |
| En revisión · biblioteca | Aprobar bibliografía | Biblioteca | Aprobado | Crea la aprobación con huella, genera Word y PDF y avisa al docente. |
| En revisión · biblioteca | Devolver al docente | Biblioteca | Corrección solicitada | Uno o más motivos predefinidos, sin texto libre. |
| Borrador, En revisión o Corrección solicitada | Reiniciar | Coordinación | Sin iniciar | Borra lo escrito, vuelve a copiar la ficha y la plantilla y conserva las revisiones. |
| Aprobado | Eliminar | Coordinación | Sin iniciar (sílabo nuevo) | Borra el sílabo con revisiones, aprobación, documentos, firmas y chat; crea uno nuevo para el paralelo. |

Reiniciar y eliminar exigen la convocatoria de la carrera abierta o pausada. Lo mismo
vale para aprobar y pedir corrección.

## Etapas de revisión

La etapa no es una columna. Se deduce de la última revisión (`ReviewStage`):

- con aprobación: ya no hay etapa;
- con envío a biblioteca: etapa biblioteca;
- sin envío a biblioteca: etapa coordinación.

Solo decide quien tiene la etapa. Los demás consultan. Aprobar en coordinación no cambia
el estado: el sílabo sigue «En revisión».

## Corrección y reenvío

- La devolución queda en la revisión devuelta: comentario, etapa, quién y cuándo.
- Motivos de Biblioteca: libro no disponible, código incorrecto, ISBN incorrecto, edición
  desactualizada y datos incompletos.
- El docente corrige todo el sílabo y lo reenvía. No responde comentarios.
- Si devolvió Coordinación, la revisión nueva vuelve a Coordinación.
- Si devolvió Biblioteca, la revisión nueva va directo a Biblioteca.

## Validación al enviar

- Todo campo del docente y de la ficha debe tener contenido. Si falta la ficha, el aviso
  es «La asignatura no tiene ficha: pídala al coordinador».
- Las reglas de los bloques deben cumplirse; por ejemplo, el bloque `unidades` exige
  16 semanas y horas iguales a las de la asignatura.
- La fecha actual debe estar entre el inicio y la entrega de la convocatoria, y ni la
  convocatoria ni la de la carrera pueden estar en pausa.
- «Enviar sílabo» queda deshabilitado mientras falten campos o falle una regla. El
  servidor vuelve a validar al recibir el envío.

## Documentos y firmas

- Al aprobar Biblioteca, el sistema genera el Word y el PDF desde la copia guardada en la
  revisión. También se pueden generar a mano desde Documentos.
- El PDF se firma en tres pasos y en orden: docente, coordinación, biblioteca.
- La firma ocurre en el navegador con un certificado `.p12` o `.pfx` y su clave. El
  certificado no sale del equipo; solo se sube el PDF firmado.
- El servidor comprueba que el PDF base no cambió, que se agregó una sola firma sobre
  todo el documento, que el certificado está vigente y que su titular coincide con la
  cuenta. La cadena de confianza se comprueba si está configurada
  (`docs/runbooks/firma-electronica-entidades.md`).
- Cada firma avisa al siguiente firmante.
- Word y PDF firmado se descargan solo cuando están las tres firmas.
- Cada revisión tiene su propio PDF y sus propias firmas.

## Convocatoria y plazos

- Administración crea la convocatoria de un período con dos fechas: inicio de la
  elaboración y entrega. Nace abierta. Hay una por período y solo una abierta o pausada
  a la vez.
- Para crearla, la plantilla debe estar completa y cada carrera activa debe tener
  coordinador.
- Coordinación abre la de su carrera cuando la convocatoria está abierta, el plazo no
  venció, la malla tiene materias, cada materia tiene paralelos, cada paralelo tiene un
  docente activo, las fichas están completas y la facultad tiene logo.
- Solo Administración prorroga la entrega. La fecha nueva debe ser posterior a la
  actual. Se avisa a los docentes con sílabos sin aprobar.
- Administración puede eliminar la convocatoria en cualquier estado. Se borran sus
  convocatorias de carrera y todos sus sílabos con su historial.
- Administración cierra la convocatoria cuando termina el período. Se cierran también
  las de cada carrera. Sus sílabos quedan solo para consulta (no se editan, envían ni
  revisan) y los aprobados todavía se pueden firmar. Cerrar no se deshace. Mientras
  siga abierta o pausada no se puede crear otra.

## Pausas y cambios con sílabos en curso

| Qué cambia | Requisito | Efecto |
|---|---|---|
| Plantilla | Convocatoria pausada o inexistente (Administración) | Al reanudar, si la plantilla cambió, se muestra el conteo por estado y se pide «Reanudar y rehacer sílabos». Se rehacen desde cero todos los sílabos de la convocatoria, incluidos los aprobados y firmados. Sin confirmación, sigue en pausa. |
| Malla: horas, créditos o modalidad | Convocatoria de la carrera pausada o sin abrir (Coordinación) | Pide confirmación y rehace los sílabos de la carrera, en cualquier estado, incluidos los aprobados. |
| Malla: nombre, código, ciclo, orden, unidad o requisitos | Convocatoria de la carrera pausada o sin abrir | Actualiza los datos académicos de los sílabos sin rehacerlos. |
| Ficha de una asignatura | Convocatoria de la carrera pausada o sin abrir | Pide confirmación y rehace los sílabos de esa asignatura, en cualquier estado. |
| Docente de un paralelo | Ninguno; se puede con la convocatoria abierta | El sílabo del paralelo en la convocatoria en curso se borra con todo su historial y empieza de cero para el docente nuevo. Los sílabos de convocatorias cerradas no se tocan. |
| Código o jornada de un paralelo | Ninguno | Sin enviar: se actualiza la identificación. Enviado o aprobado: pide confirmación y lo rehace. |

Rehacer un sílabo borra su contenido, revisiones, aprobación, firmas y chat, y avisa al
docente. Con la pausa, los docentes no editan ni envían; los borradores se conservan.
Prorrogar o reanudar sin cambios no rehace nada.

## Docente

- «Mis sílabos» muestra solo los sílabos de la carrera activa cuyo período aún no
  termina.
- Los períodos terminados se consultan en Historial, en solo lectura. Cada sílabo aparece
  a quien lo envió; si nunca se envió, al docente actual del paralelo.
- Copiar entre paralelos: el destino debe estar sin iniciar o en borrador; el origen,
  ser suyo, de la misma asignatura y convocatoria, y estar iniciado.
- «Quitar de la carrera» deja sus paralelos sin docente y retira su rol en esa carrera.
  La cuenta y los sílabos se conservan.

## IA

- **Chat del docente** («Asistente de unidades»): disponible mientras el docente puede
  editar. Usa los datos académicos del sílabo y el texto de sus campos. Responde sobre
  unidades y planificación. No escribe en el sílabo, no aprueba ni bloquea. Límites: 20
  preguntas por sílabo, 3 conversaciones, 6 por minuto y 4000 caracteres por mensaje.
- **Importación de ficha**: la IA propone valores de la ficha desde los documentos de la
  carrera. Coordinación elige qué aplicar.
- Se configura con `AI_DRIVER`. Sin proveedor, el chat y la importación se desactivan
  con un aviso; todo lo demás funciona igual.

## Casos de borde

- Doble clic o reintento de envío: una sola revisión (clave de idempotencia).
- Edición concurrente: el autoguardado detecta el conflicto y pide recargar; no
  sobrescribe en silencio.
- Reintento de exportación: no duplica la aprobación ni cambia la revisión.
- Sesión cerrada durante la edición: el cambio no se guarda y se muestra un aviso.

El respaldo normativo está en `docs/references/normativa-silabo.md`.
