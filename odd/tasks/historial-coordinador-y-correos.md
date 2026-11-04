# Historial del coordinador por período y correos más claros

Objetivo: dar a Coordinación un historial de los sílabos aprobados por período, y simplificar los correos.
Rama: main, con commits por unidad y sin push. Pruebas: Pest (`php artisan test --parallel --recreate-databases`) y Vitest (`npm test`).

## Specs

- S1: «debe haber un historial para el coordinador, creo que lo mejor es que tenga eso el coordinador, se ordena por periodo y ahi se ve los que ya fueron aprobados, independientemente de que se haya quita al anterior docente». Corrección: «espera, creo que solo los periodos anteriores mejor en coordinador, como docente y biblioteca».
  - Pantalla nueva «Historial» de Coordinación, en el menú lateral, para la carrera del rol activo.
  - Muestra SOLO períodos anteriores (`fecha_fin` del período anterior a hoy, la misma regla que el historial del docente y de biblioteca), del más reciente al más antiguo, agrupados por período, con los sílabos **aprobados** de la carrera: materia, código, paralelo, quién lo elaboró (el autor real es `revisiones_silabo.enviado_por`, no el docente actual del paralelo), fecha de aprobación y acceso al PDF.
  - Debe tener búsqueda por materia y filtro por período.
  - Un sílabo aprobado aparece aunque el docente ya no esté en el paralelo o en la carrera.
- S2: reasignar docentes: «Dejarlo como está». Se mantiene el comportamiento actual: asignar un docente borra el sílabo del período en curso de ese paralelo y empieza de cero.
- S3: «en las notificaciones por correo podemos simplificarlo, si como el mismo texto o titulo repetido varias veces, analiza los correos y enfocalos mejor».
  - Ningún correo repite la misma idea en el asunto, el título, la introducción y el cuerpo.
  - El título dice qué pasó y el cuerpo solo agrega lo nuevo: qué sílabo y revisión, el motivo, la fecha límite y qué hacer.
  - Los dos avisos que hoy comparten título («Sílabo listo para revisión»: `coordinacion.aprobado` y `silabo.reenviado_biblioteca`) tienen títulos distintos.
  - Los resúmenes diarios (docente o biblioteca y coordinación) usan una sola vista, con un asunto y un título que no se repiten.
  - La activación de cuenta, el restablecimiento de contraseña y la verificación de correo no usan «Hola:» como título ni repiten el nombre del botón.
  - Se corrige el texto de `silabo.devuelto_borrador`, que hoy atribuye a Coordinación cambios que también hace Administración (facultad o carrera).
  - Se mantiene el idioma (español), el tono formal de «usted» y los botones con su acción.

## Tasks

| ID | Specs | Ruta | Estado | Commit |
|---|---|---|---|---|
| T1 | S1 | delegada: escritor de backend y frontend (en paralelo con T2) | pendiente | |
| T2 | S3 | delegada: escritor de backend (correos; en paralelo con T1) | pendiente | |

## Log

- L1 (2026-11-03): «No pasa nada, para eso existen las prorrogas, cual es el problema? Pero si noto que aparte de eso debe haber un historial para el coordinador, creo que lo mejor es que tenga eso el coordinador, se ordena por periodo y ahi se ve los que ya fueron aprobados, independientemente de que se haya quita al anterior docente, lo que quisiera saber es que pasa si se le agrega de nuevo al docente. y una cosa, en las notificaciones por correo podemos simplificarlo, si como el mismo texto o titulo repetido varias veces, analiza los correos y enfocalos mejor».
- L2: respuestas: reasignar «Dejarlo como está»; historial «Todos (Recomendado)».
- L3: hallazgos del mapa:
  - Hoy no existe un historial de Coordinación por período.
  - `GET coordination/convocations` y `/{id}` ya cubren las convocatorias cerradas de la carrera.
  - El campo `teachers` (vista `docentes_vigentes_silabo`) da el docente actual del paralelo, no el autor.
  - El historial del docente está en `SyllabusController::history`/`historyCareer` y en `TeacherHistoryCareerPage.vue`; el de biblioteca en `LibraryReviewController::history` y `LibraryHistoryPage.vue`.
  - El PDF se obtiene con `GET revisions/{revision}/documents` y `GET exports/{artifact}/pdf`.
  - Correos: en `WorkflowNotificationMail` el asunto es igual al H1 (`resources/views/mail/workflow-notification.blade.php`) y los textos están en `WorkflowNotificationMessages.php` y en las acciones que notifican (`OpenConvocation`, `NotifyConvocationResumed`, `NotifyProcessClosingSoon`, `DeleteSyllabusProcess`, `InProgressWork::notifyRestarted`). `DailySummaryMail` y `CoordinatorSummaryMail` son casi copias. La activación está en `ManagedUserActivationMail`; el restablecimiento y la verificación en `resources/views/vendor/notifications/email.blade.php` y `lang/es.json`.
- L4 (2026-11-03): «espera, creo que solo los periodos anteriores mejor en coordinador, como docente y biblioteca». S1 se reescribe: solo períodos anteriores. El primer escritor de T1 se detuvo sin dejar cambios y se relanza.
