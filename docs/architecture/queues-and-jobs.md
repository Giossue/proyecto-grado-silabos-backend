# Colas y trabajos

## Qué va a una cola

Solo lo lento o externo:

- generar Word y PDF de una revisión aprobada;
- responder una pregunta del chat de IA;
- leer los documentos de una importación de fichas y proponer cada ficha;
- enviar el correo de activación de cuenta;
- el trabajo de humo de la plataforma.

Guardar, validar, enviar, revisar, aprobar, firmar y regenerar ocurren en la petición
web, dentro de una transacción. Las notificaciones internas y la auditoría también se
guardan ahí; no usan cola.

## Trabajos

| Trabajo | Módulo | Cola | `timeout` | Intentos | Espera entre intentos | Clave única |
|---|---|---|---:|---:|---|---|
| `PlatformSmokeJob` | Operations | `critica` | 30 s | 3 | 1, 5, 15 s | clave del comando |
| `GenerateSyllabusExportJob` | Documents | `documentos` | 120 s | 3 | 5, 30, 120 s | exportación |
| `AnswerSyllabusChatJob` | AiAssistance | `ia` | 60 s | 3 | 5, 30, 120 s | mensaje |
| `PrepareFichaImportJob` | Academic | `ia` | 900 s | 1 | — | importación |
| `ExtractSubjectFichaJob` | Academic | `ia` | 150 s | 2 | — | importación:asignatura |
| `ManagedUserActivationMail` | Identity | `general` | por defecto | por defecto | — | — |

El estado de cada trabajo vive en su registro de dominio, no en una tabla de trabajos
(I-221):

| Registro | Estados |
|---|---|
| `exportaciones_silabo.estado_exportacion` | `pendiente`, `en_ejecucion`, `completado`, `fallido` |
| `mensajes_ia.contenido_mensaje->estado` | `pendiente`, `en_ejecucion`, `completado`, `fallido` |
| `importaciones_ficha.estado_importacion` | `pendiente`, `en_ejecucion`, `lista`, `fallida`, `aplicada`, `descartada` |

Los trabajos fallidos no se guardan en otra tabla (`queue.failed.driver = null`). El
método `failed()` de cada trabajo deja el registro de dominio en `fallido`/`fallida`.

## Idempotencia (I-226)

| Capa | Mecanismo | Dónde |
|---|---|---|
| Petición | `idempotency_key` (UUID) + índice único + `lockForUpdate` en la transacción | Enviar, exportar y preguntar a la IA |
| Petición | Un solo registro por revisión (índice único) + `lockForUpdate`; si ya existe, se devuelve | Aprobar en Coordinación y verificar en Biblioteca |
| Cliente | `attemptKey`: misma clave al reintentar lo mismo; clave nueva si cambia lo enviado | SPA |
| Despacho | `->afterCommit()`: si la transacción se revierte, el trabajo no se envía | Exportación, chat e importación de fichas |
| Cola | `ShouldBeUnique` con `uniqueId()` y `#[UniqueFor]`: se descarta un duplicado mientras el primero espera o corre | Los cinco trabajos de la tabla |
| Ejecución | `RunsOnce`: `WithoutOverlapping(uniqueId)->dontRelease()->expireAfter(timeout + 30)`. Si Redis entrega una copia de un trabajo que sigue corriendo, la copia se descarta | Los cinco trabajos de la tabla |
| Consumidor | Solo cambia el registro si sigue en el estado esperado | Todos |

La verificación de Biblioteca pide la exportación con la clave de la aprobación, así
que repetirla no crea otra exportación.

## Reglas para un trabajo nuevo

- El payload lleva solo identificadores, nunca objetos grandes ni secretos.
- Define `$timeout`, `$tries` y, si reintenta, `$backoff`.
- Usa `ShouldBeUnique`, `uniqueId()`, `#[UniqueFor]` y `RunsOnce`.
- Guarda el estado en el registro de dominio y cámbialo solo desde el estado esperado.
- Despacha con `->afterCommit()` cuando se crea dentro de una transacción.
- Implementa `failed()` para dejar un estado final y un mensaje seguro.
- Si su `timeout` supera `retry_after`, sube `REDIS_QUEUE_RETRY_AFTER` (ver abajo).

## Configuración de Redis

- Conexión `redis`, cola por defecto `general` (`REDIS_QUEUE`).
- `retry_after` = `REDIS_QUEUE_RETRY_AFTER`, 180 s por defecto.
- El estado funcional está en PostgreSQL. Si Redis se vacía, se pierden los trabajos en
  espera y sus registros quedan `pendiente`.

**Requisito:** `retry_after` debe ser mayor que el `timeout` del trabajo más largo.
Hoy ese trabajo es `PrepareFichaImportJob` (900 s), así que en todo entorno que use la
importación de fichas `REDIS_QUEUE_RETRY_AFTER` debe superar 900 (por ejemplo 960).
Con el valor actual (180 s), si otro worker toma la copia entregada de nuevo, el trabajo
cuenta un segundo intento; con `tries = 1` se marca la importación como `fallida`.
`RunsOnce` evita la doble ejecución, pero no ese fallo. Ver [deployment.md](deployment.md#colas-y-worker).

## Worker

Un solo `queue:work` en el contenedor del backend:

```bash
php artisan queue:work \
  --queue=critica,notificaciones,documentos,ia,integraciones,general \
  --sleep=1 --tries=3 --max-time=3600
```

- El orden de `--queue` es la prioridad.
- El `timeout` y los intentos de cada trabajo mandan sobre los del worker.
- Un trabajo largo ocupa el único worker: mientras se leen fichas, lo demás espera.

Para probar en local:

```bash
php artisan platform:smoke-job
```

## Detalle por trabajo

- `GenerateSyllabusExportJob`: arma Word y PDF desde la fotografía de la revisión,
  los guarda en `archivos` y marca la exportación `completado`. Avisa a quien la pidió.
  Ver [files-and-documents.md](files-and-documents.md).
- `AnswerSyllabusChatJob`: llama al `AiChatGateway` con la pregunta, el historial y la
  evidencia fijada. Rechaza respuestas que citan evidencia ajena. Ver
  [ai-service.md](ai-service.md).
- `PrepareFichaImportJob`: extrae texto de cada PDF o Excel, lo guarda en bloques y
  despacha un `ExtractSubjectFichaJob` por asignatura activa de la carrera.
- `ExtractSubjectFichaJob`: busca los fragmentos de una asignatura y pide a la IA una
  propuesta de ficha. Cuando no quedan asignaturas pendientes, la importación pasa a
  `lista`.
