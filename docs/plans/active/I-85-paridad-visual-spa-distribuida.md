# I-85: Paridad visual de la SPA distribuida

## Estado

Plan activo. La aplicación distribuida es el producto definitivo: Laravel continúa como
API independiente y Vue continúa como SPA independiente. Este incremento corrige la
reescritura visual introducida durante I-84 y porta fielmente la interfaz existente del
monolito sin recuperar Inertia, Wayfinder ni rutas de producto servidas por Laravel.

## Fuente canónica

La referencia inmutable es el árbol frontend del commit `4e33b6d` del repositorio:

```text
/home/giossue/Documentos/proyecto-grado-silabos
```

Se toman como fuente visual y de interacción:

```text
resources/js/components
resources/js/composables
resources/js/layouts
resources/js/lib
resources/js/pages
resources/js/types
resources/css/app.css
components.json
```

El repositorio de referencia no se modifica. Su estado local sucio tampoco se copia ni
se limpia. Los archivos se leen desde el commit fijado cuando sea necesario distinguir
una modificación local de la versión publicada.

## Diagnóstico que origina el plan

| Inventario | Monolito de referencia | SPA separada inicial |
| --- | ---: | ---: |
| Archivos Vue | 447 | 43 |
| Archivos bajo componentes | 452 | 23 |
| Páginas Vue | 38 | 29 |
| Componentes de dominio | 85 | 6 |

I-84 separó correctamente sesión, API, OpenAPI, CORS, despliegue y procesos, pero no
conservó la presentación: reemplazó layouts, componentes shadcn-vue y páginas de dominio
por una interfaz simplificada. Esta diferencia es un defecto de migración, no una
decisión de producto.

## Objetivo

Trasladar a `proyecto-grado-silabos-frontend` la misma interfaz, composición, lenguaje,
estados, responsive y accesibilidad del repositorio de referencia, manteniendo:

- Vue 3, Vue Router y TypeScript en la SPA;
- Laravel como API JSON bajo `/api/v1`;
- Sanctum con cookie HttpOnly y CSRF;
- los módulos `src/api/*`, sus tipos y recuperación 419/401;
- los contratos e invariantes del backend actual;
- Docker/Nginx y `PUBLIC_API_URL` en tiempo de ejecución.

## No objetivos

- No rediseñar, simplificar ni “modernizar” la interfaz original.
- No volver a Inertia, Wayfinder, Blade de producto o `laravel-vite-plugin`.
- No cambiar el modelo de datos o la lógica académica para acomodar una pantalla.
- No copiar secretos, `.env`, dependencias PHP ni artefactos generados.
- No desplegar automáticamente ni sustituir producción antes de la aceptación visual.

## Invariantes de migración

1. La plantilla original de cada pantalla es la referencia; la SPA simplificada no lo es.
2. Un componente existente se porta y adapta: no se recrea con HTML/CSS equivalente.
3. Los componentes shadcn-vue conservan tokens, variantes, foco, teclado y composición.
4. Solo cambia la frontera de datos:
   - `Link` → `RouterLink`;
   - `router.*` de Inertia → Vue Router + cliente API;
   - `Form`/`useForm` → formulario API tipado;
   - `usePage().props` → sesión/estado explícito de la SPA;
   - `Head` → título de documento asociado a la ruta;
   - Wayfinder → constructores de rutas locales o funciones de `src/api`.
5. No se permiten adaptadores falsos que simulen Inertia en producción.
6. Ningún UUID, nombre de proveedor o detalle de infraestructura aparece en pantalla.
7. La autorización continúa exclusivamente en Laravel; ocultar controles no la reemplaza.
8. Cada bloque debe quedar compilable, navegable y probado antes de iniciar el siguiente.

## Estrategia técnica

### Base conservada de la SPA actual

```text
src/api/*
src/state/session.ts
src/router.ts, reestructurado sin perder guardas
playwright.config.ts
scripts/check-openapi-contract.mjs
Dockerfile
docker/*
```

### Base portada del monolito

```text
components/ui/*
components/domain/*
layouts/*
composables visuales
lib de presentación/documento
tipos exclusivos de UI
resources/css/app.css → src/styles.css
fuentes y activos públicos necesarios
```

