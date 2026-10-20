# IA y lectura de documentos

La IA ayuda en dos lugares. En ambos, una persona decide (ADR-0003):

1. **Chat del docente** sobre su sílabo (`AiAssistance`).
2. **Importación de fichas**: propone la ficha de cada asignatura a partir de PDF o
   Excel que sube Coordinación (`Academic`).

La IA no aprueba, no califica, no cambia estados ni escribe en el sílabo. Si el
proveedor falla, todo lo demás sigue funcionando.

## Proveedor

Una sola configuración para los dos usos (`config/ai.php`):

| Variable | Uso |
|---|---|
| `AI_DRIVER` | `disabled` (por defecto), `baseline`, `openai`, `deepseek`, `claude`/`anthropic` |
| `AI_BASE_URL` | URL base del proveedor |
| `AI_MODEL` | Modelo |
| `AI_API_KEY` | Clave; solo en variables de entorno |
| `AI_CONNECT_TIMEOUT` / `AI_TIMEOUT` | 2 / 20 s para el chat |

`AppServiceProvider` elige la implementación:

| Driver | Chat (`AiChatGateway`) | Fichas (`FichaExtractionGateway`) |
|---|---|---|
| `disabled` | `DisabledAiChatGateway` | `DisabledFichaExtractionGateway` |
| `baseline` | `BaselineAiChatGateway`: simulador sin red para pruebas | desactivado |
| `openai` | `HostedAiChatGateway`, endpoint `responses` | `HostedFichaExtractionGateway`, `chat/completions` |
| `deepseek` | `HostedAiChatGateway`, `chat/completions` | `HostedFichaExtractionGateway`, `chat/completions` |
| `claude` / `anthropic` | `HostedAiChatGateway`, `messages` | desactivado |

Reglas de conexión:

- Chat: `AI_BASE_URL` debe ser `https://`, sin usuario ni fragmento.
- Fichas: si `AI_BASE_URL` está vacío usa `https://api.deepseek.com`. Acepta `http`
  solo para `localhost`. Necesita `AI_MODEL` y `AI_API_KEY`. Tiempos fijos: 5 s de
  conexión y 90 s de respuesta.
- No se siguen redirecciones. La clave no aparece en logs ni respuestas.

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

## Importación de fichas

Rutas: `GET|POST coordination/ficha-imports`, `POST …/{import}/apply` y
`DELETE …/{import}`. Detalle del flujo: [sequences.md](sequences.md#6-importación-de-fichas-con-ia).

1. Coordinación sube PDF o Excel. Exige la carrera editable, el gateway disponible y
   que no haya otra importación en curso. Los archivos van a
   `storage/app/private/importaciones-ficha/{id}/`.
2. `PrepareFichaImportJob` (cola `ia`, hasta 900 s) extrae el texto con
   `FichaDocumentText`:
   - PDF: OpenParser si está activo; si no, `pdftotext -layout` (Poppler);
   - Excel: PhpSpreadsheet, hasta 20 hojas y 5 000 filas por hoja; rechaza archivos de
     más de 150 MB descomprimidos;
   - corta el texto en bloques de unas 1 800 letras y los guarda en `bloques.json`.
3. Por cada asignatura activa de la carrera, `ExtractSubjectFichaJob` busca sus
   fragmentos (`FichaEvidence`) y pide a la IA un objeto JSON con los campos de la
   ficha (campos `coordinacion` de la plantilla).
   - El prompt pide copiar el texto, no resumir ni inventar, y devolver la bibliografía
     como `Autor | Título | Año | Ciudad | Editorial | ISBN | Código`.
   - Temperatura 0,2 y `response_format = json_object`.
   - Se limpian los valores: textos hasta 4 000 caracteres; listas hasta 40 elementos
     de 1 500.
   - Estado por asignatura: `lista`, `sin_evidencia` o `fallida`.
4. Coordinación revisa la propuesta y elige asignaturas y campos. Aplicar escribe la
   ficha en `valores_campo` (dueño: asignatura) y borra los archivos. Si hay sílabos en
   curso, pide confirmación y los regenera.

## OCR con OpenParser

`OpenParserPdfReader` usa la API de OpenParser:

1. `POST /parse/async` con el PDF, `ocr_model` (`paddleocr-vl-1.6`) y
   `output_format` (`openparser@1`). Envía un `Idempotency-Key` nuevo en cada envío.
2. Consulta `GET /jobs/{id}` cada `OPENPARSER_POLL_DELAY` segundos hasta
   `succeeded`, `failed` o `indeterminate`, o hasta `OPENPARSER_MAX_WAIT` (780 s).
   La espera ocurre dentro de `PrepareFichaImportJob`: el worker queda ocupado.
3. `GET /jobs/{id}/result?format=openparser@1`. Usa el `markdown` del resultado; si no
   viene, arma el texto desde `elements` (las tablas como filas separadas por `|`).
4. Publica en la importación el estado del OCR para que la SPA lo muestre.

Está apagado por defecto. Solo se activa con `OPENPARSER_ENABLED=true`,
`OPENPARSER_API_KEY` y una `OPENPARSER_BASE_URL` `https://`. Variables y tiempos:
[deployment.md](deployment.md#ocr-con-openparser).

Referencias: <https://www.openparser.dev/api>,
<https://www.openparser.dev/openparser-schema> y
<https://www.openparser.dev/paddleocr-api>.

## Seguridad

- El contenido de documentos, fichas y sílabos se trata como dato no confiable. El
  prompt lo encierra y prohíbe seguir instrucciones que vengan dentro.
- Solo Coordinación de la carrera importa fichas. Solo el docente del sílabo usa su
  chat, y solo mientras puede editarlo (`borrador` o `correccion_solicitada`).
- Antes de activar un proveedor externo hay que revisar privacidad y aviso al usuario
  ([aviso-de-privacidad.md](../security/aviso-de-privacidad.md)).
