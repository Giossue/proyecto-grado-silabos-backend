# Asistente de IA para la plantilla

Pedido del usuario (2026-10-31): que Administración pida cambios a la plantilla en lenguaje
natural y que la IA (DeepSeek, el mismo proveedor del chat del docente) arme la propuesta. Un
docente pidió que cada capacidad sea una función aparte («tools» o *function calling*): conversar,
proponer, validar, etc. La IA trabaja con un conjunto de archivos Markdown con reglas y
ejemplos.

Motivo: a los administradores el JSON de la plantilla les resulta complicado. El JSON queda
como opción avanzada; no se reemplaza por otro formato (se descartó LaTeX: describe apariencia,
no datos ni reglas, y es más difícil para personas no técnicas).

Trazabilidad: ADR-0003 (IA bajo control humano; hoy su alcance es solo el chat del docente),
ADR-0011 (plantilla como datos), RF-046 a RF-054, RN-028 a RN-030, RNF-016, RNF-035, RNF-036,
PV-12 y PV-14 (privacidad del proveedor), PV-18 (umbrales de utilidad de la IA). Pantalla
ADM-06.

Estado: **POR VALIDAR**. Nada de esto es regla de producción hasta que la fase 1 pase la
prueba con pedidos reales.

## Decisiones acordadas con el usuario

1. La IA **propone** y una persona **aplica**. No hay *tool* para guardar, aplicar, pausar,
   importar JSON ni borrar.
2. El seguimiento queda en nuestra base: cada mensaje y propuesta guarda quién la pidió, y al
   aplicar se audita quién la aprobó. A DeepSeek se le envía en `user_id` un código anónimo y
   estable: HMAC-SHA256 del `usuario_id` con `APP_KEY`, nunca el id, nombre ni correo.
3. Modelos: los dos actuales (`deepseek-flash` y `deepseek-v4-pro`) usan la misma API, así que
   el código no los distingue. Se cambia de modelo con variables de entorno:
   - `AI_MODEL`: el de siempre (chat del docente).
   - `AI_TEMPLATE_MODEL` (nueva, opcional): solo para el asistente de la plantilla; si está
     vacía, usa `AI_MODEL`.
4. Modo de razonamiento apagado al empezar (`thinking: {type: disabled}`, como hoy). Se
   prende solo si la calidad no alcanza (ver «Razonamiento» abajo).
5. Todos los Markdown de contexto van completos al inicio de cada llamada (se cachean solos);
   no hay *tool* para consultar el manual.

## Lo que ya se revisó

### Código actual

- `AiChatGateway` (`app/Modules/AiAssistance/Domain/Contracts`) tiene un solo método
  `reply()`: una llamada y una respuesta JSON. `HostedAiChatGateway` arma el cuerpo por
  proveedor; para DeepSeek usa `chat/completions`, `response_format: json_object`, razonamiento
  apagado y `max_tokens` 4096. No soporta *tools*.
- `mensajes_ia` guarda mensajes con `silabo_id` obligatorio (FK con `ON DELETE CASCADE`) y
  `usuario_id`; el trigger `proteger_mensaje_ia` impide cambiar mensajes terminados.
- La plantilla ya tiene acciones separadas para secciones y campos
  (`TemplateStructureController`: crear, editar, ordenar y borrar) y una que reemplaza todo
  (`ReplaceTemplateStructure`, auditada como `plantilla.importada`). Todas llaman a
  `ProcessLocks::assertTemplateEditable()`.
- `TemplateDefinition::define()` valida la definición y devuelve errores con su ruta exacta.
- Límite de IA del docente: `RateLimiter` `ai-analysis` (6 por minuto) y `SyllabusAiLimits`
  (20 preguntas y 3 sesiones por sílabo).
- El servidor de pruebas usa `AI_DRIVER=deepseek`, `AI_BASE_URL=https://api.deepseek.com` y
  `AI_MODEL=deepseek-flash` (confirmado por el usuario el 2026-10-31): ya es un modelo vigente.
- Material para el contexto que ya existe: `docs/architecture/template-json.md`,
  `resources/templates/plantilla.schema.json` y `resources/templates/silabo-oficial.json`
  (unos 86 KB, unos 25 000 tokens).

### API de DeepSeek (https://api-docs.deepseek.com/, leída el 2026-10-31)

- Modelos: `deepseek-flash` (V4.1-Flash-1017) y `deepseek-v4-pro`. Contexto de 1M tokens.
  `deepseek-chat` y `deepseek-reasoner` se anunciaron retirados desde el 2026-07-24.
- *Tools* en ambos modelos: `tools[]` con `{"type": "function", "function": {name,
  description, parameters, strict}}`; `tool_choice` `none`/`auto`/`required`/nombrada. La
  respuesta trae `message.tool_calls[]` (puede traer varias) con `finish_reason: tool_calls`.
  El resultado se devuelve como `{"role": "tool", "tool_call_id", "content"}`. Los argumentos
  pueden venir como JSON inválido o con parámetros inventados: siempre validar.
