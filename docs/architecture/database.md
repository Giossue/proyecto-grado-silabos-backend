# PostgreSQL: convenciones e invariantes

La lista completa de tablas, columnas y restricciones está en
[database-schema.md](../generated/database-schema.md). Ese archivo se genera desde la
base; este documento explica las reglas que hay detrás.

## Convenciones de nombres

- Tablas, columnas, funciones, triggers y valores de estado guardados van en español,
  sin tildes y en `snake_case`. Las tablas van en plural (`silabos`, `revisiones_silabo`).
- Se conservan siglas técnicas como `id`, `uuid`, `pdf` o `sha256`.
- Las columnas de dominio llevan el nombre de su entidad: `nombre_carrera`,
  `estado_silabo`, `codigo_asignatura`. Se evitan nombres genéricos como `nombre` o
  `estado` a secas.
- La clave primaria se llama `{entidad}_id` (`silabo_id`, `revision_silabo_id`).
- Las restricciones se nombran `{tabla}_pkey` y `{tabla}_{columna}_foreign`. Excepción
  conocida: las dos FK de `revisiones_coordinacion` terminan en `_fkey`.
- Los modelos Eloquent usan alias cortos en inglés o español (`estado`, `fotografia`,
  `version_bloqueo`) mediante `MapsLegacyColumnNames`. El nombre físico es el de la base.
- Las tablas del framework (`sesiones`, `restablecimientos_contrasena`, `migraciones`)
  tienen nombre en español, pero sus columnas son las que exige Laravel.
- `SpanishSchemaTest` y `SpanishModelColumnsTest` comprueban estas reglas.

## Tipos y valores

- Identificadores: UUID v7 generados por la aplicación (`HasUuids` de Laravel 13).
  La base no genera identificadores, salvo el contador interno de `migraciones`.
- Tiempo: todas las fechas con hora son `timestamptz`. La conexión usa
  `DB_TIMEZONE=UTC`; la conversión a `America/Guayaquil` ocurre solo al mostrar.
- Fechas de calendario: las fechas sin hora (inicio y entrega de la convocatoria, fechas
  de los períodos) no son instantes. Cuando se guardan en una columna `timestamptz`, se
  guardan con los límites del día en `DISPLAY_TIMEZONE`: la medianoche para el inicio y
  el final del día para la entrega. La fecha de un valor guardado se obtiene con
  `InstitutionDate::dateOf()`, que lo lee en esa zona.
- Fechas de dominio con nombre propio (`enviado_en`, `aprobado_en`, `asignado_en`).
  No existen `created_at` ni `updated_at` en tablas de dominio. La historia está en
  `eventos_auditoria`.
- Estados: texto con un `CHECK` que lista los valores válidos.
- Modalidad de estudio: tipo enumerado `modalidad_estudio`.
- Huellas: `character(64)` con SHA-256 en hexadecimal.
- JSON solo para documentos y datos sin relaciones: definición de la plantilla
  (`json`); valores de campo, fotografías de revisión, contexto académico del sílabo,
  mensajes de IA y detalles de auditoría (`jsonb`).
- Agregar un campo a la plantilla no cambia el esquema: es una fila nueva.

## Invariantes que protege la base

### Identidad y estructura académica

- `asignaciones_rol.rol` solo admite `administrador`, `coordinador`, `docente` y
  `bibliotecario`. Administrador y Biblioteca no tienen carrera; Coordinación y
  Docencia sí (`asignaciones_rol_alcance_valido_check`).
- Un usuario con rol Administrador activo no puede tener otro rol activo
  (`comprobar_administrador_exclusivo`).
- Una carrera tiene como máximo una coordinación activa con cuenta activa
  (`comprobar_coordinacion_activa_unica`).
- Un paralelo tiene como máximo un docente activo (índice único parcial
  `asignaciones_paralelo_paralelo_activo_unico`).
- El código de paralelo es único por asignatura sin distinguir mayúsculas.
- `asignaturas.total_horas_asignatura` es una columna generada: suma de horas de
  docencia, prácticas y autónomas. La aplicación no la escribe.
- Una convocatoria institucional no puede cambiar de período si ya tiene convocatorias
  de carrera (`impedir_cambio_periodo_proceso_con_convocatorias`).
- Solo puede haber una convocatoria institucional `abierto` o `pausado` a la vez.
- `convocatorias_universidad.finalizada_en` solo tiene valor en una convocatoria `cerrado`, y
  `convocatorias_carreras.estado_previo_cierre_convocatoria_carrera` (`abierta` o `pausada`)
  solo en una convocatoria de carrera `cerrada`. Se llenan al finalizar y se vacían al
  reabrir con una prórroga.

### Plantilla

- `plantilla_silabo` admite una sola fila (`plantilla_silabo_unica`).
- `definicion_plantilla` debe ser un objeto JSON. Su contenido se valida en la
  aplicación (`TemplateDefinition`); ver [template-json.md](template-json.md).
- Cada cambio en secciones, campos o definición actualiza
  `plantilla_modificada_en` por trigger. Con esa marca, reanudar un proceso pausado o
  reabrir uno finalizado detecta que la plantilla cambió.
- Las FK compuestas `(…_id, plantilla_id)` impiden que un campo apunte a una sección
  de otra plantilla.
