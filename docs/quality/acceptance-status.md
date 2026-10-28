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
| Plantilla como datos | RF-017..026; CU-04; ADM-06 | `TemplateDefinitionTest`, `OfficialTemplateTest`, `TemplateStructureApiTest`, `DocumentBlockTest`, `TemplateGoldenWordTest` | Verificado automático; revisión visual del Word pendiente |
| Proyecto de carrera | RF-027..033; CU-05; COR-16 | `SubjectProfileTest`, `FichaImportTest`, `FichaFieldValuesTest`; `FichaImportDialog.test.ts`, `tests/e2e/ficha-import.spec.ts`; reproducción local de Excel y tipos/estilo | Flujo exclusivamente Excel con vista previa en memoria y relectura al aplicar; nueva migración elimina `importaciones_ficha`. Lectura local anterior verificada con seis campos y dos materias de ejemplo. Aceptación con Excel real y recorrido de usuario pendiente. Pruebas existentes adaptadas; suite automática no ejecutada en esta limpieza. |
| Convocatoria, borrador y validación | RF-034..045; CU-06, CU-07, CU-09; COR-01, DOC-02..07 | `SyllabusProcessTest`, `ConvocationScheduleTest`, `ConvocationAndDraftTest` | Verificado automático; volumen pendiente PV-05 |
| Revisión y aprobación | RF-055..065; CU-10..CU-14; COR-05..10, BIB-02, BIB-03, DOC-08 | `ReviewWorkflowTest`, `LibraryReviewTest` | Verificado automático; motivos confirmados por Biblioteca |
| Firmas, Word y PDF | RF-066..069; CU-15; DOC-10 | `DocumentSignatureTest`, `SignatureTrustTest`, `DocumentOperationsTest`, `WordRendererTest` | Verificado automático; entidades certificadoras pendientes (DT-16); revisión visual pendiente |
| Panel, avisos, auditoría y ayuda | RF-070..074; CU-16, CU-17; UI-03, ADM-10 | `ApiDashboardTest`, `NotificationLinksTest`, `DocumentOperationsTest`, `ScreenHelpTest` | Verificado automático; correo real PV-15 |
| Chat de IA | RF-046..054; CU-08; DOC-06; IA-NEG-01..09 | `AiAssistanceTest`, `HostedAiChatGatewayTest` | Verificado automático con proveedor simulado; evaluación experta con DeepSeek bloqueada PV-18 |
| Interfaz (SPA) | UI-01..04 y pantallas de los cuatro roles | Pruebas Vitest del repositorio frontend (ver `traceability.md`) | Verificado automático; inspección visual y asistiva pendiente PV-19 |

## Evidencia no funcional

| Área | Evidencia | Estado y límite |
|---|---|---|
| Calidad de código backend | `composer verify`: búsqueda de secretos, Pint, Larastan nivel 7 y la suite sobre PostgreSQL | Verificado automático |
| Calidad de código frontend | `npm run lint`, `npm run typecheck`, `npm test`, `npm run contract:check` y `npm run build` pasan | Verificado automático |
| Arquitectura | `ModuleBoundariesTest`, `ApiBoundaryTest`, `OpenApiContractTest` | Verificado automático para las reglas escritas |
| Seguridad HTTP | `HealthAndSecurityTest`, `AuthHardeningTest`, `SecurityHardeningTest` | Verificado automático; pentest manual pendiente |
| Dependencias | `composer audit` y `npm audit`, a mano. No hay CI | Verificado técnico; hay que repetirlo antes de cada entrega |
| PostgreSQL | `DatabaseBootstrapTest`, `SpanishSchemaTest`, constraints y triggers en las pruebas de cada módulo | Verificado automático |
| Colas y caché en PostgreSQL | `QueueAndCacheInPostgresqlTest`, `JobDispatchTest`, `JobIdempotencyTest` | Verificado automático; incluye los trabajos de la ficha y una ventana de reentrega mayor que su timeout; variable remota pendiente de comprobar |
| Recuperación | `composer verify:restore` sobre un dump sintético y un clúster efímero | Verificado técnico; política bloqueada PV-11 |
| Rendimiento | `composer benchmark:readiness` contra `/health/ready` en loopback | Prueba del script; no acepta RNF-011..015 (PV-05) |
| Accesibilidad real | Teclado, lector, foco, contraste, 360 px, zoom 200 %, claro y oscuro | Pendiente manual (PV-19) |
| Fidelidad documental | `TemplateGoldenWordTest` compara el Word con referencias | Comparación visual del Word y del PDF generados pendiente |

