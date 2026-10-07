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
- [ ] 2. Persistencia y herencia por tipo.
- [ ] 3. API de plantilla simplificada.
- [ ] 4. Snapshot v7 y render Word/PDF por tipo.
- [ ] 5. Plantilla inicial.
- [ ] 6. Frontend: registro y editor del docente.
- [ ] 7. Frontend: constructor simple.
- [ ] 8. Limpieza del modelo anterior.

## POR VALIDAR

- Formato exacto del valor de `tabla` (hoy: mapa `fila → columna → texto`).
- Lista de fuentes de `automatico` (hoy solo `identification_card`).
- Estrategia de migración de plantillas existentes (etapas 2 y 5).
