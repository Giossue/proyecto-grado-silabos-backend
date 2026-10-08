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

La SPA presenta navegación por rol, formularios, editor documental, Texto libre del docente (I-156),
revisión, documentos y asistencia de IA. Las rutas y controles sólo mejoran la
experiencia: autorización, alcance y transiciones siempre se comprueban en Laravel.

Los componentes reutilizables, pruebas Vitest, estilos, Dockerfile y configuración de
Nginx pertenecen al repositorio frontend. Sus variables públicas se inyectan en el
arranque para que el mismo bundle apunte al backend de cada ambiente.

## Componentes, tema y datos compartidos

Las acciones y campos visibles se componen con las primitivas locales shadcn-vue de
`src/components/ui`; los componentes de dominio reúnen esas primitivas sin duplicar
sus controles. `ChoiceGroup` y `BooleanChoice` usan `RadioGroup`; `EvidenceDisclosure`
usa `Collapsible`; `StepProgress` usa `Badge` y `Separator`; los avances usan `Progress`.
`FeedbackAlert`, `EmptyState` y `CommentEntry` comparten errores, vacíos e historiales.
La salida de un sílabo cuyo autoguardado falla usa `UnsavedChangesDialog` y una respuesta
asíncrona. El aviso de cierre de pestaña sigue siendo responsabilidad del navegador.

Todo botón principal (variante por defecto o `destructive`) de páginas, sheets y diálogos
lleva un icono `lucide` al inicio con `data-icon="inline-start"`; mientras procesa, el
`Spinner` reemplaza al icono. `FormSheetActions` aplica `Check` si no recibe otro icono.
Los botones secundarios (`outline`, `ghost`, `link`) no lo requieren.

`styles.css` centraliza el tema, el avatar, el logo, las dimensiones de layouts y los
niveles de superposición. Los tokens `--selection-background` y `--selection-foreground`
definen el par de selección por tema mediante `::selection` global. Las primitivas de
campos heredan ese par. El avatar del asistente (`RobotAvatar.vue`) es un blob azul con
dos ojos al estilo «Strobi» de bible-strong-avatar-lab, reimplementado sin dependencia ni
AGPL: `blobGeometry.ts` modela una esfera (radio 120, foco 620) con dos cápsulas
proyectadas sobre su superficie; cada expresión gira la cabeza y cambia tamaño, separación
y ángulo de los ojos, y cada modo o emoción encadena expresiones con transición suave y
parpadeo (`ANIMATIONS`). Colores: `--avatar-body` y `--avatar-outline` (I-174, 2026-10-04). La paleta solicitada por el responsable del producto el
2026-10-04 usa el azul `#2d4b6f` en acciones primarias, foco y menú activo, y el rojo `#ac231e`
solo como barra del ítem activo del menú. Fondos y sidebar siguen neutros; todo vive
en los tokens de `src/styles.css`. Éxito, advertencia y acción destructiva tienen
colores semánticos independientes. Los valores proceden del
pedido del usuario y no acreditan una guía oficial de identidad de la UEB.
I-197 normaliza esos roles para toda la interfaz operativa: texto, superficies,
acciones, campos, estados y foco se resuelven exclusivamente desde los tokens del tema.
En oscuro, `primary`, `destructive`, `success` y `warning` usan variantes claras con su
texto contrastado; los bordes de campos conservan una relación mínima de 3:1 con las
superficies. Las primitivas compartidas no añaden excepciones cromáticas `dark:` y las
pestañas se dibujan sin fondo propio. El azul oscuro usa un tono medio de saturación
moderada y el sidebar comparte esa familia en su fondo, estados activos, bordes y foco.
Esta normalización no altera la paleta documental, los gráficos ni los colores que
identifican unidades curriculares.
`WorkspaceLayout` comparte la composición de sílabo y revisión
con sus skeletons. `LoadingSkeleton` mantiene el anuncio accesible de carga y delega
las composiciones en `src/components/domain/loading`: `TableLoadingSkeleton` recibe
columnas, búsqueda, filtros, paginación, acciones y líneas por celda;
`DashboardLoadingSkeleton` ajusta indicadores, plazo y pasos al rol activo;
`AcademicLoadingSkeleton` distingue Malla, Materias y Docentes;
`DocumentLoadingSkeleton` reúne sílabo, revisión por etapa, plantilla, comparación y
exportaciones; `SettingsLoadingSkeleton` representa Perfil o Seguridad dentro del layout
de Configuración. `ManageTwoFactor` comunica el inicio y la finalización de su consulta
mediante el evento `loading`; `SecurityPage` conserva montado el componente oculto para
realizar una sola consulta y muestra el esqueleto completo de la subsección hasta que
responde, incluso con error. `ProfilePage` espera los datos reactivos de sesión.
Las páginas pasan la estructura que renderizan actualmente; `table-body`
no agrega búsqueda ni filtros por defecto cuando esos controles ya están visibles.
Las tablas declaran si su última columna contiene acciones para que las filas navegables
de Revisiones y Proyecto de carrera mantengan contenido normal. El Panel limita su carga
a los tres indicadores que muestra y solo Coordinación representa el plazo; Docencia ya
no tiene panel. El sílabo editable carga una barra de pestañas y una sección, mientras la
lectura conserva secciones consecutivas. La plantilla reutiliza `template-sheet` y su
variante horizontal para la tabla de planificación de 16 semanas. `SubjectProfileSheet`
usa la variante de formulario mientras consulta los campos definidos por la plantilla.
Las superficies usan `Card` y `Skeleton` locales y las tablas decorativas no montan
controles de detalle durante la carga. Los componentes de notificaciones, chat, firma
y segundo factor conservan el título o controles estables y cargan su contenido dentro
de la misma región. `styles.css` comparte las columnas y desactiva la animación de los
esqueletos con movimiento reducido.
`SheetContent` centraliza el movimiento de todos los paneles laterales: entrada y
salida usan `300 ms` con `ease-in-out`, y el overlay comparte esos parámetros. El
lado solo determina el eje y la dirección; ningún uso redefine duración o curva.
`documentPalette.ts` mantiene los colores que se serializan y
establece sus variables CSS durante el arranque; `documentDefaults.ts` reúne los
valores documentales por defecto. Los colores del papel conservan su identidad en ambos
temas. La geometría calculada y los motores Tiptap/Vue Flow permanecen conforme a
ADR-0006/0007 e I-101; los controles de zoom del lienzo son `Button` con `Tooltip`.

