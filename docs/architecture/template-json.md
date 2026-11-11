# Manual técnico: la plantilla del sílabo como JSON (formato 2)

La plantilla del sílabo **es un dato**, no código. Vive en la base (`plantilla_silabo.definicion_plantilla`,
`secciones_plantilla`, `campos_plantilla` y `definiciones_campo`) y se puede exportar, editar e importar como un
único archivo JSON. Con ese archivo se cambia **todo**: papel, letra, colores, encabezado, pie, variables, bloques
con su diseño y sus reglas, secciones y campos. No hace falta tocar código para crear o cambiar una plantilla
(I-229, ADR-0011).

El código solo sabe interpretar piezas genéricas: párrafos, listas, tablas, variables y las directivas de bloque
descritas abajo. El backend (Word/PDF, validación) y el frontend (constructor, editor del docente y vista previa)
leen la misma definición.

## Dónde está

- **Plantilla oficial de la UEB:** `resources/templates/silabo-oficial.json` (backend). La cargan la instalación y
  «Restablecer a plantilla oficial».
- **Plantilla en uso:** Admin → Plantilla → **Opciones avanzadas → Editar JSON** (con la plantilla sin cambios
  pendientes). Abre el JSON actual; permite **Descargar**, **Cargar archivo** y **Aplicar y reemplazar**. Si hay
  errores no se cambia nada y se muestra la ruta de cada uno. El editor visual sigue siendo la forma normal de
  cambiar secciones y campos, sin escribir JSON.
- **API** (solo Administración, bloqueada con un proceso abierto):
  - `GET /api/v1/admin/template/{id}/json` exporta.
  - `PUT /api/v1/admin/template/{id}/json` **reemplaza toda** la plantilla (auditado como `plantilla.importada`).

El archivo exportado empieza con `"$schema"`, que apunta al esquema publicado del formato
(`resources/templates/plantilla.schema.json`, servido en `/plantilla.schema.json`). Con él, un editor como VS Code
autocompleta y marca errores mientras se escribe; importar ignora esa clave. El servidor valida además lo que el
esquema no puede expresar (rutas de las reglas, datos del diseño, campos obligatorios).

Importar exige el proceso pausado y, al reanudar, los sílabos en curso se adaptan a la plantilla nueva por la clave de cada campo (I-188). **Un sílabo enviado o
aprobado guarda una copia de la definición** (`template_definition` en la fotografía de la revisión): cambiar la
plantilla o el código después no altera cómo se ve.

## Raíz del archivo

```json
{
  "version": 2,
  "response_policy": "protected",
  "document": { },
  "variables": { },
  "blocks": { },
  "required_sections": ["identificacion", "bibliografia"],
  "library_sections": ["bibliografia"],
  "presets": [ { "key": "firmas", "label": "Firmas de revisión y aprobación", "field": "firmas" } ],
  "sections": [ ]
}
```

| Clave | Qué es |
|---|---|
| `version` | `2`. El formato `1` (solo `sections`, `title` y `layout`) se sigue aceptando: conserva la definición actual. |
| `response_policy` | `protected`. Las definiciones nuevas o importadas en formato 2 protegen el formato. Las definiciones antiguas guardadas sin esa clave conservan su interpretación hasta actualizarlas. |
| `document` | Papel, letra, colores, título, encabezado y pie. |
| `variables` | Catálogo de datos del sistema que se pueden insertar. |
| `blocks` | Definición de cada bloque: valor, propiedades, reglas y diseño. |
| `required_sections` | Claves de sección que deben existir para abrir un proceso. |
| `library_sections` | Secciones que revisa Biblioteca. |
| `presets` | «Plantillas listas» del constructor: copian el contenido de un campo de este mismo archivo. |
| `sections` | Secciones y campos (igual que en el formato 1). |

Opcionalmente, `title` y `layout` (formato 1) sobrescriben el título y los márgenes/tamaños de `document`.

## Formato fijo y respuestas

`response_policy` no añade interruptores al constructor. Administración edita todo el
documento; en los campos Carrera, Coordinación y el docente completan sus espacios (ver
«Quién llena cada campo»). El texto no vacío y los encabezados quedan fijos. Un párrafo vacío recibe
texto con saltos de línea; una celda de datos vacía recibe texto enriquecido. Un campo
completamente vacío admite una respuesta documental libre. Si solo hay texto fijo,
se añade un espacio de respuesta al final al presentar el campo.

