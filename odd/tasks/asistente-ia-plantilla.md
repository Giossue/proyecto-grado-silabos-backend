# Asistente de IA para la plantilla

Objetivo: Administración pide cambios a la plantilla en lenguaje natural y la IA (DeepSeek) arma la propuesta.
Rama: `main` (política del repositorio). Pruebas: `php artisan test` (Pest) y `npm test` en el frontend.
Estado: **POR VALIDAR**; nada es regla de producción hasta que la fase 1 pase con pedidos reales.
Trazabilidad: ADR-0003, ADR-0011, RF-046 a RF-054, RN-028 a RN-030, RNF-016, RNF-035, RNF-036,
PV-12, PV-14, PV-18. Pantalla ADM-06.

## Specs

- S1. Cada capacidad es una función aparte («tools» o *function calling*): conversar, proponer,
  validar, etc. La IA trabaja con un conjunto de archivos Markdown con reglas y ejemplos. El JSON
  queda como opción avanzada; no se reemplaza por otro formato (se descartó LaTeX).
- S2. La IA **propone** y una persona **aplica**. No hay *tool* para guardar, aplicar, pausar,
  importar JSON ni borrar.
- S3. El seguimiento queda en nuestra base: cada mensaje y propuesta guarda quién la pidió, y al
  aplicar se audita quién la aprobó. A DeepSeek se le envía en `user_id` un código anónimo y
  estable: HMAC-SHA256 del `usuario_id` con `APP_KEY`, nunca el id, nombre ni correo.
- S4. Modelos por variables de entorno: `AI_MODEL` (chat del docente) y `AI_TEMPLATE_MODEL`
  (opcional, solo el asistente; vacía usa `AI_MODEL`). El código no distingue `deepseek-flash` de
  `deepseek-v4-pro`.
- S5. Razonamiento apagado (`thinking: {type: disabled}`). Si hay que prenderlo: reenviar
  `reasoning_content` en todas las llamadas del ciclo, no usar `tool_choice` `required` ni
  nombrada y subir `AI_TIMEOUT`.
- S6. Todos los Markdown de `resources/ai/plantilla/` van completos al inicio de cada llamada
  (se cachean); no hay *tool* para consultar el manual: `01-rol-y-reglas.md`,
  `02-secciones-y-campos.md`, `03-contenido-html.md`, `04-ejemplos.md`. `pruebas.txt` y
  `escenarios.json` no van al prompt.
- S7. Flujo: el administrador escribe en el panel; se guarda el mensaje y una respuesta
  `pendiente`; un trabajo en la cola `ia` corre el ciclo. Las *tools* de consulta leen la
  plantilla; las de propuesta cambian un borrador en memoria. El ciclo termina cuando la IA
  responde sin *tools* o al llegar a un límite. El asistente vive **solo en el modo edición**:
  «Aplicar» pone los cambios en el editor marcados «Cambió» y solo «Guardar» cambia la plantilla.
- S8. *Tools*. Todos los parámetros obligatorios y sin extras (modo estricto). Las claves nuevas
  las genera el servidor y nunca reusan una clave quitada.
  - Consulta: `ver_plantilla`, `ver_campo`, `listar_presets`, `listar_variables`, `ver_estado`.
  - Propuesta (no se ofrecen con la convocatoria abierta): `agregar_seccion`,
    `renombrar_seccion`, `cambiar_orientacion_seccion`, `mover_seccion`, `renumerar`,
    `quitar_seccion` (rechaza obligatorias y la de Biblioteca), `agregar_campo`,
    `renombrar_campo`, `cambiar_responsable_campo`, `cambiar_contenido_campo`, `mover_campo`,
    `quitar_campo`, `usar_preset`, `validar_borrador`. Posiciones con `despues_de`.
  - Conversación: `responder` (si el borrador tiene errores, se le devuelven a la IA) y
    `preguntar` (descarta el borrador).
  - La propuesta usa el formato del editor visual (`PUT admin/template/{id}/json`, versión 1).
- S9. Límites: `AI_TIMEOUT` (20 s) por llamada; 8 pasos y 2 minutos por pedido; hasta 3 pasos
  seguidos con error. Al pasarse: «No pude completar el pedido; intente explicarlo de otra
  forma», sin propuesta a medias. Sin cupo diario ni por minuto para Administración
  (2026-10-31); un pedido a la vez por persona.
- S10. Errores del proveedor: 401/402 → «Asistente no disponible» (el editor sigue); 429 y
  500/502/503/504 o sin conexión → reintenta tras 2 s y 5 s; 400/422 → sin reintento, guarda el
  detalle sin datos del pedido; `insufficient_system_resource` → «Inténtelo en unos minutos»;
  `length` o filtrada → paso con error; en blanco → reintento; argumentos inválidos o función
  inexistente → el error vuelve a la IA como resultado de la *tool*.
