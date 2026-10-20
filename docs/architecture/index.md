# Índice de arquitectura

## Lectura por tarea

| Si vas a... | Lee |
|---|---|
| Crear el repositorio | `stack.md`, `bootstrap.md`, `deployment.md` |
| Implementar un caso de uso | `modules.md`, `backend.md`, especificación de producto |
| Consumir o cambiar la API | `openapi.json`, `backend.md` y el plan de separación activo |
| Cambiar el esquema | `database.md` y skill `create-migration` |
| Crear una pantalla | `frontend.md`, `../product/screens.md` y checklist frontend |
| Crear un trabajo | `queues-and-jobs.md`, `observability.md` |
| Generar Word/PDF | `files-and-documents.md` |
| Crear o cambiar la plantilla del sílabo | `template-json.md` (manual técnico) y `adr/0011-template-as-data.md` |
| Trabajar con IA | `ai-service.md`, especificación de IA y threat model |
| Leer PDF internos para importación curricular | `academic-source-ingestion.md`, `files-and-documents.md`, `queues-and-jobs.md` |
| Importar contenido curricular PDF/XLSX | `academic-source-ingestion.md`, `database.md`, planes activos I-145/I-146 y `openapi.json` |
| Importar datos | pendientes PV-09/PV-10 |
| Cambiar despliegue | `deployment.md`, seguridad y un ADR |
| Adoptar una dependencia | `stack.md`, plan activo y ADR si es duradera |

## Principios

1. Monolito modular antes que distribución prematura.
2. Casos de uso explícitos antes que lógica repartida en controladores.
3. Invariantes en dominio y base de datos cuando sea posible.
4. Autorización en cada frontera y por registro.
5. Historial apend-only para evidencia académica.
6. Integraciones sustituibles detrás de contratos.
7. Asincronía observable para trabajo lento.
8. Degradación segura: la IA no es dependencia del núcleo.
9. Decisiones costosas registradas en ADR.
10. Pruebas y trazabilidad como parte del diseño.
