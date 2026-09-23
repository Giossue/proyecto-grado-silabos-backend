# I-77 — Simplificar períodos académicos

## Decisión

`periodos_academicos.codigo` es la única etiqueta del período. Se retiran `nombre` y
`activo`; el estado se deriva exclusivamente de `fecha_inicio` y `fecha_fin`. La etiqueta
se genera en el servidor con el rango de meses y el año, por ejemplo,
`Agosto-Diciembre 2026`; el formulario no solicita un código manual.

## Semanas lectivas

`semanas_lectivas` permanece y se calcula desde las fechas. Cuenta las semanas de lunes a
domingo que toca el período, incluidas las semanas parciales, para distribuirlas en las
unidades y horas del sílabo.

## Alcance

- Eliminar los dos campos de la tabla mediante la migración `000062`.
- Sustituir toda etiqueta de período por su código.
- Permitir planificación mientras el período no haya finalizado.
- Mantener la validación de semanas lectivas entre 1 y 52.

## Datos existentes

Antes de desplegar se respalda producción. El 14 de septiembre de 2026 había dos
períodos; uno tenía un nombre distinto de su código y ese texto se eliminará por decisión
explícita del responsable del producto.
