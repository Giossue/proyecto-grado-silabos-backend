# I-160 — Proyecto de carrera solo por asignatura

## Decisión del responsable, 2026-09-29

Al ver la pantalla Proyecto de carrera dividida en «Datos de toda la carrera» y «Datos
por asignatura», el responsable indicó que no debería existir nada «de toda la carrera».
El único dato con ese alcance («Habilidades blandas transversales») venía del sistema
anterior, cuando al declarar un dato se preguntaba su alcance; desde I-155 ya no se
pregunta nada. Se retira el ámbito por completo: todo dato del Proyecto de carrera es de
una asignatura.

## Alcance y trazabilidad

- COR-16, ADM-06, I-151, I-155, I-157; RF-027..031.
- Base: `conceptos_curriculares.ambito_concepto` desaparece; `valores_curriculares.asignatura_id`
  pasa a obligatorio con unicidad por concepto y asignatura. El trigger que comprobaba el
  ámbito se reemplaza por uno que solo comprueba el tipo JSON.
- Servidor: la propuesta de importación, su aplicación, la tabla de la carrera, las
  variables de la plantilla y la preparación de la convocatoria dejan de distinguir
  ámbitos. El agente de extracción recibe la instrucción de repetir una fila por cada
  materia cuando el documento da un dato para un grupo o para toda la carrera.
- Pantalla: una sola tabla (asignatura × dato); la revisión de la importación agrupa
  solo por asignatura; el catálogo de variables no muestra «Carrera/Asignatura».

## Trabajo

- [x] Migración `2026_09_29_000158_remove_career_wide_project_data`: copia un valor de
      toda la carrera a cada asignatura que no tenga el suyo, borra los valores sin
      asignatura, exige `asignatura_id`, retira el ámbito y su trigger. `down()` lanza:
      se restaura el respaldo.
- [x] Servidor sin `scope`/`ambito_concepto` (modelo, `CurricularData`,
      `ConfirmedCurricularValues`, `CurricularImportProposal`, `PrepareCurricularImport`,
      `ApplyCurricularImport`, `CurricularImportsViewData`, `TemplateVariables`,
      `DeclareProjectData`, `CoordinatorConvocationReadiness`, OpenAPI). La propuesta aún
      acepta `scope` en su JSON para importaciones anteriores y lo descarta.
- [x] Pruebas del servidor adaptadas; la base rechaza un valor sin asignatura (23502).
      Pint, PHPStan y 380 pruebas en verde; `openapi.json` regenerado.
- [x] Pantalla: `CurricularProjectTable` con una sola tabla; `CurricularImportReview`,
      `CurricularOptionsSourceSelect` y tipos sin ámbito; pruebas adaptadas.
- [x] Base remota: respaldo `temp/respaldos/silabos_ueb_db-2026-09-29-antes-I160.dump` y
      migración aplicada. «Habilidades blandas transversales» no tenía valores: queda
      como dato por asignatura.
- [ ] Desplegar servidor y pantalla (la base remota ya está en el formato nuevo).

## Fuera de alcance

- Borrar de la plantilla de prueba la ficha «Habilidades blandas transversales»: lo
  decide el responsable al rehacer esa parte.