Una tabla con encabezados y una sola fila de datos totalmente vacía es una lista:
permite añadir filas o quitar la última, conservando al menos una fila de datos.
Las demás tablas conservan filas, columnas, celdas combinadas y estilos. El servidor
rechaza cualquier modificación de las partes fijas. Los rótulos no cuentan como
respuesta para completar el proyecto de carrera o enviar el sílabo.

Un espacio de respuesta puede pedir además un **tipo de respuesta** (número, elegir una,
elegir varias) en lugar de texto libre. Ver «Tipos de respuesta» más abajo.

La API entrega `template` junto a `value` en campos de sílabo protegidos y junto a
`content` en campos de proyecto de carrera protegidos. La SPA usa `FieldResponseEditor`; los campos
anteriores sin `template` conservan el editor libre. Los bloques siguen usando sus
esquemas de valor, propiedades y reglas. Detalle: ADR-0012.

## `document`

```json
"document": {
  "paper": "letter",
  "orientation": "portrait",
  "margin_cm": 1.5,
  "font_family": "Arial",
  "line_height": 1.15,
  "paragraph_spacing_pt": 6,
  "sizes": { "title": 14, "section": 11, "body": 11 },
  "colors": { "text": "#000000", "title": "#2F5496", "table_header_background": "#C3D4EA",
              "table_header_text": "#000000", "table_border": "#333333", "empty_text": "#595959" },
  "title": { "text": "PROGRAMA DE ASIGNATURA (SÍLABO)", "bold": true, "italic": false, "alignment": "center" },
  "section_title": { "bold": true, "italic": false, "alignment": "left" },
  "body": { "alignment": "left" },
  "header": { "logos": [ { "source": "institution", "height_pt": 36 }, { "source": "faculty", "height_pt": 36 } ],
              "left_share": 0.638 },
  "empty_text": "Sin contenido",
  "choices": { "margin_cm": [1.5, 2.0, 2.5, 3.0], "title": [10, 14, 16, 18], "section": [10, 11, 12, 14], "body": [10, 11, 12] }
}
```

| Clave | Regla |
|---|---|
| `paper` | `letter`, `a4` o `legal`. La vista previa y el Word usan el mismo papel. |
| `orientation` | Orientación por defecto: `portrait` o `landscape` (cada sección puede cambiarla). |
| `margin_cm` | 0,5 a 5. |
| `font_family` | Arial, Calibri, Times New Roman, Verdana o Georgia. |
| `line_height`, `paragraph_spacing_pt` | Interlineado (1 a 3) y espacio después de cada párrafo (0 a 36 pt). |
| `sizes` | Tamaño del título, de los títulos de sección y del contenido (5 a 72 pt). |
| `colors` | `#RRGGBB`. `table_header_background` y `table_header_text` se aplican **solo** a los encabezados de tabla (`<th>`) sin fondo propio; pueden ser `null`. La plantilla oficial usa el celeste `#C3D4EA` con texto negro, como los rótulos de la tabla de identificación. |
| `title`, `section_title`, `body` | Negrita, cursiva y alineación (`left`, `center`, `right`, `justify`). |
| `header.logos` | Hasta dos logos: el primero a la izquierda y el segundo a la derecha. `source`: `institution` o `faculty`. |
| `header.left_share` | Fracción del ancho para el logo izquierdo (0,1 a 0,9). |
| `footer`, `metadata` | Ya no existen: los documentos no llevan número de página ni línea de revisión, huella y fecha de generación (2026-11-02). Si una definición antigua los trae, se aceptan y se ignoran. |
| `empty_text` | Texto de un campo vacío en el Word. |
| `choices` | Valores que ofrece el botón **Diseño** del constructor. |

Las claves ausentes toman valores neutros (carta, 2 cm, Arial 11, negro). La identidad de la UEB está en el
archivo oficial, no en el código.

## `variables`

```json
"variables": {
  "nombre_carrera": { "label": "Nombre de la carrera", "source": "identification.career", "sample": "Software" },
  "horas_docencia": { "label": "Horas de docencia (ACD)", "source": "identification.hours_ac", "format": "number", "sample": "32" },
  "marca_unidad_basica": { "label": "Marca de unidad básica", "source": "identification.organization_unit", "equals": "Unidad básica" },
  "firma_docente": { "label": "Firma del docente", "source": "signature.docente" }
}
```

