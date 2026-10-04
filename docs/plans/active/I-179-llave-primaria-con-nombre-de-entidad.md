# I-179 — Llave primaria con el nombre de la entidad

## Pedido del responsable, 2026-10-04

Los jefes de titulación consideran redundante tener el `id` genérico de Laravel junto a
columnas como `paralelo_id`. Cada tabla debe tener una sola llave con el nombre de la
entidad; las llaves foráneas conservan su nombre.

## Decisión

- La columna `id` de cada tabla del dominio pasa a `<entidad>_id`: `paralelos.paralelo_id`,
  `silabos.silabo_id`, `usuarios.usuario_id`, `plantilla_silabo.plantilla_id`, etc. Los
  nombres coinciden con los de las llaves foráneas que ya existían (`silabos.paralelo_id`
  apunta a `paralelos.paralelo_id`).
- Se conserva `id` solo en `migraciones`, `sesiones` y `trabajos_fallidos`: sus columnas las
  fija Laravel (y `sesiones.id` es el identificador de la sesión).
- La API no cambia: las respuestas JSON siguen trayendo `id`. Los modelos declaran
  `$primaryKey` y un alias `id` → llave (`MapsLegacyColumnNames`), así que `$modelo->id`,
  `where('id')` y `with('relacion:id,nombre')` siguen funcionando.

## Cambios

- Migración `000187` (no se revierte): renombra las 30 llaves, que arrastran sus FK, índices y
  restricciones, y reescribe los siete triggers que citaban `id`
  (`comprobar_administrador_exclusivo`, `comprobar_coordinacion_activa_unica`,
  `impedir_cambio_periodo_proceso_con_convocatorias`, `proteger_mensaje_ia`,
  `validar_condicion_definicion`, `validar_relaciones_flujo`, `validar_relaciones_revision`).
- Modelos: `$primaryKey` y alias en los 28 modelos; cinco modelos sin el alias lo reciben.
- Consultas con el query builder, uniones y SQL crudo, reglas `exists` y `unique->ignore`, y
  relaciones `hasOneThrough`, con el nombre nuevo.
- `ExplicitColumnBuilder::select/addSelect` aceptan varios argumentos (antes solo el primero).

## Verificación

Suite completa (329), Pint y PHPStan; pruebas y aserciones de tablas con los nombres nuevos.
