# Archivos y documentos

## Archivos privados

Todos los archivos viven en el disco `private` (`storage/app/private`). En producción
esa carpeta debe estar en un volumen persistente (ver [deployment.md](deployment.md)).

| Ruta | Qué guarda | Fila en `archivos` |
|---|---|---|
| `exports/{revisión}/{exportación}/syllabus.pdf` | PDF final de la revisión aprobada, con los sellos de las tres firmas | sí (`syllabus_export`) |
| `logos/facultades/{facultad}/{uuid}.png` | Logo de facultad | sí (`logo_facultad`) |
| `documentos-apoyo/{carrera}/{documento}/original.{ext}` | Documento de apoyo tal como se subió | sí (`documento_apoyo`) |
| `documentos-apoyo/{carrera}/{documento}/vista.pdf` | Vista en PDF de un Word o Excel de apoyo | sí (`documento_apoyo_vista`) |
| `logos/institucion.png` | Logo de la universidad | no |
| `ca-confiables.pem` (sugerido) | Entidades certificadoras reconocidas | no |

La tabla `archivos` guarda disco, ruta interna, nombre, tipo MIME, tamaño, hash
SHA-256, categoría, quién lo subió y cuándo. Es inmutable, salvo las filas de los
documentos de apoyo (`documento_apoyo` y `documento_apoyo_vista`), que se pueden borrar
(trigger `proteger_archivo`, función `proteger_archivo_registrado()`). Las tablas de dominio
apuntan a `archivos`; nunca guardan una ruta como dato de negocio.

Los logos se publican sin sesión en `GET /logos/institucion` y
`GET /logos/facultad/{id}`, porque aparecen en la vista previa.

## Cargas

| Carga | Regla |
|---|---|
| Proyecto de carrera (PDF/Excel) | hasta 6 archivos de 20 MB, `pdf`, `xlsx` o `xls` |
| Planillas de usuarios, docentes, carreras y malla | `xlsx` de hasta 2 MB |
| Logos | PNG transparente de hasta 4 MB; se ajusta al tamaño del encabezado |
| Documentos de apoyo | un archivo `pdf`, `doc`, `docx`, `xls` o `xlsx` de hasta 20 MB |

Nginx del backend acepta cuerpos de hasta 32 MB (`client_max_body_size`). El nombre
físico lo genera el servidor.

## Descargas

- Solo hay PDF: `GET exports/{exportación}/pdf` exige permiso sobre la revisión y la
  exportación `completado` (`ExportArtifactPolicy::download`). Está disponible apenas se
  genera; las firmas ya ocurrieron antes, al decidir.
- No hay descarga de Word: el Word es solo un paso interno para obtener el PDF y no se
  guarda. La migración `2026_10_28_000248` quitó `archivo_word_id` y borró los Word de
  exportaciones anteriores.
- La respuesta se transmite desde el disco con `X-Content-Type-Options: nosniff` y se
  audita como `documento.descargado`.
- Documentos de apoyo: `GET support-documents/{documento}/view` entrega el PDF en línea
  (`inline`) para abrirlo en una ventana aparte; el original no se descarga. Exige el rol activo de Coordinación o Docente en la carrera del
  documento; si no, responden 404. Un PDF es su propia vista; Word y Excel se convierten
  con Gotenberg en la cola `documentos` (`PrepareSupportDocumentViewJob`), y antes cada
  hoja de Excel se ajusta al ancho de la página (`SheetsFitPageWidth`).

## Generar el PDF