- S11. Seguridad: plantilla y mensajes son datos no confiables en el prompt; con la convocatoria
  abierta solo hay consulta y «Aplicar» se rechaza (`ProcessLocks`); si el borrador cambió desde
  el pedido, la propuesta queda «Vencida»; no se registra el contenido de mensajes ni la clave;
  se agrega al aviso de privacidad. Una propuesta idéntica a la pendiente no se repite
  (`TemplateAssistant::SAME_AS_PENDING`).
- S12. Datos: tablas `mensajes_ia_plantilla` («Nuevo chat» marca `ocultado_en`) y
  `propuestas_ia_plantilla` (estructura, cambios, huella base y estado). Rutas
  `admin/template/assistant`; detalle en `docs/architecture/ai-service.md`.

## Tasks

| ID | Specs | Ruta | Estado | Evidencia |
|---|---|---|---|---|
| T1 | S1–S6, S8–S10 | inline | hecho | Fase 1 (2026-10-31): `AiAgentGateway` (DeepSeek y `baseline`), *tools*, `template:assistant-trial`; 10 de 10 |
| T2 | S7, S11, S12 | inline | hecho | Fase 2 (2026-10-31): tablas, rutas, panel, «Aplicar» en el editor, auditoría |
| T3 | S8, S11 | inline | hecho | Evaluación con `escenarios.json` (2026-11-01): 27 de 27 turnos |
| T4 | S7 | — | pendiente | Probar en pantalla con DeepSeek y con administradores reales (PV-18) |
| T5 | — | — | pendiente | Fase 3: bloques, diseño y límites de listas; si aprieta el tiempo, trabajo futuro |
| T6 | S2–S12 | — | pendiente | Cierre: ADR-0003 (o ADR nuevo), `ai-service.md`, `deploy-dokploy.md`, `.env.example`, `screens.md` (ADM-06), `traceability.md`, aviso de privacidad, `DECISIONS_STATUS.md`, `pendientes.md`, `openapi:export` |

## Log

- L1 (2026-10-31, pedido del usuario, reconstruido del plan anterior): que Administración pida
  cambios a la plantilla en lenguaje natural y que la IA (DeepSeek, el mismo proveedor del chat
  del docente) arme la propuesta. Un docente pidió que cada capacidad sea una función aparte.
  Motivo: a los administradores el JSON de la plantilla les resulta complicado.
- L2 (2026-10-31, código revisado): `AiChatGateway` tiene un solo `reply()` sin *tools*;
  `mensajes_ia` exige `silabo_id` y su trigger impide cambiar mensajes terminados (por eso no se
  reusa); la plantilla ya tiene acciones por sección y campo con
  `ProcessLocks::assertTemplateEditable()`; `TemplateDefinition::define()` devuelve errores con
  ruta. El servidor de pruebas usa `AI_DRIVER=deepseek` y `AI_MODEL=deepseek-flash`.
- L3 (2026-10-31, API de DeepSeek): modelos `deepseek-flash` y `deepseek-v4-pro` (1M de
  contexto); `deepseek-chat` y `deepseek-reasoner` retirados desde el 2026-07-24. Modo estricto
  en `https://api.deepseek.com/beta` con `strict: true` en todas las funciones, todo `required`,
  `additionalProperties: false`, sin `minLength`/`maxLength`/`minItems`/`maxItems`. Caché por
  prefijo separada por `user_id`. Precio `flash` por millón (fuera de pico / pico): caché
  $0.003 / $0.006, sin caché $0.15 / $0.30, salida $0.60 / $1.20; `pro` unas 4 veces más.
  Solo `json_object`; la respuesta puede llegar en blanco.
- L4 (2026-10-31, fase 1): primera corrida 9 de 10 (mover la bibliografía llegaba al tope de 8
  pasos; se numeraba «Revisión y aprobación»). Se ajustaron las reglas. Segunda corrida 10 de 10,
  1–5 pasos y 1–9 s por pedido; ~238 000 tokens de entrada (91 % en caché) y 4 300 de salida,
  menos de un centavo.
- L5 (2026-10-31, corrección del usuario): el asistente vive solo en el modo edición; se quitaron
  la vista de la propuesta en modo lectura y el aplicar que guardaba directo.
- L6 (2026-11-01, evaluación con conversaciones): fallos de numeración al mover, cambios en la
  tabla de firmas, claves internas visibles y desconocimiento del estado de su propuesta. Se
  agregaron `despues_de`, `renumerar` y el estado de la última propuesta. Segunda corrida 27 de
  27; si el administrador aprobaba («si eso») a veces rehacía la propuesta: se resolvió con
  `SAME_AS_PENDING`.
- L7 (después de este trabajo, confirmado por el usuario el 2026-10-31): el chat del docente
  también pasará a *tools*, sobre todo para buscar en internet. Revisar entonces Search y Fetch
  de DeepSeek ($0.75 por cada 1 000 solicitudes exitosas). Va en su propio documento.
- L8 (2026-11-02): migrado de `docs/plans/active/` al formato ODD.
- Siguiente paso: T4.
