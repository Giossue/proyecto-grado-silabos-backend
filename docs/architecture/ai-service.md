# IA: chat del docente

La IA ayuda al docente sobre su sílabo (`AiAssistance`). Una persona decide qué usar
(ADR-0003). No aprueba, califica, cambia estados ni escribe en el sílabo. Si el
proveedor falla, todo lo demás sigue funcionando.

La importación de fichas usa exclusivamente [Excel estructurado](ficha-excel.md).
El flujo de extracción de fichas por IA y OCR fue eliminado.

## Proveedor

Configuración del chat en `config/ai.php`:

| Variable | Uso |
|---|---|
| `AI_DRIVER` | `disabled` (por defecto), `baseline`, `openai`, `deepseek`, `claude`/`anthropic` |
| `AI_BASE_URL` | URL base del proveedor |
| `AI_MODEL` | Modelo |
| `AI_API_KEY` | Clave; solo en variables de entorno |
| `AI_CONNECT_TIMEOUT` / `AI_TIMEOUT` | 2 / 20 s |

`AppServiceProvider` elige la implementación:

| Driver | Chat (`AiChatGateway`) |
|---|---|
| `disabled` | `DisabledAiChatGateway` |
| `baseline` | `BaselineAiChatGateway`: simulador sin red para pruebas |
| `openai` | `HostedAiChatGateway`, endpoint `responses` |
| `deepseek` | `HostedAiChatGateway`, `chat/completions` |
| `claude` / `anthropic` | `HostedAiChatGateway`, `messages` |

`AI_BASE_URL` debe ser `https://`, sin usuario ni fragmento. No se siguen
redirecciones. La clave no aparece en logs ni respuestas.

## Chat del docente

Rutas: `GET|DELETE teacher/syllabi/{id}/ai-chat` y
`POST teacher/syllabi/{id}/ai-chat/messages` (límite `ai-analysis`: 6 por minuto por
usuario y sílabo).

1. `SendSyllabusAiMessage` guarda la pregunta y una respuesta `pendiente` en
   `mensajes_ia`. La entrada fija la pregunta, la `idempotency_key`, la versión de
   contexto `free-text-v3` y la evidencia.
2. La evidencia la arma `BuildSyllabusChatContext`:
   - `contexto_academico`: carrera, asignatura, código, ciclo, unidad, créditos, horas
     y semanas del sílabo;
   - `seccion_silabo`: el texto de cada campo con contenido.

   Máximo 50 elementos de hasta 12 000 caracteres.
3. `AnswerSyllabusChatJob` (cola `ia`) envía la pregunta, los últimos 12 mensajes
   completados y la evidencia. El prompt limita el alcance a preparar unidades y
   trata el contexto como datos no confiables.
4. La respuesta es JSON con `answer` y `citation_ids`. Si cita evidencia que no recibió,
   el mensaje queda `fallido`. Si no, `completado`.
5. Un mensaje de una versión de contexto anterior termina `fallido` sin llamar al
   proveedor.

Límites: pregunta de 4 000 caracteres, respuesta de 12 000, cuerpo de 256 KB y 4 096
tokens de salida.

`DELETE …/ai-chat` no borra: agrega un registro `ocultamiento` y la vista se limpia. Los
mensajes terminados no cambian (trigger `proteger_mensaje_ia`). Se borran solo al
regenerar o eliminar el sílabo.

## Seguridad

- El contenido del sílabo se trata como dato no confiable. El prompt lo encierra y
  prohíbe seguir instrucciones que vengan dentro.
- Solo el docente del sílabo usa su chat, mientras puede editarlo (`borrador` o
  `correccion_solicitada`).
- Antes de activar un proveedor externo hay que revisar privacidad y aviso al usuario
  ([aviso-de-privacidad.md](../security/aviso-de-privacidad.md)).
