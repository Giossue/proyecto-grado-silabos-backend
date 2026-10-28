# Módulos y límites

El backend es un monolito modular. Cada módulo vive en `app/Modules/<Módulo>`.

## Módulos

| Módulo | Responsabilidad | Tablas propias |
|---|---|---|
| `Identity` | Cuentas, cuatro roles fijos, rol activo en sesión, activación por correo, docentes de la carrera, perfil y preferencias. | `usuarios`, `asignaciones_rol`, `invitaciones_activacion` |
| `Academic` | Facultades, carreras, períodos, malla (asignaturas y requisitos), paralelos y docentes asignados. Ficha de asignatura y su importación mediante plantilla Excel. | `facultades`, `carreras`, `periodos_academicos`, `asignaturas`, `requisitos_asignatura`, `paralelos`, `asignaciones_paralelo` |
| `Configuration` | La plantilla única del sílabo: definición JSON, secciones, campos, bloques, variables, logos e importación/exportación JSON. | `plantilla_silabo`, `secciones_plantilla`, `campos_plantilla`, `definiciones_campo` |
| `Syllabus` | Convocatorias institucionales y de carrera, sílabos, borrador, validación, envío, revisión de Coordinación, correcciones y regeneración. | `convocatorias_universidad`, `convocatorias_carreras`, `silabos`, `valores_campo`, `revisiones_silabo`, `revisiones_coordinacion`, `aprobaciones`; vista `docentes_vigentes_silabo` |
| `Library` | Revisión de bibliografía: comentar con motivos o aprobar. Aprobar deja el sílabo `aprobado` y pide la exportación. | `envios_biblioteca` |
| `Documents` | Word interno con PHPWord, PDF con Gotenberg, archivos privados, firmas al decidir (declaración y CMS) y descarga del PDF. | `exportaciones_silabo`, `archivos`, `firmas_silabo` |
| `AiAssistance` | Chat del docente sobre su sílabo. | `mensajes_ia` |
| `Operations` | Auditoría, notificaciones internas, ayuda por pantalla (texto fijo en `ScreenHelpCatalog`), lista de preparación y trabajo de humo. | `eventos_auditoria`, `notificaciones` |

Fuera de los módulos quedan el modelo `App\Models\User`, el `DashboardController`, los
middleware HTTP, los comandos Artisan y `app/Support` (hash canónico, alias de columnas
y `RunsOnce` para colas).

## Capas

```text
app/Modules/<Módulo>/
├── Application/      acciones (casos de uso), consultas y servicios de aplicación
├── Domain/           contratos, enums, políticas, objetos de valor, excepciones
├── Infrastructure/   modelos Eloquent, trabajos en cola, gateways, render, correo
└── Presentation/     controladores y Form Requests HTTP
```

No se crean carpetas vacías por simetría. `Library` y `Operations` no tienen `Domain`.

La única regla automática está en `tests/Architecture/ModuleBoundariesTest.php`: el
`Domain` de un módulo no usa `Illuminate\Http`, la fachada `Redis` ni controladores.

## Cómo se comunican

- Un módulo llama directamente a acciones o servicios de otro. Ejemplo: `Library`
  llama a `RequestSyllabusExport` de `Documents` al aprobar la bibliografía.
- Las lecturas que otro módulo necesita se exponen en `Application/Queries`
  (`SyllabusNavigationLookup`, `AiHistorySyllabusLookup`, `ExportArtifactLookup`).
- Las integraciones externas van detrás de un contrato del dominio:
  `AiChatGateway` y `DocumentRenderer`.
  `AppServiceProvider` elige la implementación según la configuración.
- No hay eventos de dominio ni listeners. Los efectos se resuelven así:
  - auditoría: `RecordAuditEvent` dentro de la misma transacción;
  - avisos: `ScheduleWorkflowNotification` / `ScheduleInternalNotification` guardan
    filas en `notificaciones` dentro de la misma transacción;
  - trabajo lento: se despacha un job con `afterCommit()`
    (ver [queues-and-jobs.md](queues-and-jobs.md)).

## Deuda conocida

Hay dependencias en ambos sentidos entre módulos, por ejemplo `Academic` ↔ `Syllabus`
y `Syllabus` ↔ `Configuration`. Varios módulos usan modelos Eloquent de otros. Ninguna
prueba lo impide todavía. Al tocar ese código, se prefiere una consulta en
`Application/Queries` del módulo dueño que devuelva identificadores o arreglos.
