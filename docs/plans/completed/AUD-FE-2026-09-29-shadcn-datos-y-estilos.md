# AUD-FE-2026-09-29: Auditoría del frontend sobre datos, estilos y shadcn

## Estado

Auditoría completada el 2026-09-29. El frontend tiene shadcn-vue y colores globales,
pero todavía conserva controles construidos fuera de sus primitivas, colores locales,
ejemplos fijos y valores repetidos. La inspección y el diagnóstico están terminados;
las correcciones se implementaron posteriormente por autorización «ok arregla todo».
Su cierre y evidencia están en
`FE-SHADCN-2026-09-29-componentes-y-tokens-compartidos.md`. Las tablas siguientes
conservan el diagnóstico original; no representan incidencias frontend pendientes.

Solicitud del responsable del producto: analizar todo el frontend y determinar si
los datos de negocio, estilos y controles respetan una base reutilizable con colores
globales y shadcn.

## Trazabilidad

- RF-001..026 y RF-034..074; CU-01..04 y CU-06..17: superficies activas de la SPA.
- RNF-018..023 y RNF-029..034: accesibilidad, consistencia y mantenibilidad.
- RN-001..034: conservar permisos, estados, snapshots y procedencia de datos.
- UI-01..04, ADM-01..07 y ADM-09..12, COR-01..10 y COR-13..16, DOC-01..10,
  BIB-01..03.
- CP-F01..35 y CP-N01..16: referencia al catálogo formal; esta auditoría no cierra
  aceptación funcional ni asigna pruebas a IDs individuales sin su definición.
- I-10, I-13 e I-101: tokens semánticos y componentes compartidos.
- I-165: revisión complementaria del rol Bibliotecario y las firmas incorporados
  durante la auditoría; no se modifica su alcance.
- ADR-0006 y ADR-0007: motores específicos de malla y edición documental.
- PV-07 y PV-19 permanecen abiertos para fidelidad documental y dispositivos reales.
  PV-16 no afecta la inspección y no se amplía el permiso del coordinador.

## Resultado demostrable

Un inventario completo del código fuente del repositorio hermano
`proyecto-grado-silabos-frontend`, con hallazgos verificables por archivo y línea,
distinción entre datos reales, ejemplos y constantes del contrato, y propuestas
concretas de componentes y tokens existentes para las excepciones.

## Decisiones y supuestos

- El pedido se interpreta como una auditoría con diagnóstico. La aplicación no se
  refactoriza como parte de este análisis.
- La presencia de HTML dentro de una primitiva shadcn no prueba una infracción; se
  revisan los controles construidos fuera de las primitivas y las composiciones de
  dominio.
- Se comprueba alcance de importación antes de presentar código sin uso como una
  incidencia visible en producción.
- Las geometrías documentales y del lienzo se contrastan con I-101 y los ADR aceptados.
  Su retirada sería una decisión posterior, no una corrección silenciosa del análisis.

## Cambios previstos

- Dominio, backend, datos y contratos: sin cambios funcionales.
- Frontend: inspección de páginas, layouts, componentes, API, estado, utilidades,
  estilos, configuración, pruebas y recursos públicos.
- Documentación: este plan conservará cobertura, resultados y evidencia de cierre.

## Verificación

- Inventario y búsqueda global, con análisis de templates y dependencias locales.
- Lectura de cada excepción y de sus consumidores.
- Comprobaciones existentes del frontend proporcionales al diagnóstico; sin acceso a
  bases de datos, migraciones ni despliegues.

## Pasos

- [x] Leer instrucciones, dependencias, lockfile y documentación de interfaz.
- [x] Revisar el antecedente I-101 y los límites de los motores específicos.
- [x] Inventariar todo el código y determinar qué se importa desde la aplicación.
- [x] Revisar datos fijos, colores, CSS local y controles fuera de shadcn.
- [x] Verificar las comprobaciones aplicables y registrar sus límites.
- [x] Entregar diagnóstico y prioridades con evidencia.

## Riesgos y reversión

