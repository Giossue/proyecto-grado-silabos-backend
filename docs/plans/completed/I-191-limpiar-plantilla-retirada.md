# I-191 — Borrar lo retirado de la plantilla que nadie usa

> Implementación local verificada (backend 347 pruebas, Pint y PHPStan). La migración `000199` la aplica el deploy; esta tarea no escribió en la remota.

## Pedido del responsable, 2026-10-06

La base de datos remota acumulaba filas viejas de la plantilla (17 secciones y unos 29 campos con `vigente_en_plantilla = false`). Se pidió borrar esa basura.

## Cambios

- `SyllabusTemplate::syncDocument` ya no deja acumular filas retiradas: al retirar un campo o una sección, borra las definiciones sin valores guardados, luego los campos y las secciones que quedaron vacíos. Solo se conserva lo retirado que aún tiene valores en `valores_campo` (sílabos de periodos anteriores), porque esos valores apuntan a su definición.
- Migración `000199`: aplica la misma limpieza una vez sobre lo ya acumulado y restituye `plantilla_modificada_en` (los disparadores la mueven al borrar), para no exigir rehacer sílabos por una limpieza.
- La columna `vigente_en_plantilla` se mantiene: sigue siendo necesaria para lo retirado que tiene valores.
- Pruebas desactualizadas por el retiro del decano del sembrador (`1250dce6`): `DatabaseBootstrapTest` y `ManagedUserTest` esperaban 5 en lugar de 4.

## Fuera de alcance

Quitar la columna `vigente_en_plantilla` (exigiría decidir qué pasa con los sílabos de periodos anteriores).
