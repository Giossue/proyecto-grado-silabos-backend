# IA y lectura de documentos

La IA ayuda en dos lugares. En ambos, una persona decide (ADR-0003):

1. **Chat del docente** sobre su sílabo (`AiAssistance`).
2. **Compatibilidad de importaciones antiguas de fichas**: propone la ficha de cada asignatura a partir de PDF o
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

El flujo actual de la interfaz genera y lee una plantilla Excel sin IA ni OCR:
[ficha-excel.md](ficha-excel.md). El resto de esta sección describe la compatibilidad
con documentos e importaciones anteriores. El POST histórico sigue disponible,
marcado como obsoleto en OpenAPI; no aparece en el diálogo nuevo.

Rutas: `GET|POST coordination/ficha-imports`, `POST …/{import}/apply` y
`DELETE …/{import}`. Detalle del flujo: [sequences.md](sequences.md#6-importación-de-fichas).

1. Coordinación sube PDF o Excel. Exige la carrera editable, el gateway disponible y
   que no haya otra importación en curso. Los archivos van a
   `storage/app/private/importaciones-ficha/{id}/`.
2. `PrepareFichaImportJob` (cola `ia`, hasta 900 s) extrae el texto con
   `FichaDocumentText`:
   - PDF: siempre OpenParser, incluidos los PDF con texto seleccionable. Si no está
     configurado o falla, explica el error; no usa extracción local ni un lector alternativo;
   - Excel: PhpSpreadsheet, hasta 20 hojas y 5 000 filas por hoja; rechaza archivos de
     más de 150 MB descomprimidos;
   - corta el resultado por líneas y separadores de página en bloques de unos 1 800
     caracteres y guarda texto, documento y página en `bloques.json`. Conserva la
     página de `elements.locations` mediante separadores del lector. Queda nula si
     el resultado no aporta una numeración verificable o si el documento es Excel;
   - documentos idénticos se leen una sola vez dentro de la importación. No hay caché
     entre importaciones ni cambios en la eliminación de temporales.
3. Por cada asignatura activa de la carrera, `ExtractSubjectFichaJob` busca sus
   secciones (`FichaEvidence`) por código, nombre normalizado y palabras del nombre.
   Conserva continuaciones hasta otra materia del catálogo o un nuevo documento,
   descarta entradas con puntos de índice y selecciona hasta 24 000 caracteres de
   la materia y hasta 8 000 de contexto compartido. El contexto compartido se busca
   usando las etiquetas, secciones y columnas de los campos disponibles. Cada
   ámbito admite hasta 48 fragmentos; cada fragmento lleva un identificador estable,
   documento, página, encabezado y texto. No llama al proveedor si solo existe
   contexto compartido sin evidencia de la asignatura.
   Las filas OCR identifican explícitamente cada asignatura, incluso las que no están
   en la malla activa, para cortar su contexto. Si una tabla declara una carrera,
   se compara su nombre normalizado con el de la importación y se excluyen las filas
   de otra carrera.
   `FichaFieldValues::instructions()` aporta las claves, etiquetas, sección,
   formato, columnas y estructura semántica del contenido de cada campo. Conserva
   párrafos, listas, texto y encabezados combinados, con hasta 12 000 caracteres de
   texto por campo y hasta 200 hijos por nodo. Para los componentes referenciados
   incluye etiquetas, propiedades, datos, reglas y texto del diseño (hasta 4 000
   caracteres por componente), sin el HTML de presentación. No usa ejemplos de la
   plantilla como evidencia de contenido. Los campos proceden de la responsabilidad
   configurada por Administración; no existe una lista fija por clave.
   El esquema público sigue siendo `key`, `label` y `columns`.

   Envía un JSON con `subject` (incluida la carrera), `fields` y `evidence`. Los
   fragmentos tienen `scope=subject` o `scope=career_context`; el prompt distingue
   datos propios de la materia y datos generales de carrera. La plantilla y las
   fuentes se tratan como datos no confiables.

   Pide a la IA un JSON para los campos `coordinacion` de la plantilla. El trabajo
   tiene un timeout de 240 s para hasta dos consultas de 90 s.
   - Si faltan campos, vuelve a buscar por sus etiquetas y columnas; prioriza la
     evidencia correspondiente y hace una sola consulta adicional, solo para esos
     campos, con un presupuesto total de 30 000 caracteres. Conserva lo obtenido
     si esta segunda consulta falla.
     Cada campo reserva el 75 % de su presupuesto para la materia y el 25 % para
     contexto compartido. No corta JSON serializado; limita el texto antes de formar
     los fragmentos. Al unir fragmentos repetidos conserva la variante más completa.
     La puntuación admite terminaciones de singular y plural para recuperar, por
     ejemplo, «Habilidad Blanda» al buscar «Habilidades blandas».
   - El prompt pide copiar el texto, no resumir ni inventar, y devolver la bibliografía
     como `Autor | Título | Año | Ciudad | Editorial | ISBN | Código`.
   - Temperatura 0,2 y `response_format = json_object`.
   - La respuesta del proveedor es `fields.{clave}.{value,evidence_ids}`. El gateway
     valida que los identificadores existan en la petición correspondiente y que
     sean una lista no vacía de hasta 16 referencias para los valores con contenido.
     Rechaza cada campo con referencias ausentes o desconocidas, sin perder los
     demás. El motivo público es `estructura_invalida`. La validación de referencias
     confirma que se citó una fuente recibida; no demuestra por sí sola que el texto
     esté respaldado semánticamente. La revisión de Coordinación sigue siendo necesaria.
   - Textos: hasta 12 000 caracteres. Listas: hasta 60 elementos de 3 000 caracteres.
     Tablas: hasta 40 filas con una celda de texto por columna, de hasta 1 500 caracteres.
     Las columnas se derivan de los encabezados de la plantilla, incluidos encabezados
     combinados. Las celdas ausentes quedan vacías; una estructura inválida se rechaza.
   - Estado por asignatura: `lista`, `sin_evidencia` o `fallida`.
4. Coordinación revisa la propuesta y elige asignaturas y campos. Aplicar escribe la
   ficha en `valores_campo` (dueño: asignatura) y borra los archivos. Si hay sílabos en
   curso, pide confirmación y los regenera.
   Las tablas se escriben conservando los encabezados y atributos de la tabla de la
   plantilla, sin deducir niveles ni relaciones académicas.

El contrato publica `coverage` (`found`, `total`), `missing` (clave, etiqueta y motivo)
y `extraction` (intentos y duración por materia). Los motivos son `sin_contexto`,
`no_encontrado` en los fragmentos analizados, `estructura_invalida` y
`servicio_no_disponible`. `lista` permite aplicar una propuesta parcial y no equivale
a ficha completa. La interfaz muestra «n de m campos encontrados» y permite revisar
pendientes aun cuando no haya valores propuestos.

`propuesta_importacion.timing` conserva inicio, fin, tiempo de lectura y método,
duración y reutilización de cada documento. No guarda prompts ni respuestas crudas.
Al terminar la lectura se retira el estado transitorio `reading`. Los resultados y
tiempos sobreviven a aplicar, mientras los archivos y fragmentos se eliminan.
Propuestas antiguas siguen siendo legibles; su cobertura se calcula con los campos
actuales y sus tiempos pueden ser nulos.

Cada asignatura conserva internamente `sources`: por clave de campo, identificadores,
documentos, páginas y ámbitos utilizados por el proveedor. No incluye fragmentos ni
se publica en el contrato actual. `FichaExtractionResult` transporta valores y
localizadores; se mantiene compatibilidad con gateways que devuelven directamente
los valores. Los cambios no alteran las rutas, el esquema público ni los valores de
fichas ya aplicadas.

## OCR con OpenParser

`OpenParserPdfReader` usa la API de OpenParser:

1. `POST /parse/async` con el PDF, `ocr_model` (`paddleocr-vl-1.6`) y
   `output_format` (`openparser@1`). Envía un `Idempotency-Key` nuevo en cada envío.
2. Consulta `GET /jobs/{id}` cada `OPENPARSER_POLL_DELAY` segundos hasta
   `succeeded`, `failed` o `indeterminate`, o hasta `OPENPARSER_MAX_WAIT` (780 s).
   La espera ocurre dentro de `PrepareFichaImportJob`: el worker queda ocupado.
3. `GET /jobs/{id}/result?format=openparser@1`. Prefiere `elements` y solo usa
   `markdown` si no obtiene contenido estructurado. `OpenParserTableText` expande
   `row_span` y `column_span`, conserva los encabezados y delimita las filas por
   asignatura. Los encabezados de una tabla continúan en las tablas siguientes del
   mismo ancho hasta un nuevo título o un cambio de ancho. Una fila sin nombre
   conserva la última asignatura de esa tabla, para las continuaciones entre páginas.
   Cada fila conserva título, carrera explícita y página de origen cuando existen.
   No interpreta una tabla HTML completa como una única mención ambigua de materias.
4. Publica en la importación el estado del OCR para que la SPA lo muestre.

Reproducción local del 2026-10-23 con los dos JSON OCR aportados (34 y 26 páginas):
antes, la guía no aportaba ningún fragmento para Álgebra Lineal ni Programación
Orientada a Objetos. Con las filas estructuradas, ambas selecciones incluyen
«Adaptabilidad» y «Aprender a Aprender», con la página 3 y los ciclos I y II,
respectivamente, y excluyen la tabla de Tecnologías de la Información. La comprobación
reutilizó las respuestas OCR sin llamadas externas; no evalúa una nueva respuesta
del proveedor de IA ni implica que todos los campos estén presentes en los documentos.

La reproducción del contexto estructurado reutiliza esos mismos dos JSON y la
plantilla oficial: obtiene 76 bloques del PDF de 34 páginas y 51 del de 26, todos con
página de origen. Envía los seis campos con su sección y estructura, incluida la
tabla de cinco columnas. Para Álgebra selecciona cinco fragmentos de materia y
nueve compartidos; para Programación Orientada a Objetos, doce y nueve. Las
habilidades conservan su referencia a la página 3 y no se incluye la tabla de otra
carrera. Con transporte simulado, acepta referencias recibidas, rechaza una
inexistente y conserva únicamente localizadores. Esta comprobación verifica el
recorrido de datos y el contrato del proveedor; no mide la calidad de una respuesta
real del modelo ni asegura completar los seis campos.

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
