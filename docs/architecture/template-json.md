# Plantilla del sílabo como JSON

La plantilla institucional se puede **exportar a un único archivo JSON, editar y volver a importar**.
Sirve para mantenerla fuera de la interfaz (el formato del sílabo cambia cada varios años), guardarla en
control de versiones o copiarla entre ambientes. Es un complemento del constructor visual: ambos
modifican la misma plantilla.

## Dónde está

- Admin → **Plantilla** → botones de icono **Exportar JSON** (descarga el archivo) y **Editar JSON** (editor con descarga, carga de archivo y «Aplicar y reemplazar»).
- API (solo Administración, bloqueada con un proceso abierto):
  - `GET /api/v1/admin/template/{id}/json` descarga la plantilla.
  - `PUT /api/v1/admin/template/{id}/json` **reemplaza toda** la plantilla por el JSON enviado
    (auditado como `plantilla.importada`).

Importar equivale a «Restablecer a plantilla oficial» pero con tu contenido: se retiran las secciones y
campos actuales y se crean los del JSON. Rigen las mismas reglas de bloqueo y de reinicio de sílabos que
al editar a mano (I-188): hay que tener el proceso pausado y, al reanudar, los sílabos en curso se rehacen.

## Formato (versión 1)

```json
{
  "version": 1,
  "sections": [
    {
      "key": "descripcion",
      "title": "2. Descripción de la asignatura",
      "orientation": "portrait",
      "fields": [
        {
          "key": "descripcion",
          "label": "Descripción",
          "help": "Resumen del propósito de la asignatura.",
          "quien_llena": "coordinacion",
          "content": { "type": "doc", "content": [ { "type": "paragraph" } ] }
        }
      ]
    }
  ]
}
```

### Raíz

| Campo | Regla |
|---|---|
| `version` | Obligatorio. Hoy solo `1`. |
| `sections` | Obligatorio (puede ser `[]`). Máximo 60. El orden del arreglo es el orden de la hoja. |

### Sección

| Campo | Regla |
|---|---|
| `key` | Obligatorio. Minúsculas, números y `_`; empieza con letra; máx. 100. **Única** entre secciones. |
| `title` | Obligatorio. Máx. 180. Se imprime tal cual (incluye la numeración, p. ej. `"2. Descripción…"`). |
| `orientation` | Opcional: `portrait` (vertical, por defecto) o `landscape` (horizontal). |
| `fields` | Opcional. Máx. 60. El orden es el de la sección. |

### Campo

| Campo | Regla |
|---|---|
| `key` | Obligatorio. Mismo formato que la clave de sección. **Única en toda la plantilla.** No cambies la clave de un campo en uso: identifica sus valores. |
| `label` | Opcional. Máx. 180. Vacío = el campo no lleva subtítulo. |
| `help` | Opcional. Máx. 2000. Se muestra sobre el editor a quien lo llena. |
| `quien_llena` | Obligatorio. `docente`, `coordinacion` (en la interfaz «Carrera») o `institucional` (en la interfaz «Fijo»). |
| `content` | Obligatorio. Documento (ver abajo). Es el contenido de partida o, en un campo fijo, el contenido final. |

## Contenido (`content`)

El contenido de un campo se puede escribir de **tres formas**; al importar se convierten al mismo documento:

1. **Lista de líneas HTML** (la forma que usa la exportación y el archivo oficial; la más legible):
   ```json
   "content": [
     "<p>Texto con <strong>negrita</strong>.</p>",
     "<table>",
     "<tr><th colspan=\"2\" style=\"background:#C3D4EA;font-weight:bold\">Título</th></tr>",
     "<tr><td>Carrera:</td><td><span data-variable=\"nombre_carrera\"></span></td></tr>",
     "</table>"
   ]
   ```
2. **Un texto HTML** en una sola cadena.
3. **Un documento JSON de Tiptap** (`{"type":"doc",...}`), el formato interno. Sigue aceptándose.

### HTML permitido

| Qué | Cómo se escribe |
|---|---|
| Párrafo | `<p>`; alineación con `style="text-align:center"` (izquierda es la predeterminada) |
| Negrita, cursiva, subrayado | `<strong>`, `<em>`, `<u>` |
| Salto de línea | `<br>` |
| Listas | `<ul>`/`<ol>` con `<li>`; una lista dentro de un `<li>` es una sublista |
| Tabla | `<table>`, `<tr>`, `<th>` (encabezado), `<td>` |
| Celdas combinadas | `colspan="4"`, `rowspan="2"` |
| Ancho de columnas | `data-width="144,66"` (uno por columna que abarca la celda) |
| Fondo, color, alineación, negrita, cursiva de celda | `style="background:#C3D4EA;color:#FFFFFF;text-align:center;font-weight:bold;font-style:italic"` |
| Borde de celda | `data-border="thin"`, `"thick"` o `"none"` |
| Dato del sistema (variable) | `<span data-variable="nombre_carrera"></span>`; con lista: `data-list="bullet"` o `"number"` |
| Color o fuente del texto | `<span style="color:#1F3864;font-family:Arial">…</span>` |

Los **espacios** de los textos se conservan tal cual. Cualquier otra etiqueta se ignora (su texto sí se conserva).
Los identificadores de variable válidos los define `TemplateVariables`. Límites: tablas de hasta 24 columnas y
100 filas, y un contenido de hasta 2 MB.

## Validación y errores

El servidor valida todo antes de tocar la plantilla y responde `422` con el **camino exacto** de cada error:

```json
{
  "message": "…",
  "errors": {
    "sections.0.fields.1.key": ["La clave del campo «a» está repetida; …"],
    "sections.2.orientation": ["…"]
  }
}
```

Si hay cualquier error no se cambia nada.

## El archivo de la plantilla oficial

La plantilla oficial de la UEB vive en **un solo archivo JSON**, con este mismo formato:

**`resources/templates/silabo-oficial.json`** (repositorio del backend).

Es el único lugar para cambiar la plantilla oficial de base. El botón **Restablecer a plantilla oficial**
(y la instalación inicial) cargan ese archivo; las dos plantillas listas del constructor, «Identificación institucional» y «Distribución y planificación de las
unidades curriculares», salen de los campos `asignatura` y `unidades` de ese archivo; otras se agregarán cuando haga falta. Una prueba verifica
que el archivo siga siendo válido para la importación.

> `config/syllabus_template.php` y `FixedContent.php` ya no se usan para crear la plantilla; solo los
> consultan migraciones antiguas. No los edite para cambiar la plantilla.

## Cómo obtener un ejemplo completo

1. Admin → Plantilla → **Restablecer a plantilla oficial**.
2. **Exportar JSON**: es el formato oficial de la UEB listo para editar.

## Consejos

- Edita en un editor con validación de JSON; haz un respaldo del archivo antes de importar.
- Cambia títulos, ayuda y orden con seguridad. Para tablas, edita primero en el constructor y exporta.
- Conserva las claves de campos ya usados por sílabos aprobados o por fichas de materias.
