# Secuencias

Flujos principales tal como los implementa el código. En todos, la acción autoriza,
abre una transacción, bloquea las filas que cambia, audita y guarda las notificaciones
antes del commit. Los trabajos en cola salen después del commit.

## 1. Elaboración

```mermaid
sequenceDiagram
    actor A as Administrador
    actor C as Coordinador
    actor D as Docente
    participant API as API Laravel
    participant DB as PostgreSQL

    A->>API: POST admin/processes (período, inicio, entrega)
    API->>DB: convocatorias_universidad = abierto
    C->>API: POST coordination/convocations
    API->>DB: convocatorias_carreras (preparacion → abierta)
    API->>DB: un sílabo sin_iniciar por paralelo + valores_campo heredados
    Note over API,DB: campos Carrera ← proyecto de carrera de la asignatura<br/>o, sin él, contenido inicial de la plantilla
    D->>API: POST teacher/syllabi/{id}/start
    API->>DB: estado borrador, version_bloqueo = 1
    loop Autoguardado por campo
        D->>API: PATCH teacher/syllabi/{id}/fields/{campo} (valor, version_bloqueo)
        alt versión distinta
            API-->>D: 409 conflicto_version_borrador
        else campo no editable por el docente
            API-->>D: 422
        else
            API->>DB: valores_campo + version_bloqueo + 1
        end
    end
```

- Abrir la convocatoria exige el proceso abierto, la carrera lista
  (`CoordinatorConvocationReadiness`) y una plantilla usable.
- El docente puede copiar el contenido de otro paralelo suyo de la misma asignatura
  hacia un sílabo que aún no envió (`POST …/copy-from/{source}`).
- El docente edita solo los espacios de los campos Carrera que le deja el proyecto de carrera
  (todo el campo si Coordinación lo dejó vacío).

## 2. Validar y enviar

```mermaid
sequenceDiagram
    actor D as Docente
    participant API as SubmitSyllabus
    participant DB as PostgreSQL

    D->>API: POST teacher/syllabi/{id}/submit (version_bloqueo, idempotency_key)
    API->>DB: ¿ya existe una revisión con esa clave? → la devuelve
    API->>API: estado borrador o correccion_solicitada; dentro del plazo; versión igual
    API->>API: ValidateDraft (campos obligatorios y reglas de bloque)
    alt errores
        API-->>D: 422 con errores por campo
    else válido
        API->>DB: lock sílabo; revisión N con fotografía y hash SHA-256
        alt la corrección anterior la pidió Biblioteca
            API->>DB: envios_biblioteca(N) → vuelve directo a Biblioteca
        end
        API->>DB: estado en_revision; auditoría; notificación
        API-->>D: revisión N
    end
```

`POST …/validate` ejecuta solo la validación; no cambia el sílabo.

## 3. Revisión de Coordinación

```mermaid
sequenceDiagram
    actor C as Coordinador
    participant API as API
    participant DB as PostgreSQL

    C->>API: GET coordination/reviews/{revision}
    alt aprueba
        C->>API: POST …/approve
        API->>DB: revisiones_coordinacion(N) + envios_biblioteca(N)
        API->>DB: notificación a Biblioteca
    else pide corrección
        C->>API: POST …/request-correction (comentario)
        API->>DB: revisión N: correccion_* con etapa coordinacion
        API->>DB: estado correccion_solicitada; notificación al docente
    end
```

Solo se decide sobre la revisión más reciente. `GET …/compare/{after}` muestra la
diferencia entre dos revisiones.

## 4. Verificación de Biblioteca y exportación automática

```mermaid
sequenceDiagram
    actor B as Bibliotecario
    participant API as VerifyBibliography
    participant DB as PostgreSQL
    participant Q as Cola documentos
    participant G as Gotenberg

    alt aprueba la bibliografía
        B->>API: POST library/reviews/{revision}/verify
        API->>API: verifica la firma de biblioteca (SignSyllabusDecision)
        API->>DB: firmas_silabo; aprobaciones(N); estado aprobado; auditoría
        API->>DB: exportaciones_silabo pendiente (clave = id de la aprobación)
        API-->>Q: GenerateSyllabusExportJob (afterCommit)
        Q->>Q: Word interno con PHPWord desde la fotografía y los sellos de las firmas
        Q->>G: Word → PDF
        Q->>DB: archivo PDF; exportación completado; aviso al docente
    else comenta
        B->>API: POST …/comments (motivos)
        API->>DB: correccion_* con etapa biblioteca; estado correccion_solicitada
    end
```

## 5. Firma electrónica al decidir (I-227)

```mermaid
sequenceDiagram
    actor U as Docente, Coordinación o Biblioteca
    participant SPA as SPA
    participant API as API
    participant DB as PostgreSQL

    U->>SPA: «Firmar y enviar» / «Firmar y enviar a biblioteca» / «Firmar y aprobar»
    SPA->>API: declaración (signing-statement del docente o signing_statement del detalle)
    U->>SPA: Elige su .p12 y escribe la clave
    SPA->>SPA: firma CMS separada SHA-256 de la declaración (la clave no sale del equipo)
    SPA->>API: submit / approve / verify con signature {statement, cms}
    API->>API: ¿ya firmó este rol el sílabo? si no: declaración = esperada; CMS válida
    API->>API: certificado vigente; titular = cuenta; cadena reconocida (si hay PEM)
    API->>DB: firmas_silabo; auditoría silabo.firmado; la decisión en la misma transacción
```