Se incorporan las dependencias de presentación del original —Tailwind 4, Reka UI,
Lucide, Tiptap, Vue Flow, VueUse, Sonner y utilidades de clases—, pero se excluyen
explícitamente `@inertiajs/*`, `laravel-vite-plugin`, Wayfinder y paquetes PHP/Laravel.

## Matriz de pantallas

| IDs | Fuente original | Destino SPA | Contrato principal |
| --- | --- | --- | --- |
| UI-01 | `pages/auth/Login.vue` | acceso | `api/session.ts` |
| UI-02 | `pages/Role/Select.vue` | selección de ámbito | `api/session.ts` |
| UI-03 | `pages/Notifications/Index.vue` | notificaciones | `api/notifications.ts` |
| UI-04 | `pages/settings/{Profile,Security,Appearance}.vue` | cuenta, seguridad y apariencia | `api/session.ts` |
| ADM-01, COR-01, DOC-01 | `pages/Dashboard.vue` | panel por rol | `api/dashboard.ts` |
| ADM-02..03 | `pages/Admin/Users/{Index,Show}.vue` | usuarios y detalle | `api/admin-users.ts` |
| ADM-04 | `pages/Admin/Academic/Index.vue` | estructura institucional | `api/academic.ts` |
| ADM-05..07 | `pages/Admin/Templates/{Index,Show,Edit}.vue` | plantilla, lectura y editor | `api/template.ts` |
| ADM-09 | `pages/Admin/Operations/Jobs.vue` | trabajos | `api/operations.ts` |
| ADM-10 | `pages/Admin/Operations/Audit.vue` | auditoría | `api/operations.ts` |
| ADM-12 | `pages/Admin/Processes/Index.vue` | proceso institucional | `api/processes.ts` |
| COR-02..04 | `pages/Coordination/Convocations/{Index,Show}.vue` | convocatorias | `api/convocations.ts` |
| COR-05..10 | `pages/Coordination/Reviews/{Index,Show}.vue`, `pages/Syllabi/Compare.vue` | revisión, observaciones, aprobación y comparación | `api/reviews.ts` |
| COR-11 | `pages/Sources/{Index,Show}.vue` | fuentes académicas | `api/sources.ts` |
| COR-12 | `pages/Coordination/Reports/Index.vue` | informes | `api/operations.ts` |
| COR-13 | `pages/Coordination/Academic/CurriculumBuilder.vue` | malla | `api/career-academics.ts` |
| COR-14 | `pages/Coordination/Academic/ScheduledSubjects.vue` | materias y paralelos | `api/career-academics.ts` |
| COR-15 | `pages/Coordination/Academic/TeacherAssignments.vue` | docentes | `api/career-academics.ts` |
| DOC-02 | `pages/Teacher/Syllabi/Index.vue` | mis sílabos | `api/syllabi.ts` |
| DOC-03..08 | `pages/Teacher/Syllabi/{Show,Edit,Submit,Ai}.vue` | resumen, editor, IA y envío | `api/syllabi.ts` |
| DOC-09 | `pages/Syllabi/Compare.vue` | historial/comparación | `api/reviews.ts` |
| DOC-10 | `pages/Syllabi/Documents.vue` | documentos privados | `api/documents.ts` |

Las capacidades que en la SPA actual comparten una sola ruta pueden conservar esa URL,
pero deben recuperar los estados visuales y componentes de todas las páginas fuente. Si
la diferencia de rutas cambia comportamiento observable, se divide la ruta en Vue Router;
no se aplana la interfaz para evitar hacerlo.

## Bloques de ejecución

### B0 — Congelar referencia y medir paridad

- [x] Inventariar páginas, layouts, componentes de dominio, componentes UI, composables,
      activos y dependencias del commit de referencia.
- [x] Crear una tabla mecanizada de archivos: `idéntico`, `adaptar frontera`, `retirar por
      Inertia`, `sin equivalente`.
- [x] Levantar referencia con el seeder sintético y capturar escritorio,
      oscuro y 360 px para cada ruta del inventario.
- [x] Proteger la SPA actual con una etiqueta o referencia Git antes de reemplazar vistas;
      no hacer commit/tag/push sin autorización expresa.