| Clave | Regla |
|---|---|
| `label` | Nombre que ve el administrador. |
| `source` | Dato que entrega el sistema (lista abajo). |
| `sample` | Valor de muestra para el constructor. |
| `format` | `number`: quita ceros decimales sobrantes. |
| `equals` | Muestra `X` cuando el dato es igual a este texto (casillas de marca). |

Fuentes del sistema:

- `identification.`: `faculty`, `career`, `subject`, `code`, `modality`, `campus`, `period`, `cycle`, `parallel`,
  `shift`, `prerequisites`, `corequisites`, `organization_unit`, `hours_ac`, `hours_pae`, `hours_aa`, `total_hours`,
  `credits`, `teaching_weeks` (semanas de clase del período), `teacher`, `email`, `formation`.
- `approval.`: `submitted_on`, `coordinator`, `approved_on`, `librarian`, `verified_on` (se llenan al exportar).
- `signature.`: `docente`, `coordinacion`, `biblioteca`. Marcan dónde va cada firma electrónica en el PDF.

En el contenido, una variable se escribe `<span data-variable="nombre_carrera"></span>`. Solo se aceptan claves
del catálogo.

## `blocks`: bloques con diseño, datos y reglas

Un bloque es una pieza que el docente (o la plantilla) **llena** con datos estructurados y que se dibuja con un
diseño propio. En el contenido de un campo se inserta así:

```html
<div data-block="unidades"></div>
```

`data-props` fija las propiedades de esa aparición; `data-value` (opcional) da un valor inicial. El docente solo
responde: no puede cambiar el tipo ni las propiedades.

Cada definición tiene:

```json
"unidades": {
  "label": "Distribución y planificación de las unidades",
  "props": { },
  "value": { },
  "rules": [ ],
  "layout": [ "líneas HTML" ]
}
```

### `value`: qué datos guarda

Un objeto con un esquema por clave, con el vocabulario de JSON Schema:

| Dato | Esquema | Se guarda como |
|---|---|---|
| Texto | `"type": "string"`, `maxLength` (1 a 20000, por defecto 1000), `multiline` | texto |
| Elección | `"type": "string"`, `oneOf`: `[{"const": "si", "title": "Sí"}, {"const": "no", "title": "No"}]` | la clave elegida o `null` |
| Número | `"type": "number"`, `maximum` (por defecto 999.99), `multipleOf` (1, 0.1, 0.01… por defecto 0.01) | texto con número (`2`, `1,5`) |
| Grupo | `"type": "object"`, `properties`: otro objeto de esquemas | objeto |
| Lista | `"type": "array"`, `items` (esquema de cada elemento), `minItems`, `maxItems` (≤ 100), `initial` | lista |

Comunes: `title` (nombre del dato en la pantalla y en los errores), `errorMessage` (mensaje propio al rechazarlo) y
`keep_if` (condición, ver abajo). Si `keep_if` deja de cumplirse, el dato se borra. Por ejemplo, el detalle de
discapacidad se borra si la respuesta es «No».

Tres claves son propias de este formato y JSON Schema no las define: `multiline` (la pantalla muestra un cuadro de
varias líneas; si falta, una sola línea), `initial` (con cuántos elementos arranca una lista ausente) y `keep_if`.
Una lista con `minItems` < `maxItems` se puede agrandar y achicar desde el editor, y debe repetirse en una fila de
tabla (`<tr data-repeat>`) o en una sección (`<section data-repeat>`).

El constructor visual cambia `minItems`, `maxItems` e `initial` de cada lista sin tocar el JSON («Editar → Listas»).
Al guardar envía `"lists": [{"block": "unidades", "path": "units.*.semanas", "min": 1, "max": 20, "initial": 2}]`.
El servidor vuelve a validar el bloque y rechaza un máximo menor que el mínimo o un «Al
empezar» fuera de ese rango.

Los nombres anteriores (`text`, `choice` con `options`, `number` con `digits` y `decimals`, `list` con `item`,
`min` y `max`, `fields`, `label`, `error`) se siguen aceptando al importar y al leer revisiones antiguas: el
servidor los traduce a los de esta tabla.

