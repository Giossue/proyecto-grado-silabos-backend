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

## Acciones masivas por ciclo (2026-10-09)

Los coordinadores encontraron lento llenar materia por materia. El encabezado de cada ciclo
tiene un menú «Acciones del ciclo»: agregar un paralelo a cada materia, asignar una jornada
y asignar un docente a todos los paralelos del ciclo. Los cambios solo modifican el
formulario; se guardan al confirmar la preparación y se pueden corregir uno por uno. Solo
frontend. Copiar los paralelos y docentes del período anterior queda como idea futura y
necesita decisión de producto.
