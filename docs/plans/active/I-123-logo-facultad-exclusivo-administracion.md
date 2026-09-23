# I-123 — Logo de facultad exclusivo de Administración

## Objetivo

Corregir la autoridad definida en I-68: solo Administración puede cargar o reemplazar
el logo de una facultad desde ADM-04. Coordinación no administra ese archivo.

## Estado

Implementado el 2026-09-23.

## Trazabilidad

- RF-008..016 y RF-034..036; RN-005..008 y RN-017..019; CU-03 y CU-06.
- UI-01, ADM-04 y COR-01..02.
- Corrección explícita del responsable del producto del 2026-09-23.
- I-68 conserva los dos logos del encabezado y el requisito previo a la convocatoria;
  I-123 reemplaza únicamente la autoridad y la interfaz de carga.

## Plan

- [x] Retirar el endpoint, request y acción que permitían cargar el logo con rol Coordinador.
- [x] Retirar del Panel de Coordinación el paso, diálogo, cliente API y dato específico.
- [x] Conservar la carga opcional al crear o editar una facultad en ADM-04.
- [x] Mantener el bloqueo de apertura y dirigir el mensaje a Administración.
- [x] Actualizar el contrato OpenAPI, las pruebas y la documentación trazable.

## Verificación

- `InstitutionalLogosTest` cubre el rechazo a Coordinación, la desaparición de la ruta y
  el reemplazo administrativo auditado.
- `ApiDashboardTest` cubre la ausencia del paso y del dato de logo para Coordinación.
- `ConvocationAndDraftTest` conserva el requisito previo con el mensaje corregido.
- 22 pruebas backend focalizadas: 201 aserciones aprobadas.
- Suite PHP completa: 325 pruebas y 3970 aserciones aprobadas.
- Contrato OpenAPI: 2 pruebas y 220 aserciones aprobadas; la operación retirada ya no
  aparece en el documento generado.
- PHPStan focalizado, Pint, TypeScript, ESLint, 121 pruebas Vitest y build Vite aprobados.
- `composer verify` no completa por tres errores PHPStan previos y ajenos en
  `UpdateAcademicRecord` e `InProgressWork`; la suite PHP completa posterior sí aprueba.
