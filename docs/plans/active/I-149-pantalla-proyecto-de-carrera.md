# I-149 — Pantalla Proyecto de carrera

## Decisión del responsable, 2026-09-28

Revisar cientos de datos importados en un panel lateral sobre la malla resulta
incómodo. El asistente curricular sale de Malla y pasa a una pantalla propia de
Coordinación: **Proyecto de carrera** (COR-16).

## Alcance y trazabilidad

- I-145/I-147; RF-017..026, CU-04/CU-05, COR-13, COR-16, RNF de usabilidad.
- Solo cambia la interfaz. La API `/coordination/curricular-imports`, los permisos, el
  alcance por carrera y la confirmación de regeneración no cambian.
- La revisión relaciona una sola vez cada nombre de materia del documento que no
  coincide con la malla; la elección se aplica a todos sus datos. La relación no se
  adivina: la persona sigue eligiendo la materia.
- Fuera de alcance: decidir por sección si la plantilla usa variables fijas, listas de
  selección o campos libres (pendiente con los docentes, ver I-144).

## Trabajo

- [x] Crear la ruta `/coordinacion/proyecto-carrera` y su entrada en el menú.
- [x] Mover el recorrido del panel a la pantalla y retirar el botón flotante de Malla.
- [x] Revisión: resumen de listos/pendientes, materias plegables, filtro de pendientes,
      relación única por nombre y pie de guardado fijo.
- [x] Adaptar pruebas unitarias, de rutas, de menú y e2e de navegación.
- [x] Actualizar `screens.md`, `DECISIONS_STATUS.md` y la matriz de trazabilidad.
- [x] Typecheck, lint y suite completa de Vitest.
