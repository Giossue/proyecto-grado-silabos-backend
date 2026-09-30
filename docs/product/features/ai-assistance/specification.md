# Generación asistida de contenido

## Alcance vigente — I-146, 2026-09-27

El responsable del producto retiró el revisor de IA del sílabo y la consulta de una base
vectorizada de Fuentes académicas. Esta decisión sustituye el análisis por campo, la
revisión integral, sus recomendaciones aplicables y su retroalimentación como funciones
activas. I-168 borró su código, sus mensajes en `historial_ia` y la tabla `documentos_ia`.

Se mantiene el chat para ayudar al docente a elaborar contenido y unidades de la
planificación. Su contexto procede de la carrera, la asignatura y los datos curriculares
confirmados fotografiados en el sílabo, junto con las tablas de unidades y planificación.
Incluye las horas y semanas del contexto académico. El asistente no recupera documentos
ni depende del CRUD retirado de fuentes.

## Trazabilidad

- RF-046 a RF-054 y CU-08 se conservan como identificadores históricos; su alcance activo
  es la generación asistida descrita aquí. No se declara vigente el revisor retirado.
- RN-028 a RN-030: control humano, trazabilidad y separación de validación determinística.
- RNF-013, RNF-016, RNF-017, RNF-035 y RNF-036.
- DOC-06: chat de ayuda a la elaboración. PV-12, PV-13, PV-14 y PV-18 se aplican a los
  servicios de IA que permanecen activos; embeddings deja de ser una dependencia.

## Comportamiento

- Solo el docente asignado y autorizado consulta o envía mensajes del sílabo.
- El contexto del chat se limita a carrera, asignatura, horas, semanas, datos curriculares
  fotografiados y estructura/filas de las tablas de unidades o planificación. No usa
  otros campos como permiso de lectura. La plantilla
  y la configuración técnica del chat conservan sus permisos actuales.
- Carrera, materia y datos curriculares se toman de la fotografía del expediente;
  cambios posteriores en la base no sustituyen ese contexto histórico.
- La respuesta distingue contenido institucional confirmado de propuestas de elaboración.
  Las referencias identifican el contexto académico, dato curricular o sección utilizada.
- Las solicitudes se procesan en cola con estado, límites e idempotencia. Limpiar el panel
  oculta la conversación para el usuario y conserva el historial técnico autorizado.
- La propuesta no escribe automáticamente en el sílabo. El docente conserva la decisión
  sobre el contenido que incorpora y debe cumplir las validaciones determinísticas.
- La extracción documental de I-145 es otro flujo: Coordinación importa PDF/XLSX,
  revisa candidatos y confirma datos curriculares. No es una revisión del sílabo.

## Límites y criterios críticos

- La IA no aprueba, rechaza, califica, bloquea el envío ni cambia estados académicos.
- Guardar, validar, enviar, revisar y aprobar funcionan aunque la IA esté indisponible.
- No se consultan fuentes externas ni índices vectoriales como contexto del chat.
- No se inventan cardinalidades de habilidades, efecto de APE cero, número de unidades
  ni precedencias entre documentos discrepantes.
- Los mensajes y documentos se tratan como datos no confiables; los permisos y contratos
  se aplican en servidor. Referencias no incluidas en el contexto son rechazadas.
- Los mensajes nuevos usan `context_version=curricular-units-v1`. El historial anterior
  sigue visible, pero no se envía como contexto al proveedor; sus trabajos pendientes
  terminan sin invocar el gateway.
- Las rutas de análisis, revisión integral, aplicación y feedback del revisor quedan
  retiradas. Trabajos antiguos no deben generar nuevas revisiones ni recomendaciones.
- El retiro conserva revisiones, aprobaciones, conversaciones y evidencia histórica;
  no implica borrar tablas ni registros de producción.

La evidencia de implementación y verificación se registra en el plan I-146. Los planes
I-06, I-46, I-92, I-98 y I-135 documentan etapas anteriores y no reactivan estas funciones.