Al aprobar la bibliografía se pide la exportación de forma automática
(ver [sequences.md](sequences.md#4-verificación-de-biblioteca-y-exportación-automática)).
`GenerateSyllabusExportJob` hace:

1. pasa la exportación a `en_ejecucion`;
2. toma la fotografía de la revisión (`revisiones_silabo.contenido_enviado`) y le
   agrega las variables de aprobación y los sellos de las firmas (`firmas_silabo`);
3. arma el Word con `PhpWordDocumentRenderer`;
4. convierte el Word a PDF con Gotenberg, o escribe un PDF de texto si no hay
   `GOTENBERG_URL`;
5. comprueba que ambos son válidos (`DocumentBundleValidator`);
6. guarda solo el PDF, marca `completado` y avisa al docente y a Coordinación
   (`silabo.aprobado`).

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
- `WordTableGrid` recalcula la rejilla de cada tabla con anchos fijos; las tablas usan
  el tamaño de contenido de la plantilla y una palabra que no cabe se parte dentro de su
  celda: no se achica la letra ni se ponen encabezados en vertical (2026-11-02);
- bloques (unidades, evaluación, etc.) según su `layout` en la definición;
- sin pie de página ni línea de metadatos: el PDF no lleva «Página X de Y» ni la línea
  de revisión, huella y fecha de generación (2026-11-02);
- no importa HTML ni descarga recursos externos.

### Variables que se completan al exportar

| Variable | Valor |
|---|---|
| `fecha_entrega` | Fecha de envío de la revisión |
| `nombre_coordinador` | Nombre de quien registró la aprobación final (`aprobaciones.aprobado_por`, Coordinación); en aprobaciones del orden anterior, quien firmó `revisiones_coordinacion` |
| `fecha_revision` | Fecha de la aprobación de Coordinación |
| `nombre_bibliotecario`, `fecha_verificacion` | Quién verificó la bibliografía y cuándo (`envios_biblioteca.verificado_por`, `verificado_en`) |
| `firma_docente`, `firma_coordinador`, `firma_bibliotecario` | Sello visible de la firma de ese rol: «Firmado electrónicamente por NOMBRE · ROL · fecha · certificado N.º · emisor» y debajo la etiqueta «Firma del docente», «Firma de coordinación» o «Firma de biblioteca» (`SignatureAnchor::stamp`). Sin firma de ese rol queda el espacio en blanco con la etiqueta. |

Desde 2026-11-11 el orden es Docente → Biblioteca → Coordinación: Biblioteca verifica la
bibliografía y Coordinación registra la aprobación final.

## Firma electrónica al decidir (I-227)

Cada persona firma una sola vez por sílabo, en el momento en que decide:

| Quién | Cuándo | `accion` en la declaración | Huella firmada |
|---|---|---|---|
| Docente | Primer envío del sílabo («Firmar y enviar»). Los reenvíos tras una corrección no se firman. | `envio_docente` | Huella SHA-256 de la fotografía que envía (será la `hash_contenido` de la revisión) |
| Biblioteca | «Firmar y enviar a coordinación» | `aprobacion_biblioteca` | `hash_contenido` de la revisión |
| Coordinación | «Firmar y aprobar»: el sílabo queda aprobado | `aprobacion_coordinacion` | `hash_contenido` de la revisión |

Pedir corrección o devolver al docente no se firma. En los reenvíos nadie vuelve a
firmar: si Biblioteca devolvió, vuelve a Biblioteca; si devolvió Coordinación, vuelve a
Biblioteca solo si cambió la bibliografía y si no va directo a Coordinación. Quien ya
firmó decide con «Enviar a coordinación» o «Aprobar», sin firmar otra vez. Si cambió la bibliografía tras la firma del docente o de Coordinación, el PDF igual
lleva sus sellos.

### Qué se firma

El servidor entrega una declaración en texto UTF-8:

    silabos-ueb|silabo:{silabo_id}|accion:{accion}|huella:{sha256}

- Docente: `POST teacher/syllabi/{silabo}/signing-statement` con `{version_bloqueo}`
  devuelve `{statement, required}` (`required=false` si ya firmó en este ciclo). Valida
  estado, plazo y versión igual que el envío (409 si la versión cambió).
- Coordinación y Biblioteca: el detalle de la revisión trae `signing_statement` (o
  `null` si no puede o no debe firmar) y `signatures: [{role, name, signed_at}]`.

En el navegador (frontend, `node-forge`), el `.p12` y su clave producen una firma
CMS/PKCS#7 separada (detached, SHA-256) de esa declaración, en base64. Ni el archivo ni
la clave salen del equipo. La firma viaja como `signature: {statement, cms}` en el
envío, la aprobación de Coordinación o la aprobación de Biblioteca.

### Qué verifica el servidor

`SignSyllabusDecision`, dentro de la misma transacción que la decisión:

1. si ese rol ya firmó el sílabo, no pide firma y la ignora;
2. sin firma: 422 en `signature` («Firme con su firma electrónica.»);
3. la declaración recibida debe ser idéntica a la esperada;
4. `CmsSignatureVerifier`: la firma CMS es válida sobre esos bytes, trae el certificado,
   está vigente y, si `SIGNATURE_TRUSTED_CA_BUNDLE` apunta a un PEM, su cadena llega a
   una de esas entidades (o la firmó directamente una entidad de la lista, que debe ser CA y
   estar vigente); el certificado debe ser de firma (`keyUsage` con firma digital o no
   repudio y, si trae `extendedKeyUsage`, protección de correo). Sin la lista: en producción
   se rechaza la firma; en local y pruebas solo verifica integridad;
5. si `SIGNATURE_REQUIRE_HOLDER_MATCH` es `true`, el titular del certificado debe
   coincidir con el nombre de la cuenta (`SignerHolderMatcher`: exactamente las mismas
   palabras, al menos dos, sin importar orden, tildes, mayúsculas ni puntuación);
6. guarda una fila en `firmas_silabo` (sílabo, revisión, rol, usuario, declaración,
   firma CMS, titular, número, emisor, vigencia y fecha) y audita `silabo.firmado` con
   el rol.

Cualquier error responde 422 en `signature` con un mensaje corto, y la decisión no se
registra. No hay sello de tiempo externo: la hora de la firma es la del servidor.

Reiniciar o eliminar el sílabo borra sus firmas; el ciclo nuevo se vuelve a firmar.

Configuración y armado del PEM: [deployment.md](deployment.md#firma-electrónica).

## Retención

No hay borrado automático de archivos. Los archivos de un sílabo solo se borran cuando
se regenera o se elimina ese sílabo (`InProgressWork`), después del commit.

Eliminar un documento de apoyo borra su fila, sus filas de `archivos` (original y vista)
y, después del commit, los archivos del disco, para ocupar solo el espacio necesario.
