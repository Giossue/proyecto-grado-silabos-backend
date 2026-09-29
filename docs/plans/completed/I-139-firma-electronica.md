# I-139 — Firma electrónica en el navegador

## Estado

Backend y frontend implementados (24 de septiembre de 2026). Falta desplegar Gotenberg
en Dokploy (`GOTENBERG_URL`) y probar con un `.p12` real emitido en Ecuador y en FirmaEC
(ver POR VALIDAR).

## Decisión de alcance

Hasta ahora la firma electrónica estaba fuera de alcance (`overview.md`, `AGENTS.md`).
El usuario pidió incorporarla el 24 de septiembre de 2026 con estas condiciones:

- se firma **en el navegador**: el archivo `.p12`/`.pfx` y su clave nunca salen del
  equipo del usuario ni se guardan;
- orden fijo: primero el docente vigente del sílabo, después la coordinación vigente de
  la carrera;
- solo sobre el PDF de una revisión aprobada;
- reabrir el sílabo anula el proceso de firma de esa revisión: la nueva revisión se
  firma de nuevo.

## Formato imitado (PDF real firmado con FirmaEC)

`temp/SILABOCALIDAD_SWPAO_II_2626_signed-signed (2).pdf`, estudiado con `pdfsig`:

- firmas incrementales (`adbe.pkcs7.detached`, SHA-512 en FirmaEC), `/Contents` de
  32 768 dígitos hexadecimales (16 KiB);
- sello visible en la celda "Firma:" de la tabla "Revisión y aprobación": QR a la
  izquierda y, a la derecha, en letra monoespaciada, "Validar únicamente en FirmaEC.",
  "Firmado electrónicamente por:" y el nombre del certificado en negrita;
- el QR codifica `FIRMADO POR: …`, `RAZON:`, `LOCALIZACION:`, `FECHA: …`,
  `VALIDAR CON: https://www.firmadigital.gob.ec` y la herramienta.

Nosotros usamos `adbe.pkcs7.detached` con SHA-256 (lo que produce
`@signpdf/signer-p12`) y el mismo tamaño de `/Contents`.

## Piezas

1. **PDF fiel**: Gotenberg (LibreOffice) convierte el DOCX. Se activa con
   `GOTENBERG_URL`; sin ella se conserva el PDF de texto de respaldo. La versión del
   renderizador cambia según el modo. La fecha y el `/ID` del PDF se fijan para que la
   misma revisión dé los mismos bytes.
2. **Tabla `firmas_documento`**: una fila por firma aceptada (`docente`,
   `coordinacion`) de un artefacto de exportación, con el PDF resultante, datos del
   certificado y huella.
3. **API**: `GET /revisions/{revision}/signature` (estado),
   `GET /revisions/{revision}/signature/pdf` (PDF vigente para firmar) y
   `POST /revisions/{revision}/signature` (PDF firmado).
4. **Validación en servidor (sin llaves)**: el PDF nuevo empieza con los bytes exactos
   del anterior, trae una sola firma nueva cuyo `ByteRange` cubre todo el archivo y el
   CMS verifica con OpenSSL contra esos bytes. El certificado debe estar vigente.
5. **Frontend**: tarjeta "Firma electrónica" en el sílabo aprobado del docente y en la
   revisión de coordinación. Diálogo con archivo y clave; firma local con
   `@signpdf/signpdf` + `@signpdf/signer-p12` (node-forge), `pdf-lib` solo para leer
   la estructura del PDF y `qrcode` para el sello. La actualización incremental la
   escribe el propio módulo porque `@signpdf/placeholder-pdf-lib` reescribe el PDF
   completo (rompería la firma del docente) y `@signpdf/placeholder-plain` no dibuja un
   sello visible.

## POR VALIDAR (no son reglas de producción)

