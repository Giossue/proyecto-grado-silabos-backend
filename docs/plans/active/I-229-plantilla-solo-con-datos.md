# I-229 — Plantilla definida solo con datos

## Pedido del responsable, 2026-10-19

Centralizar la plantilla: nada del formato del sílabo en código duro. La plantilla vive en la base
(`plantilla_silabo` y sus tablas) y un desarrollador crea o cambia una plantilla escribiendo JSON, sin
tocar código. El formato JSON queda descrito en el manual técnico (`docs/architecture/template-json.md`).

## Problema (inventario 2026-10-19)

- Bloques con diseño en código y duplicado en frontend y backend: `unidades` (`UnitsTable.php` y
  `DocumentUnits.vue`, ya distintos), `evaluacion` (`DocumentBlock::evaluationTable` y
  `DocumentEvaluation.vue`), `si_no` y `repetible` (`DocumentBlock` y `freeTextRender.ts` /
  `DocumentBlockView.vue`), `identificacion` (`bloque-identificacion.html` y casillas en código).
- Reglas en código: 4 unidades, 16 semanas, horas ACD/APE/AA iguales a las de la asignatura, variante de
  evaluación sin prácticas, límites de cada bloque.
- Catálogo de variables en `config/syllabus_variables.php`.
- 12 secciones obligatorias fijas (`TemplateStructureValidator`) y revisión de biblioteca atada a la clave
  `bibliografia`.
- Apariencia, papel, encabezado (título y logos) y pie en código; la vista previa usa A4 y el Word carta.
- Plantillas listas (`OfficialTemplate::PRESETS`) y título por defecto en código.
- Word, revisión y vista previa rearman los bloques con el código de hoy: cambiar el código cambia sílabos
  ya aprobados.

## Decisión

ADR-0011. La plantilla es un documento de datos (formato JSON versión 2) con:

- `page`, `appearance`, `header` y `footer`: papel, márgenes, letras, colores y encabezado.
- `variables`: catálogo de datos del sistema (clave, nombre, fuente y formato). Las fuentes posibles son
  las que entrega el sistema y se listan en el manual.
- `blocks`: definición de cada bloque (valor que llena el docente, propiedades que fija la plantilla,
  reglas y diseño). El diseño es el mismo documento de siempre más unas pocas directivas genéricas:
  repetir, mostrar si, dato del bloque, contador, suma y marca de opción.
- `sections`: secciones y campos, con marcas de datos para lo que hoy se deduce de claves fijas
  (obligatorias, revisión de biblioteca, plantillas listas).

El código solo sabe interpretar piezas genéricas. El snapshot congela la definición usada, así un cambio
de plantilla o de código no altera un sílabo enviado o aprobado.

## Pasos

1. Formato y manual: JSON v2, directivas de bloque y casos de conformidad compartidos por backend y
   frontend.
2. Plantilla oficial v2 (`resources/templates/silabo-oficial.json`) con todo lo que hoy está en código,
   sin cambiar cómo se ve el Word.
3. Backend: definición en la base, motor genérico de bloques, variables y reglas desde la plantilla,
   snapshot con definición congelada, Word con papel, apariencia y encabezado de la definición,
   importación y exportación v2, migración de la plantilla existente.
4. Frontend: un solo intérprete de bloques para editar, llenar y leer; papel y apariencia de la
   definición; resumen de reglas en la pantalla del docente.
5. Retirar el código específico: `UnitsTable`, `evaluationTable`, `DocumentUnits.vue`,
   `DocumentEvaluation.vue`, `bloque-identificacion.html`, `PRESETS`, `REQUIRED_SECTIONS`.
6. Verificación: pruebas de ambos lados con los mismos casos, Word antes y después igual,
   editor igual a la vista previa, flujo completo docente → coordinación → biblioteca en local.

## Supuestos

- La tabla de Unidades oficial es la del Word actual (`UnitsTable`); la pantalla pasa a dibujar esa.
- El papel oficial es el del Word actual (carta); la vista previa pasa a carta.
- Las reglas de negocio no cambian: solo se mudan del código a la plantilla oficial.
- La identificación sigue sin poder editarse desde el constructor visual (se marca como bloqueada en
  el JSON), conforme a la decisión del 2026-09-30.
