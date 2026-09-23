# I-124 — Plazo y preparación de convocatoria en el Panel

## Estado

Completado localmente el 2026-09-23.

## Objetivo

Hacer visible para Docencia y Coordinación el calendario institucional heredado y el
siguiente paso operativo, sin crear tablas ni duplicar fechas. Coordinación debe conocer
si su carrera está realmente lista y poder abrir la convocatoria desde el Panel;
Docencia debe reconocer cuánto tiempo queda y continuar su trabajo.

## Trazabilidad

- RF-034..045; RN-017..024; CU-06 y CU-07; UI-01, COR-01..04 y DOC-01..04.
- I-31, I-110, I-113 e I-114 definen el calendario institucional y su herencia.
- I-123 mantiene el logo de facultad bajo autoridad exclusiva de Administración.
- Solicitud explícita del responsable del producto del 2026-09-23.
- No depende de una decisión `POR VALIDAR`; PV-05 afecta capacidad real, no el flujo.

## Decisión

- El Panel mostrará una tarjeta de plazo con inicio, entrega y estado calculado:
  próxima, abierta, pausada, vencida o cerrada.
- El contador no mostrará cero como si siguiera vigente: antes de iniciar contará hacia
  el inicio; durante la apertura contará hacia la entrega; una pausa o vencimiento se
  nombrará explícitamente.
- Coordinación usará una comprobación completa por el período institucional: todas las
  materias programadas tienen paralelos y todos los paralelos tienen exactamente un
  docente vigente. El logo y la plantilla se tratarán como bloqueos administrativos.
- El siguiente paso del checklist abrirá directamente la convocatoria y permitirá fijar
  sus fuentes; el seguimiento se abrirá desde la tarjeta una vez creada.
- Docencia continuará desde la tarjeta hacia Mis sílabos.
- Se reutilizan `convocatorias_universidad`, `convocatorias_carreras` y
  `fechas_limite_convocatoria`; no habrá migraciones.

## Implementación

- Backend: `CoordinatorConvocationReadiness` calcula la preparación completa y
  `DashboardController` expone plazo y contexto de apertura según el rol activo.
- Frontend: `ConvocationDeadlineCard` presenta el calendario; el diálogo reutilizable de
  apertura se usa en COR-01 y COR-02, y la tarjeta abre el seguimiento existente.
- Pruebas: contrato por rol, período vigente, cobertura completa de docentes, apertura,
  pausa y acciones de las tarjetas y el diálogo.
- Documentación: pantallas, especificación y trazabilidad actualizadas.

## Verificación

- [x] Pruebas backend focalizadas: 29 pruebas, 372 aserciones.
- [x] Pruebas frontend focalizadas: 8 pruebas.
- [x] Pint, PHPStan focalizado, ESLint y TypeScript.
- [x] Contrato OpenAPI de la SPA.
- [x] `git diff --check` en ambos repositorios.