Las búsquedas pueden detectar primitivas legítimas, muestras de previsualización,
fixtures de pruebas y geometría calculada. Cada hallazgo se confirma antes de concluir.
El cambio durable es documental y puede revertirse retirando el plan; no se escribe en
la base ni se modifica el comportamiento de la SPA.

## Corte y cobertura del análisis

La revisión comenzó con el frontend limpio en
`e36f9e5b4a9fd8ef6cd493b494747ce8776a08f0`. El backend tenía como HEAD
`a24bf0b390340ae223d9acfdaa09e7e9ad7ab37f`. El último corte del frontend tiene HEAD
`d7dfc565ae524ff16a2e779a6b100fd64c1cb06a`, con cambios concurrentes del plan I-165.
Se incluyeron sus dos páginas nuevas y las utilidades nuevas en la inspección. Al cierre
seguían modificándose `signature/signPdf.ts` y su prueba: se revisaron sus cambios de
medidas del sello, que son geometría documental. Las comprobaciones reflejan cada
ejecución sobre el workspace compartido y no certifican cambios posteriores.

| Superficie en el inventario final | Cobertura |
| --- | --- |
| Archivos bajo `src/` | 657 |
| Archivos de aplicación, excluyendo pruebas y declaraciones de tipos | 584 |
| Componentes Vue | 445 |
| Páginas | 29 |
| Layouts | 11 |
| Componentes Vue bajo `components/ui` | 222, distribuidos en 41 familias |
| Componentes Vue de dominio | 83 |
| Componentes Vue de AI Elements | 74 |
| Archivos de pruebas unitarias y de componentes | 69 |

Se enumeraron todos los archivos y se analizaron los templates Vue y los scripts
TypeScript. La inspección buscó controles HTML, roles interactivos, colores literales,
clases de paleta, CSS local, estilos dinámicos, muestras, catálogos, URLs, límites
numéricos, imports y renderizadores con `h()`. Las coincidencias se revisaron con
sus consumidores, incluidas las que pertenecen a primitivas o código sin uso.

También se inspeccionaron `package.json`, `package-lock.json`, `components.json`,
ESLint, Vite, los scripts de contrato, las configuraciones de Playwright, las referencias
de pruebas y la configuración pública de arranque. Los logos, iconos y la imagen
`src/01-admin-plantilla.png` son recursos gráficos; esta auditoría no certifica sus
colores de píxel ni una comparación visual en navegador.

El análisis conservador de imports desde `main.ts` alcanzó 445 archivos. De los 139
restantes, varios son tipos o componentes instalados para reutilización futura. Este
número no equivale a 139 archivos de código muerto ni representa el resultado de
tree shaking. Todos los 29 archivos de página estaban incorporados al router.

Como lectura complementaria se revisaron los nuevos `api/library.ts`,
`lib/roles.ts`, `lib/libraryStates.ts` y `lib/signatureVariables.ts`. Usan el cliente
compartido y centralizan etiquetas, códigos y reconocimiento de variables de firma.

## Componentes y colores que ya se comparten

`components.json` identifica shadcn-vue, estilo `new-york-v4`, variables CSS
activadas y alias hacia `src/components/ui`. Las primitivas usan Reka UI y variantes
locales. No se encontró otra biblioteca general de botones o formularios en las páginas.

[styles.css](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/styles.css:11)
centraliza colores de superficies, texto, acción, error, éxito, advertencia, sidebar,
sombras y radio, con `:root`, `.dark` y `@theme inline`. La paleta de la malla
consume variables `--curriculum-*`; sus aristas usan los tokens de acción y error.
Los tokens del documento viven en el mismo archivo.

Las páginas reutilizan `PageFrame`, `LoadingSkeleton`, `LoadErrorState`,
`FormSheet`, `FormSheetActions`, `TableActionsMenu`, `TablePagination`,
`FileDropzone`, `DatePicker` y los componentes de identidad y configuración.
Los formularios y tablas utilizan extensamente `Field`, `Input`, `Select`,
`Textarea`, `Table`, `Badge`, `Dialog`, `Sheet`, `Tooltip` y `Button`.

