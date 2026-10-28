# Observabilidad y auditoría

## Diferencia

- **Observabilidad técnica:** diagnostica salud, latencia, errores y colas. Se guarda
  solo el tiempo necesario para operar.
- **Auditoría funcional:** prueba quién hizo qué, sobre qué recurso y con qué resultado.
  Nadie la modifica.

Los logs generales no sustituyen la auditoría.

## Salud

- `/health/live`: el proceso responde.
- `/health/ready`: la aplicación puede consultar la base. No revela detalles de la
  infraestructura (`HealthAndSecurityTest`).

## Logs

- Canal de Laravel `stack` (por defecto `single`), configurable con `LOG_CHANNEL` y
  `LOG_STACK`.
- `AssignCorrelationId` da a cada petición un identificador: usa el UUID que envía el
  cliente o crea uno. Lo agrega al contexto de los logs y lo devuelve en la cabecera
  `X-Correlation-ID`.

Nunca registres contraseñas, tokens, cookies, `.env`, documentos completos, prompts
completos ni trazas en respuestas públicas.

## Auditoría

`RecordAuditEvent` escribe en `eventos_auditoria`: actor, copia de su nombre, rol y
carrera, acción, tipo e ID del recurso, resultado, metadatos y fecha. La copia del actor
permite borrar la cuenta sin perder el registro. Solo Administración la consulta, en
`/admin/auditoria` (ADM-10).

Se auditan, entre otros:

- inicio y cierre de sesión, cierre de sesiones remotas y elección de rol;
- cambios de la plantilla (crear, importar, vaciar, secciones y campos);
- malla, paralelos, coordinación, ficha e importación de la ficha;
- convocatoria: crear, abrir, pausar, prorrogar, eliminar y rehacer sílabos;
- sílabo: inicio, guardado, copia, envío, reenvío, corrección, aprobación, reinicio y
  eliminación;
- revisión de Biblioteca, firmas, exportación y cada descarga;
- solicitudes al chat de IA y cambios de la ayuda de pantallas.

No copies el contenido completo del sílabo en la auditoría.

## Trabajos en cola

No hay tabla de trabajos fallidos; `cola_trabajos` solo es la cola técnica y se vacía al procesarse. Cada recurso guarda su propio estado:
la exportación y el mensaje del chat. Si un trabajo falla,
el recurso queda en estado fallido con una causa segura que ve el usuario.

| Cola | Trabajos |
|---|---|
| `critica` | `PlatformSmokeJob` (verificación de la plataforma) |
| `documentos` | `GenerateSyllabusExportJob` (Word y PDF) |
| `ia` | `AnswerSyllabusChatJob` |
| `general` (cola por defecto) | Correo de activación de la cuenta |

El worker del contenedor escucha todas estas colas. Si un worker escucha solo `general`,
los documentos y la IA quedan sin procesar.

## Métricas y alertas

No hay herramienta de métricas ni alertas automáticas. Hoy se revisan los logs del
contenedor y el estado de cada recurso. Cuando se agregue una herramienta, conviene
medir:

- latencia y errores HTTP por ruta;
- consultas lentas;
- espera y fallos por cola;
- duración y resultado de exportaciones e IA;
- disponibilidad de PostgreSQL, Gotenberg y el proveedor de IA.

Y alertar solo por síntomas que tengan una acción: errores sostenidos, cola detenida,
respaldo fallido, disco casi lleno o una dependencia caída.
