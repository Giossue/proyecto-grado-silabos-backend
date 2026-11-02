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
| — | Abrir la convocatoria de la carrera | Coordinación | Sin iniciar | Un sílabo por paralelo; copia el proyecto de carrera y el contexto académico; avisa a los docentes. |
| Sin iniciar | Abrir el sílabo | Docente | Borrador | El borrador se inicia solo al abrirlo. Hereda los campos del docente de su último sílabo de esa asignatura en una convocatoria cerrada, si la plantilla no cambió desde que lo empezó. |
| Sin iniciar o Borrador | Copiar desde otro paralelo propio | Docente | Borrador | Copia los campos del docente. |
| Borrador o Corrección solicitada | Firmar y enviar (primer envío) / Enviar (reenvío) | Docente | En revisión | Valida, crea una revisión inmutable con huella y avisa. En el primer envío exige la firma del docente. |
| En revisión · coordinación | Firmar y enviar a biblioteca | Coordinación | En revisión · biblioteca | Exige la firma de Coordinación; registra la revisión de coordinación y el envío a biblioteca; avisa a Biblioteca. |
| En revisión · coordinación | Solicitar corrección | Coordinación | Corrección solicitada | Comentario general de 10 a 10 000 caracteres. |
| En revisión · biblioteca | Firmar y aprobar | Biblioteca | Aprobado | Exige la firma de Biblioteca; crea la aprobación con huella y genera el PDF final; cuando está listo avisa al docente. |
| En revisión · biblioteca | Devolver al docente | Biblioteca | Corrección solicitada | Uno o más motivos predefinidos, sin texto libre. Quien devuelve queda como responsable: cuando el docente reenvíe, los demás bibliotecarios ven quién lo atiende y deben confirmar antes de actuar; no hay bloqueo. |
| Aprobado | Reiniciar | Coordinación | Sin iniciar (sílabo nuevo) | Pide un motivo y el nombre de la materia. Borra el sílabo con revisiones, aprobación, documentos, firmas y chat; crea uno nuevo para el paralelo. |

Reiniciar solo se ofrece con el sílabo aprobado y exige la convocatoria de la carrera abierta o pausada. Un sílabo en borrador, en revisión o en corrección no se reinicia. Lo mismo
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

- Todo campo del docente y del proyecto de carrera debe tener contenido. Si falta el proyecto de carrera, el aviso
  es «La asignatura no tiene proyecto de carrera: pídalo al coordinador».
- Las reglas de los bloques deben cumplirse; por ejemplo, el bloque `unidades` exige
  las semanas de clase del período y horas iguales a las de la asignatura.
- La fecha actual debe estar entre el inicio y la entrega de la convocatoria, y ni la
  convocatoria ni la de la carrera pueden estar en pausa.
- «Enviar sílabo» queda deshabilitado mientras falten campos o falle una regla. El
  servidor vuelve a validar al recibir el envío.

## Firmas y documento

- Cada persona firma una sola vez por sílabo, al decidir, con su certificado `.p12` o
  `.pfx` y su clave en el navegador. El certificado no sale del equipo.
- Docente: firma en el primer envío. Si Coordinación o Biblioteca piden corrección,
  reenvía sin volver a firmar.
- Coordinación: firma al aprobar («Firmar y enviar a biblioteca»). Pedir corrección no se
  firma. Si Biblioteca devuelve y el docente reenvía, va directo a Biblioteca y
  Coordinación no vuelve a firmar.
- Biblioteca: firma al aprobar («Firmar y aprobar»); el sílabo queda aprobado. Devolver al
  docente no se firma.
- Se firma una declaración corta que entrega el servidor con la huella del contenido que
  se decide. El servidor comprueba la firma, que la declaración sea la esperada, que el
  certificado esté vigente, que su titular coincida con la cuenta y, si está configurada,
  la cadena de confianza (`docs/runbooks/firma-electronica-entidades.md`). Cualquier
  error se muestra en `signature` y la decisión no se registra.
- Al aprobar Biblioteca se genera un solo PDF desde la copia guardada en la revisión, con
  un sello visible por persona en el cuadro de firmas. No hay descarga de Word. El PDF
  se descarga apenas existe. Si no se genera, se avisa a Administración y se puede
  reintentar desde Documentos.
- Si la bibliografía cambió después de las firmas del docente o de Coordinación, el PDF
  igual lleva sus sellos.
- Reiniciar el sílabo borra sus firmas; el ciclo nuevo se vuelve a firmar.

## Convocatoria y plazos

- Administración crea la convocatoria de un período con dos fechas: inicio de la
  elaboración y entrega. Nace abierta. Hay una por período y solo una abierta o pausada
  a la vez.
- Para crearla, la plantilla debe estar completa y cada carrera activa debe tener
  coordinador.
- Coordinación abre la de su carrera cuando la convocatoria está abierta, el plazo no
  venció, la malla tiene materias, cada materia tiene paralelos, cada paralelo tiene un
  docente activo, los proyectos de carrera están completos y la facultad tiene logo.
- Solo Administración prorroga la entrega. La fecha nueva debe ser posterior a la
  actual. Se avisa a los docentes con sílabos sin aprobar.
- Con la fecha de entrega vencida, el docente queda en solo lectura: no puede iniciar,
  editar ni enviar (el servidor lo rechaza) y se le dice que pida una prórroga a
  Administración. Coordinación y Biblioteca siguen revisando lo ya enviado. Al prorrogar,
  todo se reabre. El cierre de la convocatoria no cambia.