- Validar la cadena del certificado contra las CA ecuatorianas (Banco Central, Security
  Data, ANF, Consejo de la Judicatura, Uanataca…). Hoy solo se verifica la integridad
  criptográfica y la vigencia; `pdfsig` o FirmaEC validan la cadena.
- Comprobar que el titular del certificado sea la misma persona de la cuenta (la cuenta
  no guarda cédula). Se registra el titular para auditoría.
- Sello de tiempo (TSA) como hace FirmaEC.
- Probar con un `.p12` real emitido en Ecuador y validar el resultado en FirmaEC.

## Protecciones

Solo contra perder trabajo y quedar atascado: la subida compara con el PDF vigente
(si alguien firmó antes, se rechaza y se pide volver a descargar), la reapertura
bloquea nuevas firmas de la revisión anterior y la regeneración institucional (I-118)
borra también las firmas y sus PDF.

## Frontend (repositorio `proyecto-grado-silabos-frontend`)

- `src/api/signatures.ts`: `getSignatureStatus`, `downloadSignaturePdf`, `submitSignature`
  (`multipart`: `signed_pdf` + `base_sha256`, la huella del PDF descargado).
- `src/lib/signature/certificate.ts`: abre el `.p12`/`.pfx` con node-forge y explica
  clave incorrecta, archivo inválido, certificado vencido o aún no válido.
- `src/lib/signature/signPdf.ts`: escribe la actualización incremental propia (firma,
  campo/anotación, apariencia, página, AcroForm con `SigFlags 3` y tabla de referencias
  clásica o flujo `/XRef` según el original); firma con `@signpdf/signer-p12` (SHA-256,
  `adbe.pkcs7.detached`, `/Contents` de 32 768 hex). Usa la posición `stamp` del estado;
  sin ella, respaldo en la última página. Se carga solo al firmar (import dinámico).
- `src/lib/signature/stamp.ts`: sello con QR (`FIRMADO POR`, `RAZON`, `LOCALIZACION`,
  `FECHA` en hora de Ecuador, `VALIDAR CON`) y texto Courier/Courier-Bold.
- `DocumentSignatureCard.vue`: tarjeta «Firma electrónica» en el sílabo aprobado del
  docente (`approved_revision_id`) y en la revisión aprobada de coordinación; estado,
  firmas registradas, botón «Firmar electrónicamente» solo para quien le toca, diálogo
  (archivo + clave), «Volver a descargar y firmar» si alguien firmó antes y descarga del
  PDF vigente/firmado.
- `scripts/signature-e2e.sh documento.pdf [carpeta]`: prueba de extremo a extremo.

## Verificación

24 de septiembre de 2026:

- Frontend: `npx vue-tsc --noEmit`, `npx eslint src`, `npx vitest run` (159 pruebas;
  incluye `signPdf.test.ts` con `.p12` creado en la prueba y `DocumentSignatureCard.test.ts`
  con mocks) y `npm run build` sin errores. `npm run contract:check` solo reporta una
  ruta ajena a I-139 (`career-academics.ts`, requisitos de la malla).
- `scripts/signature-e2e.sh` con `.p12` de OpenSSL (moderno y `-legacy` 3DES/SHA-1):
  docente y después coordinación firman; `IncrementalPdfSignatureVerifier` acepta cada
  paso, la segunda firma conserva los bytes de la primera y `pdfsig` informa ambas como
  «Signature is Valid» (la última, «Total document signed»). Probado con un PDF de
  PDFKit (tabla `xref`) y con el PDF real firmado en FirmaEC (flujos `/XRef`, 3 firmas
  previas que siguen igual de válidas); los sellos quedan en las celdas «Firma:».
- Firma ejecutada en Chromium real (Playwright sobre el servidor de desarrollo, sin
  `Buffer` nativo): `pdfsig` y el verificador PHP la aceptan.
- `pdfsig` marca «Certificate issuer isn't Trusted» porque los certificados de prueba
  son autofirmados (la cadena real queda POR VALIDAR).
