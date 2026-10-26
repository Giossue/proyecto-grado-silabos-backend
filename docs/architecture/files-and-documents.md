# Archivos y documentos

## Archivos privados

Todos los archivos viven en el disco `private` (`storage/app/private`). En producción
esa carpeta debe estar en un volumen persistente (ver [deployment.md](deployment.md)).

| Ruta | Qué guarda | Fila en `archivos` |
|---|---|---|
| `exports/{revisión}/{exportación}/syllabus.docx` | Word de la revisión aprobada | sí (`syllabus_export`) |
| `exports/{revisión}/{exportación}/syllabus.pdf` | PDF sin firmas | sí (`syllabus_export`) |
| `exports/{revisión}/{exportación}/silabo-firmado-{rol}.pdf` | PDF después de cada firma | sí (`syllabus_signed_export`) |
| `logos/facultades/{facultad}/{uuid}.png` | Logo de facultad | sí (`logo_facultad`) |
| `logos/institucion.png` | Logo de la universidad | no |
| `ca-confiables.pem` (sugerido) | Entidades certificadoras reconocidas | no |

La tabla `archivos` guarda disco, ruta interna, nombre, tipo MIME, tamaño, hash
SHA-256, categoría, quién lo subió y cuándo. Es inmutable. Las tablas de dominio
apuntan a `archivos`; nunca guardan una ruta como dato de negocio.

Los logos se publican sin sesión en `GET /logos/institucion` y
`GET /logos/facultad/{id}`, porque aparecen en la vista previa.

## Cargas

| Carga | Regla |
|---|---|
| Ficha (PDF/Excel) | hasta 6 archivos de 20 MB, `pdf`, `xlsx` o `xls` |
| Planillas de usuarios, docentes, carreras y malla | `xlsx` de hasta 2 MB |
| Logos | PNG transparente de hasta 4 MB; se ajusta al tamaño del encabezado |
| PDF firmado | `application/pdf` de hasta 40 MB |

Nginx del backend acepta cuerpos de hasta 32 MB (`client_max_body_size`). El nombre
físico lo genera el servidor.

## Descargas

- `GET exports/{exportación}/{docx|pdf}` exige permiso sobre la revisión, exportación
  `completado` y las tres firmas (`ExportArtifactPolicy::download`).
- El PDF que se entrega es el último firmado.
- La respuesta se transmite desde el disco con `X-Content-Type-Options: nosniff` y se
  audita como `documento.descargado`.
- Quien debe firmar obtiene el PDF vigente en `GET revisions/{id}/signature/pdf`.

## Generar Word y PDF