Evidencia B0: `npm run audit:visual-parity` clasifica el inventario; la línea base local
contiene 99 capturas (33 rutas por tres variantes) y se reproduce con
`npm run test:visual-reference`. La SPA previa al traslado queda identificada por el
commit existente `c2a5eee`; no se creó un tag ni un commit sin autorización. Las
capturas equivalentes de la SPA se incorporan conforme cada bloque reemplaza sus vistas.

**Salida:** baseline reproducible y cero pantallas sin propietario.

### B1 — Sistema visual y armazón global

- [x] Incorporar Tailwind 4, Instrument Sans y tokens completos de `app.css`.
- [x] Portar `components/ui/*` sin alterar su API ni regenerarlos desde el registro.
- [x] Portar utilidades `cn`, apariencia, breakpoints, foco y toasts.
- [x] Portar `AppShell`, sidebar, header, navegación,
      `PageFrame`, `FormSheet`, tablas, filtros, paginación y menús de acciones.
- [x] Adaptar navegación a `RouterLink` y sesión a `sessionState`.
- [x] Recuperar temas claro/oscuro y persistencia visual.

Evidencia B1: se portaron los 252 archivos de `components/ui`, los tokens canónicos,
Instrument Sans, componentes de marco y los componentes de dominio independientes de
Inertia. `npm run typecheck`, `npm run lint`, 38 pruebas Vitest y `npm run build` pasan.
No se incorporó ninguna dependencia de Inertia, Wayfinder o Laravel al frontend.

**Aceptación:** catálogo de componentes original renderizado en la SPA, shell idéntico en
los tres tamaños y ninguna dependencia Inertia/Wayfinder en este bloque.

### B2 — Identidad y superficies globales

- [x] Portar Acceso, recuperación, restablecimiento, verificación y segundo factor.
- [x] Portar selección/cambio de ámbito sin alterar la selección explícita de Coordinación.
- [x] Portar notificaciones, perfil, seguridad, sesiones, apariencia y contraseña temporal.
- [x] Portar Panel y puesta en marcha por rol con sus componentes originales.
- [x] Conservar todos los errores, pendientes, vacíos, foco y teclado.

**Aceptación:** UI-01..04 y los tres paneles coinciden con la referencia y completan un
recorrido real contra la API.

### B3 — Administración

- [x] Portar usuarios, detalle, roles fijos, estados y acciones pendientes.
- [x] Portar Facultades, Carreras, Campus y Períodos con sus hojas compartidas.
- [x] Portar procesos, auditoría y trabajos con filtros, paginación y menús.
- [x] Portar plantilla en sus tres estados: índice, lectura y editor dedicado.
- [x] Portar todos los componentes del documento: hoja, cinta, inserción, campos, tablas,
      paginación, apariencia, logos y diálogos persistentes.

**Aceptación:** ADM-01..12 aplicables mantienen composición y comportamiento; el editor
no se sustituye por formularios genéricos ni JSON visible.

### B4 — Coordinación académica y fuentes

- [x] Portar malla interactiva y alternativa de formulario con Vue Flow.
- [x] Portar materias/paralelos, preparación del período y estados temporales.
- [x] Portar docentes, creación/incorporación, asignación y relevo.
- [x] Portar fuentes con editor Tiptap/Markdown visual y bloqueo por convocatoria.
- [x] Portar convocatorias, apertura con fuentes, pausa, reanudación y detalle.
- [x] Portar configuración efectiva de IA sin perder bloqueo administrativo.

**Aceptación:** COR-02..04, COR-11 y COR-13..15 conservan todos los componentes de
dominio originales y no mezclan catálogos o pasos.

### B5 — Revisión, documentos e informes

- [x] Portar cola de revisión, detalle documental y datos congelados.
- [x] Portar observaciones vinculadas a campo, selección para corrección y respuestas.
- [x] Portar aprobación, reapertura, historial y comparación.
- [x] Portar documentos privados, progreso, descarga y estados degradados.
- [x] Portar informes y paginación con el alcance de carrera.

**Aceptación:** COR-05..10, COR-12 y DOC-09..10 completan revisión y documentos contra la
API sin perder trazabilidad ni composición documental.

### B6 — Docencia, editor e IA

