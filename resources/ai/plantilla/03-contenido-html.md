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

No use títulos (`<h1>`…), enlaces, imágenes, `class` ni `<div>` propios.

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
