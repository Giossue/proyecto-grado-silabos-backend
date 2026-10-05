# I-189 — La ficha de asignatura usa `valores_campo` y los campos de la plantilla

> Implementación local verificada (backend 340 pruebas, Pint y PHPStan; frontend 300 pruebas, vue-tsc y ESLint). La migración `000196` la aplica el deploy; esta tarea no se conectó a la remota.

## Pedido del responsable, 2026-10-05

La ficha de I-187 tiene una columna por dato (descripción, objetivos, bibliografía…). Un dato
nuevo del Proyecto de carrera obligaba a programar. Además la migración enlazaba los campos de la
plantilla por nombre y se perdieron `descripcion_asignatura` y la bibliografía. El responsable pidió
reusar tablas existentes y que agregar un campo sea solo configuración.

## Diseño

- No hay tabla `fichas_asignatura`. Los valores de la ficha son filas de `valores_campo` que
  pertenecen a una **asignatura** en vez de a un sílabo: `valores_campo.asignatura_id` (nuevo, opcional)
  y `silabo_id` pasa a opcional; una regla exige exactamente uno de los dos.
- Un campo de la plantilla se llena desde la ficha cuando `heredado = true` y
  `origen_dato_definicion_campo = 'ficha'`. El administrador lo decide en el editor de plantilla
  («Se llena desde la ficha de la asignatura»); `editable_docente` indica si el docente puede
  ajustarlo en su sílabo.
- El formulario del coordinador se arma solo: un editor de texto libre por cada campo marcado.
- Al crear el sílabo, el valor de la ficha se copia tal cual (mismo formato de texto libre).
  Sin valor, `ValidateDraft` pide la ficha al coordinador salvo que el docente pueda editar el campo.
- Los sílabos ya creados no se actualizan solos (igual que en I-187).

## Cambios

1. Migración `2026_10_05_000196_profile_values_in_field_values.php` (`down()` lanza `RuntimeException`):
   `valores_campo` con `asignatura_id`, CHECK de un solo dueño, índices únicos parciales;
   origen `fichas_asignatura.*` → `ficha`; el CHECK de herencia solo exige origen; el campo
   `descripcion_asignatura` pasa a `ficha`; convierte las filas de `fichas_asignatura` en
   valores y elimina la tabla.
2. Backend: `FieldValue` (asignatura), `Subject::profileValues()`, consulta `SubjectProfileFields`,
   `SaveSubjectProfile`/controlador/request con `{fields, values, complete}`,
   `InheritMasterValues`, `ValidateDraft`, `SyllabusController`, `TemplateStructureValidator`,
   `SaveFieldDefinition`+request (alternar origen `ficha` y edición docente),
   `SyllabusTemplateProvisioner`, estructura académica (indicador de ficha completa), OpenAPI.
3. Frontend: `SubjectProfileSheet` con `FreeTextEditor` por campo; editor de plantilla con los dos
   controles; tipos de API.
4. Pruebas, trazabilidad, docs (`database.md`, `production-database-schema.md`, `screens.md`,
   ADR si aplica) y `temp/tablas-bd-remota.md` (31 tablas).

## Fuera de alcance

Subir documentos y rellenar con IA (siguiente plan), importar el Excel de bibliografía, botón para
aplicar la ficha a borradores.

## Resultado

- La tabla `fichas_asignatura` desaparece; la ficha son filas de `valores_campo` con `asignatura_id`.
- El administrador marca un campo «Se llena desde la ficha» (y «El docente puede ajustarlo») en
  «Propiedades del campo»; el formulario del coordinador sale de esos campos.
- El formato oficial marca `descripcion`, `objetivo_general`, `objetivos_especificos`,
  `resultados_aprendizaje`, `habilidades_blandas`, `herramientas_tic`, `contribucion_perfil` y las dos
  bibliografías (estas últimas ajustables por el docente).
- En la remota, la plantilla vieja usa `descripcion_asignatura` y no tiene campos de bibliografía:
  la migración enlaza la descripción y el administrador debe agregar los campos de bibliografía desde el
  editor de plantilla (con «Se llena desde la ficha»).
