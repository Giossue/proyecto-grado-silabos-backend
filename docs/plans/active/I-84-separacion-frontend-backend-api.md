# I-84: Separación frontend/backend mediante API

## Estado

En ejecución. Este repositorio es la futura API Laravel; el frontend independiente vive
en `proyecto-grado-silabos-frontend`. La aplicación publicada no se cambia durante la
migración.

## Decisión confirmada

No se usa Inertia en el producto final. Laravel conserva dominio, políticas, trabajos,
archivos privados, IA, auditoría y PostgreSQL; Vue consume exclusivamente JSON bajo
`/api/v1`. No se crean microservicios ni una segunda base de datos.

## Primer contrato implementado

La primera unidad vertical cubre la identidad de la SPA con Sanctum y cookies de sesión:

```text
GET  /sanctum/csrf-cookie
POST /api/v1/authentication/login
POST /api/v1/authentication/logout
PUT  /api/v1/authentication/password
GET  /api/v1/session
PUT  /api/v1/active-role
```

`GET /api/v1/session` entrega el usuario autenticado, si debe cambiar contraseña, el
rol activo y los ámbitos elegibles. Coordinación nunca se activa automáticamente: debe
elegir explícitamente la carrera. La contraseña temporal se cambia sin solicitarla de
nuevo, pero la nueva no puede repetirla.

También están disponibles el resumen de panel por rol y el primer listado docente:

```text
GET /api/v1/dashboard
GET /api/v1/teacher/syllabi?q=&state=&page=
```

El panel conserva sus métricas y checklist calculados en el servidor. El listado filtra
por usuario, carrera y rol activo antes de paginar; no expone las rutas web heredadas al
cliente nuevo.

La administración institucional ya puede gestionar usuarios, estructura académica y el
calendario de procesos. Coordinación puede consultar su alcance por período, abrir la
convocatoria de su carrera y pausar o reanudar el trabajo. La creación sigue usando las
acciones de dominio existentes: al abrirse se generan los expedientes sin duplicarlos.

```text
GET|POST|PATCH /api/v1/admin/users
POST|PATCH|DELETE /api/v1/admin/users/{user}/…
GET|POST|PATCH|DELETE /api/v1/admin/academic/{entity}/…
GET|POST|PATCH /api/v1/admin/processes
POST /api/v1/admin/processes/{process}/deadline
POST /api/v1/admin/processes/{process}/{abrir|pausar|reanudar|cerrar}
GET|POST /api/v1/coordination/convocations
GET /api/v1/coordination/convocations/{convocation}
POST /api/v1/coordination/convocations/{convocation}/{pausar|reanudar}
```

El editor docente ya expone su unidad vertical inicial: consulta e inicio de borrador,
autoguardado de campos y tablas repetibles, validación determinística, envío y respuesta
a observaciones. La IA conserva la decisión humana y ahora expone por campo el historial,
evidencias citadas, recomendaciones y operaciones explícitas para registrar una decisión
o aplicar el texto sugerido:

```text
GET   /api/v1/teacher/syllabi/{syllabus}
POST  /api/v1/teacher/syllabi/{syllabus}/start
PATCH /api/v1/teacher/syllabi/{syllabus}/fields/{field}
POST  /api/v1/teacher/syllabi/{syllabus}/validate
POST  /api/v1/teacher/syllabi/{syllabus}/submit
POST  /api/v1/teacher/syllabi/{syllabus}/observations/{observation}/response
GET   /api/v1/teacher/syllabi/{syllabus}/fields/{field}/ai
POST  /api/v1/teacher/syllabi/{syllabus}/fields/{field}/ai
POST  /api/v1/teacher/syllabi/{syllabus}/fields/{field}/ai/{recommendation}/decision
POST  /api/v1/teacher/syllabi/{syllabus}/fields/{field}/ai/{recommendation}/apply
GET   /api/v1/teacher/syllabi/{syllabus}/ai-chat
POST  /api/v1/teacher/syllabi/{syllabus}/ai-chat/messages
DELETE /api/v1/teacher/syllabi/{syllabus}/ai-chat
```

El navegador debe configurar por ambiente:

```text
FRONTEND_URL=https://frontend.ejemplo.edu.ec
SANCTUM_STATEFUL_DOMAINS=frontend.ejemplo.edu.ec
SESSION_DOMAIN=.ejemplo.edu.ec
```

No se generan tokens personales ni se añade la tabla `personal_access_tokens`: el único
cliente previsto es la SPA de primera parte y usa cookie HttpOnly más CSRF.

## Siguientes unidades

1. Identidad de acceso (sesión, contraseña temporal, recuperación, verificación de
   correo y segundo factor) está disponible en la SPA.
2. Administración de usuarios, gobierno académico, procesos institucionales,
   convocatorias, fuentes, revisión/aprobación, estructura académica de Coordinación,
   plantilla, documentos privados, auditoría, trabajos e informes ya dispone de contrato
   JSON y pantalla SPA.
3. El armazón autenticado común, navegación por rol, selector de ámbito, contador de
   notificaciones, tema claro/oscuro, perfil, contraseña, segundo factor y administración
   de sesiones están disponibles en la SPA.
4. La edición detallada de usuarios y roles, la incorporación/relevo de docentes y la
   carga del logo de facultad ya están integradas. Faltan las operaciones visuales
   completas de estructura académica y el constructor de plantilla sin editores JSON.
5. Completar el editor docente y la revisión con representación fiel de documentos y
   tablas complejas, historial/comparación, estados de conflicto, avisos y accesibilidad.
6. El chat de IA ya dispone de conversación persistente por docente/sílabo, trabajo en
   cola, contexto del borrador, fuentes seleccionadas, citas verificadas y limpieza no
   destructiva. La SPA lo presenta en un único panel derecho junto con la revisión
   global y conserva separada la validación determinística.
   La configuración efectiva también está disponible por API y pantalla: Administración
   fija el valor predeterminado y el bloqueo; Coordinación solo sustituye campos
   permitidos, y cualquier cambio posterior de plantilla invalida automáticamente esas
   excepciones mediante la revisión de configuración.
7. Ampliar las pruebas frontend y documentar el contrato OpenAPI; las guardas de rol y
   contraseña temporal ya se aplican en navegador y servidor.
8. Ejecutar la verificación integral y realizar un corte único; solo entonces retirar
   Inertia, Wayfinder, Vite Laravel y las rutas web de producto de este repositorio.

No se redirige producción ni se ejecutan migraciones de datos por esta separación hasta
que todos los flujos estén cubiertos y probados contra el frontend independiente.
