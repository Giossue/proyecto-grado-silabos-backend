# ADR-0003: IA desacoplada y bajo control humano

- Estado: Aceptado. Proveedor externo configurable; alcance actual: chat del docente
  y propuestas de ficha por asignatura.
- Fecha: 2026-08-14
- Trazabilidad: DP-10, RN-028 a RN-030, RF-046 a RF-054, RNF-016, RNF-035, RNF-036.

## Contexto

La IA puede ayudar a redactar contenido y a leer documentos, pero sus resultados no son
determinísticos. Una decisión académica debe permanecer atribuible a una persona.

## Decisión

Laravel invoca un proveedor de IA externo, elegido por configuración (`AI_DRIVER`), a
través de contratos sustituibles (`AiChatGateway`, `FichaExtractionGateway`) y trabajos
asíncronos. Hoy la IA se usa en dos lugares:

- el chat del docente, que propone contenido para su sílabo y cita la evidencia
  recibida;
- la importación de fichas, que propone la ficha de cada asignatura a partir de
  documentos que sube Coordinación.

En ambos casos una persona decide: el docente elige qué usar en su sílabo y
Coordinación elige qué asignaturas y campos aplicar. El núcleo no depende de la
disponibilidad de la IA. Detalle en [ai-service.md](../ai-service.md).

## Consecuencias

- Se puede cambiar de proveedor o modelo sin modificar reglas de dominio.
- Se guardan la entrada, la evidencia y la versión de cada mensaje para reproducirlo.
- La interfaz distingue propuesta de IA de error determinístico.
- No se permiten aprobaciones, rechazos, bloqueos o ediciones automáticas.