- Las propuestas del asistente (`propuestas_ia_plantilla`) guardan la estructura propuesta,
  la versión del borrador del editor del que partieron y la huella de la plantilla guardada.
  Su estado es `pendiente`, `aplicada` (pasó al editor), `descartada` o `reemplazada`;
  `vencida` queda admitido pero hoy la vigencia la decide la pantalla con la versión del
  borrador. Aplicada o descartada exige quién y cuándo
  (`propuestas_ia_plantilla_decision_check`).
- `definiciones_campo.quien_llena_definicion_campo` solo admite `docente`,
  `coordinacion` e `institucional`. El contenido es un documento Tiptap
  (`{"type":"doc","content":[…]}`), comprobado por `CHECK`.

### Valores de campo: un solo dueño

`valores_campo` guarda el contenido de los campos de dos dueños distintos:

- el sílabo (`silabo_id`): lo que escribe el docente y lo heredado del proyecto de carrera;
- la asignatura (`asignatura_id`): el proyecto de carrera que llena Coordinación una vez por materia.

Reglas:

- Cada fila tiene exactamente un dueño (`valores_campo_un_solo_dueno_check`).
- Hay un solo valor por dueño y campo (dos índices únicos parciales).
- Borrar el sílabo o la asignatura borra sus valores (`ON DELETE CASCADE`).

### Revisiones y evidencia

- `revisiones_silabo` es inmutable. Solo admite una escritura en las columnas
  `correccion_*`: pasan de nulas a completas una sola vez
  (`proteger_registro_con_campos_unicos` y `revisiones_silabo_correccion_completa_check`).
- El número de revisión es consecutivo por sílabo y la revisión anterior debe ser del
  mismo sílabo (`validar_relaciones_revision`).
- `contenido_enviado` es la fotografía completa del envío, con la definición de la
  plantilla congelada. El PDF se genera desde ahí.
- `aprobaciones`, `revisiones_coordinacion`, `firmas_silabo`, `archivos` y
  `eventos_auditoria` no admiten `UPDATE` ni `DELETE` (`proteger_registro_append_only`).
- `envios_biblioteca` tampoco se modifica (`proteger_envio_biblioteca`).
- Una revisión tiene como máximo una revisión de coordinación, un envío a biblioteca y
  una aprobación (índices únicos por `revision_silabo_id`).
- Una exportación completada no cambia y ninguna exportación se borra
  (`validar_exportacion_silabo`). Al completarse debe tener PDF y fecha; el Word ya no
  se guarda.
- Cada firma ocupa un rol (`docente`, `coordinacion`, `biblioteca`) una sola vez por
  sílabo (`firmas_silabo_firmado_como_unico`).
- En `notificaciones` solo puede cambiar `leido_en` (`proteger_notificacion`). Si se borra
  el período, `periodo_academico_id` queda vacío y el aviso deja de mostrarse.

### Idempotencia

- Revisión: `(silabo_id, clave_solicitud)` único.
- Exportación: `(revision_silabo_id, clave_solicitud)` único.
- Aprobación: la clave no se repite dentro del sílabo (`validar_relaciones_flujo`).
- Mensaje de IA: `(silabo_id, usuario_id, clave_idempotencia)` único para preguntas.
- Pedido al asistente de la plantilla: `(plantilla_id, usuario_id, clave_idempotencia)`
  único (`mensajes_ia_plantilla_idempotencia_unica`).
- Notificación: `(usuario_id, clave_unica)` único.

### Excepción de reinicio

Las protecciones anteriores ceden solo dentro de una transacción que ejecuta
`SET LOCAL app.reinicio_institucional = 'on'` (`reinicio_institucional_habilitado()`).
Lo hacen `InProgressWork` y, para borrar las firmas de un sílabo que se reinicia,
`ResetSyllabus`. `InProgressWork` lo usa para regenerar sílabos tras un cambio confirmado
de plantilla, malla, proyecto de carrera o paralelo, para eliminar un sílabo aprobado y para borrar un
proceso. Borra el sílabo completo con sus revisiones, aprobación, exportaciones, firmas,
archivos y mensajes de IA. Fuera de esa transacción, los triggers rechazan el cambio.

## Borrado

- Por defecto las FK usan `ON DELETE RESTRICT`: lo que tiene historia no se borra.
- `CASCADE` solo entre padre e hijos sin sentido propio: plantilla → secciones →
  campos → definiciones; sílabo → valores y mensajes de IA; asignatura → proyecto de carrera.
- Una cuenta con historia se desactiva (`usuario_activo = false`), no se borra.
  `eventos_auditoria` no tiene FK hacia usuarios y copia el nombre, rol y carrera del
  actor.
- `DB::prohibitDestructiveCommands` bloquea comandos destructivos de Artisan en
  producción.

## Migraciones

- Una migración hace un cambio coherente y revisable.
- No se edita una migración ya aplicada en un entorno compartido.
- La tabla de control se llama `migraciones`. El arranque del contenedor ejecuta
  `php artisan db:rename-migrations-table --force` y luego
  `php artisan migrate --force --isolated`.
- Toda FK nueva llega con un índice que la encabece. Hoy no lo tiene
  `definiciones_campo.plantilla_id`.
- Tras cambiar el esquema, se regenera
  [database-schema.md](../generated/database-schema.md).