- Modo estricto (beta): `base_url` `https://api.deepseek.com/beta` y `strict: true` en
  **todas** las funciones. Cada objeto exige todas sus propiedades en `required` y
  `additionalProperties: false`. No admite `minLength`, `maxLength`, `minItems` ni `maxItems`.
- Razonamiento: con *tools*, `reasoning_content` debe reenviarse completo en todas las llamadas
  siguientes o la API responde 400; `tool_choice` `required` o nombrada da 400 con
  razonamiento.
- Caché automática por prefijo (`usage.prompt_cache_hit_tokens`), separada por `user_id`.
- Precio por millón de tokens (`deepseek-flash`, fuera de pico / pico): caché $0.003 / $0.006,
  sin caché $0.15 / $0.30, salida $0.60 / $1.20. `deepseek-v4-pro`: unas 4 veces más. Pico:
  01:00–04:00 y 06:00–10:00 UTC, de lunes a viernes.
- Modo JSON: solo `json_object` (no `json_schema`); la respuesta puede llegar en blanco.
- Errores: 400, 401, 402 (sin saldo), 422, 429 (límite), 500 y 503 (reintentar tras esperar).
  Concurrencia por cuenta: 2 500 (`flash`) y 500 (`pro`).

## Diseño

### Flujo

1. El administrador escribe un pedido en el panel del asistente (pantalla de plantilla).
2. Se guarda el mensaje y una respuesta `pendiente`; un trabajo en la cola `ia` corre el ciclo.
3. En cada paso la IA llama *tools*. Las de consulta leen la plantilla; las de propuesta
   cambian un **borrador en memoria**, nunca la plantilla real.
4. El ciclo termina cuando la IA responde sin *tools*, o al llegar a un límite.
5. Si hay borrador válido, queda una **propuesta** con la huella de la plantilla base.
6. La pantalla muestra la propuesta sobre la vista previa, con lo que cambia marcado. La persona
   pulsa «Aplicar» o «Descartar».

### Catálogo de *tools*

Consulta (solo leen):

| Tool | Devuelve |
|---|---|
| `ver_plantilla` | Secciones y campos en orden, con clave, título y quién llena cada uno |
| `ver_seccion` | Detalle de una sección |
| `ver_campo` | Detalle de un campo, con su contenido |
| `listar_bloques` | Bloques definidos y qué hace cada uno |
| `listar_presets` | Presets que se pueden insertar |
| `listar_variables` | Variables disponibles |
| `ver_estado` | Si la convocatoria está abierta o en pausa y cuántos sílabos se rehacen al aplicar |

Propuesta (cambian el borrador):

| Tool | Hace |
|---|---|
| `proponer_seccion` | Agregar, renombrar, mover o quitar una sección (fase 1) |
| `proponer_campo` | Agregar, editar, mover o quitar un campo y fijar quién lo llena (fase 1) |
| `validar_borrador` | Corre la validación del servidor y devuelve los errores con su ruta (fase 1) |
| `proponer_bloque` | Insertar un bloque o preset existente (fase 3) |
| `proponer_diseno` | Márgenes y tamaños (fase 3) |
| `proponer_limites_lista` | Mínimo, máximo y «Al empezar» de una lista (fase 3) |

Conversación:

| Tool | Hace |
|---|---|
| `responder` | Explica o resume la propuesta para la persona |
| `preguntar` | Pide una aclaración cuando el pedido es ambiguo y termina el ciclo |

La IA trabaja sobre secciones y campos, no sobre el JSON completo.

### Rutas para el frontend

| Ruta | Para qué |
|---|---|
| `GET admin/template/ai-chat` | Historial y límites |
| `POST admin/template/ai-chat/messages` | Conversar o pedir un cambio |
| `DELETE admin/template/ai-chat` | Limpiar la vista (registro de ocultamiento, como el docente) |
| `GET admin/template/ai-proposals/{id}` | La propuesta con sus cambios |
| `POST admin/template/ai-proposals/{id}/apply` | Aplicar; reusa las acciones actuales |
| `POST admin/template/ai-proposals/{id}/discard` | Descartar |

Todas solo para Administración, con `active-role` y su Form Request y Policy.

### Datos

- Conversaciones de la plantilla: o bien `mensajes_ia` admite `silabo_id` nulo con una columna
  `plantilla_silabo_id` y un `CHECK` que exija uno de los dos, o bien una tabla aparte. Decidir
  al implementar; leer antes `docs/architecture/database.md`.
- Tabla nueva `propuestas_ia_plantilla`: id, plantilla, quién la pidió, mensaje de origen,
  cambios propuestos (`jsonb`), huella de la plantilla base, estado (`pendiente`, `aplicada`,
  `descartada`, `vencida`), quién decidió y cuándo.
- Al aplicar: auditoría con la acción de la plantilla y el origen «propuesta de IA», más quién
  aprobó.

### Límites del ciclo

- Por llamada: `AI_TIMEOUT` (20 s), suficiente sin razonamiento.
- Por pedido: máximo 8 pasos en total (pasos normales y reintentos juntos) y 2 minutos.
- Por error de validación: hasta 3 correcciones del mismo error.
- Si se pasa de un límite: «No pude completar el pedido; intente explicarlo de otra forma».
  No se guarda ninguna propuesta a medias.
