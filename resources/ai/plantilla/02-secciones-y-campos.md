# Secciones y campos

## Secciones

- Cada sección tiene un título, que se imprime tal cual en el sílabo, y una orientación de
  hoja: vertical u horizontal.
- Los títulos suelen llevar numeración («7. Metodología…») y algunos campos también
  («7.1 …»). No numere a mano: después de agregar, mover o quitar una sección numerada, llame a
  `renumerar`, que deja en orden las secciones numeradas y los subtítulos de sus campos.
- Una sección nueva que va entre secciones numeradas se titula con cualquier número
  («1. Proyecto integrador») y `renumerar` le pone el correcto. Un campo nuevo dentro de una
  sección con subtítulos numerados se nombra igual («1.1 Tutorías»).
- «Revisión y aprobación» no lleva número y `renumerar` no la toca: nunca le ponga número.
- Para ubicar secciones y campos use `despues_de` con la clave de la sección o del campo que
  queda antes («después del perfil de egreso» es `despues_de: perfil_egreso`), «(inicio)» o
  «(final)». «Antes de X» es después de la sección que hoy está justo antes de X.
- Toda sección necesita al menos un campo.
- `ver_plantilla` marca las secciones obligatorias (`obligatoria`) y la que revisa Biblioteca
  (`revisa_biblioteca`). Esas no se quitan.

## Campos

Cada campo tiene un nombre (subtítulo; puede ir vacío si no lleva subtítulo), un responsable
(`quien_llena`) y un contenido. En el editor, el responsable se elige en «¿Se puede editar?».

| quien_llena | En el editor se ve como | Qué significa |
|---|---|---|
| `carrera` | Editable (Carrera) | Se arma en capas: el texto de la plantilla queda fijo; Coordinación escribe lo suyo en el proyecto de carrera de cada asignatura y puede dejar espacios; el docente llena los espacios que quedan y contesta las respuestas. Si Coordinación no escribió nada, el docente lo escribe entero. |
| `institucional` | No editable (Institucional) | Texto de la plantilla, igual para todos. Nadie lo llena y no lleva respuestas. |

No existen los responsables «docente», «coordinación» ni «fijo». Si el administrador los
nombra: «que lo llene el docente» o «que lo llene Coordinación» es `carrera` (Editable);
«fijo» o «que nadie lo cambie» es `institucional` (No editable). En sus mensajes diga
«Editable» o «No editable».

- Al enviar, cada campo Editable con espacios debe tener algo escrito o respondido. No cuentan
  las respuestas opcionales (`"required":false`) ni las partes ocultas por «Mostrar solo si».
- Las claves de los campos y secciones existentes no cambian nunca. Los campos nuevos reciben
  su clave sola, a partir del nombre.
- Quitar un campo Editable hace que se pierda lo que Coordinación escribió en el proyecto de
  carrera. Avíselo en el resumen.
- Cambiar si un campo se puede editar cambia quién lo escribe en adelante. Avíselo en el
  resumen. Un campo con respuestas no puede pasar a No editable: quite antes las respuestas.

## Duplicar y mover

- `duplicar_campo` copia un campo justo debajo, con clave nueva y el nombre seguido de
  «(copia)»; `duplicar_seccion` copia una sección con todos sus campos debajo de ella. Las
  respuestas copiadas reciben identificadores nuevos; las condiciones se copian tal cual.
  Después de duplicar una sección numerada, llame a `renumerar`.
- `mover_campo` lleva un campo a otra sección o a otro lugar de la misma.

## Partes fijas y espacios para responder

En los campos Editables, el texto que ya trae la plantilla queda **fijo**: Coordinación y el
docente solo escriben en los espacios vacíos.

- Un párrafo vacío (`<p></p>`) es un espacio para escribir texto.
- En una tabla, las celdas vacías son espacios para responder y los encabezados quedan fijos.
- Una tabla con encabezados y **una sola fila vacía** funciona como lista: quien responde puede
  agregar filas.
- Un campo que solo tiene `<p></p>` es un espacio libre para responder.
- Una respuesta (`data-response`) pide contestar de una forma fija: texto libre, elegir una o
  elegir varias. No hay respuesta de número, fecha ni texto corto; si las piden, dígalo y ofrezca
  texto libre con límite de caracteres o elegir una.

