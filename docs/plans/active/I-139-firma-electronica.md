# I-139 — Firma electrónica en el navegador

## Estado

En curso (24 de septiembre de 2026).

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

## Verificación

Pendiente de ejecutar al cerrar.
