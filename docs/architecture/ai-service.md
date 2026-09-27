# Servicios de IA e ingesta documental

## Alcance vigente — I-146

El chat ayuda a elaborar contenido y unidades desde el contexto académico y curricular
fotografiado del sílabo. La extracción curricular I-145 propone datos desde documentos
aportados por Coordinación. Son flujos separados con confirmación humana.

Se retiraron el revisor por campo, la revisión integral, sus operaciones de aplicación y
feedback, y la recuperación textual/vectorial de Fuentes académicas. No se activan
embeddings ni se consulta `documentos_ia` para el chat. Las tablas y los mensajes antiguos
se conservan como historia; su existencia no habilita funcionalidad retirada.

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
- No se leen otros campos ni se usa la casilla histórica `ia_habilitada` para ampliar
  ese contexto. La versión `curricular-units-v1` separa las preguntas nuevas del historial
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
silenciosamente. Los trabajos del revisor y de embeddings anteriores a I-146 terminan
sin generar nuevos resultados del flujo retirado.

La indisponibilidad del proveedor afecta solo al servicio de IA correspondiente. La
elaboración manual, validación, envío, revisión humana y aprobación continúan. La IA no
califica, aprueba ni bloquea el proceso académico.

## Persistencia e historia

`historial_ia` conserva conversaciones y evidencia de ejecuciones anteriores; las
restricciones de inmutabilidad y la protección de aprobados permanecen. Los nuevos
mensajes usan contexto autorizado del expediente. `documentos_ia` y sus índices son
estructuras históricas inactivas para este producto; I-146 no ordena borrarlas ni aplicar
una migración destructiva.

PV-12, PV-13, PV-14 y PV-18 siguen delimitando privacidad, capacidad y evaluación de los
servicios vigentes. La elección de embeddings y la evaluación del revisor retirado dejan
de ser puertas de implementación activas. La evidencia de retirada está en el plan I-146.
