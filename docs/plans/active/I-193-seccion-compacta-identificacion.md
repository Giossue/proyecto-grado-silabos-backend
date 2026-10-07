# I-193 Sección compacta en Identificación institucional

Objetivo: la sección de identificación usa letra de 9 pt y margen de 1.5 cm; el resto del
sílabo conserva la apariencia global (11 pt / 2.5 cm).

- Ajuste por sección `compact` (bool) en `TemplateBlock.configuracion`, igual que
  `page_orientation`; sin migración.
- Se guarda con `PATCH admin/template/{template}/blocks/{block}/document` (`compact`).
- Se crea en `true` en la plantilla oficial y al crear la sección de identificación.
- Se expone en plantilla, snapshot y detalle del sílabo; Word y la hoja del editor lo aplican.
- Sin interfaz para editarlo. Las secciones ya creadas no se migran.
- No resuelve ningún `POR VALIDAR`.

Pruebas: `TemplateDocumentTest`, `composer verify`.
