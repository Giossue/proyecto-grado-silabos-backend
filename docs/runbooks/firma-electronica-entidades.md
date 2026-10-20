# Firma electrónica: entidades certificadoras de confianza

Cada sílabo aprobado se firma en orden: docente, coordinación y biblioteca. Cada persona
firma en el navegador con su archivo `.p12`; al servidor solo llega el PDF firmado.

Por defecto el sistema comprueba que la firma es íntegra y que su titular coincide con la
cuenta (`SIGNATURE_REQUIRE_HOLDER_MATCH=true`), pero acepta certificados de cualquier
emisor. Para que solo valgan los emitidos por entidades acreditadas en Ecuador se carga un
archivo con sus certificados raíz e intermedios (`SIGNATURE_TRUSTED_CA_BUNDLE`).

## Entidades acreditadas

Fuente: listado de ARCOTEL «Entidades de Certificación de Información y Servicios Relacionados
Acreditados y Terceros Vinculados» (https://www.arcotel.gob.ec/firma-electronica-acreditada/),
consultado el 2026-10-19. La lista cambia: confirmarla en ARCOTEL antes de cargar el archivo.
ARCOTEL no publica los certificados: cada entidad los publica en su sitio.

El archivo incluye estas 14 (la UEB debe confirmarlas, DT-16): Alpha Technologies, Appfirmas, Argosdata, Banco Central del Ecuador,
Consejo de la Judicatura, Corpnewbest, Darkcam, Datilmedia, Eclipsoft, Firmasegura, Letmi Ecuador,
Registro Civil, Security Data y Uanataca Ecuador. ANFAC, Primecorelat y Lazzate quedaron fuera por
decisión del responsable.

## Archivo preparado

`resources/trust/ca-ecuador.pem` reúne 52 certificados (raíces e intermedios vigentes) de esas
entidades; los originales están en `resources/trust/ec/<entidad>/`. Para usarlo:

1. En las variables del servidor: `SIGNATURE_TRUSTED_CA_BUNDLE=/var/www/html/resources/trust/ca-ecuador.pem`
   y redesplegar.
2. Probar con un `.p12` real de una de esas entidades (ver «Probar la firma»): OpenSSL valida el
   propósito del certificado (firma de documentos o correo); uno que no lo declare sería rechazado
   aunque sea legítimo. Probar también con uno hecho en casa: debe rechazarse con «no fue emitido por
   una entidad certificadora reconocida».

El sistema acepta un certificado si su cadena llega a una raíz del archivo o si lo firmó directamente
una entidad del archivo. Lo segundo cubre al Registro Civil, cuya raíz no se publica: solo se cargó su
CA subordinada.

### De dónde salen los certificados

Dos fuentes que se contrastaron entre sí:

- El sitio oficial de cada entidad (por HTTPS, salvo el repositorio del Banco Central que es HTTP).
- La biblioteca oficial del Estado para firmar y validar (FirmaEC, MINTEL), que trae incrustados los
  certificados de las entidades acreditadas; se extrajeron de su instalador publicado en
  https://mirror.cedia.org.ec/firmaec/ (enlazado desde firmadigital.gob.ec). Las huellas SHA-256 de lo
  descargado de cada sitio coinciden con las de FirmaEC; de FirmaEC se tomaron además las emisiones
  nuevas (2025 y 2026) y la CA del Registro Civil.

Aparte, Uanataca, Eclipsoft, Appfirmas, Darkcam, Letmi y el Banco Central publican la huella en su
declaración de prácticas o en su sitio y también coincide.

Solo vienen de su sitio y no están en FirmaEC: la raíz del BCE de 2008, Uanataca Express y CA1/CA2 2021,
Letmi (emisión de la DPC V5) y la subordinada de corto plazo de Datilmedia de 2033.

## Actualizar el archivo

Cuando una entidad agregue o renueve certificados, copiar los archivos a
`resources/trust/ec/<entidad>/` y regenerar:

```bash
php artisan signatures:build-trust-bundle $(find resources/trust/ec -type f | sort) \
  --output=resources/trust/ca-ecuador.pem
```

El comando rechaza lo que no sea de una entidad certificadora o esté vencido, y muestra la huella
SHA-256 de cada certificado para compararla con la que publica la entidad. Vencen primero la raíz del
BCE de 2008 (2028-10-23) y las CA1/CA2 de Uanataca 2016 (2029-03-11).

## Probar la firma

Después de cada despliegue que toque documentos o firmas:

1. Apruebe un sílabo de prueba y espere su PDF.
2. Firme con un `.p12` real, primero como docente, luego como coordinación y al final como
   biblioteca. Cada cuenta usa su propio certificado, cuyo titular debe coincidir con el
   nombre de la cuenta.
3. Valide el PDF firmado en https://www.firmadigital.gob.ec (FirmaEC) y revise que cada
   sello caiga en su celda «Firma:».

Para una prueba local sin certificados reales, el frontend trae `scripts/signature-e2e.sh
<pdf>`. Crea dos `.p12` de prueba (docente y coordinación), firma el PDF dos veces con el
mismo código del navegador, verifica cada firma con el verificador del backend y muestra
`pdfsig`. Espera el backend junto al frontend (o en `BACKEND_DIR`) y necesita `openssl`,
`php`, `pdfsig` y las dependencias del frontend instaladas. Con `SIGNATURE_TRUSTED_CA_BUNDLE` activo esas firmas se rechazan, como
corresponde.

Sin sello de tiempo externo: la hora de la firma es la del servidor (DT-16).