- Administración puede eliminar una convocatoria abierta o pausada mientras ningún
  docente haya enviado su sílabo. Entonces se borra de verdad, con las de cada carrera y
  los sílabos en borrador, y el período queda libre para abrir otra. Si ya hay sílabos
  enviados, no se puede eliminar: hay que cerrar la convocatoria, que es la acción
  final. Una convocatoria cerrada no se elimina nunca.
- Administración cierra la convocatoria cuando termina el período. Se cierran también
  las de cada carrera. Sus sílabos quedan solo para consulta (no se editan, envían,
  revisan ni firman). Cerrar no se deshace. Mientras
  siga abierta o pausada no se puede crear otra.

Al cerrar, los sílabos sin revisión enviada o aprobada guardan una fotografía del
documento y su definición en el contexto existente. La consulta posterior usa esa
fotografía, aunque se modifique la plantilla. Las revisiones enviadas y aprobadas
conservan sus propias fotografías. Aplica a cierres realizados desde ADR-0012.

## Pausas y cambios con sílabos en curso

| Qué cambia | Requisito | Efecto |
|---|---|---|
| Plantilla | Convocatoria pausada o inexistente (Administración) | Al reanudar, si la plantilla cambió, se muestra el conteo por estado y se pide «Reanudar y rehacer sílabos». Se rehacen desde cero todos los sílabos de la convocatoria, incluidos los aprobados y firmados. Sin confirmación, sigue en pausa. |
| Malla: horas, créditos, modalidad, nombre, código, ciclo, unidad o requisitos | Convocatoria de la carrera pausada o sin abrir (Coordinación) | Actualiza los datos solo en los sílabos que los muestran: los de esa materia y, si cambia su código, los de las materias que la tienen como requisito. Conserva lo escrito. Los enviados, en corrección o aprobados vuelven a Borrador tras confirmar. |
| Malla: orden en el ciclo o número de ciclos | Convocatoria de la carrera pausada o sin abrir | Actualiza los datos sin cambiar el estado. |
| Proyecto de carrera de una asignatura | Convocatoria de la carrera pausada o sin abrir | Copia los campos cambiados a los sílabos de esa asignatura y conserva lo del docente. Los enviados, en corrección o aprobados vuelven a Borrador tras confirmar. |
| Facultad: nombre, campus o logo. Carrera: nombre, código, modalidad o facultad | Siempre, también con la convocatoria abierta (Administración, 2026-11-02) | Los borradores toman los datos nuevos. Los enviados, en corrección o aprobados vuelven a Borrador tras confirmar, conservando lo escrito (2026-11-01). Los sílabos de convocatorias cerradas no se tocan. |
| Docente de un paralelo | Ninguno; se puede con la convocatoria abierta | El sílabo del paralelo en la convocatoria en curso se borra con todo su historial y empieza de cero para el docente nuevo. Los sílabos de convocatorias cerradas no se tocan. |
| Código o jornada de un paralelo | Ninguno | Sin enviar: se actualiza la identificación. Enviado o aprobado: pide confirmación y lo rehace. |

Rehacer un sílabo borra su contenido, revisiones, aprobación, firmas y chat, y avisa al
docente. Volver a Borrador conserva el contenido y el chat, borra revisiones, aprobación,
PDF y firmas, y avisa al docente por correo: debe enviarlo y firmarlo de nuevo. Toda
confirmación de estos cambios espera unos segundos antes de habilitar el botón. Con la pausa, los docentes no editan ni envían; los borradores se conservan.
Prorrogar o reanudar sin cambios no rehace nada.

## Docente

- «Mis sílabos» muestra solo los sílabos de la carrera activa cuyo período aún no
  termina.
- Los períodos terminados se consultan en Historial, en solo lectura. Cada sílabo aparece
  a quien lo envió; si nunca se envió, al docente actual del paralelo.
- Copiar entre paralelos: el destino debe estar sin iniciar o en borrador; el origen,
  ser suyo, de la misma asignatura y convocatoria, y estar iniciado.
- «Quitar de la carrera» deja sus paralelos sin docente y retira su rol en esa carrera. Si el docente aún no verificó su correo y no tiene rol en otra carrera, también cancela su invitación pendiente: el enlace deja de servir.
  La cuenta y los sílabos se conservan.

## IA

- **Chat del docente** («Asistente de unidades»): disponible mientras el docente puede
  editar. Usa los datos académicos del sílabo y el texto de sus campos. Responde sobre
  unidades y planificación. No escribe en el sílabo, no aprueba ni bloquea. Límites: 20
  preguntas por sílabo, 3 conversaciones, 6 por minuto y 4000 caracteres por mensaje.
- **Importación de proyecto de carrera**: Coordinación completa el Excel generado con los campos
  actuales, revisa la vista previa y elige qué aplicar. La revisión queda solo en el
  navegador mientras el diálogo esté abierto. Al aplicar, el servidor relee el mismo
  Excel y guarda la selección en `valores_campo`. No requiere IA ni OCR.
- Se configura con `AI_DRIVER`. Sin proveedor, el chat se desactiva
  con un aviso; la importación Excel sigue disponible.

## Casos de borde

- Doble clic o reintento de envío: una sola revisión (clave de idempotencia).
- Edición concurrente: el autoguardado detecta el conflicto y pide recargar; no
  sobrescribe en silencio.
- Reintento de exportación: no duplica la aprobación ni cambia la revisión.
- Sesión cerrada durante la edición: el cambio no se guarda y se muestra un aviso.

El respaldo normativo está en `docs/references/normativa-silabo.md`.
