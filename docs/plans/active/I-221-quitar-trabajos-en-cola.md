# I-221 — Quitar `trabajos_en_cola`

## Pedido del responsable, 2026-10-14

La tabla de trabajos en cola no hace falta: basta un estado en cada cosa. La cola real sigue
en Redis. Completa el pendiente de I-186 (entregar avisos sin un trabajo por cada uno).

## Cambios

- Avisos: `ScheduleWorkflowNotification` e `ScheduleInternalNotification` escriben directo en
  `notificaciones` dentro de la transacción del cambio; el texto sale de
  `WorkflowNotificationMessages`. La deduplicación sigue por `(usuario_id, clave_unica)`.
  Se elimina `DeliverInternalNotificationJob`.
- Exportación: el estado vive en `exportaciones_silabo`; se quita `trabajo_id`. La API de
  documentos devuelve `error` (texto seguro) en lugar de `execution`.
- Chat de IA: el estado ya estaba en el mensaje; se deja de crear la ejecución.
- Verificación de plataforma: `PlatformSmokeJob` sin tabla, registra `platform-smoke-ok`.
- Migración `000220` (no se revierte): quita la clave foránea, la columna y la tabla.

## Verificación

Suite completa, Pint y PHPStan (sin errores nuevos); frontend typecheck y Vitest.
