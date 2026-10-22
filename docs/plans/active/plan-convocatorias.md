# Plan — Convocatorias: cerrar es terminal, eliminar es eliminar

## Problema

Hoy conviven dos ideas de «eliminar»:

- La convocatoria institucional **se oculta** (estado `eliminado`) en vez de borrarse, y
  queda un quinto estado que el usuario no puede distinguir de «cerrada».
- El diálogo de eliminar un período **promete** que se borran materias, paralelos y
  docentes, cuando los paralelos son fijos por asignatura y no dependen del período
  (I-209). El mensaje miente.
- Una convocatoria oculta sigue bloqueando el borrado del período, porque la
  comprobación es SQL directo y no aplica el alcance global del modelo. De ahí el
  error genérico «ya tiene registros dependientes o historia académica».

## Decisión

- **Cerrar es terminal.** Una convocatoria cerrada queda solo para consulta y no se
  elimina nunca.
- **Eliminar es eliminar.** Se borra de verdad y solo se admite mientras no haya
  ningún sílabo enviado. Con sílabos enviados, la única salida es cerrar.
- Desaparece el estado `eliminado`. Un período tiene una sola convocatoria, para
  siempre.
- El período se elimina solo si no tiene ninguna convocatoria; si tiene, el motivo se
  dice con palabras claras.

## Alcance

Backend:

1. `SyllabusProcess`: sin estado `eliminado`, sin alcance global ni columnas
   `eliminado_en`/`eliminado_por`; cuenta de sílabos enviados.
2. `Convocation`: sin el alcance `proceso_no_eliminado`.
3. `DeleteSyllabusProcess`: borra de verdad cuando no hay sílabos enviados; si los hay,
   rechaza con el motivo.
4. `LibraryReviews`: sin el filtro de convocatorias ocultas.
5. `DeleteAcademicRecord`: mensaje propio para el período.
6. `ProcessLocks`: el motivo del bloqueo distingue «Convocatoria activa» de
   «Convocatoria cerrada».
7. `SyllabusProcessController`: la lista publica si la convocatoria se puede eliminar.
8. Migración: las convocatorias ocultas con historial pasan a «cerradas» y las vacías
   se borran; el `CHECK` se queda sin `eliminado`, el índice del período deja de ser
   parcial y se quitan las columnas.

SPA:

9. `SyllabusProcessActions`: el diálogo dice la verdad y «Eliminar» solo aparece cuando
   se puede; si no, se explica por qué.
10. `ConvocationRowActions`: el diálogo del período dice la verdad y el motivo del
    bloqueo se ve en la fila.

## Fuera de alcance

- Los sílabos de la convocatoria de carrera: no tienen acción de eliminar.
- La plantilla, la malla y las fichas no cambian.
- La regeneración de sílabos cuando cambia la plantilla o la malla: se mantiene la
  regla vigente (se borra todo lo hecho en esa convocatoria).

## Verificación

- `composer verify`.
- `npm run typecheck`, `npm run lint` y `npm test` en la SPA.
- Pruebas nuevas: borrado real de una convocatoria vacía, rechazo con sílabos
  enviados, migración de las-states ocultos, mensaje y bloqueo del período.
