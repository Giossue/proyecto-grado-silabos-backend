# I-230 — Ficha: el docente puede editar un campo de Carrera

## Pedido del responsable, 2026-10-20

En «Proyecto de carrera», cada campo de la ficha de una materia tiene un interruptor «El docente puede
editarlo». Así Coordinación deja un texto base (por ejemplo, la bibliografía o las herramientas TIC/IA del
Excel de la carrera) y el docente lo ajusta en su sílabo.

## Decisiones

- El interruptor es por materia y por campo. Vive en la fila de la ficha (`valores_campo`, columna nueva
  `editable_docente_valor_campo`, falso por defecto).
- Al crearse el sílabo se copia el valor y también el interruptor. Cambiar la ficha después sigue el flujo de
  siempre: confirmar y rehacer los sílabos de esa materia.
- Un campo de Carrera con el interruptor encendido:
  - se edita en el sílabo como uno del docente;
  - al enviar, si está vacío, el aviso es para el docente;
  - se copia entre paralelos.
- En la ficha, un campo vacío con el interruptor encendido cuenta como completo: lo llena el docente.
- Sin interruptor, todo queda como antes (solo lectura para el docente).

## Cambios

- Backend:
  - migración;
  - `FieldValue`;
  - `SaveSubjectProfile` y su request (`editable`);
  - `SubjectProfileFields` (`editable`, progreso);
  - `SubjectProfileController`;
  - `InheritMasterValues`;
  - `UpdateDraftField`;
  - `ValidateDraft`;
  - `CopySyllabusContent`;
  - `SyllabusController` (`teacher_editable` por sílabo).
- Frontend:
  - `SubjectProfileSheet` (interruptor);
  - `api/career-academics.ts`;
  - `TeacherSyllabusDetailPage` (usa `teacher_editable`).
- Documentación: esquema, decisiones, trazabilidad, roles.

## Verificación

- Pruebas de ficha (guardar interruptor, progreso), de herencia, de guardado del docente, de validación y de
  copia entre paralelos.
- Pruebas de frontend de la ficha y del editor docente.
