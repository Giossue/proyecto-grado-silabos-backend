# I-121 — Ámbito explícito y avisos que abren con el rol correcto

## Objetivo

1. Coordinación y Docencia eligen siempre su ámbito al iniciar sesión, aunque tengan una
   sola asignación, y esa pantalla no muestra el menú lateral hasta elegir.
2. Los avisos se pueden revisar desde esa pantalla, sin que abrir uno lleve a una ruta
   calculada para un rol que no está activo.

## Fuente de decisión

Instrucción explícita del responsable del producto (23 de septiembre de 2026). Los avisos
ayudan a quien tiene varios roles o materias a ir directo a lo pendiente sin recorrer
cada ámbito.

## Cambios

- [x] `ActiveRole::requiresExplicitSelection` incluye Docencia; Administración, rol
      exclusivo, se sigue activando sola. Sustituye para Docencia la regla de I-13.
- [x] `NotificationDestination` decide destino y asignación de cada aviso entre las
      asignaciones vigentes de la persona: docencia vigente del sílabo (por su asignación
      de paralelo), coordinación de la carrera, administración para cuentas. Si el rol
      activo sirve, se conserva; `silabo.enviado`, `silabo.reenviado` y
      `coordinacion.asignada` prefieren Coordinación.
- [x] `GET /notifications` deja `resource_path` y expone `has_destination` y `context`
      («Docencia · Software»). `POST /notifications/{id}/open` marca el aviso, activa la
      asignación con `SelectActiveRole` (auditoría `rol_activo.seleccionado`) y devuelve
      `path` y `role_changed`. Un aviso ajeno responde 403.
- [x] SPA: `/rol` usa `WorkspaceLayout` (logo, campana, tema y menú de usuario, sin menú
      lateral); `WorkspacePage` ya no elige por el docente; `useOpenNotification` recarga
      la sesión antes de navegar; la campana muestra el ámbito y oculta «Ver todas» sin
      rol activo; `NotificationsPage` usa el mismo flujo.

## Seguridad

El cambio de rol solo elige entre asignaciones que la persona ya podía activar a mano y
se revalida en `SelectActiveRole`. Ninguna pantalla confía en el aviso: las políticas de
cada recurso se siguen aplicando con el rol activado.

## Verificación

- Backend: suite completa; nueva
  `DocumentOperationsTest::test_opening_a_notification_before_choosing_a_scope_activates_the_matching_role`;
  pruebas de sesión y de avisos adaptadas al ámbito explícito y a `open`. PHPStan limpio
  en `Operations` y `ActiveRole`.
- SPA: typecheck, 122 pruebas (nuevas en `WorkspacePage.test.ts` y
  `NotificationsPopover.test.ts`), lint y `contract:check`.
