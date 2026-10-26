# Retirar la importación de fichas con OCR e IA

## Alcance

Petición explícita: eliminar el flujo anterior de OCR y extracción de fichas por IA.
El chat docente se decidirá aparte. RF-027..033; CU-05; COR-16;
RNF-018..028, RNF-029..036. Las RN y CP no tienen IDs locales más precisos.
PV-14 y PV-18 corresponden al chat y ya no condicionan la importación Excel.
PV-16, permisos y regeneración de sílabos conservan su comportamiento.

## Sondeo

Excel ya funciona sin servicios externos. Quedan el POST antiguo, gateways,
lectores, dos trabajos de cola, configuración, interfaz de progreso y pruebas
exclusivas del flujo retirado. Poppler también ubica las firmas PDF; sigue instalado.
No se cambian el esquema ni las migraciones aplicadas. Los registros históricos
permanecen en la base, pero solo las vistas previas Excel se consultan y aplican.

## Pasos

1. Eliminar lectores, gateways, jobs, ruta y request antiguos y su configuración.
   Simplificar las acciones y el contrato a Excel; rechazar propuestas antiguas.
2. Retirar carga histórica, polling y progreso OCR de la SPA. Adaptar las pruebas
   existentes que siguen vigentes y borrar las exclusivas del código eliminado.
3. Regenerar OpenAPI, actualizar decisiones, arquitectura, operación y trazabilidad.
4. Comprobar referencias, tipos, estilo y secretos sin añadir ni ejecutar pruebas.
   Hacer commit y push a main en ambos repositorios, según autorización vigente.
