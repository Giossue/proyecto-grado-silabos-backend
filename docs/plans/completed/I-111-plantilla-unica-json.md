# I-111 — Plantilla única como documento JSON

## Decisión

Por decisión explícita del responsable del producto del 23 de septiembre de 2026,
la plantilla institucional es un recurso singleton permanente: no existe un catálogo
ni puede existir una segunda plantilla. Su tabla física se nombra en singular como
`plantilla_silabo`.

La composición visual completa —secciones, bloques, tablas y su configuración— se
guarda en `plantilla_silabo.documento_plantilla` como JSONB versionado. Las
`definiciones_campo` permanecen relacionales porque las respuestas, validaciones,
configuración de IA y evidencia histórica las referencian. Las variables automáticas
continúan siendo referencias a un catálogo seguro y las filas repetibles siguen siendo
datos del sílabo, no de la plantilla.

Esta decisión reemplaza únicamente la persistencia relacional de
`componentes_plantilla` definida en I-85. Conserva la plantilla única de I-25/I-32,
el editor JSON de I-56/I-102 y las fotografías inmutables de cada revisión.

## Alcance

- Renombrar `plantillas_silabo` a `plantilla_silabo`.
- Migrar sin pérdida secciones y bloques a un documento JSONB singleton.
- Retirar `componentes_plantilla` y conservar los UUID de bloques dentro del JSON.
- Mantener `definiciones_campo`, `valores_campo` y `filas_repetibles`.
- Adaptar acciones, validación, renderizado, IA, seed y contrato JSON sin cambiar la
  experiencia visual del editor.
- Actualizar esquema, trazabilidad, OpenAPI y pruebas.

## Trazabilidad

- RF-017..026; RN-009..012; CU-04; ADM-06.
- RNF-001, RNF-010, RNF-022 y RNF-025.
- CP-F plantilla e inmutabilidad; CP-N persistencia y recuperación.

## Trabajo

- [x] Migración forward/backfill/down con tabla singleton singular.
- [x] Modelo de documento embebido y compatibilidad de campos.
- [x] Casos de uso administrativos y lectores de sílabo/IA/documentos.
- [x] Pruebas de migración, autorización, edición, snapshots y exportación.
- [x] Documentación, esquema productivo, trazabilidad y OpenAPI.
- [x] Verificaciones backend/frontend, commit y push.

## Recuperación

El `down` reconstruye `componentes_plantilla` con los mismos UUID, posiciones y
configuraciones conservados en el documento JSON. No modifica fotografías de revisiones
ni respuestas docentes.

## Resultado

- La migración `000094` preserva UUID y contenido al convertir filas a JSONB y al
  ejecutar su `down`/`up`.
- PostgreSQL impide definiciones que apunten a un bloque ausente y evita retirar del
  documento un bloque todavía referenciado.
- `composer verify`: 304 pruebas, 3.846 aserciones, Pint, PHPStan y escaneo de secretos.
- SPA: contrato OpenAPI (126 consumidores/129 operaciones), TypeScript y ESLint pasan.
- La migración se aplicó en la base remota con la cuenta de aplicación y quedó en el
  lote 61. El respaldo lógico previo fue legible; después del cambio se verificaron una
  plantilla, una sección, un bloque y un campo, sin referencias huérfanas, junto con los
  dos triggers de integridad y el índice singleton.
