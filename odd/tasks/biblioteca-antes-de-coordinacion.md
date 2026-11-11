# Biblioteca antes de Coordinación

Objective: change the review order to Docente → Biblioteca → Coordinación, and move «Bibliografía verificada por» under section 11.
Branch: main (local commits, no push). Delivery: ask-on-risk. Runners: backend `env -i PATH="$PATH" HOME="$HOME" LANG="$LANG" DB_DATABASE=<db> php -d memory_limit=2G vendor/bin/pest` (never `php artisan test`, never `--parallel`); frontend `npx vitest run`, `npm run -s typecheck`, `npx eslint <files>`.

## Specs

- **S1 — New order.** The user said: «me dijeron es que el flujo es: Docente -> biblioteca -> Coordinador».
  - Every submission goes first to Biblioteca, which verifies the bibliography: it approves by signing, or returns it with reasons.
  - Coordinación reviews afterwards. Its approval, with its signature, is the final «Aprobación»: the state becomes `aprobado`, the content hash is recorded and the PDF export starts.
  - Stage labels stay «En biblioteca» and «En coordinación».
- **S2 — Resubmission after a Coordinación return.** User's choice: «Biblioteca si tocó bibliografía».
  - If the teacher changed the bibliography since the last revision Biblioteca verified, the resubmission goes to Biblioteca again.
  - If not, it goes straight to Coordinación, keeping the earlier verification (who and when).
  - A resubmission after a Biblioteca return always goes to Biblioteca.
  - «Changed the bibliography» is decided by comparing the bibliography sections of the two revisions (`BibliographySections` and `CanonicalHasher`).
- **S3 — Who verified.** The verification by Biblioteca (who and when) is recorded per revision, because the final approval now belongs to Coordinación.
  - The librarian's name, the «Fecha de verificación» and the librarian's signature come from that record.
  - The coordinator's name and date come from Coordinación's approval.
