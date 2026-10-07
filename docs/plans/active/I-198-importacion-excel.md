# I-198 — Importación con Excel

## Pedido del responsable, 2026-10-07

Crear muchas cuentas o registros uno por uno es lento. Tres pantallas aceptan un archivo
`.xlsx` para crear registros en bloque.

## Alcance

- Administración → Usuarios y roles: columnas Nombre, Correo, Rol, Carrera (por nombre).
  Crea cada cuenta con su invitación de activación, igual que el alta individual.
- Coordinación → Docentes: columnas Nombre, Correo. Reutiliza `CreateCareerTeacher`
  (incorpora a la carrera una cuenta existente sin tocar su nombre ni su acceso).
- Coordinación → Materias y asignaciones: columnas Código de materia, Paralelo, Jornada,
  Correo del docente (opcional), sobre el período seleccionado. Crea el paralelo y asigna
  al docente con las mismas acciones del alta individual.

## Reglas

- Sin estado en servidor: el archivo se sube dos veces, primero para revisar
  (`confirm=0`, sin escribir) y luego para importar (`confirm=1`). Nada se guarda del archivo.
- Todo o nada: si una fila tiene errores no se importa ninguna; con todas válidas se crean
  en una sola transacción.
- Máximo 500 filas y 2 MB; las filas vacías se ignoran; el correo repetido dentro del
  archivo es error.
- Plantilla descargable por pantalla. Los permisos son los del alta individual.

## Cambios

- Backend: `SpreadsheetReader` y `SpreadsheetTemplate` (OpenSpout), acciones
  `ImportManagedUsers`, `ImportCareerTeachers` e `ImportScheduledAssignments`, rutas
  `*/import` y `*/import-template`.
- Frontend: `ImportSpreadsheetDialog` reutilizable (plantilla, revisión por fila, confirmar).

## Verificación

`SpreadsheetImportTest` (revisión sin escritura, todo o nada, permisos, plantilla),
Pint, typecheck y lint del frontend.