### `props`: qué fija la plantilla en cada aparición

Solo textos (`"type": "string"`, `maxLength`, `minLength: 1` si es obligatorio, `title`, `errorMessage`). El
administrador los escribe en el constructor.

### `rules`: reglas de negocio

```json
"rules": [
  { "code": "semanas_maximas", "on": "save", "message": "Las unidades suman más semanas que el período.",
    "checks": [ { "count": "units.*.semanas", "max": { "variable": "semanas_periodo" } } ] },
  { "code": "unidades_sin_nombre", "on": "submit", "message": "Escriba el nombre y el resultado de cada unidad: {detail}.",
    "checks": [ { "label": "Nombres", "filled": "units.*.nombre", "equals": { "count": "units" } } ] },
  { "code": "horas_unidades_no_coinciden", "on": "submit",
    "message": "Las horas de las unidades deben sumar las de la asignatura: {detail}.",
    "hint": "Las horas deben coincidir con las de la asignatura para poder enviar",
    "checks": [ { "label": "ACD", "sum": "units.*.semanas.*.acd_h", "equals": { "variable": "horas_docencia" } } ] }
]
```

| Clave | Regla |
|---|---|
| `code` | Código estable del error (lo usan pantallas y pruebas). |
| `on` | `save`: rechaza el guardado. `submit`: impide enviar el sílabo. |
| `checks` | `count` (cuántos elementos hay en la ruta), `filled` (cuántos textos o números no están vacíos) o `sum` (suma de números) comparado con `equals`, `max` o `min`. El valor es un número, `{"variable": "clave"}` o `{"count": "lista"}` (cuántos elementos tiene esa lista; así «cada unidad» vale con 3 o con 6). Si la variable no tiene valor, la comprobación no se aplica. |
| `label` | Si la comprobación lleva nombre, el editor la muestra en vivo («ACD 30 de 32»). |
| `message` | `{actual}` y `{expected}` (primera comprobación que falla) y `{detail}` («ACD 30 de 32, AA 10 de 32»). |
| `hint` | Texto de ayuda junto al resumen en vivo. |

Las rutas usan `.` y `*` para «cada elemento de la lista»: `units.*.semanas.*.acd_h`.

### `layout`: cómo se dibuja

El diseño es HTML como el de los campos (párrafos, tablas, listas, variables, celdas con estilo) más estas
directivas:

| Directiva | Dónde | Qué hace |
|---|---|---|
| `data-repeat='{"value": "units"}'` | `<section>`, `<table>`, `<tr>`, `<td>`, `<th>`, `<p>`, listas | Repite el elemento por cada elemento de la lista. Dentro, las rutas son relativas a ese elemento. |
| `"at_least": 1` (dentro de `data-repeat`) | igual | Dibuja filas vacías hasta llegar a ese número. |
| `data-when='{...}'` | igual | Dibuja el elemento solo si se cumple la condición. |
| `data-band="#E1E9F4"` | `<tr>` | Pinta las filas impares que no tengan fondo propio. |
| `<section>` | raíz o dentro de otra `section` | Agrupa elementos (para repetirlos juntos). No se dibuja. |

Datos dentro del texto (`<span data-slot='{...}'></span>`):

| Dato | Muestra |
|---|---|
| `{"value": "nombre"}` | El dato. El editor del docente pone ahí un campo para escribir, un número o una elección. Una elección muestra su texto («Sí»). |
| `{"value": "disability.answer", "option": "si"}` | `X` si el dato es esa opción (`"mark"` cambia la marca). En el editor, una casilla. |
| `{"prop": "question"}` | Una propiedad de texto. |
| `{"item": "unidad.nombre"}` | Un dato del elemento nombrado con `as` en `data-repeat`. |
| `{"index": true}` | Posición (desde 1) en la repetición más cercana. |
| `{"counter": "semana"}` | Contador que sigue entre repeticiones (1, 2, 3… en todo el bloque). |
| `{"sum": "semanas.*.acd_h"}` | Suma de números. |
| `{"count": "units.*.semanas"}` | Cantidad de elementos. |
| `{"filled": "units.*.nombre"}` | Cantidad de datos escritos; los vacíos o solo con espacios no cuentan. |

