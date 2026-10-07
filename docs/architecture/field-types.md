# Tipos de campo de la plantilla

Plan: `docs/plans/active/I-195-plantilla-mvp-cuatro-tipos-de-campo.md` (ahora cinco tipos). ADR-0009.

| Clave | Quién llena | Config | Valor |
|---|---|---|---|
| `texto` | Docente | ninguna | texto libre (`FreeText`) |
| `ficha` | Coordinación, una vez por materia | `adjustable` | texto libre heredado |
| `tabla` | Docente | `columns`, `rows` fijas | `fila → columna → texto` |
| `automatico` | Nadie | `source` | ninguno |
| `fijo` | Administración, en la plantilla | `content` (documento `FreeText`) | ninguno (el snapshot guarda el contenido) |

`texto` admite `config.inside = identification_card`: el texto se escribe dentro de la tabla oficial de
identificación y no se repite como campo aparte en la hoja, la revisión ni el Word.

Solo `texto`, `ficha` y `tabla` se eligen a mano (`FieldType::manual()`, `FieldTypeRegistry::manualKeys()`);
`automatico` y `fijo` nacen únicamente de los diseños oficiales y la API de campos rechaza crearlos a mano.

`fijo` es la quinta excepción justificada por el contenido institucional de la UEB (evaluación,
conducta ética y firmas): igual en todos los sílabos, el docente no lo edita y no puede estar vacío.

## Diseños oficiales de sección

`config/syllabus_template.php` define `designs` (clave → título, resumen y campos `[clave, etiqueta, tipo, config]`)
y `baseline` (orden de la plantilla oficial). `SectionDesigns` los lee, los ofrece en `sectionDesigns` de
`GET admin/template/{id}` y los instancia (`POST structure/sections` con `design`); el provisionador usa la misma clase para
«Cargar formato oficial UEB». El contenido de los campos `fijo` (`content`) lo arma `FixedContent::official`. Para cambiar el
formato oficial se edita ese archivo y esa clase: no hay migración ni cambio de interfaz. En `tabla`, `columns` y `rows` son
`clave => etiqueta` y `weeks` genera una fila por semana.

## Cómo agregar un tipo

1. Crear la clase en `app/Modules/Configuration/Domain/FieldTypes/` que implemente
   `FieldType`: `key`, `label`, `configRules`, `normalizeConfig`, `defaultValue`,
   `validateValue`, `isEmpty`, `teacherEditable`.
2. Registrarla en `config/field_types.php`.
3. Agregar pruebas en `tests/Unit/Configuration/`.
4. Decidir `manual()`: verdadero solo si Administración puede elegirlo a mano al crear un campo.
5. Actualizar esta tabla y `docs/quality/traceability.md`.

No se debe ramificar por clave de tipo fuera del registro.

POR VALIDAR: formato del valor de `tabla` y lista de fuentes de `automatico`.

## API de plantilla (etapa 3)

Rutas bajo `admin/template/{template}/structure` (solo Administración, auditadas, bloqueadas
mientras haya un proceso abierto):

- `POST sections`, `PATCH sections/order` (`section_ids`), `PATCH sections/{section}`, `DELETE sections/{section}`.
- `POST sections/{section}/fields` con `{key, label?, help?, type, config?, position?}`.
- `PATCH sections/{section}/fields/order` (`field_ids`), `PATCH fields/{field}`, `DELETE fields/{field}`.

`type` y `config` los valida el registro (`configRules` en el FormRequest, `normalizeConfig` en
`SaveTemplateStructure`); los errores de config salen como `config.<clave>`. La clave y el tipo
de un campo no cambian después de crearlo. `GET admin/template/{template}` devuelve ahora
`config` en cada campo.

## Snapshot v7 y Word (etapa 4)

Cada tipo implementa `toSnapshot` (valor que se congela al enviar; el automático resuelve su
fuente) y `toWord` (se pinta solo con lo que trae el snapshot). El snapshot v7 lleva por sección
`fields[]` planos: `definition_id`, `key`, `label`, `type`, `legacy`, `config`, `value`. `restore`
acepta v7 y v6. El Word recorre sección → campos y delega en el tipo; secciones con campos de la
clase anterior usan el render viejo hasta la etapa 8.

