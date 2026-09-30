# I-165 — Rol Bibliotecario, revisión de bibliografía y firmas en la plantilla

## Pedido del responsable, 2026-09-29

1. Nuevo rol **Bibliotecario** (cuarto rol).
2. Cuando termina el proceso docente ↔ coordinación, el docente envía al bibliotecario su
   propuesta de libros. Si los libros están, el bibliotecario **firma para aprobar**; si
   no, **manda un comentario**.
3. En la plantilla, variables de firma como los datos automáticos: **firma docente**,
   **firma coordinador** y **firma bibliotecario**, para colocarlas al final del documento.

Decisiones tomadas por el responsable el mismo día:

| Pregunta | Decisión |
| --- | --- |
| ¿Qué pasa tras el comentario? | El docente corrige **solo la bibliografía** y la reenvía; no vuelve a pasar por la revisión de coordinación. |
| ¿Quién propone los libros? | **El docente**: la sección Bibliografía de la plantilla debe tener partes de Texto libre. |
| ¿En qué momento entra biblioteca? | **Al final**: aprobación → firma docente → firma coordinación → envío a biblioteca → firma o comentario de biblioteca. |
| ¿Alcance del bibliotecario? | **Toda la universidad**: rol sin carrera, entra directo como el administrador. |

«Firma» es la firma electrónica del PDF que ya existe (I-139): es la única que queda en el
documento, porque el PDF no cambia después de generarse.

## Flujo resultante

```text
Aprobado ─► PDF ─► firma docente ─► firma coordinación ─► [docente] Enviar a biblioteca
                                                                  │
                         ┌────────────────────────────────────────┴───────────────┐
                         ▼                                                        ▼
               [bibliotecario] Firma  ─► firmado             [bibliotecario] Comentario
                                                                                  │
          reapertura de origen «biblioteca»: Corrección solicitada, solo Bibliografía
                                                                                  │
          [docente] Reenviar ─► revisión N+1 = copia exacta de N con la bibliografía nueva,
          aprobación heredada (sin revisión de coordinación) ─► Aprobado ─► PDF nuevo ─►
          firma docente ─► firma coordinación ─► Enviar a biblioteca ─► …
```

- El comentario reutiliza la reapertura (`reaperturas`), con `origen_reapertura =
  'biblioteca'`. Así la revisión aprobada sigue intacta, las firmas del PDF anterior se
  anulan solas (`SignatureWorkflow::voided`) y el historial ya existente sirve.
- Durante la corrección de biblioteca el docente solo puede cambiar los campos de la
  sección `bibliografia`. El servidor rechaza los demás.
- La corrección de biblioteca no depende del plazo ni de que la convocatoria esté en
  curso: es trabajo posterior a la aprobación, igual que las firmas. Así no queda un
  sílabo atascado si el proceso ya se pausó o cerró.
- La revisión N+1 se arma copiando la fotografía aprobada N y reemplazando solo los
  valores de la sección `bibliografia`. Ningún otro contenido cambia sin la revisión de
  coordinación, aunque la plantilla viva haya cambiado.
- La aprobación de N+1 se registra con `aprobacion_base_id` = aprobación de N y el mismo
  aprobador (quien revisó el resto del contenido). El PDF nuevo lo vuelven a firmar el
  docente y coordinación.
- Si coordinación reabre el sílabo con la reapertura normal, el envío a biblioteca queda
  anulado igual que las firmas.

## Variables de firma

`firma_docente`, `firma_coordinador` y `firma_bibliotecario` en
`config/syllabus_variables.php`. Su valor es fijo: cuatro líneas en blanco (espacio para
el sello) y una etiqueta visible: «Firma del docente», «Firma de coordinación» y «Firma de
biblioteca». `SignatureStampLocator` busca la etiqueta de cada rol en el PDF y dibuja el
sello encima. Si la plantilla no la tiene, conserva el comportamiento anterior: primero
las etiquetas «Firma:» y, si tampoco están, el pie de la última página, con tres
posiciones. El formato oficial (`TemplateDocumentDefaults::signatures`) pasa a tres
columnas con estas variables.

## Esquema (migración `2026_09_29_000163_add_librarian_role_and_library_review`)

