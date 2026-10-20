# ADR-0002: Evidencia mediante revisiones inmutables

- Estado: Aceptado (con las enmiendas de abajo)
- Fecha: 2026-08-14
- Trazabilidad: DP-07, RN-019, RN-025, RN-026, RF-045, RF-060, RF-063, RF-064.

## Contexto

Sobrescribir un archivo o registro impide reconstruir qué revisó y aprobó una autoridad.
El producto necesita comparar correcciones, relacionar observaciones y generar el mismo
documento aprobado después.

## Decisión

El trabajo editable vive en un borrador controlado. Enviar/reenviar crea una fotografía
inmutable numerada. Aprobación y exportación apuntan a una revisión. Un aprobado no se
reabre: Coordinación puede eliminarlo y el paralelo recibe un sílabo nuevo.

## Consecuencias

- Auditoría y comparación reproducibles.
- Mayor volumen de datos y necesidad de snapshots/mapeo cuidadoso.
- Ninguna operación funcional actualiza una revisión histórica.
- Las pruebas deben asegurar idempotencia, orden y concurrencia.

## Enmienda: corrección de escritura única (I-176)

La solicitud de corrección no tiene tabla propia: son las columnas `correccion_*` de
`revisiones_silabo`. Son de **escritura única**: pasan de NULL a un valor una sola vez y
siempre completas. El resto de la fila (contenido, huella, autor y fecha de envío) sigue
inmutable. El modelo (`ImmutableRecord::writeOnceColumns`) y el trigger
`proteger_registro_con_campos_unicos` lo hacen cumplir. `aprobaciones` es totalmente
inmutable.

## Enmienda: regeneración tras cambios de la base (I-188)

Hay una sola excepción a la inmutabilidad: regenerar sílabos en curso.

- Plantilla: si Administración la cambia durante la pausa del proceso, la reanudación
  confirmada elimina y vuelve a crear todos los sílabos de ese proceso.
- Malla, paralelos y ficha: si Coordinación los cambia con sílabos en una convocatoria
  abierta o pausada, la confirmación elimina y vuelve a crear los sílabos afectados.

En ambos casos se incluyen los sílabos aprobados y firmados. Se borran revisiones,
aprobaciones, exportaciones, firmas, archivos y mensajes de IA. La operación usa el
permiso transaccional `app.reinicio_institucional` y queda en la auditoría con su
autor. Los procesos cerrados no se afectan.
