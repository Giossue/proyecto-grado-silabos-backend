# FE-SHADCN-2026-09-29: Componentes, tokens y datos compartidos

## Estado y autorización

Corrección de los hallazgos frontend completada y verificada el 2026-09-29.
El responsable pidió «ok arregla todo» después del informe
AUD-FE-2026-09-29. Se corrigieron sus hallazgos en ambos repositorios cuando el contrato
de servidor fue necesario. No se publica ni se altera una base de producción.

## Trazabilidad

- RF-001..026/034..075, CU-01..04/06..18: formularios y superficies vigentes.
- RNF-018..023/029..034: consistencia, accesibilidad y mantenibilidad.
- UI-01..04; ADM-01..07/09..12; COR-01..10/13..16; DOC-01..10; BIB-01..03.
- RN-001..034: conservar alcance, permisos, fotografías e inmutabilidad.
- CP-F y CP-N de formularios, navegación, estructura académica y accesibilidad.
- I-10/I-13/I-101: tokens semánticos, primitivas y confirmación compartida.
- ADR-0006/0007: motores del lienzo y del documento, con controles shadcn.
- PV-07/PV-19: no se presenta una comprobación automatizada como aceptación real.
- PV-16 no se resuelve ni se amplían permisos. La tarea no depende de decisiones
  académicas por validar.

## Objetivo y aceptación

- Las acciones y controles de producto se componen con las primitivas locales.
- No quedan confirmaciones de ruta nativas, tooltips `title`, controles duplicados,
  colores literales locales ni muestras de datos institucionales fijas.
- Dimensiones comunes, estilo documental, etiquetas y validación se mantienen en
  fuentes compartidas; las opciones del dominio provienen del servidor.
- Se conservan la geometría dinámica documental y curricular, los mecanismos
  ocultos de formulario y el aviso de cierre de pestaña autorizados en I-101.
- Lint previene regresiones concretas y el verificador de contrato comprende la
  actualización multipart sin omitir la verificación del endpoint PATCH.
- El código, sus pruebas, el contrato y la documentación permanecen sincronizados.

## Pasos

- [x] Revisar estado concurrente, reglas de interfaz y APIs de primitivas locales.
- [x] Corregir controles, navegación sin guardar, tooltips y estados compartidos.
- [x] Centralizar tokens, layouts, parámetros documentales y geometría compartida.
- [x] Sustituir ejemplos fijos y compartir catálogos, etiquetas, fechas y límites.
- [x] Revisar componentes inactivos y prevenir regresiones con reglas de lint.
- [x] Reparar el verificador multipart y sincronizar OpenAPI si cambia el contrato.
- [x] Ejecutar verificaciones frontend y servidor aplicables y revisión en navegador.
- [x] Actualizar trazabilidad e informe con evidencia final.

## Cambios implementados

SPA: componentes locales y de dominio, páginas, utilidades, tema y reglas de ESLint.
Servidor: metadata de opciones existentes si no está disponible para sus consumidores,
sin cambios de esquema. Documentación: plan, arquitectura frontend y trazabilidad.

## Riesgos y reversión

Revisar teclado en grupos de elección, descarte con guardado fallido, selección de
certificado, interacción de desplegables, diálogos superpuestos y zoom del lienzo.
Preservar la paleta que se serializa en documentos, las medidas institucionales y los
cambios concurrentes de firma. La reversión consiste en retirar este cambio coherente
de archivos; no se ejecutan migraciones, rollbacks ni operaciones remotas.

## Correcciones y alcance final