- Cada persona firma una vez por sílabo. Los reenvíos tras una corrección no se firman.
- El PDF se descarga (`GET exports/{id}/pdf`) apenas se genera. No hay descarga de Word.

## 6. Importación de proyectos de carrera

```mermaid
sequenceDiagram
    participant C as Coordinación
    participant API as Laravel
    participant DB as PostgreSQL
    C->>API: GET coordination/ficha-imports/template
    API->>DB: Materias activas y campos actuales de Coordinación
    API-->>C: Excel con identificaciones y encabezados protegidos
    C->>API: POST coordination/ficha-imports/spreadsheet (file)
    API->>DB: Leer estructura vigente y valores actuales
    API-->>C: Vista previa y huellas, sin persistir la carga
    Note over C: Archivo y vista previa solo en memoria del diálogo
    C->>API: POST coordination/ficha-imports/apply (mismo file, huellas, selección)
    API->>DB: Verificar permisos, estructura y confirmación de regeneración
    Note over API: Releer Excel y validar archivo, materias y campos
    API->>DB: Transacción: valores_campo y auditoría; regenerar si corresponde
    API-->>C: Cantidad de materias aplicadas
```

«Descartar», cerrar o recargar limpia la revisión en el navegador. No hay tabla,
recuperación ni descarte HTTP de importaciones. El flujo no usa IA, OCR ni cola.

## 7. Cambios con sílabos en curso y regeneración

### Plantilla

```mermaid
sequenceDiagram
    actor A as Administrador
    participant API as API
    participant DB as PostgreSQL

    A->>API: POST admin/processes/{id}/pausar
    API->>DB: proceso pausado; pausado_en = ahora
    A->>API: cambia secciones, campos o JSON de la plantilla
    API->>DB: triggers actualizan plantilla_modificada_en
    A->>API: POST admin/processes/{id}/reanudar
    API-->>A: confirmation_required + conteo por estado
    A->>API: POST …/reanudar con confirmar_rehacer
    API->>DB: adapta cada sílabo por clave de campo; enviados y aprobados vuelven a borrador sin historial
    API->>DB: notificación a los docentes; auditoría proceso.plantilla_rehecho
    API->>DB: proceso abierto
```

La plantilla no se puede cambiar con un proceso `abierto`.

### Eliminar la convocatoria institucional

- `GET admin/processes/{id}/impact` devuelve cuántos sílabos tiene la convocatoria por
  estado (`syllabi`) y el `total`. Solo Administración.
- `DELETE admin/processes/{id}` con `mensaje` (obligatorio, hasta 1000 caracteres) borra de
  verdad la convocatoria institucional, la de cada carrera y todos sus sílabos con su
  historial (revisiones, aprobaciones, firmas, envíos a biblioteca, PDF y avisos), aunque
  estén enviados o aprobados, y deja el período libre para abrir otra convocatoria.
- Se admite con la convocatoria abierta o pausada; una cerrada no se elimina (`403`).
  Sin `mensaje` responde `422`.
- Antes de borrar se calculan los destinatarios: docentes vigentes de sus sílabos,
  Coordinación de sus carreras y Biblioteca, sin repetir y sin quien elimina. Cada uno
  recibe el aviso `proceso.eliminado` con el mensaje, sin enlace, y un correo que no se
  apaga en las preferencias. Queda la auditoría `proceso_silabos.eliminado`.
- El período tampoco se elimina si ya tiene una convocatoria.

### Malla, paralelos y proyecto de carrera

- Con la convocatoria de la carrera `abierta`, la malla y el proyecto de carrera están bloqueados.
  Coordinación pausa la convocatoria para cambiarlas.
- Si el cambio afecta sílabos de una convocatoria `abierta` o `pausada`, la API responde
  `422` con `purge_required` y la cantidad. Con `confirm_purge` aplica el cambio y
  regenera esos sílabos en la misma transacción, incluidos los aprobados.
- Si el cambio habría llegado a una convocatoria finalizada que aún podría reabrirse, la API
  responde `422` con `purge_required` y `purge_mode: convocatoria_finalizada`, y deshace lo
  hecho. Con `confirm_purge` guarda el cambio sin tocar sus sílabos.
- Regenerar borra borrador, revisiones, aprobación, exportaciones, firmas, archivos y
  mensajes de IA, y crea un sílabo nuevo desde la base corregida. El docente recibe un
  aviso. No existe la reapertura de un sílabo aprobado.

### Coordinación sobre un sílabo

- `POST coordination/syllabi/{id}/reset`: devuelve a `sin_iniciar` un sílabo en
  `borrador`, `en_revision` o `correccion_solicitada`.
- `DELETE coordination/syllabi/{id}`: elimina un sílabo `aprobado` y crea uno nuevo
  para el mismo paralelo.
