# Estado de aceptación

## Cómo leer este documento

El repositorio enumera `CP-F01..CP-F35` y `CP-N01..CP-N16`, pero no contiene el
enunciado de cada caso. Por eso no se asigna un resultado a cada número. Las tablas
acreditan capacidades contra RF/RNF, CU, pantallas y pruebas que existen. La matriz
completa está en `traceability.md`.

Estados:

- **Verificado automático**: hay una prueba reproducible que pasa.
- **Verificado técnico**: hay un script o prueba local, pero no aceptación institucional.
- **Pendiente manual**: necesita una persona, un dispositivo o una inspección visual.
- **Bloqueado PV**: una autoridad o una evidencia externa debe resolverlo.

## Evidencia funcional

| Capacidad | IDs | Evidencia | Estado |
|---|---|---|---|
| Acceso, cuentas y roles | RF-001..007; CU-01, CU-02; UI-01..04, ADM-02, ADM-03, COR-15 | `ApiSessionTest`, `SecurityHardeningTest`, `ManagedUserTest`, `ManagedUserUpdateTest`, `CareerTeacherTest`, `SpreadsheetImportTest` | Verificado automático |
| Estructura académica | RF-008..016; CU-03; ADM-04, COR-13, COR-14 | `AcademicStructureTest`, `CoordinatorReplacementTest`, `InstitutionalLogosTest`, `ParallelTeacherChangeTest` | Verificado automático |
| Plantilla como datos | RF-017..026; CU-04; ADM-06 | `TemplateDefinitionTest`, `OfficialTemplateTest`, `TemplateStructureApiTest`, `DocumentBlockTest`, `TemplateGoldenWordTest` | Verificado automático; fidelidad bloqueada PV-07 |
| Proyecto de carrera | RF-027..033; CU-05; COR-16 | `SubjectProfileTest`, `FichaImportTest`, `FichaPdfOcrTest`, `OpenParserPdfReaderTest`, `FichaEvidenceTest`, `FichaFieldValuesTest`, `ExtractFichaProposalTest`; `FichaImportDialog.test.ts`, `tests/e2e/ficha-import.spec.ts` | Verificado automático con datos sintéticos y proveedor simulado; cobertura y tiempo con los tres documentos originales requieren volver a importarlos; aceptación de IA bloqueada PV-14/PV-18 |
| Convocatoria, borrador y validación | RF-034..045; CU-06, CU-07, CU-09; COR-01, DOC-01..07 | `SyllabusProcessTest`, `ConvocationScheduleTest`, `ConvocationAndDraftTest` | Verificado automático; volumen pendiente PV-05 |
| Revisión y aprobación | RF-055..065; CU-10..CU-14; COR-05..10, BIB-01..03, DOC-08 | `ReviewWorkflowTest`, `LibraryReviewTest` | Verificado automático; motivos de Biblioteca por validar |
| Firmas, Word y PDF | RF-066..069; CU-15; DOC-10 | `DocumentSignatureTest`, `SignatureTrustTest`, `DocumentOperationsTest`, `WordRendererTest` | Verificado automático; entidades certificadoras pendientes (DT-16); fidelidad PV-07 |
| Panel, avisos, auditoría y ayuda | RF-070..074; CU-16, CU-17; UI-03, ADM-10, ADM-13 | `ApiDashboardTest`, `NotificationLinksTest`, `DocumentOperationsTest`, `ScreenHelpTest` | Verificado automático; correo real PV-15 |
| Chat de IA | RF-046..054; CU-08; DOC-06; IA-NEG-01..09 | `AiAssistanceTest`, `HostedAiChatGatewayTest` | Verificado automático con proveedor simulado; evaluación experta bloqueada PV-14/PV-18 |
| Interfaz (SPA) | UI-01..04 y pantallas de los cuatro roles | Pruebas Vitest del repositorio frontend (ver `traceability.md`) | Verificado automático; inspección visual y asistiva pendiente PV-19 |

## Evidencia no funcional

