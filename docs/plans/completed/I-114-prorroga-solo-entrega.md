# I-114: Prórroga exclusiva de la fecha de entrega

## Estado

Completado localmente.

## Decisión

La prórroga institucional amplía únicamente la fecha límite de entrega del borrador.
La fecha de inicio no es prorrogable: Administración puede corregirla mediante la
edición del proceso cuando este se encuentre en preparación o en pausa.

`fechas_limite_convocatoria` conserva las etapas `inicio` y `borrador` porque ambas se
usan para validar la ventana de envío. No se requiere una migración; la acción de
prórroga actualiza exclusivamente `borrador` en el proceso y en sus convocatorias.

## Trazabilidad

- RF-003..006, RF-017..028; RN-001..004; CU-02, CU-03, CU-05; ADM-12.
- I-114 precisa el alcance de I-15 e I-31 sin cambiar el modelo de calendario.
- Solicitud explícita del responsable del producto del 2026-09-23.

## Implementación prevista

- Frontend: retirar el selector de etapa y nombrar explícitamente la nueva fecha límite
  de entrega.
- API: retirar `stage` de la carga útil y rechazar clientes que intenten enviarlo.
- Aplicación: fijar internamente la etapa `borrador` y conservar auditoría, propagación y
  validación de avance.
- Pruebas: verificar el formulario, el contrato y que el inicio no pueda alterarse desde
  la prórroga.
- Documentación: sincronizar decisiones, producto, planes previos y trazabilidad.

## Verificación

- [x] Prueba focalizada del formulario Vue: 1 prueba aprobada.
- [x] Prueba focalizada de `ConvocationScheduleTest`: 10 pruebas aprobadas.
- [x] Pruebas de arquitectura y contrato OpenAPI: 5 pruebas aprobadas.
- [x] Typecheck, lint y formato puntual aprobados.
