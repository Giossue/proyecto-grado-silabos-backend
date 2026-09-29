# I-109: Cálculo automático de semanas del período

## Estado

Implementación local completada.

## Decisión

El formulario de período solo solicita fecha de inicio y fecha de fin. El servidor
calcula `semanas_lectivas` contando las semanas de lunes a domingo que toca el rango,
incluidas la primera y la última aunque sean parciales. Una semana con tres o cuatro
días sigue siendo una semana de planificación.

La etiqueta automática conserva el rango de meses y el año, sin ordinal romano:
`Agosto-Diciembre 2026`.

## Trazabilidad

- RF-008..016, RF-017..026; RN-005..009; CU-03..04; ADM-04, DOC-04.
- I-41: el período es institucional y único.
- I-57: la plantilla utiliza la cantidad de semanas del período.
- Solicitud explícita del responsable del producto del 2026-09-23.

## Implementación

- Backend: `AcademicPeriodWeeks` calcula el valor al crear o editar; el cliente no puede
  reemplazarlo con un número manual.
- Frontend: alta y edición muestran el resultado como salida de solo lectura.
- La plantilla y la validación del sílabo siguen leyendo `semanas_lectivas` desde la
  fotografía académica del período.

## Ejemplos verificados

- `2026-08-17` a `2026-12-18`: 18 semanas.
- `2026-08-17` a `2026-12-04`: 16 semanas.
- `2026-08-21` a `2026-08-24`: 2 semanas calendario.
