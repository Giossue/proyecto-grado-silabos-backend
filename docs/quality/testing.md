# Estrategia de pruebas

## Objetivo

Demostrar comportamiento, autorización, integridad histórica y operación degradada. La
cantidad de tests no sustituye cobertura de riesgos.

## Niveles

### Unitarias

- máquina de estados;
- permisos/invariantes puras;
- cálculos y reglas de plantilla;
- claves de idempotencia y huellas;
- mapeo de contratos externos.

No necesitan Laravel completo si la regla puede probarse aisladamente.

### Feature Laravel

- rutas JSON, Form Requests y Policies;
- transacciones y persistencia;
- filtros/paginación/alcance;
- revisión, observación, aprobación y reapertura;
- carga/descarga privada;
- eventos, trabajos persistidos y dispatch después del commit.

### Integración

- constraints/concurrencia específicos de PostgreSQL;
- Redis/colas y Horizon cuando aplique;
- renderer DOCX/PDF;
- almacenamiento real compatible;
- contrato HTTP del servicio de IA;
- adaptador institucional contra fixture/entorno autorizado.

### Interfaz y end-to-end

- componentes complejos (editor, diff, observaciones, evidencia);
- flujo principal Docente/Coordinador/Administrador;
- teclado, responsive y accesibilidad automatizada + manual;
- estados de red, conflicto, sesión revocada y proveedor caído.

Las pruebas de componentes y flujos de navegador viven en el repositorio frontend. Allí
`npm test`, `npm run typecheck` y `npm run build` cubren el contrato del cliente, los
editores documentales, Texto libre, selección múltiple, booleanos, observaciones e
IA. La aceptación con documentos y dispositivos reales continúa registrada en PV-19.

La persistencia se verifica aquí con `TemplateDocumentTest` y `ReviewWorkflowTest` sobre
PostgreSQL: permisos, huellas, saneamiento, datos tipados, reinicio de borradores,
retiro/restauración de campos e historia inmutable. La prueba de exportación inspecciona
OOXML generado; no certifica visualmente Microsoft Word.

## Base de datos de prueba

Usa PostgreSQL para suites que validan producción. SQLite no puede probar semántica de
UUID, `timestamptz`, constraints de exclusión, JSON/índices o concurrencia equivalentes.
Cada test controla sus datos y no depende de orden.

`DatabaseBootstrapTest` verifica que la conexión establezca UTC y que el seeder sintético
sea idempotente al ejecutarse dos veces, incluidas las asignaciones protegidas por rangos
de exclusión.

## Matriz mínima por mutación

1. camino feliz;
2. entrada inválida;
3. usuario sin rol;
4. rol correcto fuera de alcance;
5. estado inválido;
6. recurso inexistente/no revelable;
7. petición repetida;
8. conflicto concurrente;
9. rollback a mitad de operación;
10. auditoría/evento correcto.

## Tiempo y asincronía

- Congela/injecta reloj para vigencias, plazos y zona horaria.
- Fakes prueban dispatch; una suite integrada prueba el worker real.
- No uses sleeps para esperar trabajos; observa estado/eventos.
- Simula reintentos y ejecución duplicada.
- Verifica que rollback descarte efectos posteriores al commit.

## Documentos

Prueba contenido y estructura del DOCX/PDF, huella y autorización. Renderiza muestras a
imagen/PDF y compara visualmente casos representativos. Un archivo “válido” puede estar
cortado o desalineado.

## IA

I-146 retira el revisor y RAG. La cobertura vigente del chat comprueba:

- autorización por docente/carrera, límites e idempotencia;
- contexto académico/curricular fotografiado y Texto libre del docente sin datos personales;
- ausencia de recuperación textual/vectorial (su código y tablas se borraron en I-168);
- historia previa conservada, pero excluida de nuevas llamadas al proveedor;
- referencias inventadas, salida malformada o demasiado grande;
- prompt injection tratado como datos y ausencia de modificación/decisión automática;
- timeout, caída y reintento sin impedir la edición determinística;
- incompatibilidad de caché al cambiar la versión de contexto;
- rutas del revisor retiradas y trabajos antiguos sin efectos nuevos.