Las opciones `options.modalityOptions` y `options.shiftOptions` se reciben de la API
académica y se describen en `AcademicUiOptions` de OpenAPI. La SPA no mantiene otro
catálogo de modalidades o jornadas. Las etiquetas de estados, los límites de formulario
y el formato de fechas se reúnen en utilidades compartidas; Laravel mantiene la
validación y las decisiones del dominio. La previsualización de plantilla utiliza su
metadata sin inventar fechas, asignaturas o datos institucionales. La URL de API es
obligatoria fuera de desarrollo; el fallback local sólo se permite en desarrollo.

`npm run lint` verifica las reglas locales `shared-components` y `semantic-styles`
mediante cuatro casos de regresión y luego revisa el código. Detectan controles visibles
HTML propios, confirmaciones nativas de ruta, tooltips `title`, paletas locales,
colores literales en componentes, dimensiones arbitrarias y apilamientos locales.
Las primitivas, los fixtures, la paleta documental central y el adaptador de PDF tienen
su alcance específico. Los campos ocultos de transporte/archivo y las medidas calculadas
no se confunden con controles visibles o datos ficticios. `contract:check` reconoce
`POST` multipart con `_method=PATCH` en el mismo cuerpo y bloque, y verifica la operación
PATCH real; no omite endpoints.

La evidencia de la corrección autorizada tras AUD-FE-2026-09-29 se mantiene en
`docs/plans/completed/FE-SHADCN-2026-09-29-componentes-y-tokens-compartidos.md`. La revisión local en Chromium cubre teclado, foco,
evidencia, zoom, arrastre y layouts en claro/oscuro a 360, 768 y 1440 px; no sustituye
la aceptación institucional de PV-07/PV-19.

I-146 retira COR-11, su editor documental y los paneles/acciones del revisor de IA.
La SPA conserva el chat para elaborar contenido y unidades desde el contexto académico
confirmado del sílabo. La importación curricular I-145 permite cargar PDF/XLSX y
revisar candidatos por lote; cada importación trae sus archivos (I-152). Mientras está
abierta, el original se consulta mediante una descarga privada autorizada; al guardar o
descartar, sus documentos se eliminan. Los nombres wire `sources`/`source_ids` permanecen por compatibilidad
interna; las etiquetas de producto los presentan como documentos de ingesta.
I-147 retira del lienzo de Malla el editor manual de resultados de aprendizaje. La
sección «Datos confirmados» del asistente presenta valores efectivos de la carrera y
sus materias con procedencia, incluido el respaldo I-144 cuando todavía no hay un
resultado documental importado para esa asignatura.

## Despliegue

Frontend y backend producen imágenes distintas. El frontend necesita la URL pública de
la API; el backend necesita `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, CORS y dominio de
sesión coherentes. Ambos deben usar HTTPS en producción.
