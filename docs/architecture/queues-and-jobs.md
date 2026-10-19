# Colas y trabajos

## Usos

- respuestas del chat de IA;
- generación DOCX/PDF;
- notificaciones externas;
- tareas de mantenimiento y reportes pesados.

Guardar, validar determinísticamente, enviar, observar, aprobar y reabrir mantienen su
verdad en la transacción web; los efectos secundarios se ejecutan después.

## Reglas de un job

Cada trabajo define:

- identificador y tipo;
- recurso/versión de entrada;
- clave de idempotencia;
- cola y prioridad;
- tiempo máximo;
- máximo de intentos y backoff;
- condiciones de reintento/no reintento;
- estado y progreso persistidos en PostgreSQL;
- `correlacion_id` y métricas;
- resultado o causa segura de fallo.

El payload contiene IDs y versiones, no objetos enormes ni secretos.

## Consistencia

- Despacha después del commit (`after_commit` o equivalente explícito).
- Para efectos que no se pueden perder, guarda el registro de dominio con su estado
  `pendiente` dentro de la misma transacción (exportación, mensaje de IA); los avisos se
  guardan directo en `notificaciones`, sin trabajo (I-221).
- El consumidor cambia el estado de ese registro de forma idempotente.
- Reintentar no crea otra revisión, aprobación, notificación o artefacto lógico.
- PostgreSQL conserva el estado funcional; Redis puede vaciarse/reconstruirse.
- Colas y caché usan Redis por defecto; I-168 quitó las conexiones `database`, que
  apuntaban a tablas inexistentes.

### Patrón de idempotencia (I-226)

Laravel no trae un middleware HTTP de `Idempotency-Key`; la documentación oficial reparte la
idea entre trabajos únicos, bloqueos y transacciones. El sistema aplica cada pieza donde toca:

| Capa | Mecanismo | Dónde |
|---|---|---|
| Petición HTTP | `idempotency_key` (UUID) + restricción única `(recurso, clave_idempotencia)` + `lockForUpdate` dentro de `DB::transaction` | Enviar, aprobar, comentar en biblioteca, exportar y mensajes de IA |
| Cliente | Una clave por intento (`attemptKey`): se reutiliza al reintentar el mismo envío y se renueva tras un éxito o si cambia lo enviado | Frontend |
| Despacho | `->afterCommit()`: un rollback descarta el trabajo | Todas las acciones que despachan |
| Trabajo en cola | `ShouldBeUnique` con `uniqueId()` y `#[UniqueFor]`: un duplicado se descarta mientras el primero espera o corre | Los cinco trabajos |
| Ejecución | `WithoutOverlapping($uniqueId)->dontRelease()->expireAfter(timeout + 30)` (`App\Support\Queue\RunsOnce`): si Redis reentrega un trabajo que sigue corriendo, la copia se elimina | Los cinco trabajos |
| Consumidor | Cambia el estado del registro de dominio solo si sigue en el estado esperado | Todos |

Claves de unicidad: exportación `artefacto`, respuesta de chat `mensaje`, lectura de fichas
`importación`, extracción de ficha `importación:asignatura`, verificación `clave`.

`retry_after` de la conexión debe ser mayor que el `timeout` de cada trabajo (la documentación
avisa de que, si no, se procesa dos veces); la lectura de fichas con OCR dura hasta 900 s, así que
con OCR activo `REDIS_QUEUE_RETRY_AFTER` debe superar 900. `WithoutOverlapping` es la red de
seguridad si no se cumple.

### Idempotencia de ingesta PDF

La ingesta de fuentes usa dos niveles de idempotencia. La ejecución de PostgreSQL se
deduplica por fuente, huella SHA-256 del PDF, modelo OCR y versiones de adaptadores. El
envío a OpenParser usa un `Idempotency-Key` derivado de la huella y del adaptador OCR;
reintentar o volver a subir los mismos bytes devuelve el mismo job externo en vez de
crear otro Parse. Una nueva ejecución interna puede reutilizar ese resultado y ejecutar
otra vez la normalización DeepSeek, que es una etapa posterior e independiente del job
de OpenParser.

## Colas sugeridas

`critica`, `documentos`, `integraciones`, `ia` y `notificaciones`. La prioridad y cantidad
de workers se miden; no se supone capacidad antes de `PV-05` y `PV-13`.

## Operación

Horizon puede supervisar Redis. La pantalla ADM-09 muestra estado de negocio sin exponer
payload sensible. Alertas mínimas:

- crecimiento sostenido de espera;
- fallos definitivos;
- trabajos atascados;
- tasa de reintentos;
- latencia por tipo;
- servicio dependiente no disponible.

Reprocesar desde interfaz requiere permiso, confirma impacto y conserva todos los intentos.

## Trabajo del chat de IA

`AnswerSyllabusChatJob` responde en la cola `ia` a una pregunta ya registrada en
`historial_ia`. I-168 borró `AnalyzeSyllabusFieldJob` y `EmbedAcademicSourceJob` junto con
el revisor y los embeddings retirados en I-146.
