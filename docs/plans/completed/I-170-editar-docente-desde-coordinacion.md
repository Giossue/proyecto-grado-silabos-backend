# I-170 — Editar nombre y correo del docente desde Coordinación

## Decisión

El Coordinador corrige nombre y correo de los docentes vigentes de su carrera activa
desde COR-15. Amplía I-54; Administración conserva todo lo demás.

## Alcance

- `PATCH /api/v1/coordination/academic/teachers/{user}` con `nombre` y
  `correo_electronico`; el resto de campos queda prohibido.
- Política `UserPolicy::updateCareerTeacher`: coordinador activo de carrera activa, sobre
  una cuenta activa con rol docente vigente en esa carrera y sin rol administrador.
- Reutiliza `UpdateManagedUserProfile`: el correo solo cambia si la cuenta no verificó
  su correo; al cambiarlo invalida la invitación anterior y emite una nueva. Auditoría
  `usuario.perfil_actualizado` con valores anteriores.
- Interfaz: acción **Editar docente** en cada fila de COR-15 (`CareerTeacherEditSheet`),
  correo deshabilitado con explicación cuando la cuenta ya fue activada.

## Verificación

- `CareerTeacherTest` (3 pruebas nuevas) y suite `tests/Feature/Identity` en verde.
- `TeacherAssignmentsPanel.test.ts`, lint, typecheck y suite frontend completa en verde.
- Trazabilidad y documentación de roles y pantallas actualizadas.