- **S4 — Everything that assumes the old order follows the new one:**
  - inboxes: the coordinator's queue now shows syllabi already verified by Biblioteca, and Biblioteca's queue shows submissions not yet verified;
  - permissions: a librarian can open what is in its stage or what it handled;
  - notifications and e-mails: Biblioteca gets «Sílabo enviado» and «Sílabo corregido para revisar»; Coordinación gets «Bibliografía verificada por Biblioteca», and the teacher's notices follow suit;
  - daily summaries, the timeline and history, reports («review days» measured up to the final approval), audit labels, screen help, signing statements, and the coordinator-handover reset;
  - frontend: pages, buttons and texts such as «Firmar y enviar a biblioteca» become «Firmar y enviar a coordinación» for Biblioteca, and Coordinación gets «Firmar y aprobar»;
  - docs: AGENTS.md, docs/product/*.
- **S5 — Review data from the old flow migrates with no loss:**
  - In review, never verified by Biblioteca (including syllabi Coordinación already signed): stage Biblioteca.
  - When Biblioteca verifies one that Coordinación had already signed, it goes to Coordinación, which approves it. The coordinator's existing signature is reused, not duplicated.
  - Already approved (old approval by a librarian): stays approved. Its PDF variables still show the same librarian, verification date, coordinator and dates as before.
  - Correction requested: unchanged; the next resubmission follows S2.
  - The migration has a `down()` and does not move `plantilla_modificada_en`.
- **S6 — Template.** The user said: «esto esté acá abajo mejor, no va al mismo nivel … o sea el de biblioteca va acá arriba». User's choice: «Tabla de una columna».
  - The «Bibliografía verificada por» column leaves the «Revisión y aprobación» signature table, which keeps «Elaborado por» and «Revisado y aprobado por».
  - It becomes a one-column table with the same content (heading «Bibliografía verificada por»; «Firma:» + signature; librarian name + «Biblioteca»; «Fecha de verificación:» + date), inside section 11 «Bibliografía», below «Complementaria».
  - This applies to the official template file, the stored template (migration that keeps `plantilla_modificada_en`), the «Firmas» preset, and the fixed and default copies of the table.
  - Syllabi already submitted keep their frozen copy.

## Tasks

| ID | Specs | Route | Status | Commit |
|----|-------|-------|--------|--------|
| T1 | S1-S5 | delegated (writer reason: parallel units, with T2) | done | see commits |
| T2 | S6 | delegated (writer reason: parallel units, with T1) | done | see commits |
| T3 | S1-S6 | independent verifier (Risk item 1: migrations of review and template data) | done: S1-S6 PASS; 1 blocker fixed | 000271 |

## Log

- **L1 (2026-11-11), user:** «ok, algo que me pidieron es que esto esté acá abajo mejor, no va al mismo nivel [captura: columna «Bibliografía verificada por» de la tabla de firmas, flecha hacia debajo de Complementaria] o sea el de biblioteca va acá arriba / y otra cosa es que me dijeron es que el flujo es: / Docente -> biblioteca -> Coordinador»
- **L2, user answers:** resubmission = «Biblioteca si tocó bibliografía (Recomendado)»; block = «Tabla de una columna (Recomendado)».
- **L3, explorer map (summary):**
  - The stage is derived in `ReviewStage::ofRevision`.
  - The actions are `SubmitSyllabus` (creates `envios_biblioteca` only after a library return), `ApproveSyllabus` (coordination; creates the `LibrarySubmission`) and `VerifyBibliography` (library; creates the final `Approval`).
  - `envios_biblioteca` has no verifier columns since migration 000221.
  - The PDF variables in `SignedDocumentVariables` read the librarian from the `Approval`.
  - The template is `resources/templates/silabo-oficial.json`: field `firmas` (section `revision`) and section `bibliografia` (fields `bibliografia`, `bibliografia_complementaria`; `library_sections`).
- **L4:** migration numbers reserved: T1 = 000269, T2 = 000270. Test DBs: T1 `silabos_ueb_test`, T2 `silabos_ueb_test_b`.
- **L5 (2026-11-11), T2 writer (S6):**
  - New Institucional field `verificacion_bibliografia` (label empty, so Word prints no subheading) in section 11, after `bibliografia_complementaria`: one-column table «Bibliografía verificada por» / «Firma:» + `firma_bibliotecario` / `nombre_bibliotecario` + «Biblioteca» / «Fecha de verificación:» + `fecha_verificacion`, same header style (#C3D4EA). `firmas` keeps «Elaborado por» and «Revisado y aprobado por».
  - New preset «Bibliografía verificada por» (`verificacion_bibliografia`) before «Firmas»; the «Firmas» preset is the two-column table. `TemplateDocumentDefaults::signatures()` and `FixedContent::official('firmas')` lose the column; new `TemplateDocumentDefaults::libraryVerification()` and `FixedContent::official('verificacion_bibliografia')`.
  - Migration 000270 moves the column of the stored template structurally (the cells as they are, so Administración's edits and styling survive) into a new field of the template's library section and does the same in the stored «Firmas» preset; `down()` puts the column back and removes the field and preset. Keeps `plantilla_modificada_en`; does not touch `revisiones_silabo`.
  - RED: `LibraryVerificationBlockTest` 4 failed. GREEN: 4 passed; `tests/Feature/Configuration` 101 passed. Updated counts and presets in `OfficialTemplateTest` and `SyllabusTemplateSeederTest`.
  - Isolated check (HEAD + T2 only, scratch copy with its own vendor, DB `silabos_ueb_test_b`): base 805 passed; with T2 4 failed, 805 passed. The 4 are outside T2's surface and pass in the scratch copy with: `WordRendererTest` v8 test expects 12 tables (and «Bibliografía verificada por»); `DocumentOperationsTest::approvedRevision` snapshot adds a `bibliografia` section whose block uses `TemplateDocumentDefaults::libraryVerification()`; Word golden fixtures regenerated with `UPDATE_GOLDEN=1`.
  - Pending outside T2's surface: those three test edits, `docs/product/syllabus-sections.md` rows 11 and 12. `docs/product/screens.md` (ADM-06 «Plantilla» list, DOC-04 signed tables) updated.
  - Note for T1: section 11 now holds an Institucional field whose snapshot `value` resolves the library variables, so `BibliographySections` (S2 comparison) includes it; compare only Carrera fields or the `document`, not the resolved `value`.
- **L6 (2026-11-11), T1 writer (S1-S5):**
  - S1/S3 design: `envios_biblioteca` is the Biblioteca step per revision. Every submission creates one; migration 000269 adds `verificado_por`, `verificado_en` (write-once, both or none) and `verificacion_heredada`, and lets `proteger_envio_biblioteca` accept only that one-time write. `ReviewStage`: approval → none; verified envío → Coordinación; otherwise Biblioteca. `VerifyBibliography` signs (first time only), records the verification and notifies Coordinación «Bibliografía verificada por Biblioteca» (`biblioteca.verificado`, also by e-mail). `ApproveSyllabus` signs (if Coordinación has not signed the syllabus yet), reuses an existing `revisiones_coordinacion` row (old-flow state B) or creates it, creates the `Approval` (same hash/idempotency shape, approver = coordinator), sets `aprobado` and requests the PDF.
  - S2: `SubmitSyllabus` compares `BibliographySections` hashes of the new snapshot and the last revision with a verified envío; only after a Coordinación return and with an unchanged bibliography it copies verifier/date and marks `verificacion_heredada` (no notice; Coordinación sees it in its queue and daily summary). Otherwise Biblioteca gets «Sílabo enviado» (`silabo.enviado`) or, after its own return, «Sílabo corregido para revisar». Library inbox/history/counts ignore carried rows and show the latest envío that went through Biblioteca.
  - S3: `SignedDocumentVariables` takes librarian name and «Fecha de verificación» from the revision's verification; old approvals without an envío keep the previous fallback (approver), so their PDF variables do not change.
  - S4: coordinator queue = latest revision verified and not approved; Biblioteca no longer notifies Coordinación when it returns (Coordinación has not seen it yet); timeline adds `biblioteca_verifico` and names the coordinator on `aprobado`; signature order docente → biblioteca → coordinación; handover deletes only Coordinación's approval, review and signature (by syllabus) and keeps Biblioteca's verification and signature; messages, screen help, audit label, e-mail catalog, `config/notifications.php`; frontend buttons «Firmar y enviar a coordinación» / «Enviar a coordinación» (Biblioteca already signed) and «Firmar y aprobar» / «Aprobar» (Coordinación already signed, old flow), library «Ver PDF» only with `approved_at`; docs (AGENTS.md, DECISIONS_STATUS.md, docs/product/*, database.md, demo.md). Signing statement strings (`aprobacion_coordinacion`, `aprobacion_biblioteca`) unchanged so stored signatures stay valid.
  - S5 (migration 000269): A (in review, no envío) → envío inserted, Biblioteca; B and C (envío without verification) → Biblioteca, then Coordinación approves reusing its signature and review; D (approved, envío) → verification backfilled from the approval; E unchanged. `down()` refuses when a verification has no approval, a different approver or is carried; otherwise deletes the envíos the old flow would not have and restores the old trigger. Does not touch templates.
  - RED (base HEAD copy in a scratch dir, same new tests): backend 36 failed / 14 passed (LibraryReviewTest + ReviewWorkflowTest); frontend 9 failed / 28 passed (three page tests). GREEN: those two backend files 50 passed; frontend 160 files / 851 tests passed, typecheck and eslint clean.
  - Full backend suite (with T2's uncommitted changes in the tree): 816 passed, 4 failed, all from T2's template change: `TemplateGoldenWordTest` ×2 (golden Word fixtures), `WordRendererTest` v8 (11 → 12 tables), `DocumentOperationsTest::the_word_signs_coordination_…` (its fixture uses `TemplateDocumentDefaults::signatures()`, which no longer has the librarian column).
  - Outside T1's surface, pending: `app/Http/Controllers/DashboardController.php` hint «Aprobados por coordinación; falta la bibliografía» (library pending metric); `docs/architecture/files-and-documents.md:122-123` button labels; `docs/quality/traceability.md`; regenerate `docs/generated/database-schema.md` (new columns on `envios_biblioteca`).
- **L7 (2026-11-11), parent seam:**
  - `BibliographySections::answers()`: the S2 comparison ignores Institucional fields, such as the new verification table. RED/GREEN in `tests/Unit/Syllabus/BibliographyAnswersTest.php`.
  - Applied T2's Documents test fixes and regenerated the golden Word files.
  - Updated the dashboard hint, `docs/architecture/{files-and-documents,sequences}.md`, the runbook, the traceability test names and `syllabus-sections.md`.
  - Full backend suite: 821 passed. Frontend: 851 passed.
- **L8 (2026-11-11), T3 verifier:**
  - S1-S6 PASS, including the migration of old-flow states A-F, byte-identical PDF variables, and `000270` up/down with an Administración edit kept.
  - Blocker: a librarian's stored e-mail opt-out `coordinacion.aprobado` was lost after the notice became `silabo.enviado`. Fixed with migration `000271` (renames the key and back in `down()`); RED/GREEN in `LibraryEmailOptOutMigrationTest`.
  - Advisories:
    - `down()` of 000269 drops envíos of new-flow returns; this is accepted.
    - A stale line in `manual-review-script.md`; fixed.
    - The schema doc is not regenerated; still pending.
    - One flaky frontend test, unrelated.
- **L9 (2026-11-11), user:** «es que debe salir de todas maneras, sino el docente no sabe ni que pasa». A teacher with no syllabi now sees the pause banner (`DashboardController::pausedTeacherDeadline`, `ApiDashboardTest`). This is outside this feature's specs.

