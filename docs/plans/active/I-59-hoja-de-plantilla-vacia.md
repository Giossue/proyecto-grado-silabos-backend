# I-59 — Hoja de plantilla vacía

> Esta decisión queda supersedida por I-103. La hoja inicial sigue siendo vacía, pero
> ahora conserva el contenedor editable y ofrece una acción explícita para cargar el
> formato oficial.

## Estado

Implementación verificada localmente el 2026-09-08 por decisión explícita del
responsable del producto. Permanece activo hasta la revisión visual de la hoja.

## Decisión

ADM-06 muestra un contenedor carta blanco y vacío al crearlo. No se instalan bloques ni
campos automáticamente. La pantalla ofrece «Editar» para construirlo manualmente y
«Formatear» para cargar de forma explícita la estructura oficial mediante la seed
compartida. No se borran registros de la base ni revisiones.

## Alcance

- RF-017..026; RN-009..012; CU-04; ADM-06.
- Reemplaza en la interfaz administrativa I-56 e I-58.
- Conserva I-32, la plantilla única y la inmutabilidad de revisiones.

## Criterios

- [x] La hoja permanece visible con orientación, tamaño y márgenes.
- [x] La plantilla recién creada persiste sin secciones, bloques ni campos.
- [x] El formato oficial se instala solo desde la acción explícita «Formatear» o el
      seed idempotente.
- [x] Los datos persistidos no se eliminan.
- [x] Verificar tipos, formato, pruebas focalizadas y compilación.

## Evidencia local

- 40 pruebas focalizadas y 1.225 aserciones.
- Pint, ESLint, `vue-tsc`, PHPStan y compilación Vite aprobados.