La documentación de [temas de shadcn-vue](https://www.shadcn-vue.com/docs/theming)
respalda esta organización mediante variables y tokens semánticos. La
[introducción oficial](https://www.shadcn-vue.com/docs/introduction) describe los
componentes como código abierto a composición y personalización. Su HTML interno y
las etiquetas estructurales de una pantalla son parte normal de esa composición.

## Controles que se apartan de las primitivas

Las prioridades siguientes expresan orden de corrección propuesto, no un cambio
aprobado de los requisitos de producto.

| Prioridad | Evidencia confirmada | Propuesta |
| --- | --- | --- |
| Alta | [TeacherSyllabusDetailPage.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/pages/TeacherSyllabusDetailPage.vue:436) usa `window.confirm` cuando falla el guardado al salir de la ruta. | Reutilizar `UnsavedChangesDialog` y su resultado asíncrono. El aviso `beforeunload` al cerrar la pestaña pertenece al mecanismo del navegador reconocido en I-101. |
| Alta | [DocumentSignatureCard.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/syllabus/DocumentSignatureCard.vue:211) conserva un `input type="file"` visible con las clases de borde, altura, tipografía y archivo copiadas dentro del formulario. | Usar el `Input` existente o un selector reutilizable compuesto con él; conservar la limpieza del archivo y los eventos del certificado. |
| Alta | [TeacherSyllabusDetailPage.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/pages/TeacherSyllabusDetailPage.vue:532) y [templateDocumentInput.ts](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/configuration/templateDocumentInput.ts:125) simulan un grupo de radio Sí/No con dos botones, variantes y ARIA manuales. | Compartir un control de dominio construido con `RadioGroup` y `RadioGroupItem`. Los fragmentos revisados no implementan la navegación con flechas ni el foco de un grupo de radio. |
| Media | [NotificationsPopover.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/NotificationsPopover.vue:183) construye cada fila clicable con un `button` HTML y su aspecto local. | Encapsular la fila con `Button` y una variante compartida que admita contenido de varias líneas. |
| Media | [TemplateTableInlineNode.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/configuration/TemplateTableInlineNode.vue:138) usa un botón HTML para abrir las preguntas condicionales del editor. | Componer `PopoverTrigger` con el `Button` local y un tooltip; preservar la tipografía del marcador documental. |
| Media | [CurricularImportReview.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/academic/curricular/CurricularImportReview.vue:143) contiene dos estructuras `details/summary`, para grupos y para evidencia. [SyllabusUnitAssistant.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/ai/SyllabusUnitAssistant.vue:103) contiene otra para citas. | Usar `Collapsible`, `CollapsibleTrigger` y `CollapsibleContent`, ya instalados, con el mismo estado inicial de apertura. |
| Media | [SetupProgress.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/SetupProgress.vue:44) y [SetupChecklist.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/SetupChecklist.vue:53) dibujan barras con spans/divs y ancho porcentual. | Reutilizar `Progress`, ya instalado; centralizar sus variantes de tono si se conservan los colores por avance. |
| Media | [CurricularProjectTable.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/academic/curricular/CurricularProjectTable.vue:48), [TemplateTableInlineNode.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/configuration/TemplateTableInlineNode.vue:138) y [TeacherSyllabusDetailPage.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/pages/TeacherSyllabusDetailPage.vue:457) usan `title` como ayuda nativa. | Usar `Tooltip`. `TableCell` y `Button` propagan el atributo al HTML cuando lo reciben; no son props de título de página. Esto vuelve a incumplir el criterio de I-101 y de pantallas. |
| Media | [CurricularImportSteps.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/academic/curricular/CurricularImportSteps.vue:17) construye los pasos con círculos y conectores propios. | Mantener un componente único de pasos compuesto con primitivas y `Separator`; evaluar `Stepper` si se decide incorporarlo. No está instalado en el inventario revisado. |
| Media | [NotificationsPopover.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/NotificationsPopover.vue:177) y otros formularios presentan errores generales y vacíos con `p` estilizados; el chat construye su vacío con un `div`. | Compartir los estados mediante `Alert`, `Empty` y `FieldError`, según el contexto. Los tres ya están disponibles. |
| Media | [CurriculumCanvas.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/academic/curriculum/CurriculumCanvas.vue:510) monta `Controls` de `@vue-flow/controls`, cuyos botones reciben CSS local. | Conservar el motor aceptado en ADR-0006 y componer los controles visibles con `Button` y `Tooltip` si se exige uniformidad total. |
| Baja | [PurgeConfirmationDialog.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/PurgeConfirmationDialog.vue:26) fuerza `z-[60]` en contenido y overlay. | Resolver el apilamiento en la composición compartida de diálogos, verificando su superposición con un Sheet abierto. |

El inventario de templates encontró 21 elementos de los tipos interactivos buscados
fuera de `components/ui`; su presencia necesita clasificación. Cinco son inputs
ocultos que transportan valores de formulario y dos son selectores de archivos ocultos.
Los botones de descarga de [DocumentsPage.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/pages/DocumentsPage.vue:78)
son hijos `as-child` de `DropdownMenuItem`: la presentación procede de la primitiva.
El `label` de carga de logo también recibe el aspecto mediante `Button as-child`.
Estos casos no equivalen al input visible con clases copiadas de la firma.

[FileDropzone.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/FileDropzone.vue:165)
ya es un componente de dominio compartido y usa `Attachment`, pero su zona activable
es un `div role="button"` con teclado y eventos de arrastre propios. Una exigencia
estricta de primitivas también debería revisar ese disparador; el input oculto es el
mecanismo de selección de archivos.

## Colores y estilos locales

| Evidencia | Evaluación y propuesta |
| --- | --- |
| [RobotAvatar.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/ai/avatar/RobotAvatar.vue:76) | Tiene 16 atributos `fill/stroke` con seis colores hexadecimales distintos, fuera del tema. Centralizar colores como tokens de ilustración en `styles.css`; las animaciones y el dibujo pertenecen a este componente reutilizable. |
| [AppLogo.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/AppLogo.vue:5) | Usa `bg-white`. Si el logo necesita blanco permanente, expresarlo como un token de marca definido para ambos temas. No sustituirlo automáticamente por una superficie oscura. |
| [documentPalette.ts](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/lib/documentPalette.ts:1) y [styles.css](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/styles.css:151) | La paleta documental está centralizada en TypeScript, pero parte de sus colores también se define en CSS. Hay más de una fuente para conceptos como papel, texto y bordes. Unificar su mantenimiento conservando los valores hexadecimales que se serializan en los documentos. |
| [TeacherSyllabusDetailPage.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/pages/TeacherSyllabusDetailPage.vue:469), [SyllabusUnitAssistant.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/ai/SyllabusUnitAssistant.vue:81) y [LoadingSkeleton.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/LoadingSkeleton.vue:357) | La página reserva hasta 28 rem y fija el asistente a 28 rem; el skeleton de `syllabus` reserva 24 rem. El chat fija además alturas de 38 y 30 rem. Compartir dimensiones del layout entre página, asistente y carga. El posible salto visual requiere comprobación en navegador. |
| [TemplateDocumentView.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/configuration/TemplateDocumentView.vue:378), [TemplateTableEditor.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/configuration/TemplateTableEditor.vue:412) y [PaginatedDocument.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/PaginatedDocument.vue:24) | Hay padding de celda `2px 4px` repetido, defaults Arial/11 pt repetidos y estilos documentales de 9 pt. Centralizar parámetros documentales compartidos; mantener el contrato institucional y la configuración recibida del servidor. |
| [curriculumCanvasLayout.ts](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/academic/curriculum/curriculumCanvasLayout.ts:43) y [CurriculumSubjectNode.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/components/domain/academic/curriculum/CurriculumSubjectNode.vue:106) | La geometría del lienzo ya está parcialmente centralizada en constantes, pero la tarjeta conserva ancho y tipografías arbitrarios en su template. Compartir parámetros entre geometría y presentación cuando representen la misma medida. Las coordenadas calculadas no son datos académicos ficticios. |

Fuera de las primitivas hay seis bloques CSS, en `PaginatedDocument`,
`CurriculumCanvas`, `RobotAvatar`, `TemplateDocumentView`, `TemplateTableEditor`
y `FreeTextEditor`. Sus colores CSS consumen variables; sus medidas, tipografía,
selección y animaciones conservan valores locales.

También se encontraron 32 enlaces de estilos dinámicos en templates alcanzables desde
la aplicación. Diez pertenecen a grids de skeleton; otros expresan progreso, posiciones,
medidas de hoja, colores de la paleta y apariencia recibida por API. Su mera presencia
no demuestra un color local ni un dato de negocio fijo.

Los renderizadores `TemplateDocumentView` y `freeTextRender.ts` producen tablas
documentales mediante `h()`; Tiptap produce contenido editable y Vue Flow construye
el lienzo. ADR-0006, ADR-0007 e I-101 respaldan esos motores y la geometría específica.
La propuesta mantiene esos límites y reutiliza shadcn para sus controles de aplicación.

## Datos reales, ejemplos y constantes

No se encontraron usuarios, carreras, asignaturas o métricas de muestra usados como
resultados reales de las páginas. Los valores del Dashboard proceden de
[getDashboard](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/api/dashboard.ts:51);
la malla, programación, usuarios, convocatorias, sílabos, revisiones, documentos,
notificaciones y contenido curricular se cargan por sus clientes API compartidos.
Las páginas comienzan con estados vacíos o nulos y presentan carga y error.

Las nuevas [LibraryReviewsPage.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/pages/LibraryReviewsPage.vue:35)
y [LibraryReviewDetailPage.vue](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/pages/LibraryReviewDetailPage.vue:38)
también consumen la API y reutilizan las primitivas locales. Repiten el mismo formateador
de fecha; el detalle repite la distribución de columnas del detalle de coordinación y
su skeleton, y el historial de comentarios comparte estructura con el sílabo docente.
El vacío de comentarios conserva un `p` local. Conviene compartir esos elementos;
no se detectaron colores literales ni controles HTML propios nuevos en estas páginas.

`signatureVariables.ts` centraliza identificadores y etiquetas institucionales de firma.
Sus textos de respaldo y las medidas del sello son constantes del formato documental;
no representan datos personales de muestra.

| Datos escritos en el cliente | Alcance real | Propuesta |
| --- | --- | --- |
| [templatePreview.ts](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/lib/templatePreview.ts:3): textos por clave, número `2`, fecha `2026-03-01`, respuestas y opciones de ejemplo. | Se consume desde `TemplateVisualBuilder` para previsualizar campos. Esta función crea copias con valores de muestra; no hace una mutación HTTP. | Si se exige retirar todo ejemplo fijo, definir muestras mediante metadata de la plantilla o un generador de preview compartido. Conservar la distinción visible entre muestra y contenido real. |
| [studyModalities.ts](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/lib/studyModalities.ts:1) y [parallelShifts.ts](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/lib/parallelShifts.ts:1). | Son valores fijos del dominio, pero duplican `StudyModality` y `Parallel::SHIFTS` del backend. La malla ya recibe `modalityOptions` desde la API. | Consumir opciones del servidor donde el contrato las ofrezca; ampliar el contrato de forma trazable para los formularios que todavía no las reciben. No se ha demostrado un catálogo inexistente ni un valor inválido. |
| Estados de sílabo en `TeacherSyllabiPage`, `CoordinationReviewsPage`, `CoordinationReviewDetailPage` y `ConvocationTrackingSheet`. | Las etiquetas de los mismos estados están repartidas en varios archivos. | Una utilidad tipada compartida para etiquetas y variantes; preservar los códigos wire y la autoridad del servidor. |
| Límites de 180 caracteres, 4000 caracteres, 30 ciclos o 10 caracteres mínimos, repetidos en formularios. | Reflejan validaciones del backend; varios se repiten también entre script y template. | Compartir constantes de validación del cliente o recibir límites mediante metadata. La validación del servidor continúa siendo obligatoria. |
| [api/client.ts](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/api/client.ts:11): fallback `localhost:8000`. | La configuración pública y `VITE_API_URL` tienen prioridad. El arranque Docker exige `PUBLIC_API_URL`. No identifica una conexión de producción. | Limitar el fallback a desarrollo y hacer explícita una configuración ausente si se decide endurecer esta política. |
| [signature/stamp.ts](/home/giossue/Documentos/proyecto-grado-silabos-frontend/src/lib/signature/stamp.ts:10): URL de validación, fuentes y negro del sello PDF. | Es formato documental emitido, no un color de control de la SPA ni una llamada HTTP de la pantalla. | Tratarlo como configuración del adaptador documental si se necesita cambiar su formato. |

Los placeholders `Ej. ...`, las etiquetas, los mensajes de error, el locale
`es-EC`, las claves de API y los nombres de rutas son contenido o constantes de la
interfaz. Las reglas locales exigen ejemplos en placeholders; retirarlos por una
búsqueda general de textos fijos incumpliría el patrón documentado.

## Código instalado que no entra en la aplicación

En el corte final no se alcanzaron 112 componentes Vue mediante la cadena de
imports: 57 de AI Elements, 47 primitivas, cinco layouts y tres componentes adicionales.
Son candidatos a revisar, no una orden de eliminación.

Las excepciones relevantes para este pedido incluyen:

- `InlineCitationCarouselPrev.vue` y `InlineCitationCarouselNext.vue`: botones
  HTML propios; las primitivas de Carousel ya existen localmente.
- `prompt-input`: input de archivo oculto y textos de interfaz en inglés.
- `AppHeader.vue`: enlaces fijos al repositorio anterior y a Laravel.
- `CurricularOptionsSourceSelect.vue`: componente de dominio sin consumidor.
- `AuthCardLayout`, `AuthSplitLayout` y `AppHeaderLayout`: alternativas de
  layout no utilizadas por el arranque revisado.

La presencia de primitivas aún sin consumir es compatible con una biblioteca local.
Los componentes de dominio o enlaces históricos sin consumidores merecen revisión
antes de volver a incorporarlos.

## Resultados de verificación

| Comprobación | Resultado observado |
| --- | --- |
| `npm run lint` | Aprobada. |
| `npm run typecheck` | Aprobada. |
| `npm test` | 68 archivos aprobados y uno omitido; 266 pruebas aprobadas y una omitida. |
| `npm run contract:check` | Falló: `src/api/academic.ts:21: POST /api/v1/admin/academic/{dynamic}/{dynamic} no existe en OpenAPI`. |
| Navegador, build, exportación real y despliegue | No ejecutados en esta auditoría de código. PV-07 y PV-19 no se cierran. |

La prueba omitida es la firma de extremo a extremo condicionada por
`SIGN_E2E_INPUT`; esta ejecución no certifica un certificado ni un PDF reales.

El fallo de contrato se comprobó contra el código: el consumidor envía un FormData
con `_method=PATCH` por POST, mientras el contrato y la ruta Laravel describen PATCH.
El verificador reconoce el método escrito en el objeto y no esa sobrescritura.
La evidencia apunta a una limitación del verificador; no prueba por sí sola que el
endpoint de actualización esté ausente o falle durante una carga real.

[eslint.config.js](/home/giossue/Documentos/proyecto-grado-silabos-frontend/eslint.config.js:8)
excluye `components/ui` y no incorpora reglas específicas sobre paletas,
`window.confirm`, atributos `title`, controles nativos o dimensiones duplicadas.
Por eso una puerta verde de lint y tipos no garantiza el criterio solicitado.

## Orden de corrección propuesto

1. Sustituir la confirmación de salida, el input visible de firma y los grupos Sí/No.
2. Migrar botones propios, desplegables, ayudas y barras a las primitivas existentes.
3. Centralizar colores del avatar y logo; compartir dimensiones y estilos documentales.
4. Unificar catálogos, etiquetas de estados, límites de validación y muestras de preview.
5. Añadir controles de arquitectura sobre excepciones de UI, con una lista explícita
   para motores documentales, composición `as-child` y mecanismos del navegador.
6. Verificar por flujo teclado, claro/oscuro, móvil, guardado fallido, selección de
   certificado y malla; corregir la limitación concreta del verificador de contrato.

Este trabajo añadió únicamente el plan e informe de auditoría. Las modificaciones
funcionales detectadas durante la revisión pertenecen al trabajo concurrente I-165.
La matriz de trazabilidad no cambia porque esta auditoría no altera comportamiento.
