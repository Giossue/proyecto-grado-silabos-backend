# I-157 — Proyecto de carrera como tabla con asistente en modal

## Decisión del responsable, 2026-09-29

COR-16 deja las pestañas Datos confirmados / Importar / Historial. El Proyecto de
carrera se ve como una tabla que se actualiza cuando Coordinación importa: no hay un
documento nuevo por ciclo ni historial que revisar. El botón principal de la cabecera
abre el paso a paso en un modal; la importación en curso se guarda y se retoma al
volver, y ahí mismo se puede descartar. Sin etiquetas (badges).

## Alcance y trazabilidad

- COR-16, I-145, I-149, I-151, I-152, I-155; CU-04/CU-05.
- Solo interfaz: la API (`GET/POST/DELETE coordination/curricular-imports`) no cambia.
  El listado de importaciones se usa para encontrar la que está en curso
  (pendiente, en ejecución, lista o fallida).

## Trabajo

- [x] `CurricularProjectTable`: datos de toda la carrera y datos por asignatura
      (columnas = datos que pide la plantilla, filas en orden alfabético, «Sin dato»).
- [x] Página con botón principal «Importar documentos» / «Continuar importación» y
      modal con `CurricularImportSteps`, carga, análisis, revisión, Listo, reintento y
      descarte con confirmación en línea.
- [x] `useCurricularImports` sin vistas ni historial: retoma la importación en curso,
      `refresh` tras guardar y `startOver` tras guardar o descartar.
- [x] Revisión sin etiquetas: conteos y estado como texto.
- [x] Se retiran `CurricularConfirmedData`, las pestañas y el historial.
- [x] Lint, typecheck, 223 pruebas de Vitest y build.
