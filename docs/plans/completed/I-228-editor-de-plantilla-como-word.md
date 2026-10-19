# I-228 — Editor de plantilla como Word

## Pedido del responsable, 2026-10-19

El administrador ve que, al editar la plantilla, el contenido sube y baja, hay controles encima del
texto y la hoja no se parece a la vista previa. Pide opciones fijas, como la cinta de Word, que
cambien según dónde haga clic.

## Diagnóstico (local, plantilla oficial)

- La barra de formato aparecía dentro del campo al seleccionarlo y empujaba el contenido; con una
  tabla ocupaba dos líneas y sumaba «Agregar fila» debajo.
- Quién llena, subir, bajar y eliminar flotaban sobre el campo anterior y chocaban con
  «Agregar campo».
- Los 19 campos y las 12 secciones medían distinto en edición: nombre de campo de 28 px aunque
  estuviera vacío, títulos de una sola línea, editor con relleno, interlineado y márgenes propios.
  La última hoja crecía una página.
- Los bloques (Unidades, Identificación, Evaluación) se paginaban como una sola pieza en edición:
  las filas pasaban por encima del corte de página.

## Cambios (frontend)

- Cinta fija arriba de la hoja con la misma altura en vista y en edición: herramientas del campo o
  de la sección seleccionada, formato y menú Tabla; se habilitan según dónde esté el cursor.
- El campo y la sección ya no muestran controles propios; el nombre vacío no ocupa espacio y se
  agrega desde la cinta.
- Nombre de campo, título de sección y título del documento con la misma tipografía que la vista y
  con varias líneas.
- El editor dentro de la hoja usa los mismos márgenes, interlineado y tablas que la vista previa.
- Paginación: las tablas dentro de bloques se cortan por filas; las filas solo de encabezado no
  quedan solas al final de una página.

- Al entrar a editar ya no aparece «Sin guardar» sin cambios: el editor de la hoja no agrega un
  párrafo vacío al final de las tablas.
- En la vista previa, un párrafo vacío ocupa una línea, como en Word.

## Verificación

- Pruebas de `TemplateVisualBuilder`, `AdminTemplatePage` y `documentPagination` (dos nuevas que
  fallan sin el cambio).
- Medición en el navegador con la plantilla oficial local, a 1440 px: los 19 campos, las 12
  secciones y las 3 hojas miden igual en vista y en edición; la hoja no se mueve al entrar a editar
  ni al seleccionar un campo, una celda o una sección; ninguna fila cruza un corte de página.
- A 1100 px la cinta pasa a dos filas sin ocultar Guardar.
