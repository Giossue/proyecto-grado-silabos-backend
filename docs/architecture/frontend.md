# Frontend Vue independiente

La interfaz vive en el repositorio `proyecto-grado-silabos-frontend`. Es una SPA Vue 3
con TypeScript, Vue Router y Vite; este repositorio Laravel no compila ni sirve sus
archivos.

## Contrato con la API

- Todas las operaciones de producto consumen `/api/v1`.
- La autenticación de primera parte usa Sanctum: primero `GET /sanctum/csrf-cookie` y
  luego cookies con `credentials: include`.
- Las mutaciones envían `X-XSRF-TOKEN`; no se almacenan tokens en `localStorage`.
- `docs/architecture/openapi.json` describe la superficie HTTP y se regenera con
  `php artisan openapi:export`.
- Los errores de validación usan `422` con `message` y `errors`; los conflictos de
  autoguardado usan `409` y un código estable.

## Responsabilidades de la SPA

La SPA presenta navegación por rol, formularios, editor documental, tablas repetibles,
revisión, documentos y asistencia de IA. Las rutas y controles sólo mejoran la
experiencia: autorización, alcance y transiciones siempre se comprueban en Laravel.

Los componentes reutilizables, pruebas Vitest, estilos, Dockerfile y configuración de
Nginx pertenecen al repositorio frontend. Sus variables públicas se inyectan en el
arranque para que el mismo bundle apunte al backend de cada ambiente.

COR-11 usa Tiptap sobre el documento JSON canónico de la fuente y lo presenta como flujo
continuo, sin `PaginatedDocument`. Las tablas permiten agregar filas/columnas, combinar
y separar celdas, y se desplazan dentro del editor si exceden el ancho disponible. Las
imágenes y el PDF original usan rutas privadas emitidas por la API. La SPA nunca
convierte el documento a Markdown. La creación consulta el estado de ingesta y el
porcentaje de `ejecuciones_trabajo` dentro de un `Dialog` modal; no navega al editor
hasta recibir `completada`. La carga PDF no se repite desde la pantalla de detalle.

## Despliegue

Frontend y backend producen imágenes distintas. El frontend necesita la URL pública de
la API; el backend necesita `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, CORS y dominio de
sesión coherentes. Ambos deben usar HTTPS en producción.
