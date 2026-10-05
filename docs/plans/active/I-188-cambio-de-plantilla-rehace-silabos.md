# I-188 — Cambiar la plantilla rehace desde cero los sílabos del periodo

> Plan para otro agente. Nadie lo ha implementado. Lee `AGENTS.md` antes de empezar.
> Numeración: la IA que llena la ficha pasa a I-189, la IA del docente a I-190 y los bloques
> prearmados de plantilla a I-191 (el plan I-187 los llamaba I-188..I-190).

## Regla pedida por el responsable, 2026-10-05

Si el administrador cambia la estructura de la plantilla, **todos los sílabos del periodo en marcha se
hacen desde cero** con la plantilla nueva, sin importar su estado (borrador, en revisión, firmado o
aprobado). Solo ese periodo: los periodos finalizados no se tocan. Aplazar el periodo es cambiar las
fechas de su convocatoria (`ExtendProcessDeadline`) y **no** rehace nada.

## Qué pasa hoy (verifícalo en el código antes de empezar)

- `plantilla_silabo` tiene una sola fila (índice único `plantilla_silabo_unica`). La plantilla se
  edita en el sitio; los sílabos no guardan una copia de ella (`valores_campo.definicion_campo_id`
  apunta a `definiciones_campo` con `ON DELETE RESTRICT`).
- `ProcessLocks::assertTemplateEditable()` bloquea toda edición mientras haya un proceso en estado
  `abierto`; con el proceso `pausado` se puede editar (`SaveTemplateSection`, `SaveFieldDefinition`,
  `SaveTemplateDocument`, `SaveTemplateAppearance`, `SaveTemplateTitle`, `ReorderTemplateBlocks`,
  `DeleteTemplateSection`, `DeleteTemplateBlock`, `CreateSyllabusTemplate`, `FormatSyllabusTemplate`).
- `docs/product/domain-model.md` (~línea 154) dice que los sílabos ya creados «conservan la plantilla
  con la que nacieron»: confirma si eso es cierto o ya no, y corrige el texto.
- Ya existe lo necesario para rehacer un sílabo:
  - `InProgressWork::restartSyllabus(Syllabus)` borra el sílabo con todo su historial
    (`deleteAggregates`: revisiones, aprobaciones, exportaciones, firmas, archivos, mensajes IA, con
    `SET LOCAL app.reinicio_institucional='on'`) y lo vuelve a generar con
    `GenerateConvocationSyllabi::generateForParallel`.
  - `ResetSyllabus` (coordinador) solo permite borrador, en revisión y corrección solicitada.
- Transiciones de proceso: `TransitionSyllabusProcess` (`pausar`, `reanudar`, `cerrar`) en
  `POST /admin/processes/{process}/{transition}`.

## Diseño (decidido; no re-discutir)

1. Editar la plantilla sigue exigiendo el proceso **pausado**. Cada edición queda anotada como
   «la plantilla cambió durante esta pausa».
2. **Al reanudar** un proceso cuya plantilla cambió durante la pausa, el sistema rehace desde cero
   todos los sílabos de ese proceso (todas sus convocatorias de carrera), en cualquier estado.
   - No se rehace en cada guardado: el admin hace muchos cambios pequeños.
   - Si la plantilla no cambió, reanudar funciona como hoy y no toca nada.
3. Antes de reanudar, el administrador ve **cuántos sílabos se perderán**, desglosados por estado
   (borrador, en revisión, aprobado…), y debe confirmar. Sin confirmación explícita la reanudación
   se rechaza con 422 cuando hay cambio de plantilla.
4. Rehacer borra el historial de cada sílabo (revisiones, firmas, aprobaciones, PDF). Es la
   voluntad del responsable. Queda **auditoría**: un evento por proceso
   (`proceso.plantilla_rehecho`) con el conteo por estado, y quién confirmó.
5. Después de rehacer, los docentes reciben un aviso («El administrador cambió la plantilla: su
   sílabo se rehízo desde cero») reutilizando `ScheduleWorkflowNotification` y
   `WorkflowNotificationRecipients`; agrega el evento a `NotificationDestination` y a
   `DeliverInternalNotificationJob::message()`. Un solo aviso por docente aunque tenga varios sílabos.
