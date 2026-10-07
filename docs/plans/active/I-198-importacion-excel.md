# I-198 — Importación con Excel

## Pedido del responsable, 2026-10-07

Crear muchas cuentas o registros uno por uno es lento. Cuatro pantallas aceptan un archivo
`.xlsx` para crear registros en bloque.

## Alcance

- Administración → Usuarios y roles: columnas Primer nombre, Segundo nombre, Primer apellido, Segundo apellido, Correo, Rol y
  Carrera (por nombre).
  Crea cada cuenta con su invitación de activación, igual que el alta individual.
- Coordinación → Docentes: columnas de nombre y apellidos (como arriba) y Correo. Reutiliza `CreateCareerTeacher`
  (incorpora a la carrera una cuenta existente sin tocar su nombre ni su acceso).
- Coordinación → Materias y asignaciones: columnas Código de materia, Paralelo, Jornada,
  Correo del docente (opcional), sobre el período seleccionado. Crea el paralelo y asigna
  al docente con las mismas acciones del alta individual.
- Administración → Facultades y carreras (pestaña Carreras): columnas Facultad, Modalidad,
  Código de carrera, Nombre de la carrera y Número de ciclos.

## Reglas

- Sin estado en servidor: el archivo se sube dos veces, primero para revisar
  (`confirm=0`, sin escribir) y luego para importar (`confirm=1`). Nada se guarda del archivo.
- Todo o nada: si una fila tiene errores no se importa ninguna; con todas válidas se crean
  en una sola transacción.
- Máximo 500 filas y 2 MB; las filas vacías se ignoran; el correo repetido dentro del
  archivo es error.
- Plantilla descargable por pantalla (PhpSpreadsheet): listas desplegables con validación
  para Rol, Carrera, Código de materia, Jornada y Correo del docente, e indicaciones a la
  derecha que la importación ignora. El segundo nombre y el segundo apellido son opcionales.
-  Los permisos son los del alta individual.

## Retiro de la importación de facultades (2026-10-07)

Por instrucción del responsable, la importación de facultades desde Excel se eliminó: en
una universidad son pocas y se crean una por una con su logo. Se retiraron la acción
`ImportFaculties`, las rutas `admin/academic/faculties/import` y `.../import-template`, la
lectura de imágenes incrustadas (`SpreadsheetReader::readWithImages`), el alto de fila de
`SpreadsheetTemplate`, `CampusLocation::values()` y la prueba del flujo con logos. Las
demás importaciones no cambian.

## Cambios

- Backend: `SpreadsheetReader` y `SpreadsheetTemplate` (OpenSpout), acciones
  `ImportManagedUsers`, `ImportCareerTeachers`, `ImportCareers` e `ImportScheduledAssignments`, rutas
  `*/import` y `*/import-template`.
- Frontend: `ImportSpreadsheetDialog` reutilizable (plantilla, revisión por fila, confirmar).

## Verificación

`SpreadsheetImportTest` (revisión sin escritura, todo o nada, permisos, plantilla),
Pint, typecheck y lint del frontend.
