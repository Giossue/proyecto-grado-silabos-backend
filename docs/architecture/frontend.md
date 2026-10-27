# Frontend Vue (SPA)

La interfaz vive en el repositorio `proyecto-grado-silabos-frontend`. Es una SPA Vue 3
con TypeScript, Vite y Vue Router. Este repositorio no compila ni sirve sus archivos
(ADR-0008).

## Estructura

```text
src/
├── main.ts          arranque: tema, accesibilidad, paleta documental, router
├── router.ts        rutas por rol y guardas de sesión
├── api/             un archivo por área (syllabi, template, reviews, library, signatures…)
├── state/           estado compartido con ref/reactive de Vue (sin Pinia)
├── lib/             utilidades: rutas por rol, fechas, estados, firma, bloques
│   ├── blocks/      intérprete de la definición de plantilla
│   └── signature/   firma PAdES en el navegador
├── composables/     lógica reutilizable de componentes
├── layouts/         AppLayout, AuthLayout, WorkspaceLayout, editores de sílabo y plantilla
├── pages/           una página por pantalla
└── components/
    ├── ui/          primitivas shadcn-vue (Reka UI + Tailwind)
    ├── domain/      componentes del producto por área (academic, syllabus, configuration…)
    └── ai-elements/ piezas del chat (conversación, mensaje, entrada)
```

## Rutas y roles

- Cada rol tiene su prefijo: `/admin`, `/coordinacion`, `/docente`, `/biblioteca`.
- `meta.requiresAuth`, `meta.guest` y `meta.roles` controlan el acceso. La guarda
  carga la sesión (`state/session.ts`), exige correo verificado y rol activo, y manda
  a `/rol` si falta elegirlo.
- Rutas cortas como `/panel` o `/notificaciones` redirigen a la versión del rol activo.
- Las páginas se cargan bajo demanda (`import()`).
- La SPA solo mejora la experiencia. Permisos, alcance y estados los decide la API.

## Cliente de la API

`src/api/client.ts` expone `api<T>(ruta, init)`:

- llama a `{apiUrl}/api/v1{ruta}` con `credentials: 'include'`;
- antes de la primera escritura pide `GET /sanctum/csrf-cookie` y envía
  `X-XSRF-TOKEN`; si recibe `419`, renueva el token y reintenta una vez;
- convierte errores en `ApiError` (estado, `errors` por campo y cuerpo);
- con `401` emite `silabos:unauthorized`; con `409` «Seleccione un rol vigente.»
  emite `silabos:role-required`. `main.ts` escucha ambos y redirige.

No se guardan tokens en `localStorage`.

Las acciones que no deben duplicarse (enviar, exportar y preguntar a la IA) envían
`idempotency_key`. Aprobar en Coordinación o Biblioteca y devolver desde Biblioteca no
la necesitan: el estado del sílabo, bloqueado en la transacción, impide repetirlas. `lib/attemptKey.ts` reutiliza la clave mientras se
reintenta el mismo envío y la cambia cuando cambia lo enviado.

## URL de la API

El orden es: `window.__SILABOS_CONFIG__.apiUrl` (archivo `/config.js`), luego
`VITE_API_URL` y, solo en desarrollo, `http://localhost:8000`. En el contenedor,
`docker/runtime-config.sh` escribe `/config.js` desde `PUBLIC_API_URL` al arrancar.
Así la misma imagen sirve para cualquier ambiente. Sin URL, la SPA no arranca.

## Estado

- `state/session.ts`: sesión y rol activo, con carga única compartida.
- `state/templateEditor.ts`, `state/curriculumEditor.ts`, `state/screenHelp.ts`,
  `state/accessibilityDialog.ts`: estado de pantallas concretas.
- El resto vive en cada página o composable.

## Fechas

- El día de negocio lo decide el servidor. La sesión publica `institution.today` e
  `institution.timezone`, y el composable `useInstitutionToday` lo entrega a los
  selectores de fecha: el navegador nunca decide la fecha de hoy de la institución.
- `lib/dateDisplay.ts` muestra los instantes en `America/Guayaquil` y las fechas sin
  hora tal cual, sin desplazarlas.
- La API publica los plazos de la convocatoria como `YYYY-MM-DD`: son fechas, no
  instantes.

## Respuestas protegidas