- [x] Portar listado y resumen de sílabos.
- [x] Portar formulario continuo, contexto académico y ficha institucional.
- [x] Portar todos los tipos: texto, lista, booleano trivalente, selección múltiple,
      tablas repetibles, unidades, totales y planificación.
- [x] Conservar autoguardado único, vaciado previo, conflicto y validación determinística.
- [x] Portar asistente IA fijo a la derecha, conversación, fuentes, citas,
      recomendaciones, decisiones y aplicación explícita.
- [x] Portar observaciones, envío y estados inmutables.

**Aceptación:** DOC-02..08 coincide con la referencia, incluida la hoja/documento donde
aplica; la IA sigue degradable y nunca bloquea el flujo humano.

### B7 — Retiro de la interfaz sustituta

- [x] Eliminar páginas, componentes y estilos simplificados solo después de que su
      reemplazo haya pasado aceptación.
- [x] Eliminar todo import o dependencia de Inertia, Wayfinder y Laravel frontend.
- [x] Comprobar que cada ruta Vue Router tiene página canónica, título y guardas.
- [x] Comprobar que cada llamada SPA aparece en OpenAPI y que ninguna API queda enlazada
      desde HTML antiguo.
- [x] Actualizar README, arquitectura, trazabilidad y runbooks.

**Aceptación:** búsquedas de Inertia/Wayfinder dan cero en código productivo y el
inventario no contiene vistas sustitutas activas.

### B8 — Evidencia final y corte

- [x] ESLint, TypeScript, Vitest y build desde instalación limpia.
- [x] Contrato consumidor SPA contra OpenAPI.
- [x] `composer verify` completo en backend.
- [x] Playwright por los tres roles contra PostgreSQL/Redis aislados.
- [x] Comparación visual automatizada por ruta en 1440 px, tema oscuro y 360 px.
- [x] Comprobar automáticamente foco de sheets/diálogos y ausencia de overflow móvil.
- [ ] Revisar manualmente teclado, foco, diálogos, sheets y diferencias visuales restantes.
- [x] Construir y ejecutar ambas imágenes; probar runtime config, CORS, CSRF, logos,
      archivos privados, rutas SPA y cabeceras.
- [ ] Publicar primero en dominio de preview; producción solo después de aceptación del
      usuario y mediante el runbook de release.

**Aceptación:** cero diferencias visuales no justificadas, cero respuestas 5xx y todas
las puertas automáticas verdes. PV-07/PV-19 continúan siendo validaciones institucionales
o humanas y no se presentan como cerradas.

## Pruebas de regresión obligatorias por pantalla

Cada pantalla migrada incorpora, según aplique:

1. render con datos;
2. carga inicial;
3. estado vacío;
4. error seguro;
5. permiso/alcance;
6. validación de formulario;
7. mutación pendiente y repetición;
8. conflicto 409;
9. sesión 401 y CSRF 419;
10. teclado/foco de overlay;
11. 360 px sin overflow global;
12. snapshot visual contra la referencia.

## Puertas por bloque

```bash
# Frontend
npm run lint
npm run typecheck
npm run contract:check
npm test
npm run build

# Recorrido distribuido
VITE_API_URL=http://127.0.0.1:8000 npm run test:e2e

# Backend al cambiar contrato o cerrar el incremento
composer verify
```

Una prueba aislada no permite marcar un bloque completo. Cada bloque termina además con
revisión de `git diff --check`, inventario actualizado y evidencia visual.

## Avance comprobado — 16 de septiembre de 2026

- El armazón distribuido recuperó el título dinámico entregado por `PageFrame` al
  encabezado, incluida la selección de ámbito y los detalles con nombre propio; la
  navegación móvil expone de nuevo la región accesible «Navegación principal».
- El recorrido SPA→API pasa con Administrador, Coordinación y Docencia: sesión, CSRF,
  selección de carrera, cuentas, estructura, fuentes, convocatorias, IA, informes,
  inicio de borrador y autoguardado. La prueba también rechaza cualquier respuesta 5xx.
- Convocatorias administrativas recuperó la composición original con búsqueda, filtro,
  tabla adaptable, paginación, menú contextual y confirmaciones de transición. Auditoría
  recuperó sus pestañas «Procesos» y «Registro de actividad», el selector de intervalo,
  los filtros adaptables, la paginación y el diálogo seguro de reintento.
