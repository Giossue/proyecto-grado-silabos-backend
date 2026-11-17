# Contenido de un campo (HTML permitido)

Escriba el contenido con este HTML y nada más. Otras etiquetas o atributos dan error.

| Qué | Cómo |
|---|---|
| Párrafo | `<p>texto</p>`; alineado: `<p style="text-align:center">` |
| Negrita, cursiva, subrayado | `<strong>`, `<em>`, `<u>` |
| Salto de línea | `<br>` |
| Listas | `<ul>` o `<ol>` con `<li>`; una lista dentro de un `<li>` es una sublista |
| Tabla | `<table>`, `<tr>`, `<th>`, `<td>`, con `colspan` y `rowspan` |
| Estilo de celda | `style="background:#C3D4EA;font-weight:bold"` (también `color`, `text-align`, `font-style`) |
| Color del texto | `<span style="color:#365F91">texto</span>` |
| Dato del sistema | `<span data-variable="clave"></span>`, solo con claves de `listar_variables` |
| Respuesta (tipo de respuesta) | `<span data-response='{"kind":"una","options":[…]}'></span>`; ver «Respuestas» |

No use títulos (`<h1>`…), enlaces, imágenes, `class` ni `<div>` propios.

## Respuestas

Una respuesta es un espacio que quien llena el campo contesta de una forma fija. Va dentro de un
`<p>` o de una celda `<td>`, nunca en `<th>`, y solo en campos `carrera`: un campo `institucional`
con respuestas da error. El atributo `data-response` lleva un JSON entre comillas simples.

| Clave | Para | Valores |
|---|---|---|
| `kind` | Tipo, obligatorio | `texto` (texto libre), `una` (elegir una), `varias` (elegir varias). No hay número, fecha ni texto corto. |
| `options` | Opciones de `una` y `varias` | Lista de `{"const":"o1","title":"Texto"}` con claves `o1`, `o2`… sin repetir; hasta 50 opciones de hasta 120 caracteres. Vacía: Coordinación las escribe en cada materia. `texto` no lleva opciones. |
| `required` | Si es obligatoria | `true` (por defecto) o `false`. Una opcional sin contestar no impide enviar. |
| `help` | Ayuda bajo la respuesta | Texto de hasta 200 caracteres |
| `maxLength` | Solo `texto` | Caracteres máximos, de 1 a 3000 (por defecto 3000) |
| `minChoices`, `maxChoices` | Solo `varias` | Cuántas elegir, de 1 al número de opciones; el mínimo no supera el máximo. Requieren opciones escritas. |
| `display` | Solo `una` | `lista` (por defecto) o `desplegable` |

Omita las claves que quedan con su valor por defecto. Una clave que no corresponde al tipo da
error (por ejemplo, `maxLength` en `una`). No escriba `value`: la respuesta empieza sin contestar.

## Colores permitidos

Solo estos; cualquier otro se rechaza.

| Para | Color | Cómo |
|---|---|---|
| Fondo de celda: rótulos y encabezados | Celeste `#C3D4EA` | `style="background:#C3D4EA;font-weight:bold"` |
| Fondo de celda: fila de título de la tabla | Azul institucional `#4F81BD` | `style="background:#4F81BD;color:#FFFFFF;font-weight:bold"` |
| Texto normal | Negro `#000000` | Sin estilo: es el color por defecto |
| Texto destacado en encabezados | Azul `#365F91` | `<span style="color:#365F91">…</span>` |
| Texto sobre el azul institucional | Blanco `#FFFFFF` | `color:#FFFFFF` en la celda |

- Las celdas de respuesta no llevan fondo.
- Un `<th>` sin fondo propio ya sale celeste con texto negro.
- Si piden otro color (verde, rojo, gris…), explique que la plantilla solo usa estos y ofrezca el más cercano.

## Estilo oficial de las tablas

Los encabezados de las tablas oficiales llevan fondo celeste y negrita:

```html
<table>
<tr><th style="background:#C3D4EA;font-weight:bold">Autor</th><th style="background:#C3D4EA;font-weight:bold">Título</th><th style="background:#C3D4EA;font-weight:bold">Año</th></tr>
<tr><td></td><td></td><td></td></tr>
</table>
```

Esa tabla tiene una sola fila vacía, así que quien responde puede agregar filas.

## Ejemplos

Texto guía fijo con espacio para responder:

```html
<p>Describa las competencias que desarrollará el estudiante:</p>
<p></p>
```

Texto fijo con un dato del sistema (campo `institucional`):

```html
<p>Carrera de <span data-variable="nombre_carrera"></span>.</p>
```

Respuesta de elegir una, como desplegable y con ayuda (campo `carrera`):

```html
<p>Modalidad: <span data-response='{"kind":"una","options":[{"const":"o1","title":"Presencial"},{"const":"o2","title":"En línea"}],"display":"desplegable","help":"Elija la modalidad principal."}'></span></p>
```

Respuesta opcional de texto libre con límite:

```html
<p>Observaciones: <span data-response='{"kind":"texto","required":false,"maxLength":300}'></span></p>
```

Celda de una tabla para elegir varias, entre una y dos:

```html
<table>
<tr><th style="background:#C3D4EA;font-weight:bold">Lugar</th></tr>
<tr><td><span data-response='{"kind":"varias","options":[{"const":"o1","title":"Aula"},{"const":"o2","title":"Laboratorio"},{"const":"o3","title":"Campo"}],"minChoices":1,"maxChoices":2}'></span></td></tr>
</table>
```

Pregunta de Sí o No y un espacio que se muestra solo si respondió Sí (ver «Mostrar solo si»):

```html
<p>¿Usa laboratorio? <span data-response='{"kind":"una","options":[{"const":"o1","title":"Sí"},{"const":"o2","title":"No"}],"id":"usa_laboratorio"}'></span></p>
<p>¿Cuál? <span data-response='{"kind":"texto","showIf":{"response":"usa_laboratorio","options":["o1"]}}'></span></p>
```