- Por persona: un límite diario de pedidos (valor a definir, como el del docente).

### Errores del proveedor

| Caso | Qué hace |
|---|---|
| 402 sin saldo, 401 clave inválida | «Asistente no disponible»; el editor sigue funcionando |
| 429 límite | Espera y reintenta; si persiste, «inténtelo en unos minutos» |
| 500 / 503 | Reintenta tras esperar, dentro del tope de pasos |
| Respuesta en blanco | Cuenta como reintento, no como error final |
| Argumentos inválidos de una *tool* | Se devuelve el error a la IA como resultado de la *tool* |
| `tool_calls` con una función que no existe | Igual que el anterior |

### Seguridad

- La plantilla y los mensajes son datos no confiables dentro del prompt; los Markdown de
  contexto son del repositorio y sí son confiables.
- Con la convocatoria abierta solo funcionan las *tools* de consulta y «Aplicar» se rechaza
  (`ProcessLocks`). Con la convocatoria en pausa, «Aplicar» muestra el aviso de rehacer
  sílabos.
- «Aplicar» se rechaza si la plantilla cambió desde que se armó la propuesta (huella distinta);
  la propuesta queda `vencida`.
- No se registra en logs el contenido de los mensajes ni la clave.
- La plantilla no tiene datos personales; igual se agrega al aviso de privacidad.

### Razonamiento (si hace falta prenderlo)

- Guardar y reenviar `reasoning_content` de cada mensaje del asistente en todas las llamadas
  siguientes del ciclo.
- No usar `tool_choice` `required` ni nombrada.
- Subir `AI_TIMEOUT` para estas llamadas.

## Contexto en Markdown

Carpeta nueva en el backend (por ejemplo `resources/ai/plantilla/`), versionada:

- `reglas.md`: qué es una sección, un campo, quién llena cada cosa, qué no se puede tocar
  (secciones obligatorias, sección de Biblioteca).
- `glosario.md`: bloques, presets y variables de la plantilla oficial.
- `ejemplos.md`: 10 a 15 pares «pedido → *tools* esperadas».
- `prohibiciones.md`: no inventar bloques ni directivas; no aplicar; preguntar si hay duda.
- El manual `template-json.md` como referencia.

Orden fijo en el prompt (para aprovechar la caché): reglas y manual primero; después la
plantilla actual; al final la conversación.

## Fases

1. **Prueba técnica, sin pantalla.**
   - Contrato nuevo para el ciclo con *tools* (por ejemplo `AiAgentGateway`), separado de
     `AiChatGateway` para no tocar el chat del docente. Implementaciones: DeepSeek y
     `baseline` (simulador sin red que reproduce llamadas a *tools* en las pruebas).
   - *Tools* de consulta, `proponer_seccion`, `proponer_campo` y `validar_borrador`.
   - `AI_TEMPLATE_MODEL` con respaldo en `AI_MODEL`; `user_id` anónimo.
   - Comando de consola que corre 10 pedidos reales y muestra qué hizo la IA. Criterio para
     seguir: la mayoría termina en una propuesta válida y correcta (umbral a acordar, PV-18).
2. **Chat y propuesta en la pantalla de plantilla.** Rutas, tablas, panel del asistente,
   vista previa con cambios marcados, «Aplicar» y «Descartar», auditoría y límites.
3. **Bloques, diseño y límites de listas.** Si aprieta el tiempo antes de diciembre, queda como
   trabajo futuro.

## Pruebas

- Gateway: arma bien `tools`, `strict` y `user_id`; interpreta `tool_calls`; maneja 402, 429,
  500, 503, respuesta en blanco y argumentos inválidos (con `Http::fake`).
- Ciclo: se detiene en 8 pasos y en 3 correcciones; nunca escribe en la plantilla real.
- *Tools*: cada una con su caso válido y su caso rechazado.
- Rutas: solo Administración; con la convocatoria abierta, «Aplicar» se rechaza; con la
  plantilla cambiada, la propuesta queda vencida; aplicar audita.
- Configuración: `AI_TEMPLATE_MODEL` vacía usa `AI_MODEL`.
- Frontend: panel, propuesta marcada, aplicar y descartar.

## Documentación al cerrar

- ADR-0003: ampliar el alcance o ADR nuevo.
- `docs/architecture/ai-service.md`: sección del asistente de plantilla.
- `docs/runbooks/deploy-dokploy.md` y `.env.example`: `AI_TEMPLATE_MODEL` y los modelos
  vigentes de DeepSeek.
- `docs/product/screens.md` (ADM-06), `docs/quality/traceability.md`,
  `docs/security/aviso-de-privacidad.md`, `DECISIONS_STATUS.md` y `docs/plans/pendientes.md`.
- `docs/architecture/openapi.json` con `php artisan openapi:export`.

## Preguntas abiertas

- ¿El chat del docente también pasará a *tools* (por ejemplo `proponer_texto_campo`)? Fuera de
  este plan por ahora.
- Límite diario de pedidos por administrador.
