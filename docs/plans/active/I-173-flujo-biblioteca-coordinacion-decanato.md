# I-173 — Revisión en cadena: Biblioteca → Coordinación → Decanato, con cuatro firmas

## Pedido del responsable, 2026-10-04

Nuevo rol **Decano** y nuevo orden del flujo:

1. El docente envía el sílabo; llega primero al **bibliotecario**.
2. Si biblioteca aprueba, pasa **automáticamente** a **coordinación**.
3. Si coordinación aprueba, pasa al **decano** de la facultad.
4. El decano aprueba; recién ahí se genera el PDF y firman los cuatro, en orden:
   docente → coordinación → biblioteca → decanato.

Cualquier revisor puede devolver el sílabo al docente. Al reenviar, el ciclo empieza otra
vez desde biblioteca y las firmas anteriores quedan anuladas (cada revisión tiene su
propio PDF y sus propias firmas; cambiar el contenido cambia los bytes).

Decisiones del responsable: un decano **por facultad**; una devolución del decano vuelve
al docente y **reinicia todo**; el PDF se genera **al final**, tras la aprobación del
decano.

## Trazabilidad

RF-017..033, RF-037..045 · RN-009..024 · CU-04..07 · COR-05..10, BIB-01..03, DEC-01/02,
DOC-07/08/10 · reemplaza el orden de I-165 (biblioteca después de coordinación) y la
corrección «solo bibliografía» (I-165). Conserva I-139 (firma incremental) e I-172.

## Diseño

### Estados y etapas

`silabos.estado` no cambia (`sin_iniciar`, `borrador`, `en_revision`,
`correccion_solicitada`, `aprobado`). Dentro de `en_revision` la **etapa** se deriva de
los registros de la revisión vigente, sin columna nueva de estado:

| Etapa | Condición |
|---|---|
| `biblioteca` | el envío a biblioteca de la revisión no está verificado |
| `coordinacion` | verificado por biblioteca y sin `revisada_coordinacion_en` |
| `decanato` | revisada por coordinación y sin aprobación |

- `envios_biblioteca` se crea **al enviar** (enviado_por = docente). Verificar = aprobar
  en biblioteca.
- `revisiones_silabo.revisada_coordinacion_por/en` (columnas nuevas): aprobación de
  coordinación.
- `aprobaciones`: la aprobación final, la registra el **decano**. Pone `aprobado`.

Devoluciones: `solicitudes_correccion` con `etapa_solicitud` nueva (`biblioteca`,
`coordinacion`, `decanato`). Coordinación sigue exigiendo observaciones; biblioteca y
decanato devuelven con una justificación. El docente edita todo el sílabo y reenvía; la
revisión N+1 vuelve a biblioteca. Se retira la corrección «solo bibliografía»
(`SubmitBibliographyCorrection`, `BibliographyCorrection`, aprobación heredada).

Reapertura tras `aprobado` (`ReopenSyllabus`): coordinación o decanato; anula el PDF y
las firmas como hoy.

### Rol Decano

- `RoleCode::Dean = 'decano'`, alcance por **facultad**: columna
  `asignaciones_rol.facultad_id` (FK `facultades`), CHECK: decano ⇒ `facultad_id` y sin
  carrera; los demás sin facultad. Un decano activo por facultad.
- Administración lo crea en Usuarios y roles eligiendo la facultad.
- Políticas: ve y decide sobre los sílabos de las carreras de su facultad, en etapa
  `decanato` o ya aprobados.

### Firmas

- `firmas_documento.firmado_como` admite `decanato`; `DocumentSignature::ORDER` = docente,
  coordinación, biblioteca, decanato.
- `SignatureWorkflow`: sin estados de envío/verificación de biblioteca; `sin_documento` →
  `pendiente_docente` → `pendiente_coordinacion` → `pendiente_biblioteca` →
  `pendiente_decanato` → `firmado`; `anulado` si hay reapertura.
- Al aprobar el decano se solicita la exportación (DOCX/PDF) automáticamente.
- Plantilla: variable `firma_decano` (`SignatureAnchor` «Firma de decanato»); la
  plantilla por defecto añade la cuarta celda.

### Notificaciones

| Evento | Destinatarios |
|---|---|
| `silabo.enviado` / `silabo.reenviado` | bibliotecarios |
| `biblioteca.verificado` | coordinación (y docente, informativo) |
| `silabo.revisado_coordinacion` | decano de la facultad |
| `silabo.aprobado` | docente y coordinación |
| `silabo.correccion_solicitada` (cualquier etapa) | docente |
| `silabo.firmado_docente` → coordinación; `_coordinacion` → biblioteca; `_biblioteca` → decano; `silabo.firmado` → docente, coordinación |

## Cambios

- Migración `000183_review_chain_and_dean_role` (irreversible: exige base sin sílabos
  en revisión/aprobados, igual que 000164).
- Backend: `SubmitSyllabus` (crea el envío a biblioteca y avisa a biblioteca),
  `VerifyBibliography` (etapa biblioteca → coordinación), `ReviewByCoordination`
  (nueva), `ApproveSyllabus` (decano; exportación automática),
  `RequestSyllabusCorrection` (por etapa), `ReviewStage` (consulta de etapa),
  `SyllabusPolicy`, `SignatureWorkflow`, `LibraryReviews`, `ReviewController` (decanato
  reutiliza las rutas de revisión con prefijo `deanery/`), recipients, destinos y textos
  de avisos, `CreateManagedUserRequest`/`AssignRole`, sesión (`active_role.faculty_*`),
  seeder y usuarios demo.
- Frontend: rol `decano` en sesión, menú y rutas; bandeja y detalle de decanato
  (reutilizan las páginas de revisión de coordinación con modo); biblioteca sin «enviar
  a biblioteca» ni corrección parcial; tarjeta de firma con cuatro firmantes; formulario
  de usuarios con facultad para decano.
- Docs: `syllabus-lifecycle.md`, `roles-and-permissions.md`, `screens.md` (COR, BIB,
  DEC, DOC), `DECISIONS_STATUS.md` (I-173), trazabilidad, `usuarios-demo.md`.

## Pasos

1. [ ] Migración + rol Decano (enum, asignaciones, sesión, alta de usuarios, seeder).
2. [ ] Flujo de revisión por etapas (acciones, política, controladores, avisos).
3. [ ] Firmas con decanato y exportación automática.
4. [ ] Pruebas backend (ReviewWorkflowTest, LibraryReviewTest, DocumentSignatureTest,
       nuevo DeanReviewTest) y OpenAPI.
5. [ ] Frontend (sesión/rol, menú, páginas, firma, usuarios) y pruebas.
6. [ ] Documentación y trazabilidad.

## Verificación

Pendiente.

## Pendiente

- Aplicar la migración en la base remota de pruebas (lo hace el deploy).
- Crear la cuenta de decano de prueba.
