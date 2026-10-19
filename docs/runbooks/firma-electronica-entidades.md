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

ARCOTEL no publica los certificados: cada entidad los publica en su sitio.

## Archivo ya preparado (2026-10-19)

`resources/trust/ca-ecuador.pem` reúne 35 certificados (raíces e intermedios vigentes) de las
entidades de abajo; los originales están en `resources/trust/ec/<entidad>/`. Para usarlo:

1. En las variables del servidor: `SIGNATURE_TRUSTED_CA_BUNDLE=/var/www/html/resources/trust/ca-ecuador.pem`
   y redesplegar.
2. Probar con un `.p12` real de una de esas entidades: OpenSSL valida el propósito del certificado
   (firma de documentos/correo); uno que no lo declare sería rechazado aunque sea legítimo. Probar
   también con uno hecho en casa: debe rechazarse con «no fue emitido por una entidad certificadora
   reconocida».

Si un usuario firma con una entidad que falta (ver «Pendientes»), su firma se rechaza hasta
agregar sus certificados.

### Qué se cargó y con qué confianza

Confianza alta = la huella coincide con la que publica la propia entidad en su declaración de
prácticas (DPC) o en su página. Media = se descargó por HTTPS del sitio oficial de la entidad,
que no publica huella; conviene confirmarla con la entidad.

| Entidad | Certificados | Fuente | Confianza |
| --- | --- | --- | --- |
| Banco Central del Ecuador | raíz, AC 2011 y raíz 2008 | ocsp.eci.bce.ec (HTTP, repositorio EJBCA del BCE) | Alta para la raíz (DPC); media para las otras dos |
| Consejo de la Judicatura | raíz y subordinada | instalador de icert.fje.gob.ec | Media |
| Security Data | raíz, SUBCA-1, raíz CA-2, SUBCA-2 | securitydata.net.ec | Media |
| Uanataca Ecuador | raíz 2016, CA1 y CA2 2016, Express, CA1 y CA2 2021 | web.uanataca.com | Alta |
| Eclipsoft | raíz y subordinada 01 | firmas.eclipsoft.com | Alta |
| Datilmedia | raíz, subordinada y corto plazo | datil.com/certificados | Media |
| Argosdata | raíz y subordinada | signare.argosdata.com.ec | Media |
| Alpha Technologies | raíz y Atlas Signing CA | alphaside.com | Media |
| Corpnewbest | raíz y 3 subordinadas | newbest.net | Media |
| Firmasegura | raíz y SUBCA-1 | firmaseguraec.com | Media |
| Appfirmas | raíz y subordinada | stra.appfirmas.com | Alta |
| Darkcam | raíz y 2 subordinadas | dark-cam.com | Alta |
| Letmi Ecuador | raíz y subordinada | certs.letmi.app | Alta |

### Pendientes

- **Registro Civil:** su sitio de firma no respondía (error 523); reintentar o pedir los certificados.
- **ANFAC Ecuador:** ANF solo publica su jerarquía global (España); no se cargó para no confiar en
  firmas que no son de Ecuador. Pedir a ANFAC su raíz de Ecuador.
- **Primecorelat:** no publica sus certificados; pedirlos a la entidad.
- **Lazzate:** solo publica las intermedias y desde dominios `enext1.xyz`/`enext2.xyz` por HTTP; sin su
  raíz la cadena no valida. Pedir la raíz.

## Actualizar el archivo

Cuando una entidad agregue o renueve certificados, copiar los archivos a
`resources/trust/ec/<entidad>/` y regenerar:

```bash
php artisan signatures:build-trust-bundle $(find resources/trust/ec -type f | sort) \
  --output=resources/trust/ca-ecuador.pem
```

El comando rechaza lo que no sea de una entidad certificadora o esté vencido, y muestra la huella
SHA-256 de cada certificado para compararla con la que publica la entidad. Vencen primero la
raíz del BCE de 2008 (2028-10-23) y la de Uanataca CA1/CA2 2016 (2029-03-11).

Sin sello de tiempo externo: la hora de la firma es la del servidor (DT-16).
