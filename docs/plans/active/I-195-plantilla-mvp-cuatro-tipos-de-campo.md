# I-195 Plantilla MVP con cuatro tipos de campo y registro único

Pedido del responsable, 2026-10-07: rehacer la plantilla del sílabo como MVP con solo
cuatro tipos de campo fijos, definidos en un único registro fácil de extender.

## Decisiones confirmadas

- Se conservan el título y los logos del sílabo.
- Las tablas del docente tienen filas fijas (p. ej. distribución de 16 semanas); el docente
  no agrega filas.
- Discapacidad y formación/experiencia pasan a ser campos de texto sueltos; la tabla oficial
  de identificación es un campo automático fijo (`IdentificationCard::grid`).

## Tipos

1. `texto`: lo escribe el docente.
2. `ficha`: lo escribe coordinación una vez por materia y se hereda al sílabo; config
   `{adjustable: bool}` («el docente puede ajustarlo»).
3. `tabla`: filas y columnas fijas (`columns`, `rows`) que llena el docente.
4. `automatico`: config `{source}`, sin valor guardado; se resuelve de datos maestros.

## Aplazado

Hoja horizontal/margen/letra por sección, hoja libre con cinta Insertar, marcas
Docente/Sí-No/Firma/Dato automático insertables, anchos por celda.

## Etapas

- [x] 0. Documentación: este plan, ADR-0009, `docs/architecture/field-types.md`, trazabilidad.
- [x] 1. Registro backend y los 4 tipos (aditivo; no cambia la plantilla actual ni migra).
- [x] 2. Persistencia y herencia por tipo (migración `000202`; con capa de compatibilidad, ver Deuda).
- [ ] 3. API de plantilla simplificada.
- [ ] 4. Snapshot v7 y render Word/PDF por tipo.
- [ ] 5. Plantilla inicial.
- [ ] 6. Frontend: registro y editor del docente.
- [ ] 7. Frontend: constructor simple.
- [ ] 8. Limpieza del modelo anterior.

## Etapa 2: decisiones y deuda

- `definiciones_campo.tipo_definicion_campo` solo admite `texto|ficha|tabla|automatico`. La
  configuración por tipo va en `opciones_definicion_campo` (objeto JSON): POR VALIDAR si merece
  columna propia. En `ficha`, si falta `adjustable`, se toma de `editable_docente`.
- Filas antiguas: la migración las convierte sin borrar (`texto_libre` heredado de la ficha a
  `ficha`; `referencia_maestra` a `automatico`; el resto a `texto`) y guarda el tipo antiguo en
  `clase_anterior_definicion_campo`.
- `FieldDefinition::fieldType()` y `typeConfig()` leen del registro. `InheritMasterValues` usa
  `defaultValue` y `UpdateDraftField` usa `validateValue` para campos sin clase anterior;
  `SubjectProfileFields` filtra por tipo `ficha`.
- Deuda a quitar en la etapa 8: columna `clase_anterior_definicion_campo`, el atributo `tipo`
  que devuelve la clase anterior (`FieldDefinition::getAttribute/setAttribute/saving`),
  `hasLegacyClass()` y las rutas de compatibilidad en `UpdateDraftField` e `InheritMasterValues`.
  Las selecciones, número y fecha siguen existiendo solo por esa capa.

## POR VALIDAR

- Formato exacto del valor de `tabla` (hoy: mapa `fila → columna → texto`).
- Lista de fuentes de `automatico` (hoy solo `identification_card`).
- Estrategia de migración de plantillas existentes (etapas 2 y 5).
