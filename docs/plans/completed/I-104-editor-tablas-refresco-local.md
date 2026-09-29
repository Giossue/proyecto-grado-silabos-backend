# I-104 — Editor de tablas y refresco local del constructor

## Objetivo

Pulir el editor de tablas de `ADM-06` y evitar que una mutación de la plantilla
desmonte y reconstruya todo el documento.

## Alcance

- Hacer visibles las acciones de combinar y separar celdas.
- Hacer evidente el menú de filas y columnas, incluyendo sus operaciones de alta,
  retiro y dirección.
- Mostrar una ayuda contextual sobre la selección de celdas y la combinación.
- Mantener montado el constructor visual cuando una operación de plantilla obliga a
  volver a leer el estado desde la API.

## Criterios de aceptación

- El administrador puede identificar sin depender del tooltip cómo combinar/separar
  celdas y dónde insertar o retirar filas/columnas.
- La selección múltiple mediante Mayús se refleja en la ayuda del editor.
- Crear o editar un bloque, campo, tabla o apariencia no muestra el skeleton ni
  reinicia la posición del documento mientras el constructor está abierto.
- El estado sigue sincronizándose con la API después de una mutación.

## Verificación

- Pruebas de `AdminTemplatePage`.
- Typecheck, build y lint del frontend.
- Revisión de regresión del constructor de plantilla y del editor de tablas.

## Resultado de esta implementación

- `npm run test -- --run`: 27 archivos y 99 pruebas aprobadas.
- `npm run typecheck`: aprobado.
- `npm run lint`: aprobado.
- `npm run build`: aprobado.
- La revisión visual manual en navegador queda como comprobación final de interacción
  de selección múltiple y operaciones de tabla.