La extracción curricular I-145 tiene pruebas separadas de evidencia, conflicto,
asociación a carrera/asignatura y confirmación por lote. `IA-NEG-01` a `IA-NEG-09`
conservan trazabilidad histórica; no reactivan el revisor retirado.

La suite principal debe aprobar con IA desactivada.

## Antecedente de cobertura I-06 (revisor retirado por I-146)

La descripción siguiente documenta la etapa anterior. La cobertura vigente se registra
en el plan I-146 y en `AiAssistanceTest`; no exige conservar endpoints retirados.

`AiAssistanceTest` usa PostgreSQL real y gateways controlados para cubrir CU-08 e
`IA-NEG-01` a `IA-NEG-09`:

- autorización por colaborador/carrera/campo, idempotencia y dispatch después del commit;
- exclusión de fuente inactiva, evidencia vacía y conflicto exacto sin precedencia;
- contenido hostil tratado como dato y ausencia de modificación/decisión automática;
- cita inventada, salida sobredimensionada, acción académica y host no loopback;
- caída con error seguro y continuidad del guardado determinístico;
- reutilización por clave funcional e invalidación al cambiar contenido;
- aplicación explícita con huella y `version_bloqueo`, antes/después y repetición idempotente;
- inmutabilidad y cierre temporal de ejecución, evidencia, recomendaciones, citas y
  feedback mediante triggers PostgreSQL.

La suite automatizada valida el contrato con `contract-simulator-v1`; no sustituye la
evaluación experta del modelo/corpus, pruebas de carga del servicio local ni revisión
manual de accesibilidad, previstas para I-08.

## Cobertura automatizada I-05

`DocumentOperationsTest` usa PostgreSQL real y almacenamiento privado falso para cubrir:

- autorización por revisión y por carrera, idempotencia de solicitud y dispatch;
- estructura, contenido, huellas y determinismo de DOCX/PDF desde la misma entrada;
- descarga reautorizada y denegación lateral;
- fallo seguro, conteo de intentos y reintento administrativo auditado;
- trabajo/notificación única, propiedad de lectura e inmutabilidad en PostgreSQL;
- indicadores y detalle con el mismo alcance de carrera;
- consulta administrativa y protección append-only de auditoría.

La comparación visual contra el DOCX oficial, accesibilidad manual y prueba en un objeto
S3 compatible permanecen como evidencia manual/integrada pendiente; no las reemplaza la
validez estructural.

## Rendimiento, seguridad y usabilidad

- CP-N cubren presupuestos, concurrencia, backup/restauración, compatibilidad y acceso.
- La carga usa datos representativos y percentiles, no una petición aislada.
- Seguridad combina pruebas automatizadas, revisión y casos de abuso.
- Usabilidad usa tareas, éxito, tiempo y errores con instrumento definido.

## Evidencia técnica I-08

- `composer verify` reúne escaneo de secretos, lint PHP/TypeScript/Vue, reglas estáticas
  de accesibilidad, formato, análisis Larastan nivel 7, suite PostgreSQL y build.
- `composer verify:restore` crea y elimina un clúster efímero para comprobar un dump
  sintético; se niega a operar sobre una base sin sufijo seguro.
- `composer benchmark:readiness` limita el destino a loopback y registra fallos, p95 y
  tasa con 500 solicitudes/50 concurrencias por defecto.
- El smoke real debe procesar `critica`; los workers funcionales deben escuchar además
  `notificaciones`, `documentos`, `ia` e `integraciones`.
- `eslint-plugin-vuejs-accessibility` protege asociaciones de etiqueta, autofocus y orden
  de tabulación, pero no reemplaza teclado, lector, contraste, zoom y viewport manuales.

La relación formal individual de `CP-F01..35` y `CP-N01..16` no puede cerrarse porque sus
enunciados no están en este repositorio. `acceptance-status.md` registra la evidencia por
capacidad y el procedimiento para incorporar la matriz maestra sin inventarla.

## Trazabilidad

Usa nombres como:

```php
it('CP-F__ RF-___ impide ...', function () { /* ... */ });
```

o metadatos equivalentes. CI conserva resultados y evidencia de los casos críticos.
