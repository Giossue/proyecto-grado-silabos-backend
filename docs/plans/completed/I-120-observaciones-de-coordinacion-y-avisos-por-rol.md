# I-120 — Observaciones solo de Coordinación y avisos por rol

## Objetivo

1. Retirar la respuesta del docente a las observaciones: solo Coordinación escribe
   observaciones; el docente corrige el sílabo y lo reenvía.
2. Ampliar las notificaciones internas para que cada rol se entere de lo que le toca
   hacer, reutilizando el mecanismo idempotente de `ejecucion_trabajo`.

## Fuente de decisión

Instrucción explícita del responsable del producto (23 de septiembre de 2026): «el
docente no hace ninguna observación; solo puede hacerlo el coordinador» y aprobación de
la matriz de avisos propuesta, salvo la respuesta del docente. No toca `PV-15`: los
avisos son internos; el correo sigue fuera de alcance.

## Cambios

### Observaciones

- [x] Se retiran `RespondToObservation`, `RespondObservationRequest`,
      `SyllabusPolicy::respond` y la ruta
      `POST teacher/syllabi/{syllabus}/observations/{observation}/response`.
- [x] `VerifyObservation` ya no exige una respuesta fijada: estar en revisión implica que
      el docente reenvió su corrección y Coordinación verifica sobre esa revisión.
- [x] `SubmitSyllabus` deja de fijar respuestas y los payloads ya no las exponen.
- [x] Migración `000097`: elimina `respuestas_observacion` y su función de
      inmutabilidad, pasa las observaciones `respondida` a `abierta` y restringe el estado
      a `abierta` o `verificada`. Autorizado por el responsable del producto: el sistema
      está en pruebas con datos ficticios hasta diciembre de 2026. La rama de
      `validar_relaciones_flujo()` para esa tabla queda sin uso; no se reescribe la función
      compartida.
- [x] SPA: el docente ve «Observaciones de coordinación» en solo lectura; Coordinación ya
      no muestra «Respuesta del docente».

### Avisos

Todos se programan dentro de la transacción del cambio y se entregan después del commit
(`ScheduleInternalNotification` → `DeliverInternalNotificationJob`). Solo llegan a
cuentas activas.

| Evento | Destinatarios | Tipo |
|---|---|---|
| Convocatoria institucional abierta | Coordinación de las carreras activas | `proceso.abierto` |
| Convocatoria institucional pausada / reanudada / cerrada | Coordinación de carreras con convocatoria y docentes con sílabos sin aprobar | `proceso.pausado` / `proceso.reanudado` / `proceso.cerrado` |
| Prórroga del proceso | Coordinación de carreras con convocatoria y docentes con sílabos sin aprobar | `proceso.plazo_extendido` |
| Convocatoria abierta | Cada docente con sílabos generados (mensaje con su cantidad) | `convocatoria.abierta` |
| Convocatoria pausada / reanudada | Docentes de la convocatoria | `convocatoria.pausada` / `convocatoria.reanudada` |
| Observación verificada | Docentes del sílabo | `silabo.observacion_verificada` |
| Transferencia de un sílabo | Docente entrante y saliente | `silabo.docente_asignado` / `silabo.docente_retirado` |
| Relevo global | Un resumen al entrante y al saliente (sin un aviso por sílabo) | `docente.relevo_recibido` / `docente.relevo_entregado` |
| Reemplazo de coordinación | Nueva coordinación | `coordinacion.asignada` |
| Rol nuevo en una cuenta ya activa | La persona | `usuario.rol_asignado` |
| Cuenta activada | Quien emitió la invitación | `usuario.activado` |

Descartados tras el análisis:

- **Observación agregada al docente.** Solo se observa en revisión y el docente no la ve
  hasta que se solicita la corrección, que ya notifica.
- **Sílabos sin docente tras un relevo.** El relevo exige entrante y mueve todo a la
  vez; no deja sílabos sin responsable.

Los avisos del proceso se agregaron después del retiro de la etapa de preparación: abrir
ocurre al crear la convocatoria institucional (`CreateSyllabusProcess`).

El destino de cada aviso según el rol (`NotificationDestination`) se trabaja por separado.

## Verificación

- Backend: `ReviewWorkflowTest`, `ConvocationScheduleTest`, `TeacherTransferTest`,
  `TeacherReliefTest`, `CoordinatorReplacementTest` y `ManagedUserTest` (helper
  `AssertsInternalNotifications`); Pint y PHPStan sobre los archivos tocados.
- Frontend: pruebas de `TeacherSyllabusDetailPage`, `CoordinationReviewDetailPage`,
  iconos por tipo en `NotificationsPopover`; typecheck y lint.
