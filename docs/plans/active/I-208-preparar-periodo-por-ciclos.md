# I-208 — Asignar materias del período por ciclos

## Pedido del responsable, 2026-10-08

La lista de «Asignar materias» mostraba todas las materias seguidas. Se pidió agruparlas por
ciclo, en cascada, y quitar el aviso de «materias sin ficha completa».

## Decisión

- Solo frontend: `activeSubjects` ya trae `ciclo`; no cambia la API ni la base.
- `PeriodPreparationSheet` muestra un bloque desplegable por ciclo (abierto el primero) con
  sus materias y paralelos. `groupByCycle` ordena y agrupa en `periodPreparation.ts`.
- Se elimina el aviso de fichas incompletas (`incompleteProfileNames`) de este diálogo.
- Las validaciones y el envío no cambian.

## Verificación

Frontend: typecheck, ESLint y Vitest (363) en verde, con prueba nueva de `groupByCycle`.
