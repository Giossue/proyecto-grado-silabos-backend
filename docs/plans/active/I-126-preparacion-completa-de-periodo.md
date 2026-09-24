# I-126 — Preparación completa del período

## Estado

Implementado; revisión visual manual pendiente.

## Trazabilidad

RF-007..016, RN-005..008, CU-03, COR-14, COR-15. PV-05 solo afecta capacidad.

## Decisión del responsable del producto

Todas las materias activas de la malla se dictan en cada período. Al preparar uno,
Coordinación ve todas las materias pendientes, con paralelos, jornadas y docentes
sugeridos desde el período anterior. Revisa y corrige la propuesta antes de aplicar.
La preparación agrega únicamente registros faltantes y no modifica historia previa.
Los docentes sin cuenta verificada o sin rol efectivo en la carrera quedan pendientes.
No se requiere una tabla nueva.

## Pasos

- [x] Extender la operación atómica para aceptar docentes opcionales y validar su rol.
- [x] Mostrar la malla completa pendiente y las propuestas del último período anterior.
- [x] Verificar guardado, alcance, reversión y generación de propuestas.
- [x] Actualizar pantallas, contrato y trazabilidad.

## Verificación

- `AcademicStructureTest` y `OpenApiContractTest`: 20 pruebas, 105 aserciones.
- Pruebas de propuesta SPA: 2 aprobadas; typecheck, ESLint focalizado,
  contrato SPA y build aprobados.
- PHPStan focalizado y Pint aprobados.
- `composer verify` se detuvo en tres errores PHPStan preexistentes de
  `UpdateAcademicRecord` e `InProgressWork`, ajenos a esta unidad.
