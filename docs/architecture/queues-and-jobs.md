# Colas y trabajos

## Qué va a una cola

Solo lo lento o externo:

- generar el PDF de una revisión aprobada (el Word es interno);
- responder una pregunta del chat de IA;
- enviar el correo de activación de cuenta;
- el trabajo de humo de la plataforma.

Guardar, validar, enviar, revisar, aprobar, firmar y regenerar ocurren en la petición
web, dentro de una transacción. Las notificaciones internas y la auditoría también se
guardan ahí; no usan cola.

La carga actual de fichas mediante Excel estructurado también se resuelve en la
petición web; no tiene trabajos de cola. Ver [ficha-excel.md](ficha-excel.md).

## Trabajos

| Trabajo | Módulo | Cola | `timeout` | Intentos | Espera entre intentos | Clave única |
|---|---|---|---:|---:|---|---|
| `PlatformSmokeJob` | Operations | `critica` | 30 s | 3 | 1, 5, 15 s | clave del comando |
| `GenerateSyllabusExportJob` | Documents | `documentos` | 120 s | 3 | 5, 30, 120 s | exportación |
| `AnswerSyllabusChatJob` | AiAssistance | `ia` | 60 s | 3 | 5, 30, 120 s | mensaje |
| `ManagedUserActivationMail` | Identity | `general` | por defecto | por defecto | — | — |
| `WorkflowNotificationMail` | Operations | `general` | por defecto | por defecto | — | — |
| `CoordinatorSummaryMail` | Operations | `general` | por defecto | por defecto | — | — |

El estado de cada trabajo vive en su registro de dominio, no en una tabla de trabajos
(I-221):

| Registro | Estados |
|---|---|
| `exportaciones_silabo.estado_exportacion` | `pendiente`, `en_ejecucion`, `completado`, `fallido` |
| `mensajes_ia.contenido_mensaje->estado` | `pendiente`, `en_ejecucion`, `completado`, `fallido` |

Los trabajos fallidos no se guardan en otra tabla (`queue.failed.driver = null`). El
método `failed()` de cada trabajo deja el registro de dominio en `fallido`/`fallida`.

## Idempotencia (I-226)

| Capa | Mecanismo | Dónde |
|---|---|---|
| Petición | `idempotency_key` (UUID) + índice único + `lockForUpdate` en la transacción | Enviar, exportar y preguntar a la IA |
| Petición | Un solo registro por revisión (índice único) + `lockForUpdate`; si ya existe, se devuelve | Aprobar en Coordinación y verificar en Biblioteca |
| Cliente | `attemptKey`: misma clave al reintentar lo mismo; clave nueva si cambia lo enviado | SPA |
| Despacho | `->afterCommit()`: si la transacción se revierte, el trabajo no se envía | Exportación y chat |
| Cola | `ShouldBeUnique` con `uniqueId()` y `#[UniqueFor]`: se descarta un duplicado mientras el primero espera o corre | Los cinco trabajos de la tabla |
| Ejecución | `RunsOnce`: `WithoutOverlapping(uniqueId)->dontRelease()->expireAfter(timeout + 30)`. Si la cola entrega una copia de un trabajo que sigue corriendo, la copia se descarta | Los cinco trabajos de la tabla |
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
- Si su `timeout` supera `retry_after`, sube `QUEUE_RETRY_AFTER` (ver abajo).

## Configuración de la cola

- Conexión `database` (tabla `cola_trabajos`), cola por defecto `general`. Dos workers no
  toman el mismo trabajo: Laravel reserva con `FOR UPDATE SKIP LOCKED`.
- La caché usa `datos_temporales` y los bloqueos (`ShouldBeUnique`, `RunsOnce`, límites de
  intentos), `bloqueos_temporales`.
- `retry_after` = `QUEUE_RETRY_AFTER`, 960 s por defecto (respaldo: `REDIS_QUEUE_RETRY_AFTER`
  mientras el servidor la conserve).
- `QUEUE_CONNECTION=redis` y `CACHE_STORE=redis` ya no existen: `config/queue.php` y
  `config/cache.php` los tratan como `database`, así que un servidor con las variables
  antiguas sigue funcionando.
- No hay tabla de trabajos fallidos (`failed` es `null`). El estado funcional está en
  PostgreSQL: si se vacía la cola, se pierden los trabajos en espera y sus registros quedan
  `pendiente`.

**Requisito:** `retry_after` debe ser mayor que el `timeout` del trabajo más largo.
El mayor timeout actual es el de `GenerateSyllabusExportJob` (120 s). Se conserva
el valor predeterminado de 960 s; no necesita cambiarse por el retiro de la importación
OCR. Ver [deployment.md](deployment.md#colas-y-worker).

## Worker

Un solo `queue:work` en el contenedor del backend:

```bash
php artisan queue:work \
  --queue=critica,documentos,ia,general \
  --sleep=1 --tries=3 --max-time=3600
```

- El orden de `--queue` es la prioridad.
- El `timeout` y los intentos de cada trabajo mandan sobre los del worker.
- Un trabajo largo ocupa el único worker: los demás esperan mientras se procesa.

Para probar en local:

```bash
php artisan platform:smoke-job
```

## Detalle por trabajo

- `GenerateSyllabusExportJob`: arma el Word interno desde la fotografía de la revisión
  con los sellos de `firmas_silabo`, lo convierte en PDF, guarda solo el PDF en
  `archivos` y marca la exportación `completado`. Avisa al docente.
  Ver [files-and-documents.md](files-and-documents.md).
- `AnswerSyllabusChatJob`: llama al `AiChatGateway` con la pregunta, el historial y la
  evidencia fijada. Rechaza respuestas que citan evidencia ajena. Ver
  [ai-service.md](ai-service.md).
