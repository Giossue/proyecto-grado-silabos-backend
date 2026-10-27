# Trazabilidad

Esta matriz une cada capacidad vigente con sus requisitos y con las pruebas que la
demuestran. Describe el sistema de hoy. Las decisiones están en `DECISIONS_STATUS.md` y
lo que falta decidir, en `docs/plans/pendientes.md`.

## Cadena

```text
Necesidad / hallazgo
  → RF o RNF
  → RN y CU
  → UI / contrato / entidad
  → CP y prueba automatizada/manual
  → evidencia de ejecución
```

## De dónde salen los IDs

- SRS v0.1: `RF-001..075`, `RNF-001..036`, `RN-001..034` y `PV-01..20`.
- Casos de uso `CU-01..17`: `docs/product/use-cases.md`. CU-18 está retirado.
- Pantallas `UI`, `ADM`, `COR`, `DOC` y `BIB`: `docs/product/screens.md`.
- Plan maestro de pruebas: `CP-F01..35`, `CP-N01..16` e `IA-NEG-01..09`. Sus enunciados
  no están en el repositorio (ver `acceptance-status.md`).

Esta documentación no renumera los IDs formales.

| Capacidad | RF | CU |
|---|---|---|
| Identidad y acceso | RF-001..007 | CU-01, CU-02 |
| Estructura académica | RF-008..016 | CU-03 |
| Plantilla | RF-017..026 | CU-04 |
| Proyecto de carrera (ficha e importación) | RF-027..033, en su alcance actual | CU-05 |
| Convocatorias y elaboración | RF-034..045 | CU-06, CU-07, CU-09 |
| Chat de IA | RF-046..054, en su alcance actual | CU-08 |
| Revisión y aprobación | RF-055..065 | CU-10..CU-14 |
| Firmas, documentos y operación | RF-066..074 | CU-15..CU-17 |

| Grupo no funcional | RNF |
|---|---|
| Seguridad y privacidad | RNF-001..010 |
| Rendimiento, disponibilidad y colas | RNF-011..017 |
| Compatibilidad, accesibilidad y UX | RNF-018..023 |
| Integridad, recuperación y trazabilidad | RNF-024..028 |
| Mantenibilidad, despliegue, observabilidad e IA | RNF-029..036 |

Las reglas `RN-001..034` viven en la SRS. Sus invariantes de mayor impacto están en
`docs/product/domain-model.md` y `docs/product/syllabus-lifecycle.md`.

## Matriz vigente

Las pruebas backend están en `tests/` de este repositorio. Las que terminan en `.test.ts`
están en el repositorio frontend (Vitest). `Clase::método` señala la prueba exacta;
`Clase` sola, el archivo completo.

