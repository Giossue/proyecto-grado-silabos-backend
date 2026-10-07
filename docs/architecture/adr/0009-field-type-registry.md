# ADR-0009: Registro único de tipos de campo de la plantilla

## Estado

Propuesto — 2026-10-07 (POR VALIDAR hasta cerrar la etapa 3 de I-195).

## Contexto

La plantilla del sílabo mezcla reglas por tipo en varios lugares (validación, herencia,
render). Agregar un tipo obliga a tocar muchos archivos.

## Decisión

- Cada tipo implementa `FieldType` en `app/Modules/Configuration/Domain/FieldTypes/`.
- `FieldTypeRegistry` es el único punto que conoce los tipos; se llena desde
  `config/field_types.php` y `get()` falla con error claro si el tipo no existe.
- MVP: `texto`, `ficha`, `tabla`, `automatico`.
- El resto del sistema pregunta al registro; nunca compara claves de tipo con `if`.

## Consecuencias

Agregar un tipo es una clase, una línea de configuración y pruebas. `toWord` y `toSnapshot`
se incorporan a la interfaz en la etapa 4.