| Área | Evidencia | Estado y límite |
|---|---|---|
| Calidad de código backend | `composer verify`: búsqueda de secretos, Pint, Larastan nivel 7 y la suite sobre PostgreSQL | Verificado automático |
| Calidad de código frontend | `npm run lint`, `npm run typecheck`, `npm test` y `npm run build` pasan; `npm run contract:check` falla por dos rutas variables de importación Excel | Verificado automático con el fallo de contrato previo registrado en `pendientes.md` |
| Arquitectura | `ModuleBoundariesTest`, `ApiBoundaryTest`, `OpenApiContractTest` | Verificado automático para las reglas escritas |
| Seguridad HTTP | `HealthAndSecurityTest`, `AuthHardeningTest`, `SecurityHardeningTest` | Verificado automático; pentest manual pendiente |
| Dependencias | `composer audit` y `npm audit`, a mano. No hay CI | Verificado técnico; hay que repetirlo antes de cada entrega |
| PostgreSQL | `DatabaseBootstrapTest`, `SpanishSchemaTest`, constraints y triggers en las pruebas de cada módulo | Verificado automático |
| Redis y colas | `RedisConnectionTest`, `JobDispatchTest`, `JobIdempotencyTest` | Verificado automático; incluye los trabajos de la ficha y una ventana de reentrega mayor que su timeout; variable remota pendiente de comprobar |
| Recuperación | `composer verify:restore` sobre un dump sintético y un clúster efímero | Verificado técnico; política bloqueada PV-11 |
| Rendimiento | `composer benchmark:readiness` contra `/health/ready` en loopback | Prueba del script; no acepta RNF-011..015 (PV-05) |
| Accesibilidad real | Teclado, lector, foco, contraste, 360 px, zoom 200 %, claro y oscuro | Pendiente manual (PV-19) |
| Fidelidad documental | `TemplateGoldenWordTest` compara el Word con referencias | Comparación visual con el documento oficial pendiente (PV-07) |

La propuesta de importación se revisó en Chromium local a 360, 768 y 1440 px, en claro
y oscuro, con apertura por teclado, tabla de cinco columnas, campos pendientes y
acciones visibles sin desbordamiento global (`tests/e2e/ficha-import.spec.ts`). Las
capturas de 360 px se generan en `test-results/`, sin datos reales. Esta evidencia no
sustituye la revisión con lector de pantalla ni otros navegadores y dispositivos (PV-19).

## Series CP

| Serie | Qué se puede afirmar | Qué no se puede afirmar |
|---|---|---|
| CP-F01..CP-F35 | Las capacidades de CU-01..17 tienen pruebas agrupadas arriba. | Que cada CP-F esté aprobado, sin su enunciado oficial. |
| CP-N01..CP-N16 | Hay evidencia técnica de seguridad, calidad, PostgreSQL, Redis, restauración y un script de carga. | Que cada CP-N y sus umbrales estén aceptados, sin enunciados, PV-05, PV-11 y pruebas manuales. |
| IA-NEG-01..IA-NEG-09 | `AiAssistanceTest` y `HostedAiChatGatewayTest` cubren citas inventadas, servicio caído, límites, alcance y configuración insegura del chat. | Que sustituyan la evaluación experta del modelo. |

Para cerrar esto hay que incorporar la fuente maestra de CP sin renumerarla y agregar
una fila `CP → requisito → prueba → resultado → evidencia → responsable` por caso.

## Puertas institucionales

Las decisiones abiertas y su valor provisional están en `docs/plans/pendientes.md`. Las
que bloquean la aceptación son:

| PV | Qué falta |
|---|---|
| PV-01, PV-07 | Autoridad de la plantilla y Word oficial |
| PV-03, PV-04 | Período del piloto y responsable de la aceptación |
| PV-05 | Volumen real de docentes, paralelos y sílabos |
| PV-11, PV-12 | Respaldo, RPO/RTO, base legal y aviso de privacidad |
| PV-14, PV-18 | Modelo de IA y umbrales de utilidad |
| PV-15 | Correo institucional |
| PV-16 | Edición excepcional por Coordinación (hoy el servidor la niega) |
| PV-17, PV-19, PV-20 | Instrumento de medición, dispositivos reales y metadatos académicos |

## Acta mínima pendiente

La persona responsable de la aceptación debe registrar, en un ambiente autorizado:

1. commit y configuración no sensible;
2. fecha, rol y participantes con consentimiento;
3. enunciado de cada CP-F/CP-N y resultado esperado;
4. resultado, evidencia, severidad y decisión por caso;
5. navegadores y dispositivos, teclado, lector, contraste, zoom y documento oficial;
6. carga con datos representativos: rutas, autoguardado y exportación;
7. restauración de PostgreSQL y de los archivos privados contra el RPO/RTO aprobado;
8. evaluación experta de la IA y cierre de cada PV aplicable.

Hasta entonces el sistema es un **candidato técnico verificable**, no un producto
aceptado por la institución ni autorizado para datos reales.