| Capacidad | IDs | Evidencia | PV abiertos |
|---|---|---|---|
| Acceso, sesión y segundo factor | RF-001, RF-002, RF-007; CU-01; UI-01, UI-02, UI-04 | `ApiSessionTest`, `SecurityHardeningTest`, `PasswordResetTest`, `RegistrationTest`, `AuthenticationMailTest`, `ApiBoundaryTest`; `AuthFlowPages.test.ts`, `ManageTwoFactor.test.ts`, `router.test.ts` | PV-15 |
| Cuentas, roles e importación con Excel | RF-003..006; CU-02; ADM-02, ADM-03, COR-15 | `ManagedUserTest`, `ManagedUserUpdateTest`, `CareerTeacherTest` (no se quita un docente de la carrera con su convocatoria en curso), `SpreadsheetImportTest::test_admin_previews_then_imports_users_in_one_transaction`, `SpreadsheetImportTest::test_coordinator_imports_teachers_and_others_cannot`; `ManagedUserSheet.test.ts`, `ManagedUserDetailSheet.test.ts`, `TeacherAssignmentsPanel.test.ts` | — |
| Facultades, carreras y coordinación | RF-008..016; CU-03; ADM-04 | `AcademicStructureTest`, `CoordinatorReplacementTest`, `CoordinatorAssignmentConstraintTest`, `InstitutionalLogosTest`, `SpreadsheetImportTest::test_admin_imports_careers_all_or_nothing`; `AcademicGovernancePage.test.ts` | — |
| Períodos y convocatorias | RF-034..036; CU-06; ADM-12, COR-01, COR-04 | `SyllabusProcessTest` (cerrar la convocatoria; eliminar borra de verdad si no hay sílabos enviados y con sílabos enviados solo se puede cerrar; el inicio puede ser hoy aunque UTC ya haya pasado al día siguiente), `ConvocationStateMigrationTest` (las convocatorias ocultas con historial pasan a cerradas y las vacías se borran), `AcademicStructureTest::test_a_period_with_a_convocation_cannot_be_deleted_and_says_why`, `AcademicStructureTest::test_a_period_with_a_convocation_is_locked_with_its_reason`, `LibraryReviewTest::test_una_convocatoria_con_silabos_enviados_no_se_elimina_y_biblioteca_la_sigue_viendo`, `ParallelTeacherChangeTest::test_a_new_teacher_never_erases_the_syllabi_of_a_closed_process_and_history_keeps_its_author`, `ConvocationScheduleTest` (el envío sigue abierto el día de entrega y cierra al final del día institucional; una prórroga no puede fijar un día ya pasado), `ScheduleAndTransferTest`, `AcademicPeriodWeeksTest`, `ConvocationAndDraftTest::test_opening_lists_every_blocker_at_once`; `AdminProcessesPage.test.ts`, `SyllabusProcessActions.test.ts`, `ConvocationRowActions.test.ts`, `ConvocationPeriodSheet.test.ts`, `ConvocationOpeningDialog.test.ts`, `DeadlineExtensionSheet.test.ts`, `ConvocationDeadlineCard.test.ts` | PV-03, PV-05 |
| Fecha de la institución | RF-034..036, RF-070; CU-06, CU-16 | `ApiSessionTest::test_the_session_publishes_the_institutional_day_even_when_utc_moved_to_the_next_day`, `SyllabusProcessTest::test_the_start_can_be_today_even_when_utc_already_moved_to_the_next_day`, `SyllabusProcessTest::test_yesterday_is_rejected_when_utc_already_moved_to_the_next_day`, `ConvocationScheduleTest::test_a_teacher_can_submit_on_the_delivery_day_even_when_utc_already_moved_on`, `ConvocationScheduleTest::test_the_delivery_day_closes_at_the_end_of_the_institutional_day`, `ConvocationScheduleTest::test_an_extension_cannot_target_a_day_that_already_passed`, `ConvocationDatesMigrationTest`, `ApiDashboardTest::test_coordinator_dashboard_uses_the_current_period_and_requires_every_parallel_to_have_a_teacher`; `useInstitutionDate.test.ts`, `SyllabusProcessSheet.test.ts` | — |
| Malla, relaciones y ayuda durante la edición | RF-008..016; RNF-018..023; CU-03; COR-13 | `AcademicStructureTest`, `SpreadsheetImportTest::test_coordinator_imports_the_curriculum_with_its_requirements_all_or_nothing`, `SyllabusProcessTest::test_cosmetic_curriculum_changes_refresh_the_card_without_purging`; `CurriculumCanvas.test.ts`, `CurriculumActions.test.ts`, `CoordinationAcademicStructurePage.test.ts`; tooltips de conexión y ayuda disponible en el editor | — |
| Materias, paralelos y docentes | RF-008..016; RNF-018..023, RNF-024..028; CU-03; COR-14, COR-15 | `AcademicStructureTest` (creación con docente obligatorio, cuenta activa y correo verificado, asignación y auditoría atómicas; reversión si el docente pertenece a otra carrera), `CareerTeacherTest`, `ParallelTeacherChangeTest`, `ParallelSnapshotTest`; `ScheduledSubjectsTab.test.ts`, `ParallelCreationSheet.test.ts` (docente obligatorio al crear, búsqueda al editar y guardado al confirmar), `TeacherSelect.test.ts` (búsqueda sin distinguir tildes ni mayúsculas, teclado y sin resultados), `ParallelBulkActions.test.ts` (creación masiva con jornada y docente obligatorios), `ConvocationAndDraftTest::test_a_subject_that_does_not_generate_syllabi_is_ignored_when_opening`, `SyllabusProcessTest::test_turning_off_syllabus_generation_removes_the_subject_syllabi_and_turning_it_on_creates_them`; `CurriculumVisualSubjectForm.test.ts`, `ScheduledSubjectsTab.test.ts` (materias sin sílabo) | PV-05 |
| Proyecto de carrera: ficha, Excel estructurado con botón de importación verde consistente e interruptor «El docente puede editarlo» | RF-027..033; CU-05; COR-16 | `SubjectProfileTest`, `FichaImportTest` (Excel; adaptado sin ejecutar en esta limpieza), `FichaFieldValuesTest`, `ConvocationAndDraftTest`, `SyllabusProcessTest`; `FichaImportDialog.test.ts`, `SubjectProfileSheet.test.ts`, `tests/e2e/ficha-import.spec.ts`. Reproducción local de generación y lectura: seis campos por materia, tabla de cinco columnas, identificaciones y contenido protegido, rechazo de fórmulas, otra carrera y estructura desactualizada; ver `docs/architecture/ficha-excel.md`. La nueva plantilla separa catálogo de cuatro unidades de Carrera y planificación semanal docente; el Excel conserva filas y textos de tablas fijas. Vista previa solo en memoria del navegador; relectura del archivo y validación de ambas huellas al aplicar. Se elimina `importaciones_ficha` y las rutas de recuperación y descarte. Pruebas existentes adaptadas sin ejecutar. | — (Excel) |
| Plantilla como datos (ADR-0011), editor visual principal y JSON en «Opciones avanzadas»; barra alineada al borde de la primera hoja; formato protegido y respuestas por responsable (ADR-0012) | RF-017..026; RN-009..012; CU-04; ADM-06 | `TemplateDefinitionTest`, `OfficialTemplateTest` (incluye `test_the_split_unit_tables_return_to_the_official_format_keeping_ids_and_profiles`), `TemplateStructureApiTest`, `DocumentBlockTest`, `TemplateAndSourceTest`, `SyllabusTemplateSeederTest`, `FieldDocumentTest`, `SyllabusProcessTest::test_changing_the_template_during_a_pause_waits_for_confirmed_process_resume_to_replace_syllabi`; `FieldResponse`, `FieldResponseEditor`, `blocks.test.ts`, `TemplateVisualBuilder.test.ts`, `TemplateJsonDialog.test.ts`, `AdminTemplatePage.test.ts`, `DocumentBlock.test.ts` | PV-01, PV-07 |
| Editor del docente, validación y copia entre paralelos | RF-037..045; CU-07, CU-09; DOC-02, DOC-04, DOC-05, DOC-07, DOC-09 | `ConvocationAndDraftTest::test_teacher_starts_autosaves_and_stale_version_returns_recoverable_conflict`, `ConvocationAndDraftTest::test_unit_hours_must_add_up_to_the_subject_hours_before_sending`, `ConvocationAndDraftTest::test_unit_names_and_results_come_from_the_subject_profile_and_the_teacher_cannot_change_them`, `ConvocationAndDraftTest::test_every_teacher_field_is_required_only_when_sending`, `ConvocationAndDraftTest::test_teacher_copies_content_between_own_parallels_of_the_same_subject`, `ConvocationAndDraftTest::test_copy_is_rejected_into_a_sent_syllabus_or_from_one_not_started`, `ApiSyllabiIndexTest`, `IdentificationCardTest`; `TeacherSyllabusDetailPage.test.ts`, `FreeTextEditor.test.ts`, `TeacherHistoryPage.test.ts` | PV-16 |
| Revisión: Coordinación → Biblioteca → Aprobado | RF-055..065; CU-10..CU-14; COR-05..COR-10, BIB-01..BIB-03, DOC-08 | `ReviewWorkflowTest`, `LibraryReviewTest` (con la convocatoria cerrada, Biblioteca no devuelve ni aprueba y la pantalla oculta ambas acciones; «Otro motivo» exige texto y se une a los motivos marcados); `CoordinationReviewDetailPage.test.ts`, `LibraryReviewsPage.test.ts`, `LibraryReviewDetailPage.test.ts` | — |
| Firmas con `.p12` en el navegador | RF-066..069; CU-15; COR-06, BIB-03, DOC-10 | `DocumentSignatureTest`, `SignatureTrustTest`, `SignatureStampLocatorTest`; `DocumentSignatureCard.test.ts`, `signPdf.test.ts` | DT-16 |
| Word y PDF | RF-066..069; CU-15; DOC-10 | `DocumentOperationsTest` (la firma de Coordinación sale de su revisión aunque apruebe Biblioteca), `WordRendererTest`, `TemplateGoldenWordTest`, `DocumentSignatureTest::test_con_gotenberg_el_pdf_sale_del_docx_y_es_reproducible`; `SyllabusDocumentPreview.test.ts` (no imprime el campo fuente de un bloque), `documentPagination.test.ts` | PV-07 |
| Chat de IA del docente | RF-046..054; RN-028..030; CU-08; DOC-06; IA-NEG-01..09 | `AiAssistanceTest`, `HostedAiChatGatewayTest`, `AdministrationDecidesAiTest`; `SyllabusUnitAssistant.test.ts`, `chatMarkdown.test.ts` | PV-14, PV-18 |
| Panel, avisos, auditoría y ayuda | RF-070..074; CU-16, CU-17; UI-03, ADM-01, ADM-10, ADM-13 | `ApiDashboardTest`, `NotificationLinksTest`, `DocumentOperationsTest::test_cp_f_only_administrator_reads_safe_append_only_audit`, `ScreenHelpTest`; `NotificationsSheet.test.ts`, `NotificationsPopover.click.test.ts`, `AdminAuditPage.test.ts`, `AdminHelpPage.test.ts`, `ScreenHelpButton.test.ts` | PV-15 |
| Accesibilidad y patrones de interfaz | RNF-018..023; UI-01..UI-04 | `ApiSessionTest::test_user_saves_accessibility_preferences_and_gets_them_back_in_the_session`; `AccessibilityDialog.test.ts`, `useAccessibility.test.ts`, `AppShell.test.ts`, `DialogContent.test.ts`. Patrones por pantalla: `docs/product/screens.md` | PV-19 |
| Plataforma: HTTP, esquema, módulos y colas | RNF-001..017, RNF-024..036 | `HealthAndSecurityTest`, `AuthHardeningTest`, `SpanishSchemaTest`, `SpanishModelColumnsTest`, `ModuleBoundariesTest`, `OpenApiContractTest`, `JobIdempotencyTest`, `JobDispatchTest`, `RedisConnectionTest`, `DatabaseBootstrapTest`, `ValidationMessagesInSpanishTest` | PV-11, PV-12 |