- `asignaciones_rol`: `rol` admite `bibliotecario`; alcance `bibliotecario` sin carrera.
- `firmas_documento.rol_firma` admite `biblioteca`.
- `reaperturas.origen_reapertura varchar(16) NOT NULL DEFAULT 'coordinacion'`, con CHECK
  `('coordinacion','biblioteca')`.
- `aprobaciones.aprobacion_base_id uuid NULL` → `aprobaciones(id)`, con índice.
- `envios_biblioteca` (append-only): `id`, `artefacto_exportacion_id` UNIQUE →
  `artefactos_exportacion`, `enviado_por` → `usuarios`, `enviado_en`.

## Contrato de API (`/api/v1`)

### Sesión y usuarios

- `active_role.code` admite `bibliotecario` con `career_id = null`. Una cuenta con solo
  ese rol entra directo, sin selector de ámbito.
- `POST/PATCH admin/users`: `role_code = bibliotecario` no pide `career_id`.

### Firma (`GET revisions/{revision}/signature`)

- `state`: `sin_documento | pendiente_docente | pendiente_coordinacion |
  pendiente_envio_biblioteca | pendiente_biblioteca | firmado | anulado`.
- `next_role`, `my_role` y `signatures[].role`: `docente | coordinacion | biblioteca`.
- Nuevos: `can_send_to_library: bool` (docente, estado `pendiente_envio_biblioteca`) y
  `library: { sent_at, sent_by } | null`.
- `POST revisions/{revision}/library-submission` (docente): devuelve 201 con `{ message }`.

### Docente (`GET teacher/syllabi/{syllabus}`)

- `library_correction: bool`: la corrección vigente la pidió biblioteca. Solo son
  editables los campos de la sección con `key = 'bibliografia'`.
- `reopening.origin: 'coordinacion' | 'biblioteca'`.
- `library_comments: [{ comment, commented_at, commented_by, revision_number }]`.
- `POST teacher/syllabi/{syllabus}/submit` sirve también para reenviar la bibliografía.
  El estado pasa directamente a `aprobado`.

### Biblioteca (rol activo `bibliotecario`)

- `GET library/reviews?state=pendiente|firmado|comentado|anulado&q=&page=` devuelve
  `{ filters, reviews: Paginator<{ revision_id, syllabus_id, subject, code, career, period,
  parallels, teachers, revision_number, sent_at, sent_by, state }> }`.
- `GET library/reviews/{revision}` devuelve `{ review: { …los mismos datos, can_comment,
  template_variables, sections (solo `bibliografia`, tomada de la fotografía), comments } }`.
- `POST library/reviews/{revision}/comments` recibe `{ comment, idempotency_key }` y
  devuelve 201 con `{ message }`.
- La firma usa los endpoints de firma existentes, con el rol `biblioteca`.

### Panel y avisos

- Métricas del bibliotecario: `library_pending`, `library_signed`, `library_commented`.
- Avisos nuevos:

  | Aviso | Destinatario | Destino |
  | --- | --- | --- |
  | `biblioteca.enviado` | bibliotecarios | `/biblioteca/revisiones/{revision}` |
  | `biblioteca.comentado` | docente | el sílabo |
  | `silabo.firmado_coordinacion` | docente | el sílabo: enviar a biblioteca |
  | `silabo.bibliografia_corregida` | coordinación | informativo |
  | `silabo.firmado` | docente y coordinación | ahora se envía tras la firma de biblioteca |

## Pasos

- [ ] Migración, enum de rol, asignación sin carrera, formularios y seeder.
- [ ] Flujo de firma de tres pasos, envío a biblioteca y acciones de biblioteca.
- [ ] Corrección solo de bibliografía y reenvío con aprobación heredada.
- [ ] Variables de firma y ubicación del sello por rol.
- [ ] Panel, avisos, etiqueta de contexto y paso de puesta en marcha.
- [ ] Pruebas de servidor, OpenAPI regenerado y `composer verify`.
- [ ] Frontend: rol, rutas, bandeja y detalle de biblioteca, envío del docente,
      corrección de bibliografía y variables de firma en el editor.
- [ ] Documentación: decisiones, roles, ciclo de vida, esquema, documentos y pantallas.

## Fuera de alcance

- Catálogo de libros o consulta a un sistema de biblioteca: el bibliotecario verifica a
  mano y firma o comenta.
- Cambiar la plantilla de la base remota. La sección Bibliografía de la remota usa datos
  del Proyecto de carrera; Administración debe cambiarla a Texto libre desde el editor.
