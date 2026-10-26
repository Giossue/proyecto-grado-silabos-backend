# Índice de la documentación

Toda la documentación describe cómo funciona el sistema hoy. El historial está en git.

## Raíz del repositorio

- `../README.md`: qué es el proyecto, cómo levantarlo y cuentas de prueba.
- `../AGENTS.md`: guía para agentes (qué leer, flujo y reglas).
- `../CLAUDE.md`: entrada para Claude; remite a `AGENTS.md`.
- `../ARCHITECTURE.md`: mapa técnico corto.
- `../DECISIONS_STATUS.md`: decisiones vigentes.

## Planes

- `plans/pendientes.md`: lo único que falta: decisiones abiertas (PV), firma (DT-16),
  reglas por confirmar, tareas de producción, aceptación manual y deuda técnica.
- `plans/active/`: planes en curso. Un plan se borra al terminar.

## Producto

- `product/overview.md`: problema, alcance y resultados.
- `product/glossary.md`: vocabulario del dominio.
- `product/use-cases.md`: casos de uso.
- `product/roles-and-permissions.md`: roles, alcance y permisos.
- `product/syllabus-lifecycle.md`: estados del sílabo y transiciones.
- `product/syllabus-sections.md`: contenido de las secciones del sílabo.
- `product/domain-model.md`: entidades, reglas e invariantes.
- `product/identificacion-institucional.md`: tabla de identificación institucional (sección 1).
- `product/screens.md`: pantallas por rol.

## Arquitectura

- `architecture/index.md`: qué leer según la tarea.
- `architecture/stack.md`: tecnologías y comandos.
- `architecture/modules.md`: módulos y sus límites.
- `architecture/backend.md`: organización de la API.
- `architecture/frontend.md`: organización de la SPA.
- `architecture/database.md`: convenciones e invariantes de la base.
- `architecture/deployment.md`: despliegue y Gotenberg.
- `architecture/queues-and-jobs.md`: colas, trabajos e idempotencia.
- `architecture/sequences.md`: secuencias de los flujos críticos.
- `architecture/ai-service.md`: chat de IA y compatibilidad de documentos con OpenParser.
- `architecture/ficha-excel.md`: generación, validación e importación de fichas mediante Excel.
- `architecture/files-and-documents.md`: archivos, Word, PDF y firmas.
- `architecture/template-json.md`: manual del JSON de la plantilla.
- `architecture/openapi.json`: contrato de la API, generado con `php artisan openapi:export`.
- `architecture/adr/`: decisiones de arquitectura:
  - `0000`: formato de un ADR;
  - `0001`: monolito modular;
  - `0002`: revisiones inmutables;
  - `0003`: IA bajo control humano;
  - `0006`: Vue Flow para la malla;
  - `0007`: editor de la plantilla;
  - `0008`: SPA y API separadas;
  - `0010`: campos de plantilla como documentos libres;
  - `0011`: la plantilla es solo datos.

## Generado

- `generated/README.md`: qué se genera y cómo.
- `generated/database-schema.md`: lista de tablas de la base.

## Calidad

- `quality/traceability.md`: requisitos, código y pruebas.
- `quality/acceptance-status.md`: estado de aceptación.
- `quality/testing.md`: estrategia de pruebas.
- `quality/observability.md`: registros, salud y auditoría.
- `quality/frontend-checklist.md`: revisión de pantallas.
- `quality/manual-review-script.md`: guion de revisión manual.
- `quality/performance.md`: rendimiento.
- `quality/code-review.md`: revisión de código.
- `quality/definition-of-done.md`: cuándo algo está terminado.
- `quality/version-control.md`: ramas y commits.

## Referencias

- `references/baseline.md`: línea base documental (artefactos académicos).
- `references/normativa-silabo.md`: normas que sustentan el sílabo.
- `references/entrevista-decisiones-abiertas.md`: guion de la entrevista sobre
  decisiones abiertas.
- `references/entrevista-2026-08-26-hallazgos.md`: respuestas de esa entrevista y su
  efecto en el sistema.

## Operación

- `runbooks/demo.md`: recorrido de demostración con los cuatro roles.
- `runbooks/deploy-dokploy.md`: despliegue en Dokploy.
- `runbooks/release-verification.md`: comprobaciones de release.
- `runbooks/firma-electronica-entidades.md`: lista de entidades certificadoras.

## Seguridad

- `security/principles.md`: principios de seguridad.
- `security/threat-model.md`: amenazas y controles.
- `security/hardening.md`: endurecimiento.
- `security/aviso-de-privacidad.md`: borrador del aviso de privacidad.

## Mantenimiento

Si cambia el producto, actualiza primero la fuente (`DECISIONS_STATUS.md` o el
documento del tema) y después las referencias. Un documento nunca contradice en
silencio una decisión vigente.
