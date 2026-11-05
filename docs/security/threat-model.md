# Modelo de amenazas

## Activos

- credenciales, sesiones, 2FA y asignaciones de rol;
- datos personales de usuarios, incluida la formación del docente;
- estructura académica, malla, proyectos de carrera de asignatura y el contenido del Excel de importación;
- borradores, revisiones de Coordinación y Biblioteca, comentarios y aprobaciones;
- plantilla del sílabo, PDF generado y sus huellas;
- firmas CMS de las decisiones, datos del certificado de cada firmante y la lista de entidades
  certificadoras de confianza;
- archivo `.p12` y su clave, que solo existen en el navegador del firmante;
- auditoría, conversaciones con la IA y avisos;
- secretos de base, almacenamiento, correo e IA;
- disponibilidad del sistema durante los plazos académicos.

## Fronteras

1. navegador ↔ Laravel;
2. Laravel ↔ PostgreSQL;
3. aplicación ↔ almacenamiento privado;
4. worker ↔ proveedor de IA alojado (asistente de la plantilla);
5. worker ↔ Gotenberg (DOCX a PDF);
6. operadores ↔ despliegue y respaldos.

## Amenazas prioritarias

| Amenaza | Escenario | Controles principales |
|---|---|---|
| Acceso horizontal | Un docente cambia el UUID de la URL y ve otro sílabo | Policy por registro, alcance en la consulta, pruebas de fuga |
| Elevación de rol | El frontend concede una capacidad | permiso en el servidor, rol activo resuelto por el servidor, auditoría |
| CSRF / fijación de sesión | Mutación con sesión robada o fijada | Sanctum + Fortify, CSRF, cookies seguras, rotación, revocación |
| XSS | Un documento importado, una respuesta de IA o un campo ejecuta script | texto enriquecido como documento estructurado, sin `v-html`, CSP |
| Inyección | Los filtros alteran la consulta | Query Builder, listas permitidas, validación |
| Mass assignment | La petición cambia un estado o rol no permitido | datos validados, casos de uso, estados por acción específica |
| Subida maliciosa | Archivo con extensión permitida y contenido distinto, o enorme | tipo real, tamaño máximo, disco privado, límite de descompresión en Excel |
| Descarga indebida | Alguien descarga un documento ajeno | descarga por API autenticada, autorización por petición, auditoría |
| Firma suplantada | Alguien firma una decisión con un certificado ajeno | titular del certificado igual a la cuenta, certificado vigente, firma CMS válida, cadena hacia la lista de confianza (DT-16) |
| Firma sobre otro contenido | La firma corresponde a otro sílabo, otra acción u otra versión | la declaración incluye sílabo, acción y huella del contenido; el servidor la compara con la esperada y verifica la firma sobre esos bytes; solo firma quien tiene la etapa |
| Robo de la firma | El `.p12` o su clave salen del equipo del firmante | la firma ocurre en el navegador; el archivo y la clave nunca se envían |
| Revisión manipulada | Un sílabo aprobado se cambia fuera del flujo | aprobaciones y auditoría de solo inserción, huella, regeneración solo por cambios de plantilla, malla o proyecto de carrera |
| Carrera / reintento | Dos envíos o aprobaciones a la vez | bloqueo de fila, versión, idempotencia, transacción |
| Trabajo repetido | Un job duplica efectos | `ShouldBeUnique`, `RunsOnce`, estado persistido |
| Prompt injection | El contenido del sílabo o un mensaje ordena ignorar reglas | evidencia delimitada como datos no confiables, sin herramientas, salida JSON validada |
| Referencia inventada | La IA cita evidencia inexistente | solo se aceptan citas de evidencias enviadas |
| Decisión encubierta de IA | La IA intenta aprobar o cambiar estados | el asistente solo propone; Administración decide qué aplicar |
| SSRF / proveedor externo | Una URL manipulada desvía contenido o la clave | URL definida solo por operación, HTTPS, sin credenciales embebidas ni redirecciones; clave en secreto de despliegue |
| Tratamiento por terceros | Pedidos y borradores de la plantilla salen hacia la IA | desactivada por defecto, activación explícita, contexto mínimo; base legal pendiente (PV-12) |
| Exfiltración por logs | Prompts o documentos aparecen en logs | minimización, acceso y retención controlados |
| Pérdida de datos | Base o archivos destruidos | respaldos aislados, restauración probada (PV-11), mínimo privilegio |
| Denegación de servicio | Asistente de IA y exportación agotan los workers | colas separadas, límites por actor, tamaño y tiempo, timeouts |

## Casos de abuso obligatorios

- usuario desactivado mantiene una pestaña abierta;
- coordinador accede a otra carrera;
- docente manipula el estado o el identificador de la revisión;
- descarga con sesión revocada o sin permiso;
- contenido del sílabo con texto que da instrucciones a la IA;
- archivo con extensión permitida y contenido distinto;
- firma de otra persona, con certificado vencido o sobre otra declaración;
- misma petición enviada en paralelo;
- worker que procesa después de que cambió la versión esperada;
- alguien intenta modificar un evento de auditoría.

Actualiza este documento al agregar una frontera o un dato sensible.