- Acceso, recuperación y restablecimiento de contraseña y el desafío de segundo factor
  recuperaron la composición original. El segundo factor vuelve a alternar entre seis
  dígitos y código de recuperación en vez de mostrar ambos campos simultáneamente.
- Se recuperaron `FilterToolbar` y `TablePagination` como componentes compartidos; el
  listado docente dejó de mostrar el botón sustituto «Aplicar filtros».
- Las capacidades académicas de Coordinación dejaron de compartir una única superficie:
  «Asignación docente», «Materias y paralelos» y «Malla» vuelven a tener rutas y entradas
  de navegación independientes, conservando la misma frontera API.
- «Malla» recuperó el lienzo original de Vue Flow, sus nodos, relaciones, arrastre y
  alternativa tabular. «Materias y paralelos» y «Asignación docente» recuperaron sus
  hojas, filtros, paginación y acciones contextuales; el logo de facultad volvió al
  paso correspondiente del panel.
- Fuentes recuperó creación y edición en hojas compartidas, acciones por fila,
  paginación local, bloqueo contextual y guardado solo cuando el documento cambia.
  Convocatorias recuperó filtros, paginación, menú por fila, selección explícita de
  fuentes, confirmaciones de inicio/pausa/reanudación y seguimiento paginado.
- Revisión recuperó la cola paginada y el detalle documental congelado, además de
  observaciones ubicables, solicitud de corrección, verificación, aprobación, reapertura,
  relevo docente, reinicio, historial y acceso a comparación y documentos.
- Documentos recuperó el filtro de estado, paginación, menú contextual de descargas,
  generación idempotente y estados de progreso; Informes recuperó filtros compartidos,
  distribución paginada, detalle con alcance de carrera y menú contextual de revisión.
- Se portaron los 84 archivos de AI Elements presentes en la referencia. El asistente
  docente ya compone conversación, mensajes, fuentes y sugerencias con esos componentes,
  aunque la aceptación integral del editor y sus estados continúa dentro de B6.
- La referencia visual selecciona ahora la carrera del coordinador antes de capturar sus
  rutas, evitando usar por error la pantalla de selección de ámbito como línea base.
- El control «Mantener la sesión iniciada» vuelve a atravesar la frontera API, incluida
  la autenticación con segundo factor. Las rutas SPA se cargan de forma diferida: el
  paquete inicial pasó de aproximadamente 1,09 MB a 178 kB sin alterar las vistas.
- Puertas ejecutadas en la SPA: `npm run typecheck`, `npm run lint`, 38 pruebas Vitest,
  `npm run build`, tres recorridos Playwright y `git diff --check`, todas correctas. El
  backend ejecutó las 16 pruebas de sesión (70 aserciones) y Pint sobre los archivos
  afectados.

Los bloques funcionales B2 y B3 están portados. La revisión visual final por ruta, tema y
ancho sigue abierta para B7/B8.
En la última iteración se alineó el editor docente con la referencia: tabla institucional
reutilizable, observaciones solicitadas, errores de validación por campo y acciones de
tabla/paginación compartidas en el listado. La SPA pasó `npm run typecheck`, `npm run lint`,
38 pruebas Vitest, `npm run build`, `npm run contract:check` (120 consumidores/operaciones)
y `git diff --check`. `composer verify` alcanzó el escaneo de secretos, Pint y PHPStan;
la prueba focalizada de documentos, IA y sesión pasó 24 pruebas y 212 aserciones.
La hoja de cambio de ámbito recuperó el alcance actual, contexto de carrera y selección
explícita. Estructura académica recuperó filtros, paginación y columnas por catálogo;
su eliminación ahora exige confirmación en un diálogo en lugar del aviso nativo. Usuarios
recuperó filtros reutilizables, menú contextual, detalle y asignación de alcance; eliminar
una cuenta pendiente ya usa el mismo diálogo de confirmación. La plantilla también reemplazó
las confirmaciones nativas por diálogos persistentes, incluido el caso en que el cambio debe
descartar borradores no enviados. La hoja ahora consume el documento estructurado que entrega
la API y el editor tipográfico se carga bajo demanda al abrir el diseño; esto conserva la
hoja y el menú de inserción sin penalizar la carga normal de Administración.
La puerta canónica del backend terminó el 16 de septiembre con salida `0`: escaneo de
secretos, Pint, PHPStan y 259 pruebas / 3 503 aserciones. Se ejecutó contra PostgreSQL y
Redis aislados en loopback; no utilizó producción.