| Hallazgo auditado | Corrección implementada |
| --- | --- |
| Confirmación nativa al fallar el guardado | `UnsavedChangesDialog` y `useDiscardConfirmation`, con casos de continuar y descartar. |
| Sí/No y filtros con ARIA manual | `BooleanChoice`/`ChoiceGroup` sobre `RadioGroup`; flechas, selección y foco verificados en Chromium. |
| Selector de certificado con estilo duplicado | `Input` local con soporte de archivo y limpieza; prueba de cancelar y volver a seleccionar archivo/clave. |
| Botones HTML, ayuda `title`, filas y acciones de menú | `Button`, `Tooltip`, variantes comunes y `DropdownMenuItem`; también se compone con `Button` la selección de título documental. |
| Evidencia con `details/summary` | `EvidenceDisclosure` y grupos `Collapsible`; se conservan apertura inicial y evidencia consultable. |
| Barras y pasos propios | `Progress` con tonos compartidos y `StepProgress` con `Badge`/`Separator`. |
| Errores, vacíos e historiales duplicados | `FeedbackAlert`, `EmptyState`, `FieldError`, `Empty` y `CommentEntry`. |
| Controles y CSS locales del lienzo | `CurriculumNavigationControls` con `Button`/`Tooltip`; se retira `@vue-flow/controls`, se comparte ancho/zoom y se conserva la geometría dinámica. |
| Apilamiento particular de confirmación | Capa de confirmación en `DialogContent` y tokens globales; hoja, confirmación y retorno de foco probados. |
| Paleta del avatar/logo y duplicación documental | Tokens en `styles.css`; `documentPalette.ts` serializa e inicializa variables CSS y `documentDefaults.ts` comparte valores documentales. |
| Dimensiones distintas entre página y skeleton | `WorkspaceLayout` y tokens comunes para sílabo, revisión y asistente; medidas y ausencia de overflow verificadas en tres anchos y ambos temas. |
| Fechas y datos institucionales de ejemplo | Previsualización desde metadata con valores vacíos; `dateDisplay` evita desplazar fechas civiles al día anterior. |
| Catálogos frontend duplicados | Modalidades y jornadas desde `AcademicStructureViewData`, `StudyModality` y `Parallel::shiftOptions`; OpenAPI regenerado mediante el comando. |
| Etiquetas, límites y formato repetidos | Utilidades compartidas de estados, validación, fecha y locale; servidor conserva la validación y autoridad de dominio. |
| Componentes inactivos con controles alternativos | Carrusel de citas y prompt usan primitivas y textos contextualizados; se retiran `AppHeader`, `Breadcrumbs` y `AppHeaderLayout` después de verificar imports. Las primitivas instaladas para reutilización futura se conservan. |
| URL local implícita en producción | URL obligatoria fuera de desarrollo; fallback local limitado a `import.meta.env.DEV`. |
| Ausencia de prevención estática | Reglas `shared-components` y `semantic-styles`, verificadas antes de ESLint; incluyen templates, `h()`, strings y colores CSS locales. |
| Falso positivo de contrato multipart | Verificador reconoce `_method` en el mismo cuerpo y bloque y mantiene la comprobación de PATCH; tres casos de regresión. |

Se preservan permisos, datos de API, aprobación y firmas de los cambios concurrentes
I-165. No se modifica el esquema. La paleta serializada, la geometría SVG/documental,
los motores Tiptap/Vue Flow, campos ocultos y `beforeunload` conservan su función
conforme a I-101 y ADR-0006/0007. Los textos de interfaz y las constantes centrales
son configuración de presentación; no sustituyen datos institucionales reales.

## Verificación y evidencia

Corte local del 2026-09-29; los repositorios siguieron recibiendo cambios concurrentes.
No se ejecutó commit, push ni despliegue desde esta tarea.

| Comando o revisión | Resultado |
| --- | --- |
| `npm run lint` | Pasa: cuatro casos de las reglas de arquitectura y ESLint/accesibilidad del frontend. |
| `npm run typecheck` | Pasa; `npm run build` vuelve a ejecutar `vue-tsc --noEmit` en el corte final. |
| `npm test` | Corte final: 271 satisfactorias, una omitida y cero fallos; 70 archivos registrados. La omitida requiere `SIGN_E2E_INPUT` y certificado real. |
| `npm run contract:check` | Pasa: tres regresiones del verificador y 107 consumidores coinciden con 118 operaciones OpenAPI. |
| `npm run build` | Pasa sobre el código final, después de tipos, pruebas, lint y contrato. |
| `VITE_API_URL=http://127.0.0.1:8000 npm run test:e2e -- tests/e2e/frontend-components.spec.ts tests/e2e/curriculum-drag.spec.ts` | Siete casos satisfactorios en Chromium: teclado, evidencia, adjuntos, capas/foco, zoom, pad, arrastre satisfactorio/fallido y dimensiones. Se usan respuestas interceptadas y datos sintéticos; no se accede a producción. |
| Revisión de layouts | Sílabo y skeleton mantienen ancho común a 360/768/1440 px y medidas completas comunes en escritorio; sin desbordamiento global en claro y oscuro. |
| Comprobación de entorno de servidor | Conexión explícita a `silabos_ueb_test` mediante loopback:55432 y Redis local:56379 con `PONG`; no se usa `.pgpass`. |
| `composer verify` | Secretos, Pint y PHPStan pasan. De 387 pruebas, 385 pasan; una falla y otra genera error por las incidencias existentes de I-164/I-78 descritas debajo. |
| `php artisan test --filter='AcademicStructureTest\|OpenApiContractTest'` | 26 satisfactorias y 456 aserciones: metadata, dominio académico y OpenAPI sincronizado. |
| `git diff --check` | Pasa en ambos repositorios. |

La puerta general del backend **no está completamente verde**. `TemplateJsonMigrationTest`
todavía presupone `documento_plantilla`, retirada por la normalización irreversible
I-164. `SpanishSchemaTest` detecta ocho columnas genéricas en sus dos tablas nuevas.
Se conservaron esas migraciones y pruebas y se registró el trabajo en
`docs/plans/pending-work.md`; sus decisiones corresponden a I-164/I-78, no al contrato
aditivo de opciones de esta corrección. La verificación específica de este contrato
pasa. Este cierre frontend no certifica una release general de backend.

PV-07/PV-19 siguen abiertos para fidelidad de documentos y dispositivos reales; las
pruebas locales no se presentan como aceptación institucional. El certificado real
no se utiliza en esta tarea. Arquitectura, trazabilidad, informe original y este plan
quedan sincronizados con la implementación y con esos límites explícitos.
