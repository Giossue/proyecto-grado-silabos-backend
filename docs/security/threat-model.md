# Modelo de amenazas

## Activos

- credenciales, sesiones, 2FA y asignaciones de rol;
- datos personales de usuarios, incluida la formación del docente;
- estructura académica, malla, fichas de asignatura y el contenido del Excel de importación;
- borradores, revisiones de Coordinación y Biblioteca, comentarios y aprobaciones;
- plantilla del sílabo, DOCX y PDF generados y sus huellas;
- PDF firmados, datos del certificado de cada firmante y la lista de entidades
  certificadoras de confianza;
- archivo `.p12` y su clave, que solo existen en el navegador del firmante;
- auditoría, conversaciones con la IA y avisos;
- secretos de base, almacenamiento, correo e IA;
- disponibilidad del sistema durante los plazos académicos.

## Fronteras

1. navegador ↔ Laravel;
2. Laravel ↔ PostgreSQL;
3. aplicación ↔ almacenamiento privado;
4. worker ↔ proveedor de IA alojado (chat del docente);
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
| Descarga indebida | Alguien descarga un documento ajeno o sin las tres firmas | descarga por API autenticada, autorización por petición, auditoría |
| Firma suplantada | Alguien sube un PDF firmado con un certificado ajeno | titular del certificado igual a la cuenta, certificado vigente, firma CMS válida, cadena hacia la lista de confianza (DT-16) |
| Firma fuera de turno o sobre otro documento | El PDF firmado no parte del vigente o salta un firmante | orden docente → coordinación → biblioteca, huella del PDF base, el PDF firmado debe empezar con el vigente y agregar una sola firma |
| Robo de la firma | El `.p12` o su clave salen del equipo del firmante | la firma ocurre en el navegador; el archivo y la clave nunca se envían |
| Revisión manipulada | Un sílabo aprobado se cambia fuera del flujo | aprobaciones y auditoría de solo inserción, huella, regeneración solo por cambios de plantilla, malla o ficha |
| Carrera / reintento | Dos envíos o aprobaciones a la vez | bloqueo de fila, versión, idempotencia, transacción |
| Trabajo repetido | Un job duplica efectos | `ShouldBeUnique`, `RunsOnce`, estado persistido |
| Prompt injection | El contenido del sílabo o un mensaje ordena ignorar reglas | evidencia delimitada como datos no confiables, sin herramientas, salida JSON validada |
| Referencia inventada | La IA cita evidencia inexistente | solo se aceptan citas de evidencias enviadas |
| Decisión encubierta de IA | La IA intenta aprobar o cambiar estados | el chat solo devuelve texto; el docente decide qué usar |
| SSRF / proveedor externo | Una URL manipulada desvía contenido o la clave | URL definida solo por operación, HTTPS, sin credenciales embebidas ni redirecciones; clave en secreto de despliegue |
| Tratamiento por terceros | Borradores y preguntas del chat salen hacia la IA | desactivada por defecto, activación explícita, contexto mínimo; base legal pendiente (PV-12) |
| Exfiltración por logs | Prompts o documentos aparecen en logs | minimización, acceso y retención controlados |
| Pérdida de datos | Base o archivos destruidos | respaldos aislados, restauración probada (PV-11), mínimo privilegio |
| Denegación de servicio | Chat de IA y exportación agotan los workers | colas separadas, límites por actor, tamaño y tiempo, timeouts |

## Casos de abuso obligatorios

- usuario desactivado mantiene una pestaña abierta;
- coordinador accede a otra carrera;
- docente manipula el estado o el identificador de la revisión;
- descarga con sesión revocada o sin permiso;
- contenido del sílabo con texto que da instrucciones a la IA;
- archivo con extensión permitida y contenido distinto;
- PDF firmado por otra persona, vencido o que no parte del documento vigente;
- misma petición enviada en paralelo;
- worker que procesa después de que cambió la versión esperada;
- alguien intenta modificar un evento de auditoría.

Actualiza este documento al agregar una frontera o un dato sensible.