Por eso, para pedir información al docente, ponga el texto guía fijo y deje los espacios
vacíos. No escriba texto de ejemplo dentro de los espacios: quedaría fijo.

## Contenidos listos (presets)

`listar_presets` muestra contenidos oficiales listos, como la tabla de identificación, la
planificación de unidades, la bibliografía o las firmas. `usar_preset` los copia en un campo y
fija quién lo llena. Es la forma de poner un bloque oficial.

## Tablas repetibles nuevas

Cuando el docente debe llenar una tabla a la que agrega filas (prácticas, salidas de campo,
tutorías), use `crear_tabla_repetible`: título, nombre de cada fila en singular, filas mínimas,
máximas y al empezar, y columnas de tipo `texto`, `opcion` (con sus opciones) o `numero`. Una
columna `obligatoria` impide enviar con esa celda vacía en alguna fila. Devuelve la clave del
bloque; póngalo en un campo Carrera con `<div data-block="CLAVE"></div>` usando `agregar_campo`
o `cambiar_contenido_campo`. Para una tabla fija con espacios para responder, arme la tabla HTML
con respuestas, no una tabla repetible. Cambiar columnas de una tabla ya creada se hace en el
editor: el administrador la abre en «Tabla repetible» de la cinta.

## Mostrar solo si (lógica condicional)

Una parte puede mostrarse solo si una respuesta anterior de elegir una o elegir varias tiene
alguna de ciertas opciones (por ejemplo «mostrar esto solo si respondió Sí»). Si no se cumple,
el docente no la ve, no se le exige, no se valida al enviar y no se imprime en el Word ni en el
PDF. Lo oculto no se borra: lo que el docente había escrito se conserva y vuelve a verse si
cambia la respuesta. Si la respuesta que decide no aparece en el sílabo, la parte se muestra.

- La respuesta que decide lleva un identificador: `"id"` en su `data-response` (minúsculas,
  números y guion bajo, por ejemplo `"id":"usa_laboratorio"`). `ver_campo` lo muestra.
- Campo entero: `mostrar_solo_si` con la clave del campo, el identificador de la respuesta y
  las opciones (por su texto, como «Sí», o su clave, como `o1`). Con `respuesta` vacía se quita.
- Una sola respuesta: ponga `"showIf":{"response":"usa_laboratorio","options":["o1"]}` en su
  `data-response`, con las claves de las opciones.
- Si una respuesta queda oculta se oculta toda su línea (su párrafo, o su celda si está en una tabla), salvo que la línea tenga otra respuesta visible: escriba la etiqueta y la respuesta condicionada en el mismo párrafo (`<p>¿Cuál? <span data-response='…'></span></p>`) y el texto que depende de ella en su propio párrafo.
- La respuesta que decide debe estar antes (en un campo anterior o antes en el mismo campo) y
  no puede ser la misma parte. Una sola condición por parte, sin combinar con «y» u «o».
- Si después se quita esa respuesta o la opción usada, la condición queda inválida y no se
  puede guardar hasta corregirla. Coordinación no puede quitar en su materia una opción que use
  una condición.

Bloques (su diseño interno no se cambia): identificación, unidades, evaluación y bibliografía.
La tabla de firmas y las demás tablas no son bloques: su contenido se puede cambiar con
`cambiar_contenido_campo`, respetando los colores permitidos.

## Bibliografía

La bibliografía es un bloque, no una tabla de texto: `ver_campo` la muestra como
`<div data-block="bibliografia"></div>`. Tiene columnas fijas (autor, título, año, ciudad,
editorial, ISBN y código) y el docente puede buscar los libros en el catálogo de la biblioteca
(Koha) para llenarla. Al enviar, cada libro debe tener título.

- Para agregar una bibliografía (básica, complementaria u otra lista de libros) cree el campo
  y use `usar_preset` con `bibliografia_basica` o `bibliografia_complementaria`. No arme una
  tabla de libros a mano: no tendría el buscador de la biblioteca.
- No cambie el contenido de un campo de bibliografía ni le agregue columnas.
- La sección de bibliografía es la que revisa Biblioteca y no se quita.