En la reanudación se corrigió una diferencia real del bloque B3: la ruta de plantilla de
la SPA usaba un lienzo propio distinto al constructor canónico. Se añadió
`src/components/domain/configuration/TemplateVisualBuilder.vue`, adaptado a la frontera
API, con paginación A4, título y logos institucionales, estilos de apariencia, selección
contextual, acciones de edición/eliminación, orden, inserción y vista de documento
estructurado. `AdminTemplatePage` ahora lo utiliza como única superficie activa; el
editor Tiptap dedicado continúa cargándose bajo demanda para el diseño de cada campo.
La prueba de plantilla, las 38 pruebas Vitest, ESLint, TypeScript y la compilación
volvieron a pasar. `PaginatedDocument` también tolera entornos de prueba sin
`document.fonts`. Se retiraron los botones duplicados del encabezado de edición; las
acciones quedan concentradas en la cinta contextual y el control de inserción del
constructor, como en la referencia.

El recorrido Playwright distribuido también terminó con salida `0` contra esa API local:
3 de 3 escenarios cubrieron Administración, Coordinación y Docencia. Comprueba sesión y
CSRF, selección de ámbito, cuentas, navegación por roles, el requisito real de logo de
facultad, fuentes, convocatoria, IA, informes, creación de sílabo, autoguardado y la
ausencia de respuestas 5xx. La base y Redis empleados son contenedores locales aislados;
no se consultó ni alteró producción.

La captura de la SPA se ejecutó con `npm run test:visual-spa` y quedó en estado `passed`:
93 imágenes actuales (31 superficies, acceso incluido, en claro/oscuro a 1440 px y claro
a 360 px). La referencia se regeneró con `npm run test:visual-reference` contra el
monolito correctamente servido y quedó en 99 imágenes. `npm run test:visual-compare`
emparejó 84 superficies equivalentes, verificó sus dimensiones y reportó las diferencias
de píxeles para revisión humana; nueve superficies SPA no tienen una línea base directa
(estructura académica consolidada, trabajos y configuración IA) y cinco superficies
históricas de Administración no tienen destino directo. Las capturas actuales están
ignoradas por Git y ninguna métrica se usa como aprobación automática cuando cambian los
datos o el estado del entorno. Las 17 diferencias de altura observadas en la última
ejecución quedaron clasificadas: sesiones adicionales en Seguridad, contenido dinámico
del listado móvil de Usuarios, progreso/bloqueo de
la convocatoria en Malla, checklist de puesta en marcha del Panel y paginación variable
del documento de Plantilla. Son estados y datos de la base local, no cambios de
composición; el comparador funciona en modo informativo por defecto;
`npm run test:visual-compare -- --strict` conserva una puerta optativa que falla cuando
las dimensiones no coinciden. Tras activar el constructor visual canónico, el comparador
mantiene 84 pares; la ejecución actual informa 17 diferencias de altura. Las nuevas
diferencias de plantilla (52 px en escritorio y 140 px en 360 px) corresponden a la
fotografía de datos/paginación del documento local y quedan pendientes de confirmación
manual; no se ocultan ni se convierten en una aprobación automática.

Actualización posterior al reinicio: la prueba de accesibilidad quedó integrada en el
recorrido administrativo existente para no introducir un sexto inicio de sesión frente
al rate limiter de desarrollo. La puerta completa `VITE_API_URL=http://127.0.0.1:8000
npm run test:e2e` terminó nuevamente con 5 pruebas y 5 pasadas; incluye foco de hojas,
overflow móvil y presencia del constructor de plantilla paginado. La prueba unitaria de
`AdminTemplatePage` también valida selección de campo y apertura del editor de diseño.
La imagen frontend se construyó con Podman y se probó con Nginx: `/health/live` respondió
200, `config.js` inyectó `PUBLIC_API_URL`, las rutas SPA devolvieron 200 y las cabeceras de
seguridad estuvieron presentes. Tras el reinicio del equipo, ambas comprobaciones se
repitieron: la imagen backend volvió a construir sin cambios y arrancó con Nginx/PHP-FPM,
cola y scheduler; `/health/live` respondió `200 {"status":"up"}`. El endpoint de sesión
sin autenticar respondió `401` con CORS permitido y la cookie CSRF respondió `204`; la
SPA reiniciada volvió a entregar `/health/live` 200, `config.js` con `PUBLIC_API_URL`,
una ruta profunda 200 y las cabeceras de seguridad. La primera ejecución aislada de
contenedor no alcanzaba PostgreSQL enlazado a loopback; se repitió con red host local,
sin modificar el Dockerfile ni ningún entorno externo.