ADR-0012 añade fotografía al cerrar los borradores en `TransitionSyllabusProcess` y
consulta histórica en `SyllabusController`. Se revisaron tipos y estilo; la prueba
automatizada de esta extensión queda pendiente.

Lo que ninguna prueba automática cubre (teclado, lector, contraste, dispositivos reales y
fidelidad del documento) se revisa con `manual-review-script.md`.

## Cómo mantener la matriz

- Una fila por capacidad. Si cambia el comportamiento, actualiza la fila en el mismo
  cambio que el código.
- Cita solo pruebas que existen. Si borras o renombras una prueba, corrige la fila.
- No agregues fechas, números de incremento ni historia: eso queda en git.
- Una capacidad esencial no se da por cubierta sin al menos una prueba trazable.

## Cambio de requisito

1. Registra el origen y la autoridad del cambio.
2. Actualiza la SRS o la decisión en `DECISIONS_STATUS.md`.
3. Analiza el impacto en RN, CU, UI, datos, arquitectura y CP.
4. Si el trabajo no es trivial, crea un plan en `docs/plans/active/`.
5. Actualiza implementación, pruebas y esta matriz.
6. Vuelve a validar los criterios afectados.

No cambies primero la prueba para acomodar el código y luego reescribas el requisito.

## Evidencia

Puede ser un reporte de pruebas, una captura, un documento generado, un log anonimizado,
un acta o una métrica reproducible. Debe indicar commit, ambiente, fecha y responsable
cuando corresponda.
