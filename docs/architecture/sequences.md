# Secuencias canónicas

Estos diagramas fijan responsabilidades e invariantes. Los nombres concretos de clases
pueden variar, pero no se omiten autorización, transacción, inmutabilidad, auditoría ni
efectos posteriores al commit.

## Enviar un sílabo

```mermaid
sequenceDiagram
    actor D as Docente
    participant UI as SPA Vue
    participant C as SubmitSyllabusController
    participant A as SubmitSyllabus Action
    participant P as SyllabusPolicy
    participant V as Validador determinístico
    participant DB as PostgreSQL
    participant O as Outbox
    participant W as Worker

    D->>UI: Confirma enviar
    UI->>C: POST con version_bloqueo e idempotency_key
    C->>P: authorize(submit, syllabus)
    P-->>C: permitido por rol, alcance, asignación y estado
    C->>A: ejecutar DTO validado
    A->>V: validar borrador y versión de reglas
    V-->>A: resultados bloqueantes/advertencias
    alt Existen errores bloqueantes
        A-->>C: rechazo sin transición
        C-->>UI: errores por sección/campo
    else Borrador válido
        A->>DB: begin transaction + comprobar concurrencia/idempotencia
        A->>DB: insertar RevisionSilabo inmutable
        A->>DB: cambiar estado a En revisión
        A->>DB: insertar transición y auditoría
        A->>O: insertar evento silabo.enviado
        A->>DB: commit
        C-->>UI: JSON con número de revisión
        O-->>W: entregar después del commit
        W->>W: notificar de forma idempotente
    end
```

## Solicitar corrección y reenviar

```mermaid
sequenceDiagram
    actor Coo as Coordinador
    actor D as Docente
    participant UI as Aplicación
    participant R as Review Actions
    participant DB as PostgreSQL

    Coo->>UI: Escribe el comentario general sobre la revisión N
    UI->>R: Solicitar corrección con el comentario
    R->>DB: autorizar alcance y comprobar En revisión
    R->>DB: transacción: comentario en la revisión + estado Corrección solicitada + auditoría
    R-->>D: notificación posterior al commit
    D->>UI: Edita nueva versión de trabajo
    D->>R: Reenviar con version_bloqueo e idempotency_key
    R->>DB: validar + insertar revisión N+1 inmutable
    R->>DB: estado En revisión + transición + auditoría
    R-->>Coo: notificación posterior al commit
    Coo->>UI: Ver diff N ↔ N+1
```

## Aprobar y eliminar

```mermaid
sequenceDiagram
    actor Coo as Coordinador
    participant UI as SPA Vue
    participant A as Approval Actions
    participant DB as PostgreSQL
    participant O as Outbox

    Coo->>UI: Confirma aprobación de revisión N
    UI->>A: approve(revision N, idempotency_key)
    A->>DB: autorizar + validar estado/reglas
    A->>DB: transacción: Aprobacion(N) + estado Aprobado + auditoría + ejecución pendiente
    A-->>UI: Aprobado; revisión N bloqueada
    O-->>O: habilita notificación/exportación después del commit

    opt Eliminación autorizada (I-178)
        Coo->>UI: Confirma eliminar el sílabo aprobado
        UI->>A: DELETE sílabo
        A->>DB: autorizar + transacción
        A->>DB: borrar sílabo con revisiones, aprobación, PDF y firmas
        A->>DB: crear sílabo nuevo sin iniciar + auditoría + aviso al docente
        A-->>UI: sílabo nuevo sin iniciar
    end
```

## Solicitar contenido y unidades al chat (I-146)

```mermaid
sequenceDiagram
    actor D as Docente
    participant UI as SPA Vue
    participant A as SendSyllabusAiMessage
    participant DB as PostgreSQL
    participant Q as Redis/Worker
    participant AI as AiChatGateway

    D->>UI: Pregunta sobre contenido o unidades
    UI->>A: mensaje y clave idempotente
    A->>DB: autorizar sílabo y fijar contexto curricular-units-v1
    A->>DB: crear/reusar mensaje y contexto académico/curricular
    A-->>UI: estado Pendiente
    A-->>Q: job después del commit
    Q->>AI: contexto fotografiado, tablas de planificación y conversación compatible
    alt Respuesta válida
        AI-->>Q: propuesta y referencias al contexto recibido
        Q->>DB: validar referencias y persistir respuesta/evidencia
        Q-->>UI: respuesta disponible
        D->>UI: decide el contenido que incorpora mediante la edición normal
    else Timeout, fallo o cita inválida
        AI-->>Q: error
        Q->>DB: estado Fallido y diagnóstico seguro
        Q-->>UI: ayuda no disponible; flujo principal continúa
    end
```

No se consultan fuentes vivas ni vectores. El historial anterior permanece visible,
pero no se envía al proveedor bajo el contrato nuevo.

## Generar y descargar documentos

```mermaid
sequenceDiagram
    actor U as Usuario autorizado
    participant UI as Aplicación
    participant E as Export Actions
    participant DB as PostgreSQL
    participant Q as Worker
    participant R as DocumentRenderer
    participant S as Almacenamiento privado

    U->>UI: Solicita DOCX/PDF de revisión N
    UI->>E: create export(revision N)
    E->>DB: autorizar + fijar revisión/plantilla + crear trabajo idempotente
    E-->>Q: dispatch después del commit
    Q->>R: render fotografía inmutable
    R-->>Q: DOCX y PDF desde la misma entrada
    Q->>S: guardar privados y calcular huellas
    Q->>DB: registrar artefactos y estado Completado
    U->>UI: Descargar artefacto
    UI->>DB: reautorizar recurso/revisión
    UI->>S: stream o URL temporal corta
    S-->>U: archivo
```

## Importar datos institucionales

```mermaid
sequenceDiagram
    actor A as Administrador
    participant UI as Aplicación
    participant I as Import Application
    participant X as Fuente institucional
    participant DB as PostgreSQL

    A->>UI: Inicia simulación
    UI->>I: create import run
    I->>X: leer lote con credencial de solo lectura
    X-->>I: registros externos
    I->>DB: stage + validar + clasificar altas/cambios/conflictos
    I-->>UI: resumen y conflictos, sin aplicar
    A->>UI: Confirma lote autorizado
    UI->>I: apply(run, idempotency_key)
    I->>DB: transacciones por lotes + items + auditoría
    I-->>UI: altas, cambios, rechazos y conflictos finales
    Note over I,X: Nunca escribe en la fuente institucional
```
