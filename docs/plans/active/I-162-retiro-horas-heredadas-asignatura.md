# I-162 — Retiro de las horas heredadas de la asignatura

## Decisión del responsable, 2026-09-29

La auditoría de esquema contra la base remota
(`temp/auditorias/auditoria-esquema-remoto.md`) mostró que `asignaturas.horas_proyecto`,
`horas_ap` y `horas_paec` estaban en NULL en las 43 materias. Venían de I-11, cuando la
base institucional SIANET traía seis columnas de horas; ese módulo de importación ya no
existe. La malla vigente reparte las horas de cada materia solo en ACD, APE y AA (más
créditos), que es el conjunto fijo de `CurriculumSystemFields::fixedDefinitions()` y lo
que suma la columna generada `total_horas_asignatura` (I-132). Ninguna pantalla mostraba
ni enviaba las tres casillas; solo entraban por API como opcionales. El responsable
decidió retirarlas.

`asignaturas.modalidad_asignatura` también apareció como candidata, pero es la excepción
de modalidad por materia (I-35, I-37): NULL significa «hereda la de la carrera». Se
conserva.

## Alcance y trazabilidad

- COR-16, I-11, I-132; RF-027..031.
- Base: `asignaturas` pierde `horas_proyecto`, `horas_ap` y `horas_paec`.
- Servidor: `CurriculumSystemFields`, modelo `Subject`, alta y edición de materias, sus
  requests, `AcademicContextSnapshot`, atributos de validación en `lang/es`.
- Pantalla: sin cambios; nunca consumió esas claves.

## Trabajo

- [x] Migración `2026_09_29_000159_remove_legacy_subject_hour_columns`: retira las tres
      columnas si existen; `down()` las devuelve vacías.
- [x] Servidor sin `hours_project`/`hours_ap`/`hours_paec` ni sus columnas.
- [x] Prueba `SyllabusProcessTest` sin esas claves en el payload.
- [x] Docs: `database.md` y `production-database-schema.md`.
- [x] Pint, PHPStan y 380 pruebas en verde.
- [x] Base remota: respaldo `temp/respaldos/silabos_ueb_db-2026-09-29-antes-I162.dump` y
      migración aplicada.
- [ ] Desplegar servidor.

## Fuera de alcance

- Los `contexto_academico` ya guardados en `silabos` conservan las claves antiguas en
  NULL; son fotografía histórica y no se reescriben.
