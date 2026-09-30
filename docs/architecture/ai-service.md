# Servicios de IA e ingesta documental

## Alcance vigente — I-146

El chat ayuda a elaborar contenido y unidades desde el contexto académico y curricular
fotografiado del sílabo. La extracción curricular I-145 propone datos desde documentos
aportados por Coordinación. Son flujos separados con confirmación humana.

Se retiraron el revisor por campo, la revisión integral, sus operaciones de aplicación y
feedback, y la recuperación textual/vectorial de Fuentes académicas. I-168 borró su
código, la tabla `documentos_ia`, la extensión `vector` y la configuración de embeddings
y del revisor. Los gateways del chat son `HostedAiChatGateway`, `BaselineAiChatGateway`
y `DisabledAiChatGateway`.

## Fronteras

Laravel conserva permisos, ámbito de carrera, convocatorias, contenido, trabajos y
decisiones. El chat usa `AiChatGateway`; la extracción documental usa
`CurricularExtractionGateway`. Los adaptadores no autorizan usuarios ni escriben datos
académicos por decisión propia.

```text
Docente → chat autorizado → contexto fotografiado → cola → AiChatGateway
       ← propuesta de elaboración y referencias a ese contexto

Coordinación → PDF/XLSX privado → lectura/OCR → CurricularExtractionGateway
            ← propuesta por carrera/materia + extractos + excepciones
Coordinación → confirma selección → datos curriculares vigentes → nuevos snapshots
```

## Contexto del chat

- Carrera, asignatura, horas y semanas del expediente; datos curriculares confirmados
  fotografiados; estructura y filas de tablas de unidades/planificación.
- No se leen otros campos para ampliar ese contexto. La versión `curricular-units-v1` separa las preguntas nuevas del historial
  anterior, que permanece visible y no se envía al proveedor. Los trabajos de chat
  anteriores a esa versión terminan sin invocar el gateway.
- Ninguna búsqueda en documentos vivos ni recuperación vectorial.
- Referencias del tipo `contexto_academico`, `dato_curricular` y `seccion_silabo`.
  La respuesta solo puede citar evidencias incluidas en la entrada autorizada.
- El contenido existente y la pregunta son datos no confiables. No alteran permisos,
  estados, instrucciones del sistema ni configuración de herramientas.
- La salida ofrece propuestas; no completa automáticamente campos ni sustituye la
  validación determinística de semanas, horas o información obligatoria.

## Ingesta documental I-145

PDF y XLSX permanecen privados y asociados a una importación de la carrera. OpenParser
produce el documento interno PDF; la normalización editorial opcional conserva el
contenido y las estructuras protegidas. `CurricularWorkbookReader` conserva referencias
de hoja/celda y rangos combinados. No ejecuta fórmulas ni macros.

La extracción curricular identifica conceptos de texto/lista/tabla, destino y citas.
Laravel comprueba la correspondencia entre dato, extracto y documento. Solo se guardan
valores vigentes después de una confirmación con huella, permiso y alcance válidos.
El detalle técnico está en [academic-source-ingestion.md](academic-source-ingestion.md).
Estos documentos internos no tienen CRUD público ni alimentan una base vectorizada.

## Configuración, seguridad y degradación

La configuración de proveedor/modelo del chat permanece disponible. La lectura OCR y la
extracción curricular conservan sus adaptadores y configuración independientes. Los
secretos permanecen en configuración del entorno; no se incorporan al documento ni a
respuestas, logs o mensajes de error.

Cada llamada limita tamaño, tiempo, intentos y salida. La respuesta se valida contra el
contrato del flujo y se rechazan referencias ajenas. La clave de idempotencia incluye el
contexto, contenido, modelo e instrucciones relevantes; el historial no se recalcula
silenciosamente.

La indisponibilidad del proveedor afecta solo al servicio de IA correspondiente. La
elaboración manual, validación, envío, revisión humana y aprobación continúan. La IA no
califica, aprueba ni bloquea el proceso académico.

## Persistencia e historia

`historial_ia` conserva solo el chat: sesiones `silabo:{id}:usuario:{usuario}`, tipos
`mensaje` y `ocultamiento`. Las restricciones de inmutabilidad y la protección de
aprobados permanecen. Los mensajes usan contexto autorizado del expediente. I-168 quitó
los mensajes e índices del revisor y la tabla `documentos_ia`.

PV-12, PV-13, PV-14 y PV-18 siguen delimitando privacidad, capacidad y evaluación de los
servicios vigentes. Embeddings y revisor ya no existen en el código. La evidencia de retirada está en el plan I-146.