Condiciones (`data-when` y `keep_if`):

```json
{ "value": "answer", "equals": "si" }
{ "prop": "titulo", "empty": false }
{ "variable": "horas_practicas", "zero": false }
```

Una condición lleva una fuente (`value`, `prop`, `item` o `variable`) y una prueba (`equals`, `empty` o `zero`,
donde `zero` significa que el dato tiene un número igual a 0). Para negar, use `false` en la prueba. No hay
condiciones compuestas (`all`, `any`, `not`); las revisiones antiguas que guardaron un `not` se siguen mostrando.

### `catalog`: llenar filas con libros de la biblioteca

Un bloque puede declarar que sus filas se llenan con libros del catálogo Koha (`BibliographicCatalog`):

```json
"catalog": { "list": "libros", "map": { "author": "autor", "title": "titulo", "year": "anio", "place": "ciudad", "publisher": "editorial", "isbn": "isbn" } }
```

`list` es una lista del bloque y `map` dice qué dato del libro va a cada columna de texto de esa lista. Los datos
del libro son `author`, `title`, `year`, `place`, `publisher`, `isbn`, `call_number` (signatura), `format` («Libro
físico», «Tesis física» o «Revista física», según el tipo de Koha) y `kind` («Libro», «Referencia», «Tesis» o «Revista»); el que no se nombre no se llena (la plantilla oficial lleva `format` a `codigo`). Un dato que el catálogo no
tiene se escribe «N/A». El docente ve «Buscar en la biblioteca» sobre el
bloque; el servidor valida que `list` exista y que cada columna sea de texto.

### Ejemplo completo: un bloque nuevo sin código

```json
"practicas": {
  "label": "Prácticas de laboratorio",
  "value": { "practicas": { "type": "array", "maxItems": 10, "initial": 1, "title": "práctica", "items": {
    "type": "object", "properties": {
      "tema": { "type": "string", "maxLength": 200, "title": "Tema" },
      "horas": { "type": "number", "maximum": 99, "multipleOf": 1, "title": "Horas" }
    } } } },
  "rules": [ { "code": "horas_practicas_insuficientes", "on": "submit",
    "message": "Las prácticas suman {actual} horas de {expected}.",
    "checks": [ { "label": "Horas", "sum": "practicas.*.horas", "min": { "variable": "horas_practicas" } } ] } ],
  "layout": [
    "<table>",
    "<tr><th>N.º</th><th>Tema</th><th>Horas</th></tr>",
    "<tr data-repeat='{\"value\":\"practicas\"}' data-band=\"#EEEEEE\"><td><span data-slot='{\"index\":true}'></span></td><td><span data-slot='{\"value\":\"tema\"}'></span></td><td><span data-slot='{\"value\":\"horas\"}'></span></td></tr>",
    "<tr><th colspan=\"2\">Total</th><th><span data-slot='{\"sum\":\"practicas.*.horas\"}'></span></th></tr>",
    "</table>"
  ]
}
```

Y en un campo docente: `"content": ["<div data-block=\"practicas\"></div>"]`. El docente ve la tabla con campos y
el botón «Agregar práctica»; el Word imprime la tabla con el total; enviar exige que las horas lleguen a las de la
asignatura. (La prueba `TemplateDefinitionTest` usa este mismo ejemplo.)

### Bloques de la plantilla oficial

| Bloque | Qué hace |
|---|---|
| `identificacion` | Tabla de identificación con datos del sistema; el docente responde la discapacidad (Sí/No, tipo y descripción). |
| `unidades` | De 1 a 8 unidades (empieza con 4; el administrador lo cambia en «Editar → Listas») con semanas, horas y actividades. El docente escribe el nombre y el resultado de cada unidad. Reglas: al guardar, no más semanas que las del período (`semanas_periodo`); al enviar, cada unidad con nombre y resultado, las semanas del período y horas iguales a las de la asignatura. |
| `evaluacion` | Tabla de puntos con dos variantes según `horas_practicas` (con o sin práctico-experimental). |
| `bibliografia` | Tabla de libros (de 1 a 40) con las columnas `autor`, `titulo`, `anio`, `ciudad`, `editorial`, `isbn` y `codigo`; los títulos de columna los cambia Administración sin romper nada. Declara `catalog` para el buscador de la biblioteca. Al enviar, cada libro exige su título. |

