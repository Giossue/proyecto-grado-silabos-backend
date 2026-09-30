# I-169 — Quitar el Proyecto de carrera

## Pedido del responsable, 2026-09-30

Se elimina por completo el «Proyecto de carrera»: los datos curriculares que
Coordinación importaba (PDF/XLSX con OpenParser y normalizador DeepSeek) y confirmaba,
y que la plantilla usaba como variables `curricular_*` u orígenes de opciones. En su
lugar, el administrador pone en la plantilla campos que llena el docente.

## Cambios

- Código: modelos, acciones, jobs, gateways, controladores, requests, configuración
  (`config/curricular_import.php`, `config/openparser.php`, `ai.source_normalization`),
  variables de `.env.example`, limitador `source-ocr` y `poppler-utils` del Dockerfile.
- Plantilla: sin variables `curricular_*` ni `resultados_aprendizaje_asignatura`,
  `fuente_resultados_aprendizaje`, `ubicacion_resultados_aprendizaje`; los campos de
  selección solo admiten opciones fijas (`FieldOptions`). Se retiró
  `RequiredAcademicTemplateData` (ya no hay variables obligatorias).
- Convocatoria: abrirla ya no exige datos del proyecto; el paso `project_data` sale de
  la guía de Coordinación.
- Chat de IA: sin contexto curricular (`context_version=free-text-v3`).
- Datos: migración `2026_09_30_000182_remove_career_project_data` (irreversible).

## API retirada

- `POST /api/v1/admin/template/{template}/project-data`
- `GET|POST /api/v1/coordination/curricular-imports`
- `GET|DELETE /api/v1/coordination/curricular-imports/{import}`
- `POST /api/v1/coordination/curricular-imports/{import}/apply`
- `POST /api/v1/coordination/curricular-imports/{import}/retry`
- `GET /api/v1/coordination/curricular-imports/{import}/documents/{source}/original`
- Claves JSON: `options_source`, `options_sources` (campos de plantilla y sílabo, y
  entradas de guardado), el paso `project_data` de `setup.steps` del tablero de
  Coordinación (ahora 5 pasos) y `academic_context.curricular`.

## Verificación

`php artisan test`, `pint --dirty` y `phpstan` en verde sobre `silabos_ueb_test_p`.