6. Cambiar fechas (aplazar) y pausar/reanudar sin cambio de plantilla **no** rehacen nada.

## Cambios

### Backend

- Cómo saber si la plantilla cambió durante la pausa: elige la opción más simple y justifícala en el
  plan. Sugerencia: columna `plantilla_modificada_en` (timestamptz null) en `plantilla_silabo`,
  actualizada por todas las acciones de edición listadas arriba (hay un punto común: añade un
  método `touch` en `SyllabusTemplate` o en `ProcessLocks::assertTemplateEditable()`), y se compara con
  el `pausado_en` del proceso (revisa si existe esa fecha; si no, guarda `pausado_en` en el proceso
  al pausar). Migración `000195` (`down()` lanza `RuntimeException`).
- Acción `RegenerateProcessSyllabi` (`app/Modules/Syllabus/Application/Actions/`): para un proceso,
  en una transacción, recorre las convocatorias de carrera y llama a
  `InProgressWork::restartSyllabus` por sílabo; bloquea con `lockForUpdate`. Debe funcionar con
  convocatorias `abierta` y `pausada`.
- `TransitionSyllabusProcess::reanudar`: si la plantilla cambió durante la pausa y el request no trae
  `confirmar_rehacer=true`, devuelve 422 con `{ code: 'plantilla_cambiada', counts: {borrador: n, ...} }`.
  Con confirmación: rehace y reanuda en la misma transacción.
- Endpoint de consulta (puede ser el mismo 422 o un `GET /admin/processes/{process}/plantilla-impacto`)
  para que la interfaz muestre el conteo antes de pedir confirmación.
- `docs/openapi.json` y pruebas de contrato.
- Quita la restricción de `ResetSyllabus` solo si hace falta; no la toques si basta con
  `restartSyllabus`.

### Frontend (`/home/giossue/Documentos/proyecto-grado-silabos-frontend`)

- En la acción **Reanudar** del proceso (página de procesos del administrador): si la API responde
  `plantilla_cambiada`, mostrar un diálogo en español, texto claro, con la tabla de conteos por estado
  y el botón destructivo «Reanudar y rehacer sílabos»; cancelar deja el proceso pausado.
- En la pantalla de plantilla (cuando está pausado y se editó): aviso fijo «Al reanudar el proceso,
  todos los sílabos de este periodo se rehacen desde cero».
- `src/api/*.ts`, tipos y pruebas Vitest.

### Documentos

- `docs/product/domain-model.md`, `docs/product/syllabus-lifecycle.md` (sección Pausas),
  `docs/product/screens.md`, `docs/product/roles-and-permissions.md`, ADR 0002 si toca el borrado
  de registros inmutables (usa el mismo bypass `reinicio_institucional` ya documentado).
- Fila en `docs/quality/traceability.md`; actualizar `temp/tablas-bd-remota.md` si hay columna nueva.

## Fuera de alcance

- Mantener versiones antiguas de la plantilla o conservar sílabos con la plantilla anterior.
- Rehacer sílabos de periodos finalizados o de otros procesos.
- Rehacer al cambiar la ficha de asignatura (eso sigue siendo el botón pendiente de I-187).

## Pruebas

- Backend: reanudar sin cambio de plantilla no toca sílabos; con cambio y sin confirmar → 422 con
  conteos correctos por estado; con confirmación se rehacen todos (incluidos en revisión, firmados y
  aprobados), el historial desaparece y el sílabo queda `sin_iniciar` con la plantilla nueva;
  sílabos de otro proceso/periodo finalizado intactos; cambiar fechas no rehace; auditoría y aviso
  único por docente; solo el administrador puede reanudar con confirmación.
- Frontend: diálogo de confirmación y cancelación, aviso en la pantalla de plantilla.
- Ejecuta suite backend completa, Pint, PHPStan; Vitest, `vue-tsc`, ESLint.

## Entregables

Plan, implementación, pruebas, trazabilidad y documentación juntos; mover a `docs/plans/completed/`
con la evidencia. La migración la aplica el deploy; no te conectes a la remota.
