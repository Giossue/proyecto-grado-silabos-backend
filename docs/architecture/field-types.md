# Tipos de campo de la plantilla

Plan: `docs/plans/active/I-195-plantilla-mvp-cuatro-tipos-de-campo.md`. ADR-0009.

| Clave | Quién llena | Config | Valor |
|---|---|---|---|
| `texto` | Docente | ninguna | texto libre (`FreeText`) |
| `ficha` | Coordinación, una vez por materia | `adjustable` | texto libre heredado |
| `tabla` | Docente | `columns`, `rows` fijas | `fila → columna → texto` |
| `automatico` | Nadie | `source` | ninguno |

## Cómo agregar un tipo

1. Crear la clase en `app/Modules/Configuration/Domain/FieldTypes/` que implemente
   `FieldType`: `key`, `label`, `configRules`, `normalizeConfig`, `defaultValue`,
   `validateValue`, `isEmpty`, `teacherEditable`.
2. Registrarla en `config/field_types.php`.
3. Agregar pruebas en `tests/Unit/Configuration/`.
4. Actualizar esta tabla y `docs/quality/traceability.md`.

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
