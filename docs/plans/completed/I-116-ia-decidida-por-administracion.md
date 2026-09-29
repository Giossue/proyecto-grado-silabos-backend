# I-116 — Administración decide la IA de cada campo

## Objetivo

Retirar la excepción por carrera que permitía a Coordinación cambiar el uso de IA de un
campo. El interruptor «Usar asistencia de IA» de la plantilla es la única fuente: la IA
solo sugiere y el docente decide si aplica cada sugerencia, así que una segunda capa de
decisión por carrera no aporta control y complica la configuración.

## Fuente de decisión

Instrucción explícita del responsable del producto (23 de septiembre de 2026): «el admin
define eso; lo que dice el agente son sugerencias, entonces es cuestión del docente
tomarlo en cuenta o no». Sustituye las decisiones 1 a 3 de I-72 sobre la excepción de
Coordinación y la parte de «Configuración efectiva» de ese plan.

## Cambios

- [x] Migración `000095_remove_coordinator_ai_preferences`: elimina
      `preferencias_ia_carrera` y `definiciones_campo.ia_coordinacion_configurable`.
      `down()` recrea ambas estructuras vacías.
- [x] Retirar `ResolveEffectiveAiConfiguration`, `CareerAiPreference`,
      `SaveCareerAiPreferences`, su request, su controlador y las rutas
      `GET|PUT|DELETE /api/v1/coordination/ai-configuration`.
- [x] Chat, revisión global, análisis por campo y carga del sílabo consultan
      directamente `definiciones_campo.ia_habilitada`.
- [x] Las requests de plantilla dejan de aceptar `ai_coordinator_configurable`; un
      campo retirado del diseño antes de I-116 restaura solo `editable_docente` e
      `ia_habilitada`.
- [x] SPA: se quita la casilla «Permitir que Coordinación cambie este valor» del
      diálogo del campo y de las propiedades del bloque; el texto de ayuda de IA indica
      que el docente decide sobre cada sugerencia.
- [x] Contrato OpenAPI regenerado (126 operaciones consumidas por la SPA).

## Fuera de alcance

`plantilla_silabo.revision_configuracion` y sus triggers siguen existiendo. Ya no los
consume la IA; retirarlos exige revisar las migraciones de triggers de I-111 y queda para
una limpieza de esquema separada.

## Verificación

- `php artisan test tests/Feature/AiAssistance tests/Feature/Configuration` (89).
- `php artisan test tests/Feature/Syllabus` (75).
- `AdministrationDecidesAiTest`: las rutas de Coordinación responden 404.
- PHPStan en `app/Modules/AiAssistance`.
- SPA: `npm run typecheck`, `AdminTemplatePage.test.ts`, lint de los archivos tocados y
  `npm run contract:check`.
