# Arquitectura

Mapa corto del sistema. El detalle está en `docs/architecture/` (índice:
`docs/architecture/index.md`).

## Vista general

```text
Navegador
  │ HTTPS
  ▼
SPA Vue 3 (repositorio proyecto-grado-silabos-frontend)
  │ JSON /api/v1 + Sanctum (cookie y CSRF)
  ▼
API Laravel 13 (este repositorio, monolito modular)
  ├─ PostgreSQL: todos los datos y la auditoría
  ├─ PostgreSQL (también): colas critica, documentos, ia y general; caché y bloqueos
  ├─ Disco privado: logos, Word, PDF y firmas
  ├─ Gotenberg: Word → PDF
  ├─ Proveedor de IA (AI_DRIVER): chat del docente
  └─ Excel estructurado para fichas
```

Dos artefactos: la SPA y la API (ADR-0008). La API no tiene pantallas. Un contenedor
corre PHP-FPM, Nginx, el worker de colas y el scheduler.

## Módulos (`app/Modules`)

| Módulo | Qué hace |
|---|---|
| `Identity` | Cuentas, roles, rol activo, sesiones, 2FA, accesibilidad, importación de usuarios y docentes |
| `Academic` | Facultades, carreras, períodos, malla, paralelos, asignación de docentes, ficha de asignatura e importación de ficha |
| `Configuration` | Plantilla del sílabo (definición JSON, secciones y campos) y logos |
| `Syllabus` | Convocatorias, sílabos, validación, envío, revisión de Coordinación, reinicio y regeneración |
| `Library` | Revisión de bibliografía |
| `Documents` | Word, PDF, firmas electrónicas y descargas |
| `AiAssistance` | Chat de IA del docente |
| `Operations` | Auditoría, notificaciones y textos fijos de ayuda de pantalla |

Cada módulo usa las capas que necesita: `Application` (acciones y consultas), `Domain`
(reglas y políticas), `Infrastructure` (modelos, trabajos, gateways) y `Presentation`
(controladores y requests). Fuera de los módulos quedan `app/Http` (panel y salud) y `app/Support`
(utilidades compartidas, como `RunsOnce` para colas). Límites y dependencias:
`docs/architecture/modules.md`.

## Invariantes

- PostgreSQL es la única fuente de verdad; la cola y la caché se pueden vaciar sin perder datos funcionales.
- Toda autorización se decide en el servidor y sobre el registro concreto.
- Las transiciones de estado pasan por acciones de aplicación dentro de transacciones.
- Una revisión enviada se inserta y no se modifica (ADR-0002).
- La plantilla es solo datos (ADR-0011).
- La IA es opcional: si falla, el resto funciona (ADR-0003).
- Word, PDF e IA corren en cola; los trabajos son idempotentes.
- La auditoría registra actor, acción, recurso, momento, metadatos y resultado.

## Dónde leer más

| Tema | Documento |
|---|---|
| Stack y comandos | `docs/architecture/stack.md` |
| Backend y frontend | `docs/architecture/backend.md`, `docs/architecture/frontend.md` |
| Base de datos | `docs/architecture/database.md`, `docs/generated/database-schema.md` |
| Plantilla en JSON | `docs/architecture/template-json.md` |
| Colas | `docs/architecture/queues-and-jobs.md` |
| Flujos críticos | `docs/architecture/sequences.md` |
| IA | `docs/architecture/ai-service.md` |
| Archivos, Word, PDF y firmas | `docs/architecture/files-and-documents.md` |
| Despliegue y Gotenberg | `docs/architecture/deployment.md` |
| Decisiones de arquitectura | `docs/architecture/adr/` |
