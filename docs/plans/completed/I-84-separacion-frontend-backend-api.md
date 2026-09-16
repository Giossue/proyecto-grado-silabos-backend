# I-84: Separación frontend/backend mediante API

## Estado

Implementación y auditoría técnica completadas. Este repositorio es exclusivamente la API
Laravel y el frontend independiente vive en `proyecto-grado-silabos-frontend`. La SPA no
depende de Inertia, sus consumidores se contrastan automáticamente con OpenAPI y los tres
roles completan recorridos autenticados reales contra Laravel y PostgreSQL de pruebas.
El cambio de dominios en producción continúa siendo una operación de release separada y
no se ejecuta como parte de este plan sin una orden explícita.

## Bloques de cierre

- [x] **B1 — Contrato y seguridad:** proteger toda operación de producto por sesión,
      correo verificado, contraseña definitiva, rol y alcance; contrastar las llamadas
      de la SPA con OpenAPI.
- [x] **B2 — Identidad:** probar ingreso repetido en una misma pestaña, segundo factor,
      recuperación, verificación, cambio de contraseña, sesiones y cambio de ámbito.
- [x] **B3 — Administración:** completar usuarios, catálogos académicos, procesos,
      plantilla, auditoría y trabajos, incluidos estados vacíos y paginación.
- [x] **B4 — Coordinación:** completar convocatorias, fuentes, malla, docentes,
      revisiones, informes, configuración de IA, documentos y paginación.
- [x] **B5 — Docencia e IA:** demostrar borrador, tipos de campo, tablas repetibles,
      autoguardado, conflicto, validación, chat, recomendaciones, observaciones y envío.
- [x] **B6 — Operación distribuida:** validar imágenes independientes, configuración
      CORS/Sanctum/cookies, salud, colas, archivos privados y procedimiento de corte.
- [x] **B7 — Evidencia final:** puertas completas desde ambos repositorios, recorrido
      HTTP real SPA→API y documentación/trazabilidad sincronizadas.

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
convocatoria de su carrera y pausar o reanudar el trabajo. Antes de abrir debe elegir un
subconjunto no vacío de sus fuentes activas; la API valida carrera y actividad, fija solo
esa selección y no la modifica al reanudar. Al abrirse se generan los expedientes sin
duplicarlos.

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
   Las fuentes académicas se redactan en una hoja visual con cinta de formato; Markdown
   permanece como contrato interno y ya no se expone a Coordinación.
4. La edición detallada de usuarios y roles, la incorporación/relevo de docentes y la
   carga del logo de facultad ya están integradas. La estructura académica permite crear,
   editar, activar, eliminar y ordenar materias por ciclo; configurar la malla; mantener
   requisitos; preparar períodos y paralelos; asignar, incorporar y relevar docentes.
   La plantilla se presenta como documento y separa lectura de edición. El modo edición
   usa cinta fija, selección contextual e inspector lateral; permite crear bloques con
   varios campos, reordenarlos y configurar texto, listas, tablas, booleanos y selección
   múltiple. El esquema de tablas usa un diseñador estructurado —columnas, grupos,
   bandas, cabeceras, totales y repetición— y no expone JSON al usuario.
5. El editor docente y la revisión usan una hoja documental con la apariencia de la
   plantilla, logos, título y ficha institucional. Las tablas conservan bandas, grupos,
   cabeceras y unidades repetibles; los booleanos distinguen Sí, No y pendiente. El
   autoguardado se vacía antes de validar/enviar y un conflicto de versión detiene nuevas
   escrituras hasta recargar. Coordinación ve la apariencia congelada de cada revisión,
   tablas estructuradas, historial, comparación y observaciones vinculadas. Queda la
   validación manual de accesibilidad y dispositivos reales en PV-19.
6. El chat de IA ya dispone de conversación persistente por docente/sílabo, trabajo en
   cola, contexto del borrador, fuentes seleccionadas, citas verificadas y limpieza no
   destructiva. La SPA lo presenta en un único panel derecho junto con la revisión
   global y conserva separada la validación determinística.
   Las fuentes Markdown se dividen por encabezados en fragmentos acotados y la API
   recupera por búsqueda textual PostgreSQL solo los extractos pertinentes para la
   pregunta o el campo, conservando ruta y huella como evidencia no confiable. La
   revisión de cada campo ya reserva contexto para fuentes externas y lo contrasta con
   campos habilitados del mismo borrador mediante fotografías tipadas e inmutables.
   La revisión integral se representa mediante una ejecución padre única por versión,
   agrega el progreso de sus trabajos por campo y entrega resultados agrupados por
   sección. La SPA la inicia al terminar una validación determinística limpia y no trata
   una indisponibilidad de IA como impedimento para enviar.
   La configuración efectiva también está disponible por API y pantalla: Administración
   fija el valor predeterminado y el bloqueo; Coordinación solo sustituye campos
   permitidos, y cualquier cambio posterior de plantilla invalida automáticamente esas
   excepciones mediante la revisión de configuración.
