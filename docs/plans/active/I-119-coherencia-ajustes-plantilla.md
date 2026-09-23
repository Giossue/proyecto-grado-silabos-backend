# I-119 — Coherencia de los ajustes de la plantilla

## Objetivo

Corregir las contradicciones halladas al revisar los ajustes de la plantilla (tablas,
tipos de contenido, campos e IA) frente a lo que el docente puede llenar.

## Fuente de decisión

Instrucción explícita del responsable del producto (23 de septiembre de 2026). La
casilla de IA de un campo decide si el agente **lee** ese campo como referencia para sus
sugerencias; la decide Administración en cualquier campo, incluidos los que vienen de
variables o de la malla. La IA solo sugiere y el docente decide.

## Cambios

- [x] **Listas.** `TableLayout::fromBlock` da a «Lista con viñetas» y «Lista numerada»
      la estructura mínima de una columna `texto`. Antes el docente veía «Esta tabla no
      tiene una estructura configurada.» en seis secciones de la plantilla oficial. El
      Word y la vista previa ya leían `data.texto`; las filas existentes se conservan.
- [x] **IA en todos los campos.** La casilla «Permitir que la IA lea este campo» se
      muestra y se guarda en todo campo salvo el estado de revisión (`flow`), incluidos
      la ficha institucional y los datos heredados. Con la ficha activada, el agente la
      lee como pares «dato: valor» desde las variables, sin marcas de unidad ni nombre
      ni correo del docente (`BuildSyllabusChatContext`). Las sugerencias por campo
      siguen limitadas a texto corto y largo.
- [x] **Fila de totales.** Solo admite datos numéricos: el editor desactiva los datos de
      texto en esa fila y `SaveTemplateDocument` rechaza el diseño con «La fila de
      totales solo admite datos numéricos.».
- [x] **Formulario de estructura retirado.** Se eliminan `UpdateTableLayout`, su
      request, la ruta `PATCH /admin/template/{template}/blocks/{block}/table` y el
      cliente SPA sin uso; borraban el diseño visual de la tabla al cambiar columnas.

## Verificación

- Backend: suite completa (313 pruebas). Nuevas: `test_lists_give_teachers_one_text_row_per_item`,
  `test_administration_decides_ai_reading_on_identification_but_not_on_the_review_flow`,
  `test_total_rows_only_accept_numeric_data`,
  `test_administration_decides_whether_the_agent_reads_the_identification_card`.
- PHPStan sin errores en `AiAssistance`, `Configuration` y `Syllabus/Presentation`,
  salvo la antigua acción `SaveFacultyLogo.php`, retirada posteriormente por I-123.
- SPA: typecheck, 117 pruebas, lint de los archivos tocados y `contract:check`.