`FieldResponseEditor.ts` usa `template` de la API y edita solo párrafos y celdas vacíos,
como formulario: cada fila de una tabla es una tarjeta con un campo por columna,
etiquetado con el encabezado de la plantilla. Las tablas de listas permiten agregar y
quitar filas; las fijas no. Los bloques se llenan con `BlockForm.ts`, que recorre el
esquema de valores del bloque (etiquetas, listas, `keep_if` y reglas) en vez de
su dibujo; `DocumentBlockContent` queda para el constructor. El aspecto del
Word solo aparece en la vista previa. El constructor de Administración conserva
`FreeTextEditor` completo. Los campos antiguos sin `template` conservan el
comportamiento anterior.

## Plantilla en tiempo de ejecución (`src/lib/blocks`)

La SPA no conoce la plantilla de antemano. Cuando la API entrega la definición
(`definition`) y las variables, `setTemplateRuntime()` las guarda en un `shallowRef`.
Lo llaman `api/template.ts` (constructor), `api/syllabi.ts` (sílabo) y
`api/career-academics.ts` (ficha).

| Archivo | Qué hace |
|---|---|
| `runtime.ts` | Guarda la definición y variables; expande bloques y variables. |
| `types.ts` | Tipos de la definición: `document`, `blocks`, esquemas de valor y reglas. |
| `values.ts` | Valor vacío de un bloque y lectura/escritura por ruta. |
| `rules.ts` | Reglas de bloque al guardar o enviar. |
| `condition.ts`, `scope.ts`, `walk.ts` | Condiciones, contadores y recorrido del diseño. |

El backend interpreta la misma definición. La prueba `blocks.test.ts` compara el
resultado con `src/test/fixtures/block-conformance.json`, que genera el backend
(`php artisan template:conformance`). Ver [template-json.md](template-json.md).

## Editor y documentos

- `components/domain/syllabus/FreeTextEditor.vue`: Tiptap para texto, listas y tablas.
  Lo usan el constructor de plantilla, la ficha y el editor del docente.
- `fieldDocumentExtensions.ts` agrega los nodos `variable` y `documentBlock`.
- `components/domain/configuration/TemplateVisualBuilder.vue`: constructor visual.
  `TemplateJsonDialog.vue`: «Opciones avanzadas → Editar JSON».
- `components/domain/academic/curriculum/`: lienzo de la malla con Vue Flow (ADR-0006).
- `lib/signature/`: lee el `.p12`, agrega una firma incremental al PDF y sube solo el
  PDF firmado. El certificado no sale del equipo.

## Componentes y estilos

- Los controles visibles salen de `components/ui`. Los de dominio combinan esas
  primitivas; no crean controles propios.
- `styles.css` define los tokens de tema claro y oscuro. Los componentes no usan
  colores literales.
- `documentPalette.ts` y `documentDefaults.ts` guardan colores y valores del documento.
- `npm run lint` aplica las reglas locales `frontend/shared-components` y
  `frontend/semantic-styles`.

## Pruebas y verificación

```bash
npm run lint
npm run typecheck
npm test               # Vitest con jsdom
npm run test:e2e       # Playwright
npm run contract:check # llamadas del cliente contra openapi.json del backend
```

## Aplicación instalable (PWA)

`vite-plugin-pwa` genera `manifest.webmanifest` y `sw.js` al compilar; en desarrollo no
se registra. El service worker guarda la interfaz compilada para abrir rápido, pero no
`config.js` ni las llamadas a la API: sin conexión la interfaz abre y los datos no
cargan. Con una versión nueva, `AppUpdatePrompt` muestra «Hay una versión nueva del
sistema» con un único botón, «Actualizar», sin opción de posponer; solo recarga cuando la
persona lo pulsa. Revisa si hay versión nueva
cada hora. «Instalar aplicación», al pie del menú lateral, abre `InstallAppDialog`: si el
navegador ofrece instalar, muestra el botón «Instalar»; en iPhone explica Compartir →
«Agregar a pantalla de inicio»; en otros navegadores, cómo hacerlo en Chrome, Edge o
Safari de Mac. La opción se oculta cuando el sistema ya corre instalado. Nginx sirve
`sw.js` y el manifiesto con `no-store`. Necesita HTTPS.

## Despliegue

La imagen compila con Node 22 y sirve con Nginx en el puerto 8080 (`/health/live`).
Necesita `PUBLIC_API_URL`. El backend debe tener `FRONTEND_URL`,
`SANCTUM_STATEFUL_DOMAINS`, CORS y dominio de sesión coherentes con esa URL. Ambos con
HTTPS en producción. Ver [deployment.md](deployment.md).
