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
- **Plantilla en uso:** Admin → Plantilla → **Editar JSON** (junto a «Editar», con la plantilla sin cambios
  pendientes). Abre el JSON actual; permite **Descargar**, **Cargar archivo** y **Aplicar y reemplazar**. Si hay
  errores no se cambia nada y se muestra la ruta de cada uno. El editor visual sigue siendo la forma normal de
  cambiar secciones y campos, sin escribir JSON.
- **API** (solo Administración, bloqueada con un proceso abierto):
  - `GET /api/v1/admin/template/{id}/json` exporta.
  - `PUT /api/v1/admin/template/{id}/json` **reemplaza toda** la plantilla (auditado como `plantilla.importada`).

Importar exige el proceso pausado y, al reanudar, los sílabos en curso se rehacen (I-188). **Un sílabo enviado o
aprobado guarda una copia de la definición** (`template_definition` en la fotografía de la revisión): cambiar la
plantilla o el código después no altera cómo se ve.

## Raíz del archivo

```json
{
  "version": 2,
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
| `document` | Papel, letra, colores, título, encabezado y pie. |
| `variables` | Catálogo de datos del sistema que se pueden insertar. |
| `blocks` | Definición de cada bloque: valor, propiedades, reglas y diseño. |
| `required_sections` | Claves de sección que deben existir para abrir un proceso. |
| `library_sections` | Secciones que revisa Biblioteca. |
| `presets` | «Plantillas listas» del constructor: copian el contenido de un campo de este mismo archivo. |
| `sections` | Secciones y campos (igual que en el formato 1). |

Opcionalmente, `title` y `layout` (formato 1) sobrescriben el título y los márgenes/tamaños de `document`.

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
  "colors": { "text": "#000000", "title": "#2F5496", "table_header_background": "#4F81BD",
              "table_header_text": "#FFFFFF", "table_border": "#333333", "empty_text": "#595959" },
  "title": { "text": "PROGRAMA DE ASIGNATURA (SÍLABO)", "bold": true, "italic": false, "alignment": "center" },
  "section_title": { "bold": true, "italic": false, "alignment": "left" },
  "body": { "alignment": "left" },
  "header": { "logos": [ { "source": "institution", "height_pt": 28 }, { "source": "faculty", "height_pt": 40 } ],
              "left_share": 0.638 },
  "footer": { "text": "Página {page} de {pages}", "size": 8, "color": "#7F7F7F" },
  "metadata": { "text": "Revisión {revision} · {subject} ({code}) · hash {fingerprint} · generado {generated}",
                "size": 7, "color": "#7F7F7F" },
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
| `colors` | `#RRGGBB`. `table_header_background` y `table_header_text` se aplican **solo** a los encabezados de tabla (`<th>`) sin fondo propio; pueden ser `null`. |
| `title`, `section_title`, `body` | Negrita, cursiva y alineación (`left`, `center`, `right`, `justify`). |
| `header.logos` | Hasta dos logos: el primero a la izquierda y el segundo a la derecha. `source`: `institution` o `faculty`. |
| `header.left_share` | Fracción del ancho para el logo izquierdo (0,1 a 0,9). |
| `footer`, `metadata` | Texto con marcadores (`{page}`, `{pages}`; `{revision}`, `{subject}`, `{code}`, `{fingerprint}`, `{generated}`, `{period}`) o `null` para quitarlos. |
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
  `credits`, `teacher`, `email`, `formation`.
- `approval.`: `submitted_on`, `coordinator`, `approved_on` (se llenan al exportar).
- `signature.`: `docente`, `coordinacion`, `biblioteca`. Marcan dónde va cada firma electrónica en el PDF.

En el contenido, una variable se escribe `<span data-variable="nombre_carrera"></span>`. Solo se aceptan claves
del catálogo.

## `blocks`: bloques con diseño, datos y reglas

Un bloque es una pieza que el docente (o la plantilla) **llena** con datos estructurados y que se dibuja con un
diseño propio. En el contenido de un campo se inserta así:

