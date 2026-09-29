# I-90 — Simplificación de registros de operación

## Decisión de trabajo

El modelo funcional conservará solo dos tablas para estas responsabilidades:

- `eventos_auditoria`: historial inmutable de acciones relevantes.
- `ejecuciones_trabajo`: trabajos asíncronos, notificaciones, idempotencia, intentos,
  estado, resultado y error.

`trabajos_fallidos` se conserva únicamente porque Laravel lo usa como registro técnico
de la cola; no forma parte del modelo funcional ni de la superficie de negocio.

Se retira `eventos_salientes`: su único consumidor actual son las notificaciones internas
del flujo de sílabos y duplicaba el estado que ya conserva `ejecuciones_trabajo`.

## Alcance

- Migrar el contenido de `eventos_salientes` a `ejecuciones_trabajo`.
- Guardar la entrada de cada trabajo en `entrada_ejecucion_trabajo`.
- Adaptar creación, entrega y reintento de notificaciones.
- Retirar el modelo y la tabla outbox.
- Actualizar pruebas, documentación y trazabilidad.

## Trazabilidad

- I-05, I-14.
- RF-066..074; RN-031..034; CU-15..17; ADM-09..10.
- RNF-001, RNF-010, RNF-022 y RNF-025.

## Plan de ejecución

- [x] Añadir migración compatible y trasladar los registros existentes.
- [x] Unificar la persistencia de notificaciones en `ejecuciones_trabajo`.
- [x] Retirar el modelo outbox y adaptar reintentos.
- [x] Actualizar pruebas y documentación.
- [x] Ejecutar verificaciones proporcionales.

## Verificación

- `DocumentOperationsTest` y `ReviewWorkflowTest`: 19 pruebas, 579 aserciones.
- Suite backend: 268 de 269 pruebas pasan; la única excepción es Redis no disponible en
  `127.0.0.1:56379`.
- `./vendor/bin/pint --test`, `php -l` y `git diff --check`: correctos.
