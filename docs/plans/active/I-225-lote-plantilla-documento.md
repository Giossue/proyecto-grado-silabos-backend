# I-225 — Lote de plantilla: alineación, numeración, diseño acotado y vista previa docente

## Pedido del responsable, 2026-10-19

Dejar preparado lo más común de un documento oficial para que un cambio de formato no exija
código, y cubrir cómo se ve el sílabo para el docente. Lote aprobado completo:

1. Alinear párrafos (izquierda, centro, derecha, justificado) desde el editor, y que el Word
   lo respete.
2. Número de página en el pie del Word («Página X de Y»).
3. Diseño editable por Administración. Decisión del responsable (2026-10-19): **solo márgenes y
   tamaños** (título, sección y contenido). Arial, colores, alineaciones y énfasis siguen fijos
   (I-195). Se guarda en la plantilla y viaja en el JSON.
4. Vista previa del documento para el docente, con el aspecto de las hojas de Administración.

## Diseño

- `TemplateAppearance::LAYOUT` lista lo editable: `margin_cm`, `title_font_size`,
  `section_font_size`, `body_font_size`. Se guarda en `mapeo_documento.layout`; la clave
  antigua `appearance` sigue ignorada para no revivir valores guardados antes de I-195.
  Los snapshots de revisiones copian el mapeo, así que el Word de una revisión usa el diseño
  vigente al enviarla.
- El JSON de la plantilla exporta e importa `title` y `layout` (opcionales al importar).
  Guardar el constructor ya usa esa importación, con sus mismas reglas de bloqueo y reinicio.
- Alineación: el editor usa la extensión TextAlign de TipTap sobre párrafos. En el Word, una
  alineación distinta de «izquierda» en el párrafo prevalece sobre la del cuerpo.
- Pie de página: cada sección del Word lleva `Página {PAGE} de {NUMPAGES}` centrado, gris, 8 pt.
- Vista previa docente: diálogo de pantalla completa con hojas (`template-sheet`) que usan
  logos, título, secciones y valores del sílabo, con márgenes y tamaños del diseño.

## Verificación

Backend: pruebas de Word (pie, alineación, diseño), de importación/exportación JSON y suite.
Frontend: typecheck, Vitest del editor, del constructor y de la vista previa.
Sin verificación visual en navegador ni del PDF real (PV-07, PV-19).