Al aprobar la bibliografía se pide la exportación de forma automática
(ver [sequences.md](sequences.md#4-verificación-de-biblioteca-y-exportación-automática)).
`GenerateSyllabusExportJob` hace:

1. pasa la exportación a `en_ejecucion`;
2. toma la fotografía de la revisión (`revisiones_silabo.contenido_enviado`) y le
   agrega las variables de aprobación;
3. arma el Word con `PhpWordDocumentRenderer`;
4. convierte el Word a PDF con Gotenberg, o escribe un PDF de texto si no hay
   `GOTENBERG_URL`;
5. comprueba que ambos archivos son válidos (`DocumentBundleValidator`);
6. guarda los archivos, marca `completado` y avisa a quien pidió la exportación.

Versión del motor: `phpword-docx-v4+libreoffice-pdf-v1` con Gotenberg y
`phpword-docx-v4+text-pdf-v2` sin él. Se registra en la auditoría de la solicitud.

### El Word sale de la definición congelada

La fotografía de cada revisión (esquema 9) guarda:

- `template_definition`: la definición completa de la plantilla al enviar;
- `logos`: logos de la universidad y la facultad en base64;
- `identification` y `template_variables`: datos del sistema ya resueltos;
- `sections[].fields[]`: cada campo con su documento Tiptap y su valor resuelto.

`SyllabusWordDocument` usa `TemplateContext::fromSnapshot()`. Por eso papel, letra,
colores, título, encabezado, pie, bloques y textos salen de la fotografía, no de la
plantilla viva. Cambiar la plantilla después no cambia un documento enviado.
Las fotografías antiguas sin `template_definition` usan la plantilla oficial
(`resources/templates/silabo-oficial.json`).

Qué hace el render:

- encabezado con hasta dos logos y el título del documento;
- una sección de Word por sección de la plantilla, vertical u horizontal;
- `TemplateDocumentWord` traduce el documento Tiptap: párrafos, marcas, alineación,
  listas anidadas, tablas con `gridSpan`/`vMerge`, colores y anchos;
- `WordTableGrid` recalcula la rejilla de cada tabla con anchos fijos; si una palabra no
  cabe, baja la letra hasta 7 pt (6 pt en esa celda);
- bloques (unidades, evaluación, etc.) según su `layout` en la definición;
- pie y línea de metadatos según `document.footer` y `document.metadata` (la oficial
  usa «Página {page} de {pages}»);
- no importa HTML ni descarga recursos externos.

### Variables que se completan al exportar

| Variable | Valor |
|---|---|
| `fecha_entrega` | Fecha de envío de la revisión |
| `nombre_coordinador` | Nombre de quien registró la aprobación (`aprobaciones.aprobado_por`) |
| `fecha_revision` | Fecha de la aprobación |
| `firma_docente`, `firma_coordinador`, `firma_bibliotecario` | Espacio en blanco y la etiqueta «Firma del docente», «Firma de coordinación» o «Firma de biblioteca» (`SignatureAnchor`) |

Diferencia conocida: la aprobación la registra Biblioteca. Por eso
`nombre_coordinador` y `fecha_revision` muestran hoy el nombre y la fecha de Biblioteca,
no los de `revisiones_coordinacion`.

## Firma electrónica (I-227)

La firma es incremental (PAdES) y se hace en el navegador. Orden fijo:
docente → coordinación → biblioteca. Solo empieza cuando la revisión está aprobada y
el PDF existe.

En el navegador (`src/lib/signature` del frontend):

1. pide el PDF vigente y la posición del sello;
2. lee el `.p12` con su clave; ni el archivo ni la clave salen del equipo;
3. agrega una firma incremental con el sello visible;
4. sube solo el PDF firmado y la huella del PDF del que partió.

En el servidor (`AcceptDocumentSignature`):

1. bloquea la exportación y comprueba que es el turno de ese rol;
2. comprueba que el PDF base no cambió mientras se firmaba;
3. `IncrementalPdfSignatureVerifier`: el PDF firmado empieza con los bytes exactos del
   vigente, agrega una sola firma nueva, esa firma cubre todo el archivo y la firma
   CMS es válida;
4. si `SIGNATURE_TRUSTED_CA_BUNDLE` apunta a un PEM, exige que la cadena llegue a una de
   esas entidades; vacío, solo verifica integridad;
5. si `SIGNATURE_REQUIRE_HOLDER_MATCH` es `true`, el titular del certificado debe
   coincidir con el nombre de la cuenta (`SignerHolderMatcher`: mismas palabras, sin
   importar orden, tildes ni mayúsculas);
6. guarda el PDF, la fila en `archivos` y en `firmas_documento` (titular, número de
   serie, emisor, vigencia y fecha); audita y avisa al siguiente firmante.

`SignatureStampLocator` lee el PDF con `pdftotext -bbox` y ubica el sello sobre la
etiqueta del rol. Si no la encuentra, usa las etiquetas «Firma:» en orden y, por último,
el pie de la última página. No hay sello de tiempo externo.

Configuración y armado del PEM: [deployment.md](deployment.md#firma-electrónica).

## Retención

No hay borrado automático de archivos. Los archivos de un sílabo solo se borran cuando
se regenera o se elimina ese sílabo (`InProgressWork`), después del commit.
