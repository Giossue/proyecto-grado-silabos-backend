# I-106: Campus como atributo de facultad

## Estado

Completado el 2026-09-22. La migración `000093` se aplicó a `silabos_ueb` local y a la
base remota `silabos_ueb_db`, siguiendo el procedimiento de `hardening.md`. Antes del
cambio remoto se creó una copia cifrada y se validó con `pg_restore -l`.

## Trazabilidad

- RF-001..016; RN-001..008; CU-01..03.
- ADM-04 (estructura académica); CP-F estructura académica.
- Sin dependencia de PV: las cinco opciones y su ubicación en la facultad fueron
  confirmadas por el responsable del producto el 2026-09-22.

## Resultado demostrable

Administración elige el campus de una facultad desde un selector con las cinco opciones
institucionales confirmadas. Las carreras lo heredan; las programaciones conservan una
copia textual y el sílabo mantiene la fotografía inmutable. El catálogo, su código,
pantallas, rutas y claves foráneas dejan de existir.

## Decisiones y supuestos

- I-106 reemplaza la separación de campus aceptada en I-36/I-48.
- Valores exactos: Campus Matriz Dr. Gabriel Galarza López; Campus Laguacoto; Campus CENI
  (Centro de Eventos Nacionales e Internacionales); Extensión San Miguel; Sede Chimbo.
- La base guarda el valor enumerado de campus, sin código de negocio.
- Toda facultad debe tener exactamente una opción; la alta/edición y la columna
  `facultades.campus_facultad` son obligatorias.
- El borrado de la tabla exige mapear sus filas referenciadas. La migración aborta antes
  de retirar datos si hay cualquier fila de campus ajena a las opciones confirmadas,
  una referencia inválida, una facultad sin carreras con campus o carreras de una misma
  facultad con ubicaciones distintas; primero se concilian esos casos.
- La migración remota requiere respaldo y comprobación previa según el runbook.

## Cambios previstos

- Dominio: enum PHP y restricciones `CHECK` con cinco campus; Facultad es propietaria.
- Backend: alta/edición/listado de facultad; herencia hacia carreras y programaciones;
  retiro de CRUD, estado, permisos y entidades del catálogo.
- Datos: migración `000093` hacia delante para poblar facultad y copia textual en
  programación, retirar `carreras.campus_id`, `programaciones_asignatura.campus_id` y
  `campus`.
- Frontend: ADM-04 deja de listar Campus; alta/edición de facultad usa selector; carrera
  muestra el valor heredado; las programaciones y sílabos muestran el nombre.
- Seguridad/auditoría: validar la opción fija en servidor y auditar cambios junto con los
  demás metadatos de facultad.
- Documentación: contrato JSON, esquema, identidad del sílabo, arquitectura, glosario,
  puesta en marcha, trazabilidad y reglas de las interfaces.

## Pruebas

- Actualizar las pruebas académicas y de interfaz que todavía modelan un catálogo o
  `campus_id`; se adaptaron los contratos académicos, de logo y de pausa del proceso.
- Se aplicó la migración local: tabla y FKs anteriores ausentes, columnas nuevas sin
  nulos y el campus de las programaciones existentes conservado.
- La migración remota `000093` quedó aplicada en `silabos_ueb_db` (lote 59). El estado
  previo mostraba solo esa migración pendiente; las tablas de facultades, campus,
  carreras y programaciones estaban vacías. Después se verificaron la ausencia de
  `campus` y de ambos `campus_id`, las columnas nuevas `NOT NULL`, sus dos restricciones
  `CHECK`, cero valores nulos y el registro de migración aplicado.
- El respaldo remoto cifrado quedó fuera del repositorio y se validó mediante listado
  de `pg_restore` sin guardar una copia en claro.
- No se reinició ni redesplegó el contenedor desde esta estación. El runbook de Dokploy
  indica redesplegar o reiniciar tras las migraciones remotas.
- `composer lint:check`, `npm run typecheck`, `npm run lint` y
  `npm run contract:check` pasaron; el contrato OpenAPI se regeneró sin cambios de rutas.
- `composer types:check` aún reporta 14 errores en archivos ajenos a este cambio. No se
  ejecutaron las suites automatizadas en esta entrega.

## Pasos

- [x] Confirmar opciones y revisar el alcance en backend, SPA, contrato y documentación.
- [x] Implementar enum obligatorio y migración con conciliación segura.
- [x] Retirar dependencias del catálogo y actualizar herencia/fotografías.
- [x] Actualizar ADM-04 y documentación trazable; el inventario OpenAPI no cambia rutas.
- [x] Revisar el código, aplicar y comprobar la migración local y ejecutar lint, tipos y contrato.
- [x] Registrar evidencia y mover el plan a `docs/plans/completed/`.

## Riesgos y reversión

- Una facultad sin campus inferible, con varias sedes o un nombre ajeno al catálogo fijo
  impide la migración; no se elige ni fusiona automáticamente un valor incorrecto.
- Las revisiones históricas no se reescriben. La foto de una programación se mantiene
  como texto y conserva su rótulo anterior durante la transición.
- La migración es irreversible una vez retirados los códigos/filas del catálogo. Se
  requiere respaldo lógico y revisión de conciliación antes del release.

## Evidencia de cierre

En `silabos_ueb` y `silabos_ueb_db` la tabla `campus`, `carreras.campus_id` y
`programaciones_asignatura.campus_id` ya no existen. Ambas columnas nuevas son
obligatorias y tienen `CHECK` con las cinco opciones. No quedan campus nulos. La base
remota tenía vacías las tablas académicas afectadas; su copia cifrada previa fue
validada antes de aplicar el DDL.
