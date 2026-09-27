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

I-146 retira COR-11, su editor documental y los paneles/acciones del revisor de IA.
La SPA conserva el chat para elaborar contenido y unidades desde el contexto académico
confirmado del sílabo. La importación curricular I-145 permite cargar PDF/XLSX o
reutilizar documentos previos de ingesta y revisar candidatos por lote. El original se
consulta mediante una descarga privada autorizada por importación, sin recuperar el
CRUD de fuentes. Los nombres wire `sources`/`source_ids` permanecen por compatibilidad
interna; las etiquetas de producto los presentan como documentos de ingesta.
I-147 retira del lienzo de Malla el editor manual de resultados de aprendizaje. La
sección «Datos confirmados» del asistente presenta valores efectivos de la carrera y
sus materias con procedencia, incluido el respaldo I-144 cuando todavía no hay un
resultado documental importado para esa asignatura.

## Despliegue

Frontend y backend producen imágenes distintas. El frontend necesita la URL pública de
la API; el backend necesita `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, CORS y dominio de
sesión coherentes. Ambos deben usar HTTPS en producción.
