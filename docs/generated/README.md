# Referencias generadas

Este directorio guarda archivos que salen del código o de la base. No se editan a mano.

| Archivo | Qué contiene | Cómo se genera |
|---|---|---|
| [database-schema.md](database-schema.md) | Tablas, columnas, claves, únicos, `CHECK`, triggers, vistas y funciones del esquema `public`. | Consultas de solo lectura a `pg_catalog` con `psql` sobre la base local. El encabezado del archivo indica fecha, última migración y consultas. |

Reglas:

- Cada archivo dice en su encabezado de dónde sale y cuándo se generó.
- Si el archivo está desactualizado, se vuelve a generar; no se corrige a mano.
- Solo se genera desde la base local de desarrollo. Nunca desde una base remota.
- No se guardan secretos, datos de personas ni conteos de filas.

El contrato HTTP también es un archivo generado, pero vive en
`docs/architecture/openapi.json` (`php artisan openapi:export`).
