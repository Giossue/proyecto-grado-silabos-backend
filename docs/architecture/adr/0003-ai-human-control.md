# ADR-0003: IA desacoplada y bajo control humano

- Estado: Aceptado. Proveedor externo configurable; alcance actual: chat del docente.
  Extracción de fichas retirada el 2026-10-25 por decisión del usuario.
- Fecha: 2026-08-14
- Trazabilidad: DP-10, RN-028 a RN-030, RF-046 a RF-054, RNF-016, RNF-035, RNF-036.

## Contexto

La IA puede ayudar a redactar contenido y a leer documentos, pero sus resultados no son
determinísticos. Una decisión académica debe permanecer atribuible a una persona.

## Decisión

Laravel invoca un proveedor de IA externo, elegido por configuración (`AI_DRIVER`), a
través del contrato sustituible `AiChatGateway` y trabajos asíncronos. La IA se usa
en el chat del docente, que propone contenido para su sílabo y cita la evidencia
recibida. El docente elige qué usar. El núcleo no depende de la disponibilidad de la
IA. Detalle en [ai-service.md](../ai-service.md).

Las fichas se importan mediante Excel estructurado, sin IA ni OCR. Coordinación
revisa la vista previa y elige qué materias y campos aplicar.

## Consecuencias

- Se puede cambiar de proveedor o modelo sin modificar reglas de dominio.
- Se guardan la entrada, la evidencia y la versión de cada mensaje para reproducirlo.
- La interfaz distingue propuesta de IA de error determinístico.
- No se permiten aprobaciones, rechazos, bloqueos o ediciones automáticas.