```html
<div data-block="unidades"></div>
<div data-block="si_no" data-props='{"question":"¿Usa laboratorio?","columns":[]}'></div>
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

Un objeto con un esquema por clave:

| `type` | Claves | Se guarda como |
|---|---|---|
| `text` | `max` (1 a 20000, por defecto 1000), `multiline` | texto |
| `number` | `digits` (enteros, por defecto 3), `decimals` (por defecto 2) | texto con número (`2`, `1,5`) |
| `choice` | `options`: `{"si": "Sí", "no": "No"}` | la clave elegida o `null` |
| `object` | `fields`: otro objeto de esquemas | objeto |
| `list` | `item` (esquema de cada elemento; si no lleva `type` se toma como `fields` de un objeto), `min`, `max` (≤ 100), `initial` | lista |
| `row` | `columns` (nombre de una propiedad `columns`), `cell` (esquema de cada celda) | objeto con una clave por columna |

Comunes: `label` (nombre del dato en la pantalla y en los errores), `error` (mensaje propio al rechazarlo) y
`keep_if` (condición, ver abajo). Si `keep_if` deja de cumplirse, el dato se borra. Por ejemplo, el detalle de
discapacidad se borra si la respuesta es «No».

Una lista ausente arranca con `initial` elementos. Una lista con `min` < `max` se puede agrandar y achicar desde
el editor, y debe repetirse en una fila de tabla (`<tr data-repeat>`).

### `props`: qué fija la plantilla en cada aparición

Solo `text` (`max`, `required`, `label`, `error`) y `columns` (`min`, `max`: lista de `{key, label}` que el
administrador edita en el constructor).

### `rules`: reglas de negocio

```json
"rules": [
  { "code": "semanas_maximas", "on": "save", "message": "Las unidades suman más de 16 semanas.",
    "checks": [ { "count": "units.*.semanas", "max": 16 } ] },
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
| `checks` | `count` (cuántos elementos hay en la ruta) o `sum` (suma de números) comparado con `equals`, `max` o `min`. El valor es un número o `{"variable": "clave"}`. |
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
| `data-repeat='{"prop": "columns", "as": "column"}'` | igual | Repite por cada columna de las propiedades; `as` le pone nombre. |
| `"at_least": 1` (dentro de `data-repeat`) | igual | Dibuja filas vacías hasta llegar a ese número. |
| `data-when='{...}'` | igual | Dibuja el elemento solo si se cumple la condición. |
| `data-band="#E1E9F4"` | `<tr>` | Pinta las filas impares que no tengan fondo propio. |
| `<section>` | raíz o dentro de otra `section` | Agrupa elementos (para repetirlos juntos). No se dibuja. |

Datos dentro del texto (`<span data-slot='{...}'></span>`):

| Dato | Muestra |
|---|---|
| `{"value": "nombre"}` | El dato. El editor del docente pone ahí un campo para escribir, un número o una elección. Una elección muestra su texto («Sí»). |
| `{"value": "disability.answer", "option": "si"}` | `X` si el dato es esa opción (`"mark"` cambia la marca). En el editor, una casilla. |
| `{"value": "{column.key}"}` | El dato cuya clave da el nombre `column` (filas de columnas variables). |
| `{"prop": "question"}` | Una propiedad de texto. |
| `{"item": "column.label"}` | Un dato del elemento nombrado con `as`. |
| `{"index": true}` | Posición (desde 1) en la repetición más cercana. |
| `{"counter": "semana"}` | Contador que sigue entre repeticiones (1, 2, 3… en todo el bloque). |
| `{"sum": "semanas.*.acd_h"}` | Suma de números. |
| `{"count": "units.*.semanas"}` | Cantidad de elementos. |

Condiciones (`data-when` y `keep_if`):

```json
{ "value": "answer", "equals": "si" }
{ "prop": "columns", "empty": false }
{ "variable": "horas_practicas", "zero": true }
{ "all": [ ... ] }   { "any": [ ... ] }   { "not": { ... } }
```

Una condición lleva una fuente (`value`, `prop`, `item` o `variable`) y una prueba (`equals`, `empty` o `zero`,
donde `zero` significa que el dato tiene un número igual a 0).

### Ejemplo completo: un bloque nuevo sin código

```json
"practicas": {
  "label": "Prácticas de laboratorio",
  "value": { "practicas": { "type": "list", "max": 10, "initial": 1, "label": "práctica", "item": {
    "tema": { "type": "text", "max": 200, "label": "Tema" },
    "horas": { "type": "number", "digits": 2, "decimals": 0, "label": "Horas" }
  } } },
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
| `unidades` | Cuatro unidades (lista fija de 4) con semanas (lista que crece), horas y actividades. Reglas: máximo 16 semanas al guardar; exactamente 16 y horas iguales a las de la asignatura al enviar. |
| `evaluacion` | Tabla de puntos con dos variantes según `horas_practicas` (con o sin práctico-experimental). |
| `si_no` | Pregunta Sí/No con tabla opcional si responde Sí. Propiedades: `question` y `columns`. |
| `repetible` | Lista de filas con columnas que fija el administrador. Propiedades: `label` y `columns`. |

## Secciones y campos

```json
{
  "key": "descripcion",
  "title": "2. Descripción de la asignatura",
  "orientation": "portrait",
  "fields": [
    { "key": "descripcion", "label": "Descripción", "quien_llena": "coordinacion", "content": ["<p></p>"] }
  ]
}
```

| Campo | Regla |
|---|---|
| `key` | Minúsculas, números y `_`; empieza con letra; máximo 100. Única entre secciones o entre campos de toda la plantilla. No cambie la clave de un campo en uso. |
| `title` / `label` | Máximo 180. El título se imprime tal cual. Un `label` vacío no imprime subtítulo. |
| `orientation` | `portrait` o `landscape`. |
| `quien_llena` | `docente`, `coordinacion` («Carrera», en la ficha de la asignatura) o `institucional` («Fijo»). |
| `content` | Lista de líneas HTML, un texto HTML o un documento Tiptap (`{"type":"doc",...}`). |

Límites: 60 secciones, 60 campos por sección, tablas de hasta 24 columnas y 100 filas, documentos de hasta 2 MB.

### HTML permitido en el contenido

| Qué | Cómo |
|---|---|
| Párrafo | `<p>`, alineación con `style="text-align:center"` |
| Negrita, cursiva, subrayado | `<strong>`, `<em>`, `<u>` |
| Salto de línea | `<br>` |
| Listas | `<ul>`/`<ol>` con `<li>`; una lista dentro de `<li>` es sublista |
| Tabla | `<table>`, `<tr>`, `<th>`, `<td>`; `colspan`, `rowspan`; `data-width="144,66"`; `data-border="thin\|thick\|none"` |
| Estilo de celda | `style="background:#C3D4EA;color:#FFFFFF;text-align:center;font-weight:bold;font-style:italic"` |
| Color o fuente del texto | `<span style="color:#1F3864;font-family:Arial">…</span>` |
| Variable | `<span data-variable="clave"></span>` (`data-list="bullet\|number"` para listas) |
| Bloque | `<div data-block="tipo" data-props='{…}' data-value='{…}'></div>` |

## Validación y errores

El servidor valida todo antes de cambiar nada y responde `422`. Los errores de la definición llevan su ruta exacta
en `errors.definition`, por ejemplo:

```json
{ "errors": { "definition": ["blocks.practicas.layout: «nada» no es un dato del valor del bloque."] } }
```

Los errores de campos llevan su camino (`sections.0.fields.1.content`).

## Consejos

- Parta de **Descargar** en «Editar JSON» (plantilla en uso) o de `silabo-oficial.json`.
- Edite con un editor que valide JSON y guarde un respaldo antes de importar.
- Para un bloque nuevo, empiece por `value` y `layout`, pruebe la importación y agregue después `rules`.
- Las comillas dentro de los atributos `data-*` se escriben con comillas simples afuera: `data-slot='{"value":"x"}'`.

## Para desarrolladores

- **Backend:** `TemplateDefinition`, `TemplateStyle`, `Blocks/*` (valor, diseño, condiciones y reglas) y
  `TemplateContext` (definición activa: la de la plantilla o la congelada en la fotografía).
- **Frontend:** `src/lib/blocks/*` interpreta la misma definición; `DocumentBlockContent` dibuja y llena cualquier
  bloque.
- **Conformidad:** `src/test/fixtures/block-conformance.json` guarda lo que produce el backend para cada bloque
  oficial y el frontend debe producir lo mismo (`src/lib/blocks/blocks.test.ts`). Si cambia el motor o la
  plantilla oficial, regénerelo con `php artisan template:conformance ../proyecto-grado-silabos-frontend/src/test/fixtures`.
- **Word sin cambios:** `TemplateGoldenWordTest` compara el `document.xml` del Word con referencias en
  `tests/Fixtures/golden` (`UPDATE_GOLDEN=1` las regenera tras revisar el cambio).
- `FixedContent` y `TemplateDocumentDefaults` solo los usan migraciones antiguas; no definen la plantilla.
  `config/syllabus_template.php` se eliminó.
