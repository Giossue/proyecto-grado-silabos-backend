# I-227 — Validación de la firma electrónica

## Pedido del responsable, 2026-10-19

Reforzar la firma: que el certificado sea de una entidad reconocida y que su titular sea
quien firma. Quedó fuera, por petición de la UEB, una verificación pública que señale
documentos alterados y obligue a volver a firmar: genera fricción con su trabajo habitual.

## Cambios

- `config/signatures.php`: `SIGNATURE_TRUSTED_CA_BUNDLE` (archivo PEM) y
  `SIGNATURE_REQUIRE_HOLDER_MATCH` (activo por defecto).
- `IncrementalPdfSignatureVerifier`: con el archivo configurado, la cadena del certificado debe
  llegar a una entidad de confianza. Sin archivo, solo integridad.
- `SignerHolderMatcher` y `AcceptDocumentSignature`: el titular debe coincidir con el nombre de la
  cuenta (mismas palabras, sin importar orden, tildes ni mayúsculas; al menos dos).

## Pendiente

DT-16: la UEB indica las entidades certificadoras reconocidas y si exige sello de tiempo externo.
Sin eso no se carga el archivo PEM ni se implementa el sello.

## Verificación

`DocumentSignatureTest` y `SignatureTrustTest` (certificados de prueba emitidos por una entidad propia).
