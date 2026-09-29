# I-156 — Texto libre y retiro de la planificación automática

## Decisión del responsable y su tutor, 2026-09-29

- Una parte nueva es **Texto libre** (lo escribe el docente como quiera: párrafos,
  viñetas, numeración y tablas simples) o **Contenido de la plantilla** (lo arma
  Administración con texto fijo, listas, tablas y marcas de I-155).
- Se retira «Filas que agrega el docente».
- La **planificación de unidades se va**: cada docente la escribe a mano. Se retiran las
  sumas de horas, las revisiones de semanas y la tabla automática de unidades.

Sustituye las formas Párrafo/Lista/Tabla de I-155 y las reglas de planificación de I-57,
I-58, I-63, I-66 e I-143. El cálculo de semanas del período (I-109) se conserva porque
las pantallas académicas lo usan.

## Alcance y trazabilidad

- ADM-06, formulario docente, revisión de Coordinación y exportación Word/PDF.
- Las filas repetibles (`repetible`, `filas_repetibles`) sostienen hoy también las listas y
  tablas que llena el docente; retirarlas exige convertir esas partes a Texto libre.
- Valor de Texto libre: documento JSON (párrafo, texto con negrita/cursiva, salto,
  listas, tablas simples) en `valores_campo.valor_campo` (jsonb); sin cambio de tabla,
  solo del CHECK de tipos de campo.

## Etapa 1 — Agregar lo nuevo

- [x] Tipo `texto_libre`: migración del CHECK (000156), `FreeText` valida y normaliza
      (solo negrita/cursiva, celdas con colspan/rowspan), vacío cuenta como faltante, texto
      plano para IA, PDF y Word.
- [x] Resolvedor y Word: el Texto libre entra como bloques (párrafos, listas, tablas).
- [x] Crear parte: `free_text` o `template` (hoja vacía); se retira `blank`.
- [x] Editor del docente (`FreeTextEditor`) y lectura (`renderFreeText`).
- [x] Hoja de Administración: Insertar Tabla y Lista; sin «Filas que agrega el docente»
      (se retira `TemplateTableNewDataSheet`). Se retiran `@tiptap/extension-mention` y
      `@tiptap/suggestion`, ya sin uso.
- [x] `composer verify` (402 pruebas); lint, typecheck, 228 pruebas de Vitest y build.

## Etapa 2 — Quitar lo viejo

- [ ] Formato oficial: listas, tablas del docente y planificación como Texto libre.
- [ ] Retiro de `PlanningTable`, reglas de planificación, `planning_expectations`,
      resumen de planificación y contexto de unidades de la IA.
- [ ] Retiro de filas repetibles: tablas con `repeatKey`, nodos `column`, `repetible`,
      `filas_repetibles`, `SyllabusTableEditor`, `SyllabusTableView`, `WordDataTable`.
- [ ] Plantilla remota de pruebas convertida (respaldo previo).
- [ ] Pruebas, documentación y verificación.