La prueba E2E se ajustó para esperar a que termine la carga del panel antes de buscar la
acción de logo; de ese modo no se omite una precondición válida por una carrera de UI.
Después del ajuste, los tres escenarios volvieron a pasar y la captura visual generó
93 imágenes actuales. La ejecución de `composer verify` posterior al reinicio volvió a
terminar con 259 pruebas y 3 503 aserciones.

La captura pública de la SPA también espera explícitamente el campo de contraseña antes
de tomar la imagen; esto elimina un falso desfase causado por el montaje diferido de la
ruta de acceso. La captura pública se repitió y pasó 1/1 prueba.

La revisión automatizable de accesibilidad añadió un recorrido administrativo que abre y
cierra una hoja con teclado, verifica que el foco permanezca dentro del diálogo y que
regrese al disparador, además de comprobar las superficies administrativas principales
a 360 px sin desbordamiento horizontal. `npx playwright test
tests/e2e/accessibility.spec.ts` terminó con 2 pruebas y 2 pasadas contra la API local.
La puerta completa `VITE_API_URL=http://127.0.0.1:8000 npm run test:e2e` terminó con 5
pruebas y 5 pasadas, incluyendo esos recorridos y los tres roles.

Revisión visual inicial: el shell, las tablas, filtros, menús, hojas y superficies de los
tres roles mantienen la composición de la fuente canónica en las capturas actuales. La
línea base pública ya fue regenerada desde una instancia cargada y ahora incluye los
campos de contraseña y recuperación definidos en `resources/js/pages/auth/Login.vue`.
Persisten diferencias de altura y contenido en algunas superficies dinámicas (por ejemplo,
sesiones, auditoría y malla), por lo que B8 continúa abierto hasta terminar la revisión
manual de foco, teclado, diálogos, sheets y overflow.

## Riesgos y controles

| Riesgo | Control |
| --- | --- |
| Copiar dependencias de Inertia | Lista de exclusión y búsqueda automática final |
| Romper API al adaptar formularios | Conservar `src/api/*` y comprobar OpenAPI en cada bloque |
| Reescribir componentes complejos | Portar fuente original y cambiar solo imports/frontera |
| Cambiar diseño accidentalmente | Capturas baseline y comparación por ruta/tamaño/tema |
| Mezclar cambios enormes | Bloques verticales compilables, sin avanzar con puerta roja |
| Perder funcionalidad reciente de API | Matriz pantalla↔endpoint y E2E contra backend actual |
| Afectar producción durante el trabajo | Preview independiente; despliegue solo por orden expresa |

## Estrategia de reversión

- Cada bloque conserva la última versión funcional de la ruta hasta que el reemplazo
  pase sus pruebas.
- El frontend actual se mantiene recuperable por Git; no se reescribe historia.
- Un fallo en preview no requiere revertir backend ni base de datos.
- Ninguna migración de datos forma parte de este incremento salvo que una carencia de
  contrato se demuestre y se apruebe por separado.

## Definition of Done específica

I-85 solo termina cuando:

- todos los bloques B0..B8 están marcados;
- las 38 páginas de referencia tienen destino explícito o retiro justificado;
- los componentes originales necesarios están portados, no imitados;
- no existe dependencia productiva de Inertia/Wayfinder;
- los tres roles completan sus recorridos reales SPA→API;
- las comparaciones visuales no presentan regresiones no aceptadas;
- documentación, pruebas y código describen el mismo estado;
- el usuario acepta el preview antes del corte productivo.
