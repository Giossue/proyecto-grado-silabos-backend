# FE-TABLE-2026-10-07 — Buscadores de tablas y bandeja de Biblioteca

## Estado

Completado técnicamente el 2026-10-07. PV-19 mantiene pendiente la aceptación visual
en dispositivos reales.

## Trazabilidad

RF-001..026 y RF-062..069; RNF-018..023; UI-02..04; ADM-02/04/12,
COR-03/05/13..16, DOC-02/08, BIB-02 y CP-N experiencia/accesibilidad; PV-19.
No depende de una decisión `POR VALIDAR`.

## Resultado demostrable

Cada buscador asociado a una tabla indica «Buscar por…» y nombra los datos que realmente
consulta. La bandeja de Biblioteca muestra únicamente Asignatura, Carrera, Docente y
Estado; el código permanece como detalle de la asignatura y Paralelo deja de ocupar una
columna.

## Cambios

- Auditar todos los buscadores de tablas y corregir placeholders inconsistentes.
- Alinear las etiquetas accesibles con cada placeholder.
- Reducir la bandeja BIB-02 y su skeleton a cuatro columnas.
- Sincronizar pruebas, producto, arquitectura y trazabilidad.

## Verificación

- Auditoría estática de los cinco `type="search"` y nueve usos de `ClientFilterBar`:
  los catorce placeholders empiezan con «Buscar por» y corresponden al filtro real.
- `npm run typecheck`: aprobado.
- ESLint focalizado de los siete archivos modificados: aprobado.
- `LibraryReviewsPage.test.ts`: una prueba aprobada.
- `git diff --check` en frontend y backend: aprobado.
- Revisión pendiente en dispositivos reales bajo PV-19.
