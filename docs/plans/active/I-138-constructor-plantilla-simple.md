# I-138 — Constructor de plantilla más simple

## Estado

Implementado el 24 de septiembre de 2026. Pendiente al cerrar: aplicar la migración en la base
remota de pruebas (no se ejecuta nada contra ella desde este incremento).

## Objetivo

La plantilla es un documento con tres piezas:

1. **Texto fijo**: lo escribe Administración y nadie lo llena.
2. **Dato automático**: variables de `config/syllabus_variables.php`.
3. **Campo del docente**: siempre obligatorio al enviar, sin límites configurables
   (ajuste del 24 de septiembre, ver «Reglas retiradas»).

Las tablas son fijas, con filas o por unidad. Regla del usuario: en el documento final
nada va fijo en código salvo el título y los logos del encabezado. Solo se protege contra
"perder trabajo sin aviso" y "quedar atascado".

## Trazabilidad

RF de plantilla institucional (I-32, I-56, I-116, I-119), validación determinística al
enviar (`ValidateDraft`), exportación Word (`GenerateSyllabusExportJob`).

## Decisiones

- **Contenido fijo**: nuevo tipo de bloque `fixed` («Contenido fijo (el docente no
  llena)»). Nace con una tabla de una celda editable y sin campos. En el editor de
  tablas, «Convertir en contenido fijo» quita los campos y la repetición de la tabla
  seleccionada.
- **Indicadores de evaluación** y **Escala de valoración** pasan a bloques `fixed` con los
  valores UEB en el formato oficial y en la plantilla existente (migración).
- **Firmas**: se retira el bloque fijo en código (revert en stage). Las variables
  `fecha_entrega`, `nombre_coordinador` y `fecha_revision` se resuelven al exportar una
  revisión aprobada; la sección «Revisión y aprobación» es una tabla fija con esas
  variables, editable por Administración. La firma digital la investiga otro equipo; las
  variables solo muestran nombres y fechas.
- **Se retira `flow`** («Estado de revisión»): sin casos especiales en el constructor, el
  editor docente ni la validación de estructura.
- **Tipo de bloque una sola vez**: el JSON del bloque ya no guarda `tipo`; se deriva de
  `configuracion.content_type` (`table`/listas → `repetible`, resto → `narrativa`).
- **TableLayout**: `repeat` y `totals` guardan `label` solo si están encendidos; `sum`
  solo vale en columnas numéricas que no sean semana ni año, y por defecto solo suman las
  horas (ACD, APE, AA).
- **Reglas retiradas (regla definitiva del usuario)**: todo el sílabo lo llena el
  docente salvo el contenido fijo. No existe «Obligatorio» configurable ni máximo de
  caracteres ni mínimo/máximo de filas en UI, API, dominio, validación ni BD. Se borró
  `FieldRules`, el campo `rules` de los requests y de las respuestas (`required` vuelve a
  ser siempre `true`), el contador del editor docente y la columna
  `definiciones_campo.reglas_definicion_campo`. `ValidateDraft` solo marca vacíos:
  ««Campo» está vacío. Complétalo antes de enviar.»; el borrador se guarda siempre.
- Se borra compatibilidad vieja: retiro previo a I-116 y `demoteEmptyRepeatedTables` del
  backend (el editor ya la aplica antes de guardar).

## Migración

`2026_09_24_000106_simplify_template_blocks`: idempotente. Quita `tipo` de los bloques,
elimina el bloque `flow` y sus definiciones (con sus valores de sistema), pone la tabla
fija de firmas en «Revisión y aprobación», convierte indicadores y escala en contenido
fijo (sus campos quedan retirados, no se borran datos) y limpia `repeat/totals/sum`.

Ajuste: `2026_09_24_000111_quitar_reglas_definicion_campo`, idempotente. Borra la columna
`reglas_definicion_campo` si existe y quita claves `rules`, `required`, `max_length`,
`min_rows` y `max_rows` de la `configuracion` de los bloques (y de `detached_fields`).
Pendiente aplicarla en la base remota de pruebas.

## Verificación

- Backend: `composer verify` en `silabos_ueb_test_plantilla` (pint, phpstan, 329 pruebas).
- Frontend: `vue-tsc --noEmit`, `eslint src`, `vitest run` (148 pruebas).
- Migración probada sobre una plantilla sembrada con el código anterior: quita `tipo`,
  retira el estado de revisión (24 → 23 definiciones), convierte indicadores y escala,
  y una segunda ejecución deja el JSON idéntico.
- Fuera de alcance: unificar la doble descripción de tablas y la paginación del editor.
