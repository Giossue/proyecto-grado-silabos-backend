# I-205 — Ayuda contextual por pantalla (botón flotante)

## Pedido del responsable, 2026-10-07

Un botón flotante, movible dentro del contenido, en todas las pantallas, con la ayuda de esa
pantalla para no sobrecargar el contenido. Los textos los edita Administración desde un módulo
propio; Administración tiene además su propia ayuda.

## Decisiones

- Tabla `ayudas_pantalla`: una fila por pantalla. La clave es el patrón de la ruta del SPA
  (`/admin/usuarios`, `/docente/mis-silabos/:syllabus`). Cada rol tiene rutas con su prefijo, así que la
  ayuda queda separada por rol sin una columna de rol; las pantallas comunes (perfil, seguridad,
  notificaciones) ya tienen una ruta por rol.
- El backend no mantiene un catálogo de pantallas: el módulo de Administración lista las rutas del
  SPA y guarda solo las que tienen texto. Una clave debe empezar con `/` y usar solo `a-z 0-9 / : _ -`.
- Contenido: texto plano con párrafos separados por una línea en blanco; nunca HTML.
- El botón solo aparece si la pantalla tiene una ayuda activa. Su posición se recuerda en el
  navegador y no sale del área de contenido.

## Hecho

- Migración `ayudas_pantalla`, modelo `ScreenHelp`, acciones `SaveScreenHelp` y `DeleteScreenHelp`
  (con auditoría `ayuda.guardada` y `ayuda.eliminada`), puerta `manage-help` (solo Administración).
- API: `GET help?screen=` (cualquier rol con sesión y rol activo) y, para Administración,
  `GET admin/help`, `PUT admin/help` y `DELETE admin/help`.
- Frontend: botón flotante `ScreenHelpButton` en el shell y página `/admin/ayuda`
  (menú «Ayuda de pantallas»).

## Verificación

`ScreenHelpTest` (lectura por cualquier rol, ocultar inactiva, permisos de edición, validación de la
clave, guardar, reemplazar, eliminar y auditoría) y pruebas del botón y de la página de Administración.
