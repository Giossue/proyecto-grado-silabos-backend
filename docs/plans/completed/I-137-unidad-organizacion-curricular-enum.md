# I-137 — Unidad de organización curricular como enum

## Estado

Implementado el 24 de septiembre de 2026. Pendiente: aplicar la migración en la base
remota de pruebas.

## Objetivo

Decisión del usuario: la unidad de organización curricular era texto libre con
sugerencias de valores ya usados, y las marcas X del sílabo comparaban contra
«Unidad Básica» (mayúscula) mientras la base guardaba «Unidad básica», así que nunca se
pintaban. Las unidades las fija el Reglamento de Régimen Académico (art. 30): básica,
profesional e integración curricular. Pasan a valores fijos en código, como la modalidad
(I-37).

**Revisión de fuente, 2026-09-24.** La atribución anterior al art. 30 del CES no está
respaldada por la codificación consultada: el texto anterior enumeraba las tres
unidades en el art. 31 y fue derogado por el reglamento de 2022. La malla de Software
publicada por la UEB sí utiliza estas categorías. `DT-15` quedó cerrado por decisión del
responsable del producto: una materia elige una sola unidad y el ciclo no asigna ni
restringe unidades. El enum existente no equivale a una regla del CES que asigne unidades
a ciclos concretos.

## Trazabilidad

RF de malla (UI Coordinación › Estructura académica) e identificación institucional del
sílabo (`docs/product/identificacion-institucional.md`).

## Decisiones

- Enum `App\Modules\Academic\Domain\CurricularUnit` (`basica`, `profesional`,
  `integracion_curricular`) con `label()`, `values()`, `options()` y `tryFromText()` para
  reconocer fotografías históricas («Unidad Básica», «Unidad de Titulación»).
- Migración `2026_09_24_000105`: traduce lo existente por el texto guardado, acorta la
  columna a 30 y agrega `asignaturas_unidad_organizativa_curricular_check`. Lo que no
  se reconoce queda en NULL para que Coordinación lo corrija.
- `Subject` castea la columna al enum. La API expone `organization_unit` (valor),
  `organization_unit_label` y `organizationUnitOptions`. Los Form Requests validan con
  `Rule::in`.
- Las fotografías del sílabo (`contexto_academico`) siguen guardando la etiqueta, que es
  lo que muestran el documento y la ficha. `config/syllabus_variables.php` compara las
  marcas contra esas etiquetas; la tercera fila de la ficha dice «Unidad de integración
  curricular».
- Frontend: el formulario de materia usa un `Select` con las opciones del servidor; se
  elimina `OrganizationUnitInput.vue`. La leyenda de la malla muestra las tres unidades
  con color fijo por posición.

## Verificación

- Backend: `pint`, `phpstan --memory-limit=1G`, pruebas de Academic, Syllabus
  (IdentificationCard, SyllabusProcess) y SpanishSchema en verde (65 pruebas).
- Frontend: `vue-tsc`, `eslint` y `vitest` sobre malla y páginas (47 pruebas).
- Local: `php artisan migrate` dejó 16 `basica`, 26 `profesional`, 1
  `integracion_curricular`.
