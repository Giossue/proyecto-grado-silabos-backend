# Vista previa global desde cada hoja de plantilla

Pedido confirmado del responsable: añadir un botón de ojo en la esquina superior
derecha de todas las hojas, que controle la vista previa de la plantilla completa.
Sustituye la restricción anterior «sin vista previa» de ADM-06.

## Alcance

RF-017..026; RNF-018..023; RN-009..012; CU-04; ADM-06;
CP-F plantilla, CP-N experiencia/accesibilidad; PV-07/PV-19.
La implementación es de presentación, sin dependencia de decisiones pendientes de
persistencia de I-195/ADR-0009 ni cambios del contrato JSON o permisos.

## Unidad

- [x] Un estado común de vista previa para todas las hojas verticales/horizontales.
- [x] Ojo en cada esquina superior derecha, con tooltip, nombre y estado accesibles.
- [x] Vista de consulta sin contornos, tipos ni acciones de edición; salida desde
      cualquier hoja, incluso con proceso bloqueado, sin modificar datos.
- [x] Reutilizar la presentación de los campos en constructor y vista previa.
- [x] Sincronizar pantallas, arquitectura y trazabilidad.
- [x] Comprobar tipos, lint puntual y formato; no se solicitaron pruebas.

Supuesto comunicado: la vista previa se activa sobre las mismas hojas, para toda
la plantilla, sin una ventana separada. La aceptación visual en navegador y con
dispositivos reales permanece pendiente en PV-07/PV-19.

## Evidencia

`npm run typecheck` y ESLint puntual de `TemplateVisualBuilder.vue` y
`TemplateFieldContent.vue` aprobados; revisión de diff y formato en ambos repositorios.
No se añadieron ni ejecutaron pruebas; tampoco se hizo build, commit o push.
El cambio no afecta persistencia, exportaciones ni la autorización de edición.
