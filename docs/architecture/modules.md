# Módulos y límites

## Estructura interna sugerida

```text
app/Modules/Syllabi/
├── Application/
│   ├── Actions/
│   ├── Data/
│   ├── Queries/
│   └── Contracts/
├── Domain/
│   ├── Entities/
│   ├── Enums/
│   ├── Events/
│   ├── Exceptions/
│   ├── Policies/
│   └── ValueObjects/
├── Infrastructure/
│   ├── Persistence/
│   ├── Jobs/
│   └── Providers/
└── Presentation/
    └── Http/
        ├── Controllers/
        ├── Requests/
        └── Resources/
```

No crees carpetas vacías por simetría. Aplica la capa cuando exista una responsabilidad
real.

## Dependencias permitidas

| Módulo | Puede depender de |
|---|---|
| Identidad | soporte común |
| Académico | identidad (IDs/contratos), soporte, `ProcessLocks` de sílabos para saber si hay convocatoria en curso |
| Configuración | identidad, académico mediante contratos, `ProcessLocks` de sílabos para saber si el proceso está abierto |
| Convocatorias | académico, configuración, identidad |
| Sílabos | académico, configuración, convocatorias, identidad |
| Revisión | sílabos, identidad |
| Validación | sílabos/configuración mediante DTO/contratos |
| IA | sílabos, configuración/fuentes mediante contratos |
| Documentos | sílabos, configuración mediante snapshots/contratos; DOCX con PhpWord (`PhpWordDocumentRenderer`, I-34) y PDF de texto plano de respaldo |
| Operaciones | eventos/DTO públicos de módulos |

Evita ciclos. Si dos módulos se necesitan mutuamente, extrae un contrato público, un
evento o revisa el límite.

## Contratos públicos

Un módulo expone solo:

- acciones/casos de uso invocables;
- DTO de entrada/salida estables;
- consultas autorizadas;
- eventos de dominio/aplicación;
- interfaces que la infraestructura implementa.

No expone consultas ad hoc sobre sus tablas, modelos Eloquent mutables ni lógica interna.

Las consultas de solo lectura que otro módulo necesita viven en
`Application/Queries/` del módulo dueño y devuelven identificadores o arreglos, no
modelos. Ejemplo: `NotificationDestination` (Operaciones) resuelve el destino de un
aviso con `Syllabus\Application\Queries\SyllabusNavigationLookup`,
`AiAssistance\Application\Queries\AiHistorySyllabusLookup` y
`Documents\Application\Queries\ExportArtifactLookup`.

Deuda conocida: aún quedan usos directos de modelos de otros módulos (por ejemplo
`WorkflowNotificationRecipients`, `SetupChecklist` y `RetryJobExecution` en
Operaciones, y Sílabos sobre modelos de Académico/Configuración). Se migran a este
patrón cuando se toquen; no hay prueba de arquitectura que los bloquee todavía.

## Eventos recomendados

- `CampaignOpened`
- `SyllabusDraftCreated`
- `SyllabusSubmitted`
- `CorrectionsRequested`
- `SyllabusResubmitted`
- `SyllabusApproved`
- `ApprovedSyllabusReopened`
- `TemplateVersionPublished`
- `SourceVersionActivated`
- `ExportRequested`
- `ImportCompleted`

Los nombres representan hechos ya confirmados. Efectos externos se procesan después del
commit y pueden registrarse como `ejecuciones_trabajo` pendientes.

## Soporte común

`app/Support` solo contiene preocupaciones verdaderamente transversales: UUID, reloj,
idempotencia, paginación, trazas y resultados. No se convierte en un módulo “cajón”.
