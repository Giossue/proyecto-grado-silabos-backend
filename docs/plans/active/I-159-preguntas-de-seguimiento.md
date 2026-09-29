# I-159 — Preguntas de seguimiento en la hoja

## Decisión del responsable, 2026-09-29

Personas que vieron el sistema no entendieron «¿Cuándo se pide?» en cada ficha: no
sabían con qué pregunta se relacionaba y la lista traía todas las de la plantilla. Se
adopta el patrón de los formularios conocidos (Google Forms, HubSpot, Typeform): la
regla se define en la pregunta que se responde, y la relación se ve en la hoja (como las
plantillas de DocuSign o PandaDoc).

## Alcance y trazabilidad

- ADM-06, I-142, I-153, I-158; RF-017..026. Solo interfaz: el servidor sigue recibiendo
  `showWhen` en la ficha de cada dependiente (I-158).

## Trabajo

- [x] `sheetConditions`: candidatas de la misma parte, respuestas (opciones o casillas
      «X»), numeración de grupos y cambio de `showWhen` en todas las fichas de una pregunta.
- [x] Ficha Sí/No (y casillas «X»): «Si responde [Sí | No], pedir también:». Las fichas
      «Docente» sin menú; número compartido (①…) y resaltado al pasar el cursor.
- [x] Se retira el menú «¿Cuándo se pide?» de I-158 y la lista de preguntas de toda la
      plantilla (`conditionTriggers`, `choiceOptions`).
- [x] Base remota: ficha de identificación reparada tras las pruebas del responsable
      (respaldo previo en `temp/respaldos/`): vuelven `discapacidad_tiene` (casillas «X»),
      `discapacidad_tipo` y `discapacidad_adaptacion` como seguimiento de «Sí»; las cuatro
      preguntas de prueba quedan retiradas del formulario.
- [x] Lint, typecheck, 223 pruebas de Vitest y build; el servidor no cambió.