La propuesta de importación se revisó en Chromium local a 360, 768 y 1440 px, en claro
y oscuro, con apertura por teclado, tabla de cinco columnas, campos pendientes y
acciones visibles sin desbordamiento global (`tests/e2e/ficha-import.spec.ts`). Las
capturas de 360 px se generan en `test-results/`, sin datos reales. Esta evidencia no
sustituye la revisión con lector de pantalla ni otros navegadores y dispositivos (PV-19).

## Cambio del 26 de octubre de 2026: respuestas protegidas

Se implementó la separación entre formato y respuestas en API, ficha, Excel y sílabo.
La nueva plantilla oficial separa nombres/resultados de unidades y planificación
semanal. Se adaptaron pruebas y datos existentes sin añadir ni ejecutar casos nuevos;
PHPStan, TypeScript, estilo y contrato generado se revisan por separado. Las referencias
Word anteriores siguen cubriendo el formato anterior. La ejecución de pruebas y la
aceptación visual del formato nuevo, sus filas, el Excel y el Word/PDF están pendientes.
No debe interpretarse la evidencia anterior como verificación automática de este cambio.

La activación en `silabos_ueb_db` conservó los 16 valores de ficha y los IDs de los
campos anteriores; quedaron 20 campos, siete de Coordinación y 32 tablas. Se verificó
un respaldo antes de la transacción y los permisos después. La convocatoria sigue
pausada y no hay sílabos. El detalle de la activación está en ADR-0012.

Pasaron PHPStan, Pint, TypeScript, ESLint de los archivos cambiados, el escaneo de
secretos y `git diff --check`; se regeneró OpenAPI. El chequeo directo del contrato
frontend sigue señalando las dos rutas dinámicas previas de `spreadsheet-import.ts`
(líneas 12 y 16), ajenas a este cambio. No se ejecutaron suites ni builds.

### Corrección del mismo día: unidades con el formato oficial

La sección 6 vuelve a una tabla por unidad. El nombre y el resultado vienen de la
ficha (`from`) y el campo de Carrera no se imprime aparte. Pasan la suite completa del
backend (471 pruebas, en paralelo), PHPStan, Pint, y en el frontend TypeScript, ESLint
y Vitest (416 pruebas, una omitida). El Word oficial coincide de nuevo con las
referencias del formato original. Se corrigieron siete pruebas que la primera versión
dejó sin ajustar. La activación en la base la hace la migración `000230` al
desplegar; falta comprobarla en el sitio, junto con la aceptación visual.


## Series CP

| Serie | Qué se puede afirmar | Qué no se puede afirmar |
|---|---|---|
| CP-F01..CP-F35 | Las capacidades de CU-01..17 tienen pruebas agrupadas arriba. | Que cada CP-F esté aprobado, sin su enunciado oficial. |
| CP-N01..CP-N16 | Hay evidencia técnica de seguridad, calidad, PostgreSQL, restauración y un script de carga. | Que cada CP-N y sus umbrales estén aceptados, sin enunciados, PV-05, PV-11 y pruebas manuales. |
| IA-NEG-01..IA-NEG-09 | `AiAssistanceTest` y `HostedAiChatGatewayTest` cubren citas inventadas, servicio caído, límites, alcance y configuración insegura del chat. | Que sustituyan la evaluación experta del modelo. |

Para cerrar esto hay que incorporar la fuente maestra de CP sin renumerarla y agregar
una fila `CP → requisito → prueba → resultado → evidencia → responsable` por caso.

## Puertas institucionales

Las decisiones abiertas y su valor provisional están en `docs/plans/pendientes.md`. Las
que bloquean la aceptación son:

| PV | Qué falta |
|---|---|
| PV-03, PV-04 | Período del piloto y responsable de la aceptación |
| PV-05 | Volumen real de docentes, paralelos y sílabos |
| PV-11, PV-12 | Respaldo, RPO/RTO, base legal y aviso de privacidad |
| PV-18 | Umbrales de utilidad de la IA (el modelo es DeepSeek) |
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
