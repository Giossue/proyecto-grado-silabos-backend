# I-125 — Logo de facultad obligatorio y sin fondo

## Objetivo

Hacer obligatoria la carga del logo al registrar una facultad en ADM-04, indicar su
medida recomendada y exigir un PNG sin fondo, con transparencia.

## Estado

Implementado el 2026-09-23.

## Trazabilidad

- RF-008..016 y RF-034..036; RN-005..008 y RN-017..019; CU-03 y CU-06.
- ADM-04 y COR-01..02.
- Instrucción explícita y corrección inmediata del responsable del producto del 2026-09-23.
- I-125 complementa I-123: Administración conserva la autoridad exclusiva y el alta ya
  no admite dejar el logo pendiente.

## Plan

- [x] Exigir el logo al crear una facultad y al editar un registro anterior que no lo tenga.
- [x] Mantener opcional el reemplazo cuando ya existe un logo configurado.
- [x] Validar un PNG sin fondo y normalizarlo a 600 × 180 px sobre lienzo transparente.
- [x] Mostrar en alta y edición la medida recomendada y el requisito de transparencia.
- [x] Actualizar pruebas, trazabilidad y documentación de producto.

## Verificación

- 45 pruebas backend focalizadas y de contrato: 733 aserciones aprobadas después de la
  corrección de transparencia.
- Pint y PHPStan focalizado aprobados.
- TypeScript, ESLint y 9 pruebas Vitest focalizadas aprobados después de la corrección;
  la suite completa de 121 pruebas Vitest y el build Vite ya habían aprobado en la misma
  revisión antes del ajuste textual y de la regla de transparencia.
- La suite PHP completa ejecutó 325 pruebas: 324 aprobaron. El único fallo pertenece al
  trabajo paralelo de I-124 sobre el Panel: `ApiDashboardTest` todavía espera tres
  métricas docentes y la implementación nueva devuelve dos al separar el plazo.
- `git diff --check` aprobado en backend y frontend.
