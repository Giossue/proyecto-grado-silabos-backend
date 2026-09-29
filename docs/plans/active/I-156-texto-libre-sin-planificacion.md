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

- [ ] Tipo `texto_libre`: migración del CHECK, validación y normalización del documento
      del docente, vacío cuenta como faltante, texto plano para IA y PDF.
- [ ] Resolvedor y Word: el Texto libre entra como bloques (párrafos, listas, tablas).
- [ ] Crear parte: «Texto libre» o «Contenido de la plantilla» (hoja vacía); se retira `blank`.
- [ ] Editor del docente con barra simple (negrita, cursiva, viñetas, numeración, tabla).
- [ ] Hoja de Administración: Insertar Tabla y Lista; sin «Filas que agrega el docente».
- [ ] Pruebas, documentación y verificación.

## Etapa 2 — Quitar lo viejo

- [ ] Formato oficial: listas, tablas del docente y planificación como Texto libre.
- [ ] Retiro de `PlanningTable`, reglas de planificación, `planning_expectations`,
      resumen de planificación y contexto de unidades de la IA.
- [ ] Retiro de filas repetibles: tablas con `repeatKey`, nodos `column`, `repetible`,
      `filas_repetibles`, `SyllabusTableEditor`, `SyllabusTableView`, `WordDataTable`.
- [ ] Plantilla remota de pruebas convertida (respaldo previo).
- [ ] Pruebas, documentación y verificación.
