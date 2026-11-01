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

### Catálogo de *tools* (implementado en la fase 1)

Una función por acción, como pidió el docente. Todos los parámetros son obligatorios y no hay
parámetros extra, para cumplir el modo estricto de DeepSeek. Las claves de secciones y campos
nuevos las genera el servidor a partir del nombre y nunca reusan la clave de algo que se quitó
(así una ficha de Coordinación no se pega a otro campo).

Consulta (solo leen):

| Tool | Devuelve |
|---|---|
| `ver_plantilla` | Secciones y campos en orden: clave, título, orientación, si es obligatoria o la revisa Biblioteca, y de cada campo su nombre, quién lo llena y un resumen |
| `ver_campo` | Un campo con su contenido completo en HTML |
| `listar_presets` | Contenidos listos (tablas y bloques oficiales) |
| `listar_variables` | Datos del sistema que se pueden insertar |
| `ver_estado` | Convocatoria abierta, en pausa o ninguna, y qué implica |

Propuesta (cambian el borrador; no se ofrecen con la convocatoria abierta):

| Tool | Hace |
|---|---|
| `agregar_seccion` | Agrega una sección vacía después de otra (`despues_de`) |
| `renombrar_seccion` | Cambia el título |
| `cambiar_orientacion_seccion` | Vertical u horizontal |
| `mover_seccion` | La deja después de otra sección (`despues_de`) |
| `renumerar` | Ordena la numeración de secciones y subtítulos |
| `quitar_seccion` | Quita la sección; rechaza las obligatorias y la de Biblioteca |
| `agregar_campo` | Agrega un campo con nombre, responsable y contenido HTML |
| `renombrar_campo` | Cambia el nombre (subtítulo) |
| `cambiar_responsable_campo` | Cambia quién lo llena |
| `cambiar_contenido_campo` | Reemplaza el contenido HTML |
| `mover_campo` | Lo deja después de otro campo, en la misma sección o en otra |
| `quitar_campo` | Quita el campo |
| `usar_preset` | Pone un contenido listo (así se insertan los bloques) |
| `validar_borrador` | Corre la validación de «Guardar» y lista los cambios |

Conversación (terminan el turno):

| Tool | Hace |
|---|---|
| `responder` | Resume la propuesta o responde. Si el borrador cambió y tiene errores, se le devuelven a la IA para que los corrija |
| `preguntar` | Pide una aclaración; descarta el borrador |

Se descartaron `ver_seccion` y `listar_bloques`: `ver_plantilla` y `listar_presets` ya dan esa
información. Diseño (márgenes, letra) y límites de listas quedan para la fase 3.

La IA trabaja sobre secciones y campos, no sobre el JSON completo. La propuesta tiene el mismo
formato que guarda el editor visual (`PUT admin/template/{id}/json`, versión 1), así que pasa
por la misma validación.

### Rutas y datos (implementado en la fase 2)

Rutas y flujo: ver `docs/architecture/ai-service.md`, «Asistente de la plantilla». Tablas
`mensajes_ia_plantilla` (conversación por persona; «Nuevo chat» marca `ocultado_en`) y
`propuestas_ia_plantilla` (estructura propuesta, cambios, huella base y estado); detalle en
`docs/generated/database-schema.md`. Se descartó reusar `mensajes_ia`: está atada al sílabo,
con su propia idempotencia y un trigger que impide cambiar mensajes terminados.

### Límites del ciclo

- Por llamada: `AI_TIMEOUT` (20 s), suficiente sin razonamiento.
- Por pedido: máximo 8 pasos en total (pasos normales y reintentos juntos) y 2 minutos.
- Por errores: hasta 3 pasos seguidos con error (de una *tool*, de validación o respuesta en
  blanco); al cuarto se detiene.
- Si se pasa de un límite: «No pude completar el pedido; intente explicarlo de otra forma».
  No se guarda ninguna propuesta a medias.
- Por persona: sin cupo diario ni por minuto para Administración (decisión del usuario,
  2026-10-31). Los topes de pasos y tiempo de arriba se mantienen: no son un cupo, evitan que
  un pedido quede dando vueltas.

### Errores del proveedor

| Caso | Qué hace |
|---|---|
| 402 sin saldo, 401 clave inválida | «Asistente no disponible»; el editor sigue funcionando |
| 429 límite | Reintenta tras 2 s y 5 s; si persiste, «inténtelo en unos minutos» |
| 500, 502, 503, 504 o sin conexión | Reintenta tras 2 s y 5 s; si persiste, «no disponible» |
| 400 o 422 | No reintenta; guarda el detalle del proveedor (sin datos del pedido) |
| Sin capacidad (`insufficient_system_resource`) | «Inténtelo en unos minutos» |
| Respuesta cortada (`length`) o filtrada | Cuenta como paso con error |
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

