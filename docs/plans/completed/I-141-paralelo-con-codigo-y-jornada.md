# I-141: Todo paralelo con código y jornada; edición posterior

## Estado

Implementado localmente (backend y frontend). Migración `000112` pendiente de aplicar en
la base remota de pruebas; no se ejecutó nada contra ella.

## Trazabilidad

RF-008..016, RN-005..008, CU-03, COR-14, CP-F estructura académica. No depende de una
decisión `POR VALIDAR`.

## Reglas

1. Todo paralelo tiene código y jornada (`Parallel::SHIFTS`: matutina, vespertina,
   nocturna). «Sin jornada» deja de existir. `PreparePeriodRequest`,
   `StoreParallelsRequest` y `UpdateParallelRequest` exigen `shift`. La migración
   `2026_09_24_000112_exigir_jornada_paralelo` rellena los nulos con `matutina`, deja
   `paralelos.jornada_paralelo` en NOT NULL y endurece `paralelos_jornada_check`.
2. «Preparar período» propone el paralelo `A` en jornada matutina para cada materia sin
   programación anterior; si existe, conserva esa propuesta (jornada vacía heredada pasa a
   matutina). El navegador bloquea el envío y explica qué falta (`preparationProblem`).
3. `PATCH coordination/academic/parallels/{parallel}` (`UpdateParallel`) corrige código y
   jornada; `DELETE coordination/academic/parallels/{parallel}` (`DeleteParallel`)
   elimina. Ambos respetan `ProcessLocks::assertCareerEditable` (convocatoria abierta =
   sin cambios), `AcademicPeriodPlanning::assertParallelMayChange` (período finalizado) y
   alcance por carrera; se auditan como `academico.paralelo.actualizacion|eliminacion`.
   No se edita ni elimina un paralelo con sílabo; no se elimina uno con docente asignado
   (vigente o histórico) ni el último de la materia. El código es único por materia sin
   distinguir mayúsculas.
4. Acciones masivas: la tabla de materias programadas no tiene selección múltiple y
   ninguna tabla del proyecto la usa; «Cambiar jornada» masivo no se agregó (no es
   barato: requiere patrón nuevo de selección, endpoint por lote y pruebas). La hoja de la
   materia permite editar la jornada de cada paralelo en línea.

## Verificación

`composer verify` (base `silabos_ueb_test_par`), `OpenApiContractTest`, pruebas nuevas en
`AcademicStructureTest`, `SyllabusProcessTest`, `RequireParallelShiftMigrationTest`;
frontend `vue-tsc`, `eslint`, `vitest` (`periodPreparation.test.ts`,
`ParallelCreationSheet.test.ts`) y `npm run build`.
