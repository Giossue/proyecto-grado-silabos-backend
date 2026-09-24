# Gotenberg en Dokploy (PDF igual al Word)

Gotenberg convierte el Word del sílabo en un PDF con el mismo formato: tablas, colores
y logos. Si no está configurado, el sistema genera un PDF de texto simple. La firma
electrónica se hace sobre el PDF, así que conviene tenerlo.

## Pasos

1. En el proyecto de Dokploy, crea un servicio nuevo de tipo **Aplicación → Docker**.
   - Imagen: `gotenberg/gotenberg:8`
   - Nombre sugerido: `gotenberg`
   - Puerto interno: `3000`. No le pongas dominio ni lo publiques a internet.
2. Despliega el servicio y espera a que quede en verde.
3. Copia el nombre interno que Dokploy le asignó al servicio. Aparece en la ficha del
   servicio (por ejemplo `silabos-gotenberg-abc123`).
4. En las **variables de entorno del backend y también del worker**, agrega:
   ```
   GOTENBERG_URL=http://<nombre-interno>:3000
   GOTENBERG_TIMEOUT=90
   ```
5. Vuelve a desplegar el backend y reinicia el worker.

## Cómo comprobar que funciona

1. Aprueba un sílabo de prueba y descarga su PDF.
2. Si sale con tablas y logos, igual al Word, Gotenberg funciona.
3. Si sale como texto simple, revisa que `GOTENBERG_URL` esté en el backend **y** en el
   worker, que el nombre y el puerto sean correctos, y los registros del servicio
   `gotenberg` en Dokploy.

## Después del despliegue

- Prueba la firma con tu certificado `.p12` real, primero como docente y luego como
  coordinador.
- Valida el PDF firmado en https://www.firmadigital.gob.ec (FirmaEC) y revisa que el
  sello caiga en la celda "Firma:".
