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

El navegador debe configurar por ambiente:

```text
FRONTEND_URL=https://frontend.ejemplo.edu.ec
SANCTUM_STATEFUL_DOMAINS=frontend.ejemplo.edu.ec
SESSION_DOMAIN=.ejemplo.edu.ec
```

No se generan tokens personales ni se añade la tabla `personal_access_tokens`: el único
cliente previsto es la SPA de primera parte y usa cookie HttpOnly más CSRF.

## Siguientes unidades

1. Recuperación, verificación y segundo factor de autenticación.
2. Notificaciones, preferencias y selector de ámbito desde el encabezado.
3. Identidad y estructura académica.
4. Detalle/editor docente, autoguardado, validación y envío.
5. Plantilla, convocatorias, revisión, documentos e IA.
6. Corte único, luego retiro de Inertia, Wayfinder, Vite Laravel y las rutas web de
   producto de este repositorio.

No se redirige producción ni se ejecutan migraciones de datos por esta separación hasta
que todos los flujos estén cubiertos y probados contra el frontend independiente.