## Secciones y campos

```json
{
  "key": "descripcion",
  "title": "2. Descripción de la asignatura",
  "orientation": "portrait",
  "fields": [
    { "key": "descripcion", "label": "Descripción", "quien_llena": "carrera", "content": ["<p></p>"] }
  ]
}
```

| Campo | Regla |
|---|---|
| `key` | Minúsculas, números y `_`; empieza con letra; máximo 100. Única entre secciones o entre campos de toda la plantilla. No cambie la clave de un campo en uso. |
| `title` / `label` | Máximo 180. El título se imprime tal cual. Un `label` vacío no imprime subtítulo. |
| `orientation` | `portrait` o `landscape`. |
| `quien_llena` | `carrera` («Carrera») o `institucional` («Institucional»). Un JSON anterior con `docente` o `coordinacion` se importa como `carrera`. |
| `content` | Lista de líneas HTML, un texto HTML o un documento Tiptap (`{"type":"doc",...}`). |

Límites: 60 secciones, 60 campos por sección, tablas de hasta 24 columnas y 100 filas, documentos de hasta 2 MB.

### Quién llena cada campo

Los campos no tienen tipo: todos guardan un documento Tiptap con texto, listas, tablas,
variables y bloques. Lo que cambia es el responsable (`quien_llena`). Hay dos (2026-11-10;
antes eran `docente`, `coordinacion` e `institucional`):

| `quien_llena` | En pantalla | Contenido que ve el sílabo |
|---|---|---|
| `carrera` | Carrera | Se arma en tres capas: el `content` de la plantilla (su texto no vacío queda fijo para todos), lo que Coordinación escribe para cada materia en el proyecto de carrera y lo que el docente escribe en los espacios que le quedan. |
| `institucional` | Institucional | El `content` de la plantilla con las variables resueltas. No se guarda por sílabo. Nadie lo edita. |

Un campo Carrera, en cada materia:

- **Sin fila de la materia** (Coordinación no escribió nada): el docente lo escribe entero, como
  el antiguo campo `docente`. Si la plantilla solo trae texto fijo, se le añade un espacio al final.
- **Con fila de la materia**: lo que escribió Coordinación le llega fijo al docente. El docente
  solo llena los espacios: los espacios vacíos de la plantilla que Coordinación dejó vacíos, las
  celdas vacías de tablas y las respuestas sin contestar (incluido «Texto libre»). Una respuesta
  que Coordinación contestó queda fija. Si no queda ningún espacio, el campo es de solo lectura
  para el docente (`teacher_editable: false`), como el antiguo campo `coordinacion`.
- Al guardar, el servidor quita las líneas vacías que Coordinación deja dentro de lo que escribió
  (no son espacios para el docente) y deja sin contestar las respuestas que ella puso. Para dejar
  un espacio se usa el botón «Espacio para el docente».

Reglas:

- El proyecto de carrera (`GET`/`PUT academic/asignaturas/{id}/ficha`) lista todos los campos
  Carrera con algún espacio para escribir (los que son solo un bloque, como unidades o
  bibliografía, no). `values` trae lo guardado por campo; `null` o lo mismo que la plantilla
  borra la fila. Ya no existe `response_fields`.
- El proyecto de carrera de una materia está **completo** si ningún campo Carrera deja un espacio
  de elegir sin opciones. Un campo sin llenar no lo deja incompleto: lo escribe el docente.
  Esa regla decide el paso «Completar el proyecto de carrera» y el aviso «Falta completar el
  proyecto de carrera de N materias.» al abrir la convocatoria.
- El guardado del borrador acepta solo campos Carrera con espacios para el docente; rechaza los
  demás con `422` («Este campo pertenece a Coordinación o a la institución y el docente no
  puede cambiarlo.»).
- Al enviar, cada campo Carrera con espacios debe tener algo escrito o respondido y cada
  respuesta debe estar contestada. Los campos de solo lectura no se revisan.
- Cambiar el proyecto de carrera con sílabos en curso actualiza esos campos en los sílabos de la
  materia y conserva lo que el docente escribió donde todavía encaja; un campo que queda de solo
  lectura toma lo que escribió Coordinación. Los enviados o aprobados vuelven a Borrador tras
  confirmar.
