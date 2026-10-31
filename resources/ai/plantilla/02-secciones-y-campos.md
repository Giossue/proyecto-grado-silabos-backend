# Secciones y campos

## Secciones

- Cada sección tiene un título, que se imprime tal cual en el sílabo, y una orientación de
  hoja: vertical u horizontal.
- Los títulos suelen llevar numeración («7. Metodología…»). Si agrega, quita o mueve una
  sección numerada, renombre las siguientes para que la numeración siga siendo consecutiva.
  Haga lo mismo con los campos numerados («7.1 …», «7.2 …»): si una sección cambia de número,
  sus campos numerados también (si «7. Metodología» pasa a ser la 8, «7.1 …» pasa a «8.1 …»).
  Use `renombrar_campo` en el mismo paso que los demás renombres. Renumere solo los títulos que ya
  llevan número: no agregue números a los que no lo tienen (por ejemplo, «Revisión y
  aprobación»).
- Toda sección necesita al menos un campo.
- `ver_plantilla` marca las secciones obligatorias (`obligatoria`) y la que revisa Biblioteca
  (`revisa_biblioteca`). Esas no se quitan.

## Campos

Cada campo tiene un nombre (subtítulo; puede ir vacío si no lleva subtítulo), un responsable
(`quien_llena`) y un contenido.

| quien_llena | En el editor se ve como | Qué significa |
|---|---|---|
| `docente` | Docente | Lo escribe el docente en su sílabo. Empieza con el contenido de la plantilla. |
| `coordinacion` | Carrera | Lo llena Coordinación una vez por asignatura (ficha de la asignatura) y se copia a cada sílabo. El docente no lo edita. |
| `institucional` | Fijo | Texto fijo de la plantilla, igual para todos. Nadie lo llena. |

- Todo campo que no sea `institucional` es obligatorio: el docente no puede enviar su sílabo
  si está vacío. Tenga esto en cuenta al agregar campos.
- Las claves de los campos y secciones existentes no cambian nunca. Los campos nuevos reciben
  su clave sola, a partir del nombre.
- Quitar un campo `coordinacion` hace que se pierda lo que Coordinación escribió en las fichas.
  Avíselo en el resumen.
- Cambiar quién llena un campo cambia quién lo escribe en adelante. Avíselo en el resumen.

## Partes fijas y espacios para responder

En los campos `docente` y `coordinacion`, el texto que ya trae la plantilla queda **fijo**: el
docente o Coordinación solo escriben en los espacios vacíos.

- Un párrafo vacío (`<p></p>`) es un espacio para escribir texto.
- En una tabla, las celdas vacías son espacios para responder y los encabezados quedan fijos.
- Una tabla con encabezados y **una sola fila vacía** funciona como lista: quien responde puede
  agregar filas.
- Un campo que solo tiene `<p></p>` es un espacio libre para responder.

Por eso, para pedir información al docente, ponga el texto guía fijo y deje los espacios
vacíos. No escriba texto de ejemplo dentro de los espacios: quedaría fijo.

## Contenidos listos (presets)

`listar_presets` muestra contenidos oficiales listos, como la tabla de identificación, la
planificación de unidades, la bibliografía o las firmas. `usar_preset` los copia en un campo y
fija quién lo llena. Es la única forma de poner un bloque.
