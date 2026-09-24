# I-132 — Normalización de columnas derivadas

## Estado

Implementado el 2026-09-24 con la migración `2026_09_24_000100_normalize_derived_columns`.
Pendiente: validar con la UEB la ubicación del saldo de horas de prácticas (ver abajo).

## Objetivo

Eliminar datos que duplicaban otra fuente y podían divergir de ella.

## Decisiones

1. `asignaturas.total_horas_asignatura` pasa a ser `GENERATED ALWAYS AS
   (round(horas_ac + horas_pae + horas_aa)) STORED`. La fórmula es la de
   `CurriculumSystemFields::totalHours` y `PlanningTable` (ACD + APE + AA; excluye
   `horas_proyecto`, `horas_ap` y `horas_paec`). La API sigue exponiendo `total_hours`.
   Las filas cuyo total superaba la suma (en local: `SW-P7-PPP` 240 h y `SW-IC8-PSC`
   96 h, con componentes en 0) conservan sus horas: el saldo pasa a `horas_pae`.
   **POR VALIDAR**: si esas horas de práctica deben registrarse en otro componente.
2. `fechas_limite_convocatoria` se retira. Solo `CreateConvocation` la llenaba, copiando
   `inicia_en`/`entrega_en` del proceso, y `ExtendProcessDeadline` la movía junto con el
   proceso; no había plazos por carrera en la interfaz. `ConvocationSchedule` lee el
   proceso. La migración adelanta `entrega_en` si alguna copia tenía una fecha mayor.
3. `plantilla_id` se retira de `silabos`, `convocatorias_universidad`,
   `artefactos_exportacion` y `ejecuciones_ia`. La plantilla es única (índice
   `plantilla_silabo_unica`), se edita en el sitio y la exportación renderiza desde
   `revisiones_silabo.fotografia`, así que la referencia no aportaba historia. Se resuelve
   con `SyllabusTemplate::institutional()`. `definiciones_campo.plantilla_id` se conserva:
   ancla las definiciones con `ON DELETE CASCADE`, sostiene la unicidad de clave y las
   rutas de Configuración (`/admin/template/{template}/...`).
4. `transiciones_silabo` frente a `eventos_auditoria`: solo análisis (ver informe).

## Verificación

- Pint, PHPStan.
- Pruebas: `tests/Feature/{Syllabus,Configuration,Academic,Documents,AiAssistance}`,
  `tests/Architecture`, `tests/Unit/Syllabus`, en `silabos_ueb_test_norm2`.
- Sin cambios de forma en la API: el frontend no se modifica.
