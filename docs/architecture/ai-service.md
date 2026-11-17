# IA: asistente de la plantilla

La IA (`AiAssistance`) solo ayuda a Administración a editar la plantilla. Una persona decide qué
usar (ADR-0003). No aprueba, califica, cambia estados ni escribe en la plantilla ni en ningún
sílabo. Si el proveedor falla, todo lo demás sigue funcionando. El docente no usa IA.

La importación de proyectos de carrera usa exclusivamente [Excel estructurado](ficha-excel.md).
El flujo de extracción de proyectos de carrera por IA y OCR fue eliminado, igual que el chat
del docente (2026-11-05).

## Proveedor

Configuración en `config/ai.php`:

| Variable | Uso |
|---|---|
| `AI_DRIVER` | `disabled` (por defecto), `baseline` o `deepseek` |
| `AI_BASE_URL` | URL base del proveedor |
| `AI_MODEL` | Modelo |
| `AI_API_KEY` | Clave; solo en variables de entorno |
| `AI_CONNECT_TIMEOUT` / `AI_TIMEOUT` | 2 / 20 s |

`AppServiceProvider` elige la implementación de `AiAgentGateway`:

| Driver | Implementación |
|---|---|
| `disabled` | `DisabledAiAgentGateway` |
| `baseline` | `BaselineAiAgentGateway`: simulador sin red para pruebas |
| `deepseek` | `HostedAiAgentGateway`, `chat/completions` |

`AI_BASE_URL` debe ser `https://`, sin usuario ni fragmento. No se siguen
redirecciones. La clave no aparece en logs ni respuestas.

## Asistente de la plantilla (POR VALIDAR)

Documento ODD: `odd/tasks/asistente-ia-plantilla.md`. Administración pide un cambio en
lenguaje natural mientras edita y la IA arma una propuesta llamando funciones (*tools*)
sobre una copia del borrador del editor. La persona la aplica en el editor (marcada «Cambió»)
o la descarta; solo «Guardar» cambia la plantilla.

- Contrato `AiAgentGateway` (un paso del ciclo con *tools*).
  `HostedAiAgentGateway` solo admite DeepSeek (`chat/completions`, `tool_choice: required`,
  razonamiento apagado, `user_id` anónimo). Con `AI_TOOLS_STRICT=true` (por defecto) usa
  `https://api.deepseek.com/beta/chat/completions` y marca cada función como `strict`.
  Envía `temperature` desde `AI_TEMPERATURE` (por defecto `0`; vacía, no la envía y DeepSeek
  usa `1`). Con 0 el asistente responde igual entre corridas: medido el 2026-11-17 con 38 escenarios
  (59 turnos, antes de quitar los 3 del historial) contra `deepseek-flash`, pasó del 93 % sin
  `temperature` al 100 %. El razonamiento sigue apagado:
  con él DeepSeek ignora `temperature` y rechaza `tool_choice: required` con un 400
  ([API](https://api-docs.deepseek.com/api/create-chat-completion)).
  `baseline` es un simulador sin red; `disabled` falla con «no disponible».
- `AI_TEMPLATE_MODEL` elige el modelo del asistente; vacía, usa `AI_MODEL`. Los dos modelos
  vigentes (`deepseek-flash` y `deepseek-v4-pro`) usan la misma API.
- `TemplateAssistant` corre el ciclo: máximo 8 pasos, 2 minutos y 3 pasos seguidos con error
  (`config/ai.php`, `template`). Reintenta 429, 5xx y caídas de conexión tras 2 s y 5 s; 401,
  402, 400 y 422 no se reintentan.
- `TemplateDraft` es la copia en memoria sobre la que trabajan las funciones;
  `TemplateDraftValidator` aplica las mismas reglas que «Guardar»
  (`ImportTemplateStructureRequest`) más secciones obligatorias y secciones sin campos. La
  propuesta tiene el formato que guarda el editor visual.
- El contenido que propone la IA solo puede usar la paleta del editor: fondo de celda celeste
  `#C3D4EA` o azul institucional `#4F81BD`, y texto negro, azul `#365F91` o blanco
  (`TemplateDraft::CELL_COLORS` y `TEXT_COLORS`). Otro color vuelve a la IA como error. Sus reglas
  (`resources/ai/plantilla/`) explican también que la bibliografía es el bloque enlazado a Koha.
- El contexto de la IA está en `resources/ai/plantilla/*.md` y va completo como mensaje de
  sistema (DeepSeek lo cachea).
- Las funciones ubican secciones y campos con `despues_de` (una clave, «(inicio)» o «(final)»),
  no con números de posición, y `renumerar` ordena la numeración de secciones («7. …») y
  subtítulos («7.1 …») sin que el modelo haga la cuenta.
- Cada turno le dice al modelo qué pasó con su última propuesta (aplicada, pendiente,
  descartada o ya no coincide con el borrador), para que no la repita ni la dé por aplicada.
  Si aun así arma una propuesta idéntica a la pendiente, no se crea otra: la respuesta pide
  pulsar «Aplicar» en la anterior.
- Prueba con pedidos reales, sin guardar: `php artisan template:assistant-trial`
  (`--pedido="…"` para uno suelto, o `--escenarios=resources/ai/plantilla/escenarios.json` para 10
  conversaciones de varios mensajes que simulan «Aplicar»; `--solo=3` corre solo el 3).

Rutas (solo Administración, `active-role`, sin cupo de pedidos):

| Ruta | Para qué |
|---|---|
| `GET admin/template/assistant` | Conversación visible, disponibilidad y si la convocatoria está abierta |
| `POST admin/template/assistant/messages` | Pedido (`message`, `idempotency_key`, `draft` con las secciones del editor y `draft_version`); responde 202 |
| `DELETE admin/template/assistant` | «Nuevo chat»: oculta los mensajes (`ocultado_en`) |
| `GET admin/template/assistant/proposals/{id}` | Estructura propuesta, claves que cambian y `draft_version` |
| `POST …/proposals/{id}/apply` | Marca que pasó al editor (`plantilla.propuesta_ia_aplicada`); no guarda la plantilla |
| `POST …/proposals/{id}/discard` | Descarta (`plantilla.propuesta_ia_descartada`) |

1. `SendTemplateAssistantMessage` guarda el pedido y una respuesta `pendiente` en
   `mensajes_ia_plantilla`, con el borrador del editor. El contenido de cada campo se valida
   como al guardar. Solo hay un pedido en curso por persona.
2. `AnswerTemplateAssistantJob` (cola `ia`, 180 s, un intento) corre `TemplateAssistant` con
   los últimos 12 mensajes sobre ese borrador. Los cambios y las marcas se calculan contra el
   borrador enviado, así que solo aparecen los de la IA. Los errores que el borrador ya tenía
   no bloquean la propuesta y se le avisan a la IA para que no los toque.
3. La propuesta queda en `propuestas_ia_plantilla` con la versión del borrador
   (`sesión de edición:revisión`). La pantalla solo la aplica si el editor sigue en esa
   versión; si no, la muestra «Vencida». Una propuesta nueva reemplaza la pendiente.

## Seguridad

- Solo Administración usa el asistente.
- Antes de activar un proveedor externo hay que revisar privacidad y aviso al usuario
  ([aviso-de-privacidad.md](../security/aviso-de-privacidad.md)).