- La importación desde Excel escribe el contenido de los campos Carrera (no crea espacios de
  elegir): lo importado le llega fijo al docente.
- Las revisiones enviadas antes del cambio conservan `docente` o `coordinacion` en su
  fotografía; el PDF, el Word y la vista previa no dependen de ese valor.
- Título y logos van en el encabezado fijo (`document.title`, `document.header`); no
  son campos.

### Tipos de respuesta

Un párrafo o una celda puede llevar una respuesta de un tipo, como en un formulario: quien arma
elige el tipo y escribe las opciones; quien llena el campo solo responde. Un párrafo o una celda
vacíos siguen siendo un espacio de texto libre sin nodo; el tipo `texto` sirve para dejar un
espacio de texto libre junto al texto que ya escribió Coordinación.

| Tipo (`kind`) | Nombre | Opciones | Se guarda en `value` |
|---|---|---|---|
| `texto` | Texto libre | no lleva | texto de hasta 3000 caracteres, con saltos de línea; vacío `""` |
| `numero` | Número | no lleva | texto con número (`12`, `12,5`); vacío `""` |
| `una` | Elegir una | lista | la clave elegida o `null` |
| `varias` | Elegir varias | lista | lista de claves; vacío `[]` |

El tipo, las opciones y lo respondido viajan dentro del documento del campo, en un nodo en línea
`response`. No hay tablas ni columnas para esto:

```json
{ "type": "response", "attrs": { "kind": "una",
  "options": [ { "const": "o1", "title": "Presencial" }, { "const": "o2", "title": "En línea" } ],
  "value": "o2" } }
```

En HTML: `<span data-response='{"kind":"una","options":[…],"value":null}'></span>`.

Reglas:

- Hasta 50 opciones por respuesta, cada una con texto de hasta 120 caracteres y sin repetir.
  `const` es la clave estable de la opción (minúsculas, números y `_`); el editor la genera
  (`o1`, `o2`…). Cambiar el texto de una opción conserva lo ya elegido.
- Un campo `institucional` («Institucional») no admite tipos de respuesta («Un campo
  Institucional no lleva tipos de respuesta. Cambie quién lo llena o quite la respuesta.»). En la
  definición de la plantilla las respuestas se guardan siempre sin contestar.
- **Administración** las pone desde la cinta del editor de la plantilla, en campos Carrera; valen
  para toda la universidad. Puede dejar una de elegir sin opciones.
- **Coordinación**, en la página del proyecto de carrera de cada materia, escribe el campo y deja
  espacios con «Espacio para el docente» (texto libre, número, elegir una o varias, con las
  opciones escritas ahí mismo). Puede contestar una respuesta que puso Administración (queda fija
  para el docente) o dejarla para el docente, y escribe las opciones que Administración dejó
  vacías. No cambia el formato fijo de la plantilla. Lo escrito se guarda como fila de
  `valores_campo` de la asignatura y sigue el bloqueo de la convocatoria abierta. Reemplazar la
  plantilla lo conserva por clave de campo mientras siga encajando.
- **El sílabo** del docente parte de esa fila; esa es la `template` que recibe y contra la que el
  servidor protege el guardado. El docente solo contesta: no cambia el tipo ni las opciones, no
  escribe junto a la respuesta y no la quita. En una tabla que crece, cada fila nueva repite las
  respuestas de la fila modelo, sin contestar.
- **Al enviar**, cada respuesta debe estar contestada (`respuesta_faltante`: ««{campo}» tiene una
  respuesta sin contestar. Elija una opción o escriba la respuesta antes de enviar.») y una de elegir sin
  opciones escritas avisa `respuesta_sin_opciones` («…pídalas al coordinador.»).
- **Cambiar lo armado con sílabos en curso** (en pausa, o al reabrir una convocatoria
  finalizada) lleva el campo de cada sílabo a la estructura nueva y conserva lo ya contestado
  donde todavía encaja; los enviados o aprobados vuelven a Borrador tras confirmar, como con
  cualquier cambio del proyecto de carrera.
- **Word, PDF y revisiones** imprimen lo elegido como texto (una opción por línea en «Elegir
  varias») y nada si no se contestó. Las opciones viajan con la respuesta, así que una revisión
  enviada conserva lo elegido aunque después cambien.

