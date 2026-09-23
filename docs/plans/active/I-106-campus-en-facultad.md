# I-106: Campus como atributo de facultad

## Estado

En curso.

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
  de retirar datos si un valor es desconocido, una facultad no tiene campus asignado o
  reúne más de una opción; primero se concilian esos casos preservando la facultad como
  ámbito único.
- La migración remota requiere respaldo y comprobación previa según el runbook; este plan
  no aplica DDL a producción.

## Cambios previstos

- Dominio: enum de cinco campus; Facultad es propietaria de campus.
- Backend: alta/edición/listado de facultad; herencia hacia carreras y programaciones;
  retiro de CRUD, estado, permisos y rutas del catálogo.
- Datos: migración hacia delante para poblar facultad y copia textual en programación,
  retirar `carreras.campus_id`, `programaciones_asignatura.campus_id` y `campus`.
- Frontend: ADM-04 deja de listar Campus; alta/edición de facultad usa selector; carrera
  muestra el valor heredado; las programaciones y sílabos muestran el nombre.
- Seguridad/auditoría: validar la opción fija en servidor y auditar cambios junto con los
  demás metadatos de facultad.
- Documentación: contrato JSON, esquema, identidad del sílabo, arquitectura, glosario,
  puesta en marcha, trazabilidad y reglas de las interfaces.

## Pruebas

- Actualizar las pruebas académicas y de interfaz que todavía modelan un catálogo o
  `campus_id`.
- Comprobar migración con los valores canónicos, detección de referencias desconocidas y
  de varias opciones en una facultad.
- Verificar que el contrato publicado no exponga campus como entidad y conserve los cinco
  valores del selector.

## Pasos

- [x] Confirmar opciones y revisar el alcance en backend, SPA, contrato y documentación.
- [ ] Implementar enum obligatorio y migración con conciliación segura.
- [ ] Retirar dependencias del catálogo y actualizar herencia/fotografías.
- [ ] Actualizar ADM-04, contrato y documentación trazable.
- [ ] Revisar código y ejecutar las verificaciones aplicables.
- [ ] Registrar evidencia y mover el plan a `docs/plans/completed/`.

## Riesgos y reversión

- Una facultad sin campus inferible, con varias sedes o un nombre ajeno al catálogo fijo
  impide la migración; no se elige ni fusiona automáticamente un valor incorrecto.
- Las revisiones históricas no se reescriben. La foto de una programación se mantiene
  como texto y conserva su rótulo anterior durante la transición.
- La migración es irreversible una vez retirados los códigos/filas del catálogo. Se
  requiere respaldo lógico y revisión de conciliación antes del release.

## Evidencia de cierre

Pendiente.
