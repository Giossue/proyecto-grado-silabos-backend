# ADR-0002: Evidencia mediante revisiones inmutables

- Estado: Aceptado
- Fecha: 2026-08-14
- Trazabilidad: DP-07, RN-019, RN-025, RN-026, RF-045, RF-060, RF-063, RF-064.

## Contexto

Sobrescribir un archivo o registro impide reconstruir qué revisó y aprobó una autoridad.
El producto necesita comparar correcciones, relacionar observaciones y generar el mismo
documento aprobado después.

## Decisión

El trabajo editable vive en un borrador controlado. Enviar/reenviar crea una fotografía
inmutable numerada. Aprobación y exportación apuntan a una revisión. Un aprobado no se reabre (I-178):
se elimina completo y el paralelo recibe un sílabo nuevo.

## Consecuencias

- Auditoría y comparación reproducibles.
- Mayor volumen de datos y necesidad de snapshots/mapeo cuidadoso.
- Ninguna operación funcional actualiza una revisión histórica.
- Las pruebas deben asegurar idempotencia, orden y concurrencia.

## Enmienda I-176 (2026-10-04)

Dos hechos que ocurren una sola vez sobre un registro histórico dejan de tener tabla propia:
la solicitud de corrección (columnas `correccion_*` de `revisiones_silabo`) y la reapertura
(columnas de reapertura de `aprobaciones`). Esas columnas son de **escritura única**: pasan de
NULL a un valor una sola vez y siempre completas. El resto de la fila, incluidos contenido,
huella, autor y fecha de envío o aprobación, sigue inmutable, y ninguna fila se borra. El
modelo (`ImmutableRecord::writeOnceColumns`) y el trigger
`proteger_registro_con_campos_unicos` lo hacen cumplir; ambos rechazan cambiar una columna ya
escrita o cualquier otra columna. La excepción de reinicio institucional no cambia.

## Enmienda I-178 (2026-10-04)

La reapertura se retiró: un aprobado no se modifica, se elimina. Las columnas de reapertura de
`aprobaciones` se eliminaron y esa tabla vuelve a ser totalmente inmutable. Solo las columnas
`correccion_*` de `revisiones_silabo` quedan como escritura única.

## Enmienda I-188 (2026-10-05)

Si Administración cambia la plantilla durante la pausa del proceso institucional, la
reanudación confirmada elimina y vuelve a crear todos los sílabos de ese proceso, incluidos
los aprobados y firmados. También elimina revisiones, aprobaciones, exportaciones, firmas,
archivos y mensajes de IA. La mutación usa el bypass transaccional
`app.reinicio_institucional`; un evento de auditoría conserva el actor que confirmó y el
conteo por estado. Los procesos cerrados no se afectan. Esta excepción solo aplica al
cambio de plantilla I-188; las correcciones de bases académicas mantienen el alcance de
I-118/I-144.
