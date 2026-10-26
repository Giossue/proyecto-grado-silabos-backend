# Importación Excel sin tabla de vistas previas

## Alcance autorizado

El usuario pide eliminar `importaciones_ficha`. Se conserva descargar, cargar,
revisar y aplicar con selección de materias y campos. Cerrar o recargar descarta
la vista previa. RF-027..033; CU-05; COR-16; RNF-001..010, RNF-018..028, RNF-029..036.
PV-16 y las confirmaciones de regeneración siguen vigentes; ningún pendiente bloquea
el cambio. Las RN y CP no tienen identificadores locales más precisos.

## Diseño

- POST de vista previa sin persistencia, caché, sesión ni archivos privados. Devuelve
  campos, materias, comparación, huella de estructura y SHA-256 del Excel.
- POST de aplicación sin ID: recibe el mismo archivo, sus dos huellas y la selección.
  Relee y valida en servidor, comprueba alcance y contenido de toda la selección y
  vuelve a comprobar estructura bajo bloqueo. Escribe en `valores_campo` con la
  transacción, permisos, auditoría y regeneración existentes.
- La SPA conserva archivo y vista previa solo mientras el diálogo esté abierto.
  Se retiran recuperación y descarte HTTP, modelo y acciones de importación persistida.
- Nueva migración elimina únicamente `importaciones_ficha`, sin editar migraciones
  aplicadas. Se retiran dependencias de borrado de carreras y referencias de contrato.

## Seguridad y despliegue

La base de este repositorio, confirmada por el usuario, es `silabos_ueb_db`.
`calidad_software` pertenece a otro sistema y no debe consultarse ni modificarse.
Antes del push: verificar identidad y propietario en Sílabos, respaldar fuera del
repositorio y comprobar el archivo. El despliegue ejecuta la migración. Después:
comprobar historial, ausencia de tabla y permisos del resto del esquema.

## Pasos

1. Implementar contrato y flujo sin persistencia; adaptar la SPA y las pruebas existentes.
2. Preparar migración, actualizar documentación, decisiones y trazabilidad; regenerar
   OpenAPI y esquema. No añadir ni ejecutar pruebas sin petición del usuario.
3. Revisar tipos, estilo, referencias y secretos. Hacer commit y push a main en ambos
   repositorios y verificar el resultado del despliegue en la base correcta.
