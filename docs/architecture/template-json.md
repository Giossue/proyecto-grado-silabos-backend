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

Documento de texto enriquecido en JSON de Tiptap/ProseMirror, con este subconjunto:

- Nodos: `doc`, `paragraph`, `text`, `bulletList`, `orderedList`, `listItem`, `table`, `tableRow`,
  `tableHeader`, `tableCell`, `variable`, `hardBreak`.
- Marcas de texto: `bold`, `italic`, `underline`.
- Celdas: `colspan`, `rowspan`, `colwidth` y `backgroundColor` (color `#RRGGBB`).
- `variable`: dato del sistema (`attrs.id`, p. ej. `carrera`); solo para campos fijos y las plantillas de
  identificación y firmas. Los identificadores válidos los define `TemplateVariables`.

Los **espacios** de los textos se conservan tal cual (importa: `"Hola "` seguido de una palabra en negrita).
Una tabla combina celdas con `colspan`/`rowspan`; el documento oficial de identificación es un buen ejemplo.

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

## Cómo obtener un ejemplo completo

1. Admin → Plantilla → **Restablecer a plantilla oficial**.
2. **Exportar JSON**: es el formato oficial de la UEB listo para editar.

## Consejos

- Edita en un editor con validación de JSON; haz un respaldo del archivo antes de importar.
- Cambia títulos, ayuda y orden con seguridad. Para tablas, edita primero en el constructor y exporta.
- Conserva las claves de campos ya usados por sílabos aprobados o por fichas de materias.
