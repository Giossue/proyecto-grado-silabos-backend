# Colas y caché en PostgreSQL, sin Redis

Pedido del usuario (2026-10-27): retirar Redis. PostgreSQL atiende colas, caché, bloqueos y
límites de intentos con los drivers `database` de Laravel. El volumen es bajo (una carrera,
decenas de docentes), así que un servicio menos que instalar en la UEB pesa más que la
velocidad de Redis.

Trazabilidad: RNF-09 (tareas pesadas en segundo plano), principios 7 y 8 de
`docs/architecture/index.md`, decisión «No hay tabla de trabajos» (I-221: se refiere al estado
funcional de cada recurso, no a la cola técnica; dejarlo aclarado).

## Lo que ya se revisó

- El código **no llama a Redis directamente**: todo pasa por los drivers de cola y caché.
  `tests/Architecture/ModuleBoundariesTest.php` ya prohíbe la fachada `Redis`; se conserva.
- Usan la caché, y por tanto PostgreSQL tras el cambio: `ShouldBeUnique` de
  `GenerateSyllabusExportJob` y `AnswerSyllabusChatJob`, `RunsOnce`
  (`WithoutOverlapping`), `RateLimiter` de sesión y doble factor, el límite de mensajes de IA
  y `Cache::add` del resumen diario de Coordinación (`SendCoordinatorDailySummary`). El
  driver `database` soporta bloqueos, así que no cambian.
- Las sesiones ya están en PostgreSQL (`sesiones`).
- `config/queue.php` → `'failed' => ['driver' => 'null']`: no se guardan trabajos fallidos.
  Mantenerlo así.
- El worker (`docker/app/supervisord.conf`) corre `queue:work --queue=critica,documentos,ia,general`
  sin nombrar la conexión: toma la predeterminada, no hay que cambiarlo.
- Laravel usa `SELECT … FOR UPDATE SKIP LOCKED` en PostgreSQL para que dos workers no tomen el
  mismo trabajo.
- Ya existieron tablas `jobs`, `cache` y `cache_locks`; las borró
  `2026_09_01_000021_drop_unused_queue_and_cache_tables.php` (sirve de modelo de columnas).

## Pasos

1. **Configuración**
   - `config/queue.php`: agregar la conexión `database` (`table` `cola_trabajos`, `queue`
     `general`, `retry_after` = `QUEUE_RETRY_AFTER` con respaldo en `REDIS_QUEUE_RETRY_AFTER`,
     960 por defecto, `after_commit` false) y quitar `redis`; `failover` pasa a
     `['database', 'deferred']`.
   - `config/cache.php`: agregar el almacén `database` (`table` `datos_temporales`,
     `lock_table` `bloqueos_temporales`) y quitar `redis`; `failover` pasa a
     `['database', 'array']`.
   - Valores por defecto `QUEUE_CONNECTION=database` y `CACHE_STORE=database`. **Si el
     servidor aún dice `redis`, usar `database`** (por ejemplo
     `($queue = env('QUEUE_CONNECTION', 'database')) === 'redis' ? 'database' : $queue`):
     un push despliega solo y el servidor de pruebas todavía tiene `QUEUE_CONNECTION=redis`
     y `CACHE_STORE=redis`; sin esto, las colas y la caché fallarían hasta cambiar las
     variables.
   - `config/database.php`: quitar el bloque `redis` y el `use Illuminate\Support\Str` que
     solo usaba ese bloque.
2. **Migración** con `cola_trabajos` (id, queue, payload, attempts, reserved_at, available_at,
   created_at), `datos_temporales` (key, value, expiration) y `bloqueos_temporales` (key,
   owner, expiration), con `down`. Los nombres de tabla deben estar en español (la prueba
   `tests/Feature/Platform/SpanishSchemaTest.php` rechaza partes como `cache`, `job`,
   `jobs`). Sus **columnas** las fija Laravel: agregar las tres tablas a
   `$tablasDeFramework` de esa prueba y ampliar D-01 en `docs/plans/pendientes.md`.
   Regenerar `docs/generated/database-schema.md` si hay comando para ello.
3. **Dependencias y entorno**
   - `composer remove predis/predis`.
   - `compose.yaml`: quitar el servicio `redis` y el volumen `redis-data`.
   - `phpunit.xml`: quitar `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT` y
     `REDIS_QUEUE_RETRY_AFTER` (las pruebas siguen con `QUEUE_CONNECTION=sync` y caché
     `array`).
   - `.env.example`: `QUEUE_CONNECTION=database`, `CACHE_STORE=database`,
     `QUEUE_RETRY_AFTER=960`; quitar `REDIS_*`.
4. **Pruebas**
   - Borrar `tests/Integration/RedisConnectionTest.php`.
   - `tests/Feature/Platform/JobDispatchTest.php`: la prueba de la ventana de reentrega debe
     leer `queue.connections.database.retry_after` (mayor que el timeout más largo, 120 s de
     la exportación).
   - Nuevas: valores por defecto `database`; `redis` en el entorno resuelve a `database`;
     un trabajo encolado con la conexión `database` queda en `cola_trabajos` y
     `queue:work --once` lo procesa; un bloqueo de caché (`Cache::lock`) funciona con
     `datos_temporales`/`bloqueos_temporales`.
   - Correr `composer verify` completo y la puerta del frontend no cambia.
5. **Documentación** (todas mencionan Redis): `AGENTS.md`, `ARCHITECTURE.md`, `README.md`
   (comandos `queue:work redis …` → sin `redis`, puertos de Compose),
   `docs/architecture/stack.md`, `deployment.md`, `queues-and-jobs.md` (sección
   «Configuración de Redis»), `modules.md`, `ficha-excel.md`, `docs/product/domain-model.md`,
   `docs/quality/testing.md`, `observability.md`, `performance.md`,
   `acceptance-status.md`, `traceability.md`, `docs/runbooks/deploy-dokploy.md` (variables
   `REDIS_*`), `release-verification.md` (sección «Cola Redis real»), `demo.md`,
   `docs/security/hardening.md` (sección «Redis y trabajos»), `principles.md`,
   `threat-model.md`. Anotar la decisión en `DECISIONS_STATUS.md` (Operación y datos) y
   quitar de `docs/plans/pendientes.md` lo que ya no aplique.
6. **Tesis** (Google Doc `1BFww6XN…`, con los scripts de `temp/tesis/herramientas-gdocs`):
   2.2.4 («Redis se usa solo para colas y caché»), 2.2.8 (Redis en el texto) y su fila en la
   Tabla 2, RNF-09 de la Tabla 11, y cualquier otra mención de Redis. Hacerlo solo si el
   usuario lo pide.

## Despliegue

Después del push (que despliega y migra solo), en Dokploy: poner
`QUEUE_CONNECTION=database` y `CACHE_STORE=database`, borrar `REDIS_*`, volver a desplegar
y quitar el contenedor de Redis. Hasta entonces, el paso 1 evita que algo se rompa. Las
tareas que estuvieran en Redis al cambiar se pierden (correos o documentos en espera); el
estado funcional sigue en PostgreSQL y se puede volver a pedir.

Al terminar: borrar este plan, commit y push en `main`.