7. Las pruebas frontend cubren los flujos críticos y el contrato OpenAPI 3.1 se exporta
   desde todas las rutas `api.v1.*`. `OpenApiContractTest` obliga a regenerar
   `docs/architecture/openapi.json` cuando cambia la superficie HTTP; las guardas de rol
   y contraseña temporal ya se aplican en navegador y servidor.
   La SPA ya cuenta con Dockerfile multi-stage, Nginx con fallback de rutas, liveness y
   `PUBLIC_API_URL` inyectable al arrancar; no requiere reconstruir el bundle por ambiente.
8. Inertia, Wayfinder, Vite Laravel, los recursos Vue y las rutas web de producto ya se
   retiraron. Las puertas automáticas pasan, pero no constituyen por sí solas la prueba
   de equivalencia funcional distribuida; los bloques anteriores deben cerrarse antes
   de declarar el corte listo.

Esta implementación no redirigió producción ni ejecutó migraciones de datos: el corte de
dominios y despliegue se realiza mediante el runbook como una operación explícita.

## Evidencia de cierre automatizado — 2026-09-16

- Backend: `composer verify` aprobó Pint, PHPStan y **258 pruebas / 3.499
  aserciones** sobre PostgreSQL y Redis.
- Frontera HTTP: `ApiBoundaryTest` exige `auth:sanctum` y correo verificado en toda ruta
  de producto; `OpenApiContractTest` mantiene sincronizado OpenAPI.
- Identidad distribuida: `ApiSessionTest` cubre sesión, acceso repetido, segundo factor,
  verificación, contraseña temporal, sesiones de dispositivo y limitación por correo.
- Frontend: Vitest, `vue-tsc` y Vite verifican autenticación, paginación, tablas
  repetibles, selección múltiple, booleanos, respuestas a observaciones, chat, citas,
  decisiones y aplicación explícita de recomendaciones.
- Contrato consumidor: el analizador AST contrastó **119 llamadas** de la SPA con las
  **120 operaciones** publicadas en OpenAPI; ninguna llamada usa una ruta o verbo ausente.
- Navegador real: tres recorridos Playwright aprobaron Administración, Coordinación y
  Docencia contra Laravel/PostgreSQL aislados, con CSRF, creación y recarga, apertura de
  proceso/convocatoria, edición de fuente, logo, autosalvado docente e imágenes, sin
  respuestas HTTP 5xx.
- Recuperación operativa del navegador: una mutación sin token prepara CSRF, un `419`
  renueva y reintenta una vez, y un `401` limpia la sesión y vuelve al ingreso.
- Contenedores: ambas imágenes se construyeron. La SPA respondió liveness, inyección de
  `PUBLIC_API_URL` y fallback de rutas; la API respondió readiness, CORS con credenciales
  y `/sanctum/csrf-cookie`. Supervisor inicia un solo contenedor sin duplicar cada línea
  de actividad.

La revisión perceptiva en dispositivos reales, la fidelidad institucional del documento
y las decisiones UEB abiertas siguen sus puertas `PV`; no son defectos de la separación
ni se declaran resueltos aquí.

## Auditoría de cierre — 2026-09-16

La auditoría detectó que la evidencia anterior probaba módulos aislados y contenedores,
pero no un navegador autenticado consumiendo Laravel. Se añadió esa prueba y se cerraron
los estados de interfaz que faltaban.

Primer resultado de la auditoría:

- se incorporó ESLint 10 con reglas de Vue, TypeScript y accesibilidad; `npm run lint`
  forma parte de la puerta frontend y pasa;
- las mutaciones de usuarios, notificaciones, cambio de ámbito y cierre de sesión ya
  informan fallos y bloquean duplicados en vez de dejar promesas rechazadas;
- Playwright recorrió una SPA real contra Laravel y PostgreSQL de pruebas: ingreso,
  cookie de sesión, CSRF con creación de cuenta, recarga, selección obligatoria de la
  carrera coordinada, procesos, plantilla, fuentes, convocatoria, estructura académica,
  revisiones, IA, informes, borrador docente, autosalvado y 360 px;
- la prueba E2E rechaza URLs de API que no sean loopback para impedir que sus datos
  sintéticos alcancen un entorno remoto;
- un verificador basado en el AST de TypeScript impide que un consumidor de la SPA use
  una ruta o verbo ausente en OpenAPI;
- todas las páginas con carga inicial muestran estado de carga y los listados principales
  muestran vacío; las mutaciones críticas bloquean duplicados y presentan sus errores;
- la imagen Nginx exige un origen HTTP(S) válido, conserva cabeceras de seguridad en sus
  ubicaciones y permite que la SPA cargue los logos públicos sin abrir documentos
  privados entre orígenes.

El despliegue y el corte de dominios no forman parte de esta auditoría local. Tampoco se
cierran las validaciones perceptivas o institucionales registradas como `PV`.
