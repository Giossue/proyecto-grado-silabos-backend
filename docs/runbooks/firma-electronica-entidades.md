# Firma electrónica: entidades certificadoras de confianza

Por defecto el sistema comprueba que la firma es íntegra y que su titular coincide con la
cuenta, pero acepta certificados de cualquier emisor. Para que solo valgan los emitidos por
entidades acreditadas en Ecuador se carga un archivo con sus certificados raíz e intermedios.

## Entidades acreditadas

Fuente: listado de ARCOTEL «Entidades de Certificación de Información y Servicios Relacionados
Acreditados y Terceros Vinculados» (https://www.arcotel.gob.ec/firma-electronica-acreditada/),
consultado el 2026-10-19. La lista cambia: confirmarla en ARCOTEL antes de cargar el archivo.

Alpha Technologies · ANFAC Autoridad de Certificación Ecuador · Appfirmas · Argosdata ·
Banco Central del Ecuador · Consejo de la Judicatura · Corpnewbest · Darkcam · Datilmedia ·
Dirección General de Registro Civil, Identificación y Cedulación · Eclipsoft · Firmasegura ·
Lazzate · Letmi Ecuador · Primecorelat · Security Data · Uanataca Ecuador.

Para el piloto basta con cargar las de quienes emitieron la firma de los docentes,
coordinadores y bibliotecarios de la UEB; cada una publica sus certificados en su sitio web
(por ejemplo, eci.bce.ec, securitydata.net.ec, web.uanataca.com/ec, anf.es/ec).

## Pasos

1. Descargar de cada entidad su certificado raíz y sus intermedios (`.cer`, `.crt` o `.pem`).
2. Armar el archivo en el servidor:

   ```bash
   php artisan signatures:build-trust-bundle raiz-bce.cer intermedia-bce.cer raiz-uanataca.crt \
     --output=storage/app/private/ca-confiables.pem
   ```

   El comando rechaza lo que no sea una entidad certificadora o esté vencido, y muestra la
   huella SHA-256 de cada certificado: compararla con la que publica la entidad.
3. En las variables del servidor: `SIGNATURE_TRUSTED_CA_BUNDLE=/var/www/html/storage/app/private/ca-confiables.pem`
   y redesplegar.
4. Probar con un `.p12` real de una de esas entidades antes de dar por terminado: OpenSSL valida el
   propósito del certificado (firma de documentos/correo); un certificado que no lo declare sería
   rechazado aunque sea legítimo. Si pasa, probar también con uno hecho en casa: debe rechazarse
   con «no fue emitido por una entidad certificadora reconocida».

Sin sello de tiempo externo: la hora de la firma es la del servidor (DT-16).
