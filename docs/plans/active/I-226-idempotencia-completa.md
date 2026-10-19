# I-226 — Idempotencia completa

## Pedido del responsable, 2026-10-19

Auditoría: la idempotencia de las operaciones críticas existía, pero faltaban piezas que la
documentación oficial de Laravel recomienda. Se aplican todas.

## Cambios

- Los cinco trabajos de cola implementan `ShouldBeUnique` (`uniqueId`, `#[UniqueFor]`) y usan
  `App\Support\Queue\RunsOnce` (`WithoutOverlapping` sin reintento de la copia).
- El frontend genera una clave por intento (`src/lib/attemptKey.ts`) en envío, aprobación,
  exportación y mensaje de chat, en lugar de una por clic.
- `docs/architecture/queues-and-jobs.md` documenta el patrón y las claves de cada trabajo.

## Verificación

`JobIdempotencyTest` (unicidad, no solapamiento y descarte del duplicado), `attemptKey.test.ts` y la
suite. Sin pruebas de concurrencia reales entre procesos.