`resources/ai/plantilla/` (se leen en orden y van completos como mensaje de sistema, que se
cachea):

- `01-rol-y-reglas.md`: cómo trabaja, qué no hace, preguntar si hay duda, no aplicar.
- `02-secciones-y-campos.md`: secciones, numeración de títulos, quién llena cada campo, partes
  fijas y espacios para responder, presets.
- `03-contenido-html.md`: HTML permitido, estilo oficial de las tablas y ejemplos.
- `04-ejemplos.md`: 10 pedidos con las *tools* esperadas.
- `pruebas.txt`: los 10 pedidos del comando de prueba (no va al prompt).

No se incluye el manual `template-json.md` completo: explica el JSON y el diseño de bloques,
que la IA no usa.

## Fases

1. **Prueba técnica, sin pantalla.** Hecha (2026-10-31) con `deepseek-flash`, modo estricto y
   razonamiento apagado. Primera corrida: 9 de 10; fallaron mover la bibliografía (renombraba
   de a una sección por paso y llegó al tope de 8) y se numeró «Revisión y aprobación», que no
   llevaba número. Se ajustaron las reglas (varias funciones en un mismo paso, renumerar solo lo
   numerado y también los campos de una sección que cambia de número). Segunda corrida: 10 de
   10, entre 1 y 5 pasos y entre 1 y 9 s por pedido; los 10 pedidos usaron unos 238 000 tokens
   de entrada (91 % en caché) y 4 300 de salida, menos de un centavo de dólar en total.
   - Contrato nuevo para el ciclo con *tools* (por ejemplo `AiAgentGateway`), separado de
     `AiChatGateway` para no tocar el chat del docente. Implementaciones: DeepSeek y
     `baseline` (simulador sin red que reproduce llamadas a *tools* en las pruebas).
   - *Tools* de consulta, `proponer_seccion`, `proponer_campo` y `validar_borrador`.
   - `AI_TEMPLATE_MODEL` con respaldo en `AI_MODEL`; `user_id` anónimo.
   - Comando `php artisan template:assistant-trial` (pedidos de `resources/ai/plantilla/pruebas.txt`
     o `--pedido="…"`): muestra cada paso, el resultado, los cambios, los tokens y el tiempo, sin
     guardar nada. Criterio para seguir: la mayoría termina en una propuesta válida y correcta
     (umbral a acordar, PV-18).
2. **Chat y propuesta en la pantalla de plantilla.** Hecha (2026-10-31): tablas
   `mensajes_ia_plantilla` y `propuestas_ia_plantilla`, rutas `admin/template/assistant`, panel
   flotante, «Ver en la hoja» con cambios marcados, «Aplicar» con confirmación, «Descartar» y
   auditoría. Ajuste pedido por el usuario el mismo día: el asistente vive **solo en el modo
   edición**. Trabaja sobre el borrador del editor (con lo no guardado), «Aplicar» pone los
   cambios en el editor marcados «Cambió» y solo «Guardar» cambia la plantilla. Se quitaron la
   vista de la propuesta en modo lectura, el aplicar que guardaba directo y su confirmación.
   Una propuesta nueva reemplaza la pendiente; un pedido a la vez por persona (no es un cupo);
   si el borrador cambió después del pedido, la propuesta queda «Vencida». Falta probarlo en
   pantalla con DeepSeek y con administradores reales.
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

## Después de este plan

El chat del docente también pasará a *tools* más adelante (confirmado por el usuario el
2026-10-31), sobre todo para buscar en internet información de los temas de sus unidades.
DeepSeek abrió el 2026-10-17 sus APIs de búsqueda y lectura de páginas (Search y Fetch, $0.75
por cada 1 000 solicitudes exitosas); revisarlas entonces. El contrato del ciclo con *tools*
de este plan debe servir también para ese caso. Va en su propio plan.

## Evaluación con conversaciones (2026-11-01)

`resources/ai/plantilla/escenarios.json`: 10 conversaciones de 2 o 3 mensajes (pedido vago, cambios
encadenados, colores, sección obligatoria, color prohibido en la bibliografía, mensajes informales,
mover y numerar, solo preguntas, editar un texto largo, sección con tres campos). Primera corrida:
fallos de numeración al mover (el modelo contaba posiciones a mano), cambió la tabla de firmas al
no poder cambiar la bibliografía, mostró claves internas y no sabía si su propuesta anterior se
había aplicado. Se agregaron `despues_de`, `renumerar` y el estado de la última propuesta, y reglas
para no tocar otra parte ni mostrar nombres internos. Segunda corrida: los 27 turnos correctos;
si el administrador aprobaba («si eso») una propuesta pendiente, a veces el modelo la rehacía.
Se resolvió en el código: si la propuesta nueva es idéntica a la pendiente, no se crea otra y la
respuesta pide pulsar «Aplicar» en la anterior (`TemplateAssistant::SAME_AS_PENDING`).

