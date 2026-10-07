# I-201 — Crear la plantilla desde un modal, sección 6 horizontal y pestañas del docente

## Pedido del responsable, 2026-10-07

1. En `/admin/plantilla`, sin plantilla, un modal con dos opciones: «En blanco» o «Plantilla oficial».
2. La sección 6 del PDF (distribución y planificación) va en hoja horizontal.
3. El formulario del docente se reparte en pestañas, no corrido.

## Hecho

- `POST admin/template` acepta `official` (booleano): crea la plantilla ya formateada y audita
  `plantilla.creada` y `plantilla.formateada`.
- `AdminTemplatePage`: modal de elección; «Crear plantilla» lo reabre.
- Diseño oficial `planificacion` con `page_orientation = landscape`.
- `TeacherSyllabusDetailPage`: pestañas por sección al editar.

## Pendiente (decisión del responsable)

La tabla de la sección 6 del PDF es una tabla por unidad (encabezado con nombre y resultados de
aprendizaje, semanas con horas ACD/APE/AA, actividades, evaluación y total de horas). El tipo
`tabla` actual es una cuadrícula fija de 16 filas y no la representa; hace falta un tipo de campo
«unidades» (alta de unidades, totales, Word y revisión).

## Verificación

`TemplateAndSourceTest`, `SyllabusTemplateSeederTest`, `AdminTemplatePage.test.ts`,
`TeacherSyllabusDetailPage.test.ts`.
