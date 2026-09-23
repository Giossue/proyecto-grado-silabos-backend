# I-81 — Logo de facultad como objeto almacenado

## Decisión y alcance

El logo de una facultad es un archivo privado institucional. Su contenido vive en el
disco configurado por Laravel y la facultad referencia su metadato mediante
`logo_objeto_id → objetos_almacenados.id`; se elimina la ruta física de
`facultades`.

Cada carga crea un objeto inmutable y una ruta nueva. Reemplazar un logo no altera el
objeto anterior ni inventa sus metadatos; la facultad solo cambia su referencia actual.

## Trazabilidad

- RN-001: archivos privados y autorizados.
- ADM-04: carga y reemplazo administrativo del logo de facultad.
- I-68/I-123: logo previo al título del sílabo y gestión exclusiva de Administración.

## Migración y recuperación

La migración se ejecuta dentro del contenedor desplegado, donde está montado el disco
privado. Para cada ruta anterior que exista, calcula tamaño, MIME y SHA-256 reales,
crea su `objetos_almacenados` y enlaza la facultad. Una ruta cuyo archivo ya no exista se
considera no configurada. Solo después elimina `ruta_logo_facultad`.

El `down` restaura la ruta desde el objeto referenciado y no borra objetos ni archivos,
porque son inmutables.

## Verificación local

- Migración `000073` aplicada localmente.
- `InstitutionalLogosTest`, convocatorias, panel y `SpanishSchemaTest`: 30 pruebas.
- Suite completa: 429 pruebas y 6605 aserciones.

## Pendiente remoto

La fotografía remota anterior a esta migración conserva una facultad con ruta de logo.
El despliegue debe ejecutar `000073` dentro del contenedor para convertir ese archivo
desde su volumen privado. La documentación de producción se actualizará solo después
de comprobar la migración remota.
