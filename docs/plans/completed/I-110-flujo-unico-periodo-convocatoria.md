# I-110 — Preparación única del período y la convocatoria institucional

## Estado
Completado

## Trazabilidad

- I-31, I-41, I-46, I-72.
- RF-008..016, RF-034..036; RN-005..019; CU-03..06.
- ADM-04, ADM-12, COR-01..04, COR-13..15; CP-F proceso/alcance/bloqueo.
- PV-01 no cambia: la plantilla sigue siendo responsabilidad de Administración.

## Resultado demostrable

Administración prepara y abre la convocatoria institucional desde la fila del período
académico. El período se fija en el formulario, la plantilla y el nombre siguen siendo
automáticos y las fechas de elaboración continúan siendo configurables porque pueden
diferir de las fechas lectivas. Cada Coordinación conserva su propia convocatoria: la
inicia dentro del proceso abierto, hereda período, plantilla y fechas, y elige las
fuentes activas de su carrera conforme a I-72.

## Decisiones y supuestos

- Las tablas `periodos_academicos`, `convocatorias_universidad` y
  `convocatorias_carreras` permanecen separadas: representan catálogo, calendario
  institucional y alcance de carrera.
- La acción simplificada es atómica: si la plantilla o la estructura institucional no
  están listas, no queda un proceso a medio crear.
- Las fechas del proceso se muestran inicialmente con las fechas del período como ayuda,
  pero se pueden corregir antes de abrir; el servidor sigue validando sus reglas actuales.

## Cambios previstos

- Dominio/backend: acción atómica para crear y abrir un proceso institucional; la
  selección de fuentes y la convocatoria propia del Coordinador no cambian.
- Datos: sin migración; se exponen en el catálogo el estado del proceso asociado al
  período.
- Frontend: acción “Preparar convocatoria” en cada período, período fijo y fechas
  editables; la página de convocatorias se conserva para seguimiento y ajustes.
- Seguridad/auditoría: se reutilizan las políticas y auditorías existentes de creación y
  apertura; el nuevo flujo no amplía permisos.

## Pruebas

- API: la acción crea y abre de forma atómica, rechaza una estructura no lista y mantiene
  la unicidad de proceso por período.
- API: una Coordinación sigue viendo el alcance `sin_iniciar` y al iniciarlo hereda la
  configuración institucional.
- SPA: el catálogo muestra la acción solo para períodos sin proceso y envía el período
  fijo; typecheck, lint, pruebas y build.

## Pasos

- [x] Diseñar el flujo y registrar el plan.
- [x] Añadir el caso de uso y contrato HTTP.
- [x] Exponer el estado del proceso en el catálogo académico.
- [x] Integrar la acción en la pantalla de períodos.
- [x] Actualizar documentación, OpenAPI y trazabilidad.
- [x] Ejecutar verificaciones proporcionales y cerrar el plan.

## Riesgos y reversión

El flujo nuevo es aditivo: las rutas existentes de preparación, edición y transición se
conservan para operaciones excepcionales. Si la apertura falla, la transacción revierte
también la preparación; no se crean convocatorias de carrera ni sílabos.

## Evidencia de cierre

- Backend: `php artisan test tests/Feature/Syllabus/SyllabusProcessTest.php` (20 pruebas,
  295 aserciones) y `tests/Feature/Academic/AcademicStructureTest.php` (15 pruebas,
  78 aserciones); Pint, PHP y `git diff --check` correctos.
- Frontend: `npm run typecheck`, `npm run lint`, `npm test -- --run` (29 archivos,
  106 pruebas), `npm run build`, `npm run contract:check` y `git diff --check` correctos.
- Contrato: `php artisan openapi:export` actualizado; no hubo migración porque el modelo
  de períodos y convocatorias no cambia.