El catálogo de tipos está en un solo lugar por capa: `ResponseKind::KINDS` (backend) y
`RESPONSE_KINDS` en `src/lib/responseKinds.ts` (frontend). Cada tipo es un nombre y un esquema
del mismo motor de valores de los bloques (`ValueSchema`: número, elección `oneOf`, lista), de
modo que validar, mostrar el control e imprimir leen del catálogo. Agregar un tipo es agregar su
entrada en cada catálogo (y su ícono en el menú de cada espacio, `ResponseSlotSetup.vue`).

### HTML permitido en el contenido

| Qué | Cómo |
|---|---|
| Párrafo | `<p>`, alineación con `style="text-align:center"` |
| Negrita, cursiva, subrayado | `<strong>`, `<em>`, `<u>` |
| Salto de línea | `<br>` |
| Listas | `<ul>`/`<ol>` con `<li>`; una lista dentro de `<li>` es sublista |
| Tabla | `<table>`, `<tr>`, `<th>`, `<td>`; `colspan`, `rowspan`; `data-width="144,66"`; `data-border="thin\|thick\|none"` |
| Estilo de celda | `style="background:#C3D4EA;color:#FFFFFF;text-align:center;font-weight:bold;font-style:italic"` |
| Color o fuente del texto | `<span style="color:#1F3864;font-family:Arial">…</span>`. El editor visual ofrece solo negro, azul `#365F91` y blanco para el texto, y celeste `#C3D4EA` o azul institucional `#4F81BD` para el fondo de celda; el JSON acepta cualquier `#RRGGBB`. |
| Variable | `<span data-variable="clave"></span>` (`data-list="bullet\|number"` para listas) |
| Bloque | `<div data-block="tipo" data-props='{…}' data-value='{…}'></div>` |
| Tipo de respuesta | `<span data-response='{"kind":"numero","options":[],"value":""}'></span>` dentro de un párrafo |

## Validación y errores

El servidor valida todo antes de cambiar nada y responde `422`. Los errores de la definición llevan su ruta exacta
en `errors.definition`, por ejemplo:

```json
{ "errors": { "definition": ["blocks.practicas.layout: «nada» no es un dato del valor del bloque."] } }
```

Los errores de campos llevan su camino (`sections.0.fields.1.content`).

## Consejos

- Parta de **Descargar** en «Opciones avanzadas → Editar JSON» (plantilla en uso) o de `silabo-oficial.json`.
- Edite con un editor que valide JSON y guarde un respaldo antes de importar.
- Para un bloque nuevo, empiece por `value` y `layout`, pruebe la importación y agregue después `rules`.
- Las comillas dentro de los atributos `data-*` se escriben con comillas simples afuera: `data-slot='{"value":"x"}'`.

## Para desarrolladores

- **Backend:** `TemplateDefinition`, `TemplateStyle`, `Blocks/*` (valor, diseño, condiciones y reglas) y
  `TemplateContext` (definición activa: la de la plantilla o la congelada en la fotografía).
  `ResponseKind` es el catálogo de tipos de respuesta y `FieldResponse` protege el formato
  (`normalize` para quien responde, `setup` para lo que arma Coordinación, `carried` para llevar
  lo contestado a una estructura nueva).
- **Frontend:** `src/lib/blocks/*` interpreta la misma definición; `DocumentBlockContent` dibuja y llena cualquier
  bloque.
- **Conformidad:** `src/test/fixtures/block-conformance.json` guarda lo que produce el backend para cada bloque
  oficial y el frontend debe producir lo mismo (`src/lib/blocks/blocks.test.ts`). Si cambia el motor o la
  plantilla oficial, regénerelo con `php artisan template:conformance proyecto-grado-silabos-frontend/src/test/fixtures`
  si el frontend está dentro del backend; si es un directorio hermano, use
  `../proyecto-grado-silabos-frontend/src/test/fixtures`.
- **Word sin cambios:** `TemplateGoldenWordTest` compara el `document.xml` del Word con referencias en
  `tests/Fixtures/golden` (`UPDATE_GOLDEN=1` las regenera tras revisar el cambio).
- `FixedContent` y `TemplateDocumentDefaults` solo los usan migraciones antiguas; no definen la plantilla.
