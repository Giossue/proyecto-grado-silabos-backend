# ADR-0011: La plantilla del sílabo es solo datos

- Estado: Aceptado
- Fecha: 2026-10-19
- Responsables: responsable del producto (pedido en I-229)
- Reemplaza / reemplazado por: amplía ADR-0010; reemplaza la parte de I-195/I-225 que fijaba en código la letra,
  los colores y la alineación.

## Contexto

La plantilla ya era un documento editable (ADR-0010), pero parte de su formato seguía en código. El diseño de los
bloques Unidades, Evaluación, Sí/No y Repetible estaba duplicado en PHP y Vue, y la pantalla y el Word ya no
coincidían. Las reglas de 4 unidades, 16 semanas y horas iguales a las de la asignatura vivían en código. También
estaban en código el catálogo de variables, las 12 secciones obligatorias, la sección que revisa Biblioteca, la
apariencia y el papel: la vista previa usaba A4 y el Word, carta. Además, Word y revisión rearmaban los bloques con
el código vigente, así que un cambio de código alteraba sílabos ya aprobados.

El responsable pidió que la plantilla viva solo en la base y que crear o cambiar una plantilla sea escribir JSON,
documentado en el manual técnico.

## Opciones consideradas

1. Mantener los bloques en código y alinear manualmente PHP y Vue: rápido, pero vuelve a separarse con cada
   cambio y no permite plantillas nuevas sin programar.
2. Que el backend dibuje todo y el frontend solo muestre HTML: el editor del docente necesita controles
   interactivos dentro de las tablas, así que igual haría falta un intérprete en el frontend.
3. Definición en datos con un lenguaje mínimo de directivas e intérpretes genéricos en ambos lados, verificados con
   casos de conformidad compartidos.

## Decisión

Opción 3. `plantilla_silabo.definicion_plantilla` guarda `document`, `variables`, `blocks`, `required_sections`,
`library_sections` y `presets`; secciones y campos siguen en sus tablas. Los bloques se describen con:

- un esquema de valor (texto, número, elección, objeto, lista y fila por columnas, con `keep_if`);
- propiedades (texto y columnas);
- reglas (`count`/`sum` comparadas con números o variables, al guardar o al enviar);
- un diseño HTML con las directivas `repeat`, `when`, `band`, agrupación y datos (`slot`).

Backend y frontend interpretan la misma definición. La fotografía de cada revisión congela la definición
(`template_definition`). Las fotografías anteriores usan la plantilla oficial con el título y diseño que
guardaron.

La plantilla oficial (`silabo-oficial.json`) reproduce lo que había en código. El Word de la plantilla oficial no
cambia, con una corrección: los encabezados con fondo propio conservan su color de texto. Antes salían en blanco
sobre celeste en Identificación y Unidades.

## Consecuencias

- Positivas:
  - Una plantilla nueva o un bloque nuevo se crean con JSON.
  - Pantalla, vista previa y Word comparten diseño y papel.
  - Los sílabos aprobados no cambian con el código.
  - Los errores de la definición dicen su ruta exacta.
- Costes/riesgos:
  - Un lenguaje de directivas que mantener en dos intérpretes.
  - Las fotografías son más grandes, porque llevan la definición.
  - El frontend recibe la definición en cada pantalla que dibuja bloques.
- Cómo verificar:
  - `TemplateGoldenWordTest`: Word idéntico.
  - `TemplateDefinitionTest`: bloque nuevo solo con JSON, errores con ruta, fotografía congelada y HTML de ida y
    vuelta.
  - `blocks.test.ts`: conformidad frontend–backend.
  - Pruebas de constructor, editor docente y validación.
- Condición para revisar: si una plantilla necesita una estructura que las directivas no expresan (por ejemplo,
  fórmulas entre columnas), se amplía el lenguaje en ambos intérpretes y en el manual, no se agrega un bloque en
  código.

## Trazabilidad

I-229, I-219, I-210, I-225, ADR-0010; manual: `docs/architecture/template-json.md`.
