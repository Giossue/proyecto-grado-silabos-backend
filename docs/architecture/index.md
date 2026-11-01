# Índice de arquitectura

## Qué leer según la tarea

| Si vas a… | Lee |
|---|---|
| Entender las piezas y versiones | [stack.md](stack.md) |
| Implementar un caso de uso | [modules.md](modules.md), [backend.md](backend.md) |
| Consumir o cambiar la API | [backend.md](backend.md) y `docs/architecture/openapi.json` |
| Cambiar una pantalla | [frontend.md](frontend.md) |
| Cambiar el esquema | [database.md](database.md) y [database-schema.md](../generated/database-schema.md) |
| Crear o cambiar un trabajo en cola | [queues-and-jobs.md](queues-and-jobs.md) |
| Entender un flujo de punta a punta | [sequences.md](sequences.md) |
| Generar Word/PDF o firmar | [files-and-documents.md](files-and-documents.md) |
| Cambiar la plantilla del sílabo | [template-json.md](template-json.md) y [ADR-0011](adr/0011-template-as-data.md) |
| Importar los proyectos de carrera de la carrera | [ficha-excel.md](ficha-excel.md) |
| Trabajar con el chat de IA | [ai-service.md](ai-service.md) |
| Desplegar o cambiar variables | [deployment.md](deployment.md) |

## Decisiones (ADR)

| ADR | Tema | Estado |
|---|---|---|
| [0000](adr/0000-template.md) | Plantilla para nuevos ADR | — |
| [0001](adr/0001-modular-monolith.md) | Monolito modular Laravel | Aceptado; la parte de interfaz la reemplaza 0008 |
| [0002](adr/0002-immutable-revisions.md) | Revisiones inmutables | Aceptado |
| [0003](adr/0003-ai-human-control.md) | IA bajo control humano | Aceptado |
| [0006](adr/0006-vue-flow-curriculum-builder.md) | Vue Flow para la malla | Aceptado |
| [0007](adr/0007-template-document-editor.md) | Tiptap y PHPWord | Aceptado; ampliado por 0010 y 0011 |
| [0008](adr/0008-separate-spa-api.md) | SPA y API separadas | Aceptado |
| [0010](adr/0010-simple-template-documents.md) | Campos como documentos libres | Aceptado; ampliado por 0011 |
| [0011](adr/0011-template-as-data.md) | Plantilla solo como datos | Aceptado |
| [0012](adr/0012-protected-field-responses.md) | Formato fijo y respuestas por responsable | Aceptado; activación por plantilla |

## Principios

1. Monolito modular: un solo backend, módulos con límites claros.
2. Casos de uso en acciones, no en controladores.
3. Las reglas importantes se protegen también en PostgreSQL.
4. Autorización en cada petición y por registro.
5. La evidencia académica no se edita: se agrega.
6. Integraciones externas detrás de una interfaz sustituible.
7. Lo lento va a una cola y deja su estado en PostgreSQL.
8. Si la IA falla, el resto del sistema sigue funcionando.
9. Las decisiones costosas se registran en un ADR.
