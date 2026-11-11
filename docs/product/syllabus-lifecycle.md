# Ciclo de vida del sílabo

## Estados

```text
                 reiniciar (Coordinación)
        ┌──────────────────────────────────────────────┐
        ▼                                              │
Sin iniciar ── abrir o copiar ──► Borrador ── enviar ──► En revisión
    ▲                                ▲          ┌── etapa biblioteca
    │                                │          └── etapa coordinación
    │                                │                 │
    │                                └── reenviar ── Corrección solicitada
    │                                                  │
    │                       Coordinación aprueba ──► Aprobado
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
| Borrador o Corrección solicitada | Firmar y enviar (primer envío) / Enviar (reenvío) | Docente | En revisión · biblioteca (o · coordinación, ver «Corrección y reenvío») | Valida, crea una revisión inmutable con huella y su envío a biblioteca, y avisa a Biblioteca («Sílabo enviado» o «Sílabo corregido para revisar»). En el primer envío exige la firma del docente. |
| En revisión · biblioteca | Firmar y enviar a coordinación | Biblioteca | En revisión · coordinación | Exige la firma de Biblioteca (solo la primera vez; después «Enviar a coordinación» sin firmar); registra quién verificó la bibliografía y cuándo; avisa a Coordinación («Bibliografía verificada por Biblioteca»). |
| En revisión · biblioteca | Devolver al docente | Biblioteca | Corrección solicitada | Uno o más motivos predefinidos, sin texto libre. Quien devuelve queda como responsable: cuando el docente reenvíe, los demás bibliotecarios ven quién lo atiende y deben confirmar antes de actuar; no hay bloqueo. |
| En revisión · coordinación | Firmar y aprobar | Coordinación | Aprobado | Exige la firma de Coordinación (si ya firmó, «Aprobar» sin firmar); registra la revisión de coordinación, crea la aprobación con huella y genera el PDF final; cuando está listo avisa al docente. |
| En revisión · coordinación | Solicitar corrección | Coordinación | Corrección solicitada | Comentario general de 10 a 10 000 caracteres. |
| Aprobado | Reiniciar | Coordinación | Sin iniciar (sílabo nuevo) | Pide un motivo y el nombre de la materia. Borra el sílabo con revisiones, aprobación, documentos y firmas; crea uno nuevo para el paralelo. |

Reiniciar solo se ofrece con el sílabo aprobado y exige la convocatoria de la carrera abierta; en pausa (de la carrera o de Administración) no se reinicia. Un sílabo en borrador, en revisión o en corrección no se reinicia. Lo mismo
vale para aprobar y pedir corrección.

## Etapas de revisión

La etapa no es una columna. Se deduce de la última revisión (`ReviewStage`):

- con aprobación: ya no hay etapa;
- con la verificación de Biblioteca registrada en su envío (`envios_biblioteca.verificado_en`): etapa coordinación;
- sin esa verificación: etapa biblioteca.

El orden es Docente → Biblioteca → Coordinación (2026-11-11). Solo decide quien tiene la
etapa. Los demás consultan. Verificar en Biblioteca no cambia el estado: el sílabo sigue
«En revisión». La aprobación final es de Coordinación.

## Corrección y reenvío

- La devolución queda en la revisión devuelta: comentario, etapa, quién y cuándo.
- Motivos de Biblioteca: libro no disponible, código incorrecto, ISBN incorrecto, edición
  desactualizada y datos incompletos.
- El docente corrige todo el sílabo y lo reenvía. No responde comentarios.
- Si devolvió Biblioteca, la revisión nueva vuelve a Biblioteca.
- Si devolvió Coordinación y el docente cambió la bibliografía desde la última revisión que
  Biblioteca verificó, la revisión nueva vuelve a Biblioteca. Si no la cambió, va directo a
  Coordinación y conserva la verificación anterior (quién y cuándo); Biblioteca no la ve como
  trabajo nuevo. «Cambió la bibliografía» se decide comparando las secciones de bibliografía
  de las dos revisiones (`BibliographySections` y `CanonicalHasher`).

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
- Docente: firma en el primer envío. Si Biblioteca o Coordinación piden corrección,
  reenvía sin volver a firmar.
- Biblioteca: firma al verificar la bibliografía («Firmar y enviar a coordinación»). Si
  vuelve a recibir el sílabo, lo envía sin volver a firmar («Enviar a coordinación»).
  Devolver al docente no se firma.
- Coordinación: firma al aprobar («Firmar y aprobar»); el sílabo queda aprobado. Pedir
  corrección no se firma. Si ya había firmado (sílabos del orden anterior), aprueba sin
  volver a firmar («Aprobar»).
- El nombre de quien verificó la bibliografía y la «Fecha de verificación» salen de la
  verificación de Biblioteca guardada en la revisión; el nombre y la fecha de Coordinación,
  de su aprobación. En los aprobados antes del cambio de orden se conservan los mismos datos.
- Se firma una declaración corta que entrega el servidor con la huella del contenido que
  se decide. El servidor comprueba la firma, que la declaración sea la esperada, que el
  certificado esté vigente y sea de firma, que su titular tenga las mismas palabras que el
  nombre de la cuenta (en cualquier orden, sin importar tildes ni mayúsculas) y la cadena
  de confianza (`docs/runbooks/firma-electronica-entidades.md`). En producción, sin lista
  de entidades de confianza no se acepta ninguna firma. Cualquier
  error se muestra en `signature` y la decisión no se registra.
- Al aprobar Coordinación se genera un solo PDF desde la copia guardada en la revisión, con
  un sello visible por persona en el cuadro de firmas. No hay descarga de Word. El PDF
  se descarga apenas existe. Si no se genera, se avisa a Administración y se puede
  reintentar desde Documentos.
- Si el contenido cambió después de las firmas del docente o de Biblioteca, el PDF
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
- Solo Administración prorroga la entrega, como máximo 2 veces por convocatoria. La fecha
  nueva debe ser posterior a la actual, no puede estar en el pasado ni pasar del último día
  de clases del período. Si ya no quedan prórrogas o el plazo ya llega a ese día, no se
  puede prorrogar. Se avisa a los docentes con sílabos sin aprobar. La prórroga también se
  usa sobre una convocatoria finalizada, la haya finalizado el sistema o Administración, y
  la vuelve a abrir (ver «Reabrir con una prórroga»).
- Con la fecha de entrega vencida, el docente queda en solo lectura: no puede iniciar,
  editar ni enviar (el servidor lo rechaza) y se le dice que pida una prórroga a
  Administración. Coordinación y Biblioteca siguen revisando lo ya enviado. Al prorrogar,
  todo se reabre y la finalización automática pasa al día después de la nueva entrega.
- Administración puede eliminar una convocatoria abierta o pausada aunque tenga sílabos
  enviados, en revisión o aprobados. Se borra de verdad, con las de cada carrera y todos
  sus sílabos (borradores, revisiones, aprobaciones, firmas y PDF), y el período queda
  libre para abrir otra. Antes se muestra cuántos sílabos se borran por estado y se pide
  la frase «eliminar convocatoria» y un mensaje. Ese mensaje llega como aviso y por correo
  a los docentes con sílabo, a Coordinación de sus carreras y a Biblioteca; ese correo no
  se puede apagar en las preferencias. Una convocatoria cerrada no se elimina nunca.
- Administración finaliza la convocatoria (acción «Finalizar»; el estado guardado es
  `cerrado` y la interfaz lo llama «Finalizado»), abierta o pausada. Se cierran también las
  de cada carrera. Sus sílabos quedan solo para consulta (no se editan, envían, revisan ni
  firman). Antes de confirmar se ve cuántos sílabos quedan sin aprobar, por estado, y se
  pide la frase «finalizar convocatoria». Mientras siga abierta o pausada no se puede crear
  otra.
- Si Administración no la finaliza, el sistema lo hace solo el día después de su fecha de
  entrega (`fecha_entrega`, día de la institución), a las 00:10 de la hora de la
  institución, abierta o pausada y con el mismo efecto que Finalizar
  (`convocatorias:cerrar-vencidas`). Se finaliza aunque haya sílabos «En revisión» o
  «Corrección solicitada»; esos quedan como estén. En la auditoría queda como acción del
  «Sistema» (`automatic: true`). No manda aviso al cerrar.
- 3 días antes de la fecha de entrega, a la hora del resumen diario, el sistema avisa
  «La convocatoria se cierra pronto» (`proceso.cierre_proximo`, campana y correo) a los
  docentes con sílabos sin aprobar y a Coordinación de las carreras con sílabos sin aprobar,
  también con la convocatoria pausada (`convocatorias:avisar-cierre`). El texto dice que se
  finaliza al terminar ese día, el último de entrega. Un solo aviso por convocatoria,
  persona y fecha de entrega: tras una prórroga llega otro aviso 3 días antes de la nueva
  fecha. El correo se puede apagar en las preferencias.

Al cerrar, los sílabos sin revisión enviada o aprobada guardan una fotografía del
documento y su definición en el contexto existente (`closed_document`). La consulta
posterior usa esa fotografía, aunque se modifique la plantilla. Las revisiones enviadas y
aprobadas conservan sus propias fotografías. Aplica a cierres realizados desde ADR-0012.
También se guardan la fecha de finalización (`convocatorias_universidad.finalizada_en`) y
el estado que tenía cada convocatoria de carrera, abierta o pausada
(`convocatorias_carreras.estado_previo_cierre_convocatoria_carrera`).

### Reabrir con una prórroga

«Prorrogar plazo» sobre una convocatoria finalizada la vuelve a abrir, con los mismos
límites de cualquier prórroga (como máximo 2, una fecha posterior a la entrega vigente, no
en el pasado y sin pasar del último día de clases). No se puede si hay otra convocatoria
abierta o pausada: «Hay otra convocatoria en curso; finalícela o elimínela antes de reabrir
esta» (también en `extension_block_reason`). Al reabrir:

- la convocatoria vuelve a «abierto» y cada convocatoria de carrera vuelve al estado que
  tenía al finalizar (abierta o pausada);
- los sílabos dejan la fotografía `closed_document` y vuelven a verse en vivo;
- los sílabos se ponen al día con lo que cambió mientras estuvo finalizada (ver «Convocatoria
  finalizada»);
- cada convocatoria de carrera que vuelve a «abierta» crea, como al reanudar, los sílabos sin
  iniciar de los paralelos que todavía no tienen uno (por ejemplo, los creados mientras estaba
  finalizada); los paralelos sin exactamente un docente vigente se omiten sin impedir la
  reapertura, y las que vuelven a «pausada» los crean al reanudarse (2026-11-03);
- cuenta como una prórroga, avisa a los docentes con sílabos sin aprobar y queda en la
  auditoría `proceso_silabos.plazo_extendido` y `proceso_silabos.reabierto`.

Si la plantilla cambió después de finalizar (o durante la pausa en la que se finalizó),
la respuesta es la misma confirmación que al reanudar: 422 con `code: plantilla_cambiada` y
el conteo por estado. Con `confirmar_rehacer` se reabre y se adaptan los sílabos: se
conserva lo escrito y los enviados o aprobados vuelven a Borrador.

### Convocatoria finalizada

Con la convocatoria finalizada (2026-11-04), los cambios de facultad, carrera, malla, proyecto de
carrera, paralelos, docentes, coordinador y fechas del período no tocan sus sílabos: ni el estado,
ni el contenido, ni `closed_document`, ni las revisiones. Un docente nuevo no borra un sílabo
aprobado y un paralelo con sílabo de una finalizada no se elimina. Si no se reabre, esos cambios
valen para la próxima convocatoria.

Al guardar uno de esos cambios con una convocatoria finalizada que aún podría reabrirse, sale este
aviso, con «Guardar de todos modos» y «Cancelar»:

> La convocatoria {período} ya finalizó. Este cambio no afecta sus sílabos: conservan los datos que tenían. Se aplicará en la próxima convocatoria, o en esta si la reabre con una prórroga. Evite estos cambios hasta abrir la próxima convocatoria, salvo que sea necesario.

Aún podría reabrirse mientras no termine el último día de clases de su período
(`fecha_fin_periodo`, día de la institución), Administración no haya creado otra convocatoria
después de ella (una abierta o en pausa, o una de un período que empieza más tarde) y queden
prórrogas y plazo. El aviso solo sale si el cambio habría llegado a sus sílabos: no con el orden en
el ciclo, el número de ciclos, las bibliotecas de Koha, un guardado sin cambios ni al quitar un
docente sin poner otro.

Al reabrir con «Prorrogar plazo», todo se pone al día con el efecto que tiene en una abierta:

| Qué cambió | Cómo se sabe | Efecto al reabrir |
|---|---|---|
| Fechas o semanas del período | Lo que guarda el sílabo y el período | Se rehacen los sílabos. |
| La materia dejó de generar sílabo | Estado de la materia | Se quitan sus sílabos. |
| Docente del paralelo | Asignación posterior a la finalización y autor distinto: la última revisión o, en un borrador, el correo de `closed_document` | Se rehace el sílabo para el docente nuevo. Volver a asignar al mismo docente no cambia nada. |
| Código o jornada del paralelo | Lo que guarda el sílabo y el paralelo | En revisión o aprobado: se rehace. Los demás actualizan la identificación. |
| Malla, facultad, carrera o proyecto de carrera | Lo que guarda el sílabo y el dato vigente | Se actualizan. Los enviados, en corrección o aprobados vuelven a Borrador. |
| Coordinador | Auditoría `academico.coordinacion.reemplazada` posterior a la finalización | Lo aprobado o firmado por el anterior vuelve a «En coordinación»; la verificación de Biblioteca se conserva. |
| Paralelo nuevo | No tiene sílabo | Recibe su sílabo sin iniciar. |

Si un sílabo enviado o aprobado volvería a Borrador o a Coordinación, o un sílabo con contenido del
docente (también un borrador) se rehace o se quita, reabrir pide confirmación antes de cambiar nada:
422 con `code: cambios_pendientes` y `counts` (`to_draft`,
`restarted`, `to_coordination`). Con `confirmar_cambios` se reabre. Si además cambió la plantilla,
`plantilla_cambiada` trae también `pending_counts` y se confirman juntas. Sin confirmar, la
convocatoria sigue finalizada y la base no cambia. Un sílabo sin iniciar se rehace sin preguntar,
porque no tiene nada que perder.

## Pausas y cambios con sílabos en curso

| Qué cambia | Requisito | Efecto |
|---|---|---|
| Plantilla | Convocatoria pausada o inexistente (Administración) | Al reanudar, si la plantilla cambió, se muestra el conteo por estado y se pide «Reanudar y adaptar sílabos». Los sílabos de la convocatoria se adaptan: cada campo del docente conserva la respuesta del campo con su misma clave si todavía encaja en él (si no encaja, solo ese campo queda vacío); los de Coordinación vuelven del proyecto de carrera y los campos quitados se descartan. Los enviados, en corrección o aprobados vuelven a Borrador sin historial ni firmas; los sin iniciar se generan de nuevo. Sin confirmación, sigue en pausa. |
| Malla: horas, créditos, modalidad, nombre, código, ciclo, unidad o requisitos | Convocatoria de la carrera pausada, finalizada o sin abrir (Coordinación) | Actualiza los datos solo en los sílabos que los muestran: los de esa materia y, si cambia su código, los de las materias que la tienen como requisito. Conserva lo escrito. Los enviados, en corrección o aprobados vuelven a Borrador tras confirmar. |
| Malla: orden en el ciclo o número de ciclos | Convocatoria de la carrera pausada, finalizada o sin abrir | Actualiza los datos sin cambiar el estado. |
| Proyecto de carrera de una asignatura | Convocatoria de la carrera pausada, finalizada o sin abrir | Copia los campos cambiados a los sílabos de esa asignatura y conserva lo del docente. Los enviados, en corrección o aprobados vuelven a Borrador tras confirmar. |
| Facultad: nombre, campus o logo. Carrera: nombre, código, modalidad o facultad | Siempre, también con la convocatoria abierta (Administración, 2026-11-02) | Los borradores toman los datos nuevos. Los enviados, en corrección o aprobados vuelven a Borrador tras confirmar, conservando lo escrito (2026-11-01). Los sílabos de convocatorias finalizadas no se tocan. |
| Docente de un paralelo | Ninguno; se puede con la convocatoria abierta | El sílabo del paralelo en la convocatoria abierta o en pausa se borra con todo su historial y empieza de cero para el docente nuevo. Un paralelo nuevo recibe su sílabo al reanudar la convocatoria de la carrera o, si está finalizada vigente, al reabrirla con una prórroga (2026-11-03). Los sílabos de convocatorias finalizadas no se tocan. |
| Código o jornada de un paralelo | Ninguno | Sin enviar: se actualiza la identificación. Enviado o aprobado: pide confirmación y lo rehace. |

Rehacer un sílabo borra su contenido, revisiones, aprobación y firmas, y avisa al
docente. Volver a Borrador conserva el contenido, borra revisiones, aprobación,
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

- **Docente:** no usa IA. El chat «Asistente de unidades» se retiró el 2026-11-05.
- **Importación de proyecto de carrera**: Coordinación completa el Excel generado con los campos
  actuales, revisa la vista previa y elige qué aplicar. La revisión queda solo en el
  navegador mientras el diálogo esté abierto. Al aplicar, el servidor relee el mismo
  Excel y guarda la selección en `valores_campo`. No requiere IA ni OCR.
- Se configura con `AI_DRIVER`. Sin proveedor, el asistente de la plantilla se desactiva
  con un aviso; la importación Excel sigue disponible.

## Casos de borde

- Doble clic o reintento de envío: una sola revisión (clave de idempotencia).
- Edición concurrente: el autoguardado detecta el conflicto y pide recargar; no
  sobrescribe en silencio.
- Reintento de exportación: no duplica la aprobación ni cambia la revisión.
- Sesión cerrada durante la edición: el cambio no se guarda y se muestra un aviso.

El respaldo normativo está en `docs/references/normativa-silabo.md`.
