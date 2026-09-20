# I-101: Auditoría de tokens y componentes reutilizables del frontend

## Estado

Implementado y verificado el 2026-09-20. El alcance se limita al repositorio hermano de
la SPA; no cambia el esquema, los permisos ni el ciclo de vida académico.

## Trazabilidad

- RNF-001..036, especialmente consistencia visual, accesibilidad y mantenibilidad.
- RF-066..075; CU-15..18; UI-02; COR-02, COR-05 y COR-12..15.
- I-10 e I-13 como decisiones confirmadas sobre tokens semánticos, superficies,
  tablas, tooltips y composición compartida.
- No depende de una decisión `POR VALIDAR`.

## Objetivo

Eliminar estilos de aplicación duplicados o dependientes de paletas directas, sustituir
espaciado `space-*` por composición con `gap`, conservar los colores propios del
documento académico dentro de su ámbito y reutilizar los componentes shadcn existentes.

También se corrige el canal común de navegación de la SPA para que los editores puedan
interceptar cambios sin guardar mediante `Dialog`, manteniendo únicamente el aviso
nativo inevitable al cerrar o recargar la pestaña.

## Criterios de aceptación

- Las pantallas de producto usan tokens semánticos del tema y no fuerzan variantes
  `dark:` ni colores de paleta para estados de aplicación.
- El espaciado entre elementos se expresa mediante `gap` en contenedores flex/grid.
- No quedan `window.confirm` ni atributos `title` como tooltip en producto.
- Los editores de plantilla muestran una confirmación reutilizable al abandonar cambios
  sin guardar y el bus `before` de `apiRouter` respeta la cancelación.
- Los colores oficiales y las reglas de maquetación del documento permanecen aislados
  y no se sustituyen por colores del tema de la aplicación.

## Resultado

- Se añadieron tokens semánticos para estados, superposiciones, marca y paleta fija del
  documento; la paleta configurable de Tiptap se centralizó en `documentPalette.ts`.
- Se reemplazaron los espaciados `space-x/y` de producto por composición con `gap` y se
  normalizaron los controles visuales a componentes locales de shadcn.
- Se creó `UnsavedChangesDialog` y se hizo operativo el evento `before` de `apiRouter`;
  los editores de diseño y tablas comparten ahora el flujo de descarte.
- Se retiraron ocho componentes sin referencias tras verificar el repositorio completo.
- Se conservó como excepción explícita el estilo dinámico necesario para documentos,
  tablas, paginación, malla curricular y porcentajes de progreso.

## Verificación

- `npm run lint`
- `npm run typecheck`
- `npm test`
- `npm run build`
- auditoría de clases directas, `space-*`, `window.confirm`, `title` y composición
  ejecutada sobre `src/`, excluyendo la implementación interna de `components/ui`.

Resultado: `npm run lint`, `npm run typecheck`, `npm test` (27 archivos, 94 pruebas) y
`npm run build` en verde; las búsquedas de patrones no encontraron coincidencias de
`space-x/y`, `window.confirm` ni atributos `title` nativos en producto.
