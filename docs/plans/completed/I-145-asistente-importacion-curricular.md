# I-145 — Asistente de importación curricular y datos configurables

## Estado y corrección de alcance, 2026-09-27

Implementación autorizada por «ok veamos», después de la propuesta explícita del
responsable de un asistente con botón flotante que recibe documentos, extrae su
contenido y prepara el llenado por carrera/asignatura. I-144 implementó una carga
manual de resultados que no satisface ese recorrido completo. Su base se conserva
por compatibilidad; la entrada principal nueva parte de archivos y fuentes.

El resultado exigido es importar contenido una vez, revisar una propuesta por lote
y reutilizarlo en los sílabos. Agregar conceptos de los tipos admitidos debe hacerse
mediante datos/configuración, sin una tabla, endpoint o pantalla por cada concepto.

## Trazabilidad y permisos

- RF-008..045, RF-046..054; RN-005..016, RN-020..024, RN-028..030.
- RNF-001..010, RNF-024..036; CU-03..08; COR-11, COR-13, ADM-06, DOC-04, DOC-06.
- CP: registrar casos de aceptación de importación, aislamiento, evidencia y
  fotografía en la matriz; no inventar correspondencias del Plan Maestro.
- PV-02 (fuentes contradictorias), límite de habilidades, APE y agrupación de
  contenidos en unidades siguen siendo decisiones académicas. No bloquean extraer
  candidatos. Las discrepancias entre fuentes requieren elegir un candidato y aceptar
  esa decisión; evidencia inválida y conflictos estructurales bloquean las filas afectadas.
- Coordinación importa y confirma datos de su carrera. Administración mantiene la
  plantilla institucional. El asistente no recibe permisos superiores a su usuario.
- Se aclara la frontera de I-75 por la petición actual: la identidad y estructura
  académica continúan tipadas en su modelo; el contenido curricular documental
  admite conceptos configurables. No se añaden columnas arbitrarias a asignaturas.

## Recorrido que se implementa

1. Botón flotante solo en **Coordinación → Malla** abre el asistente de documentos.
   Carrera derivada de la sesión; carga PDF/XLSX y elección de fuentes ya cargadas.
2. PDF reutiliza OpenParser/PaddleOCR; XLSX se lee directamente con sus hojas/celdas.
   Archivo privado, huella, trabajo asíncrono y progreso. No se vuelve a pedir nombre,
   página o transcripción de cada dato al coordinador.
3. Se recorren los documentos completos mediante bloques identificados. La extracción
   asistida propone conceptos tipados, contenidos, materias y referencias verificables.
   El servidor valida tipos y evidencia; código o nombre inequívoco vincula materias.
   Una coincidencia dudosa permanece pendiente con selección asistida de la materia.
4. Se presenta una propuesta con fuente/localizador/extracto. Coordinación confirma
   el conjunto elegido y resuelve o excluye excepciones. Las candidatas nunca son
   datos vigentes hasta esa acción; no se exige volver a escribir las filas.
5. La confirmación guarda conceptos y valores en una transacción, revalida documentos,
   huellas, permisos, bloqueo de convocatoria y concurrencia. La corrección de bases
   conserva aprobados según I-144 y solo afecta materias pertinentes.
6. Administración encuentra los conceptos nuevos en el selector de datos automáticos
   y puede vincular listas curriculares a opciones docentes. La plantilla se resuelve
   con carrera/asignatura; el sílabo conserva valores, definición y evidencia completos.

## Persistencia y contrato

- Conceptos compartidos: clave estable, etiqueta, ámbito carrera/asignatura, tipo
  texto/lista/tabla y columnas declaradas para tablas. Su forma no cambia por importar
  otra carrera; discrepancias se presentan como conflicto.
- Valores curriculares: FK concepto/carrera/asignatura, contenido JSON validado según
  el tipo, evidencia y confirmador/fecha. Relaciones e identidad permanecen relacionales.
- Importaciones: estado, documentos, instrucciones opcionales, propuesta y fechas de
  solicitud/aplicación, selección aplicada e idempotencia por carrera. Son operaciones
  de importación, no versiones de malla/fuente.
- GET/POST `/coordination/curricular-imports`, GET `/{id}`, POST `/{id}/apply` y
  POST `/{id}/retry` (sin payload; detalle con HTTP 202).
- Confirmación: huella esperada, IDs seleccionados, materia corregida si corresponde
  y confirmación de regeneración cuando el procedimiento existente la requiera.
  `source_conflict` requiere `accept_conflict:true` en la fila elegida. El detalle
  añade `current_value` y `change:new|unchanged|replace|null` sin alterar la propuesta
  almacenada; para una materia aún sin resolver informa que el destino puede tener
  un valor que será reemplazado. `applied_items` conserva la decisión aplicada.
- Variables automáticas `curricular_{clave}`: catálogo procedente de conceptos en BD,
  resolución histórica exclusivamente desde la fotografía. Compatibilidad con las
  variables y sílabos anteriores a esta entrega.
- Los aliases I-144 de resultados/fuente/ubicación usan primero el concepto genérico
  `resultados_aprendizaje` confirmado de tipo lista/asignatura en la fotografía. Si
  no existe, mantienen la base fotografiada I-144; no duplican filas actuales.

## Ejemplos documentales de aceptación

- Proyecto p. 22, fila 40: Calidad de Software tiene cuatro resultados y seis
  contenidos mínimos. Extraerlos sin pedir transcripción; no inferir seis unidades.
- Guía pp. 3–4 y definiciones posteriores: recuperar habilidades del grupo y
  transversales. Preparar opciones y su texto; la elección docente sigue siendo humana.
- XLSX: Calidad en `Herramientas de aprendizaje!A44:D44`,
  `Inteligencia artificial!A42:C42` y `Bibliografia!A42:C42`. Mantener categorías,
  hojas/celdas y relaciones. El encabezado equivocado es una excepción del documento.
- Agregar un concepto sintético nuevo por importación (por ejemplo, competencias
  digitales) y mostrarlo en una plantilla sin modificar PHP ni el esquema físico.

## Invariantes y límites técnicos

- Archivos son datos no confiables, nunca instrucciones ejecutables. Validar ZIP/XML,
  tamaño expandido, referencias de celdas y respuesta del proveedor; no ejecutar macros.
- La IA propone extracción; tipos, pertenencia, coincidencia de extractos, duplicados
  y permisos se verifican determinísticamente. No mostrar confianza numérica del modelo
  como prueba de corrección; las discrepancias no se completan inventando hechos.
- Mantener hash del documento al preparar y al aplicar. Un job antiguo no publica ni
  sobrescribe una propuesta más reciente. Reintentos y aplicar dos veces son idempotentes.
- Una fuente distinta no reemplaza silenciosamente datos confirmados: la propuesta
  muestra los cambios. La pérdida del proveedor afecta solo una importación nueva.
- Nuevos conceptos de tipos admitidos son configuración. Nuevos formatos, fórmulas
  académicas o procesos fuera de esas operaciones pueden requerir desarrollo.
- No se certifican vigencia institucional ni criterios académicos desde un PDF ejemplo.

## Verificación prevista

- Backend: archivos/contratos, conceptos nuevos sin código, aislamiento entre carreras,
  materia ambigua, evidencia inválida, documentos cambiados, duplicados, reintentos,
  confirmación, fotografías históricas y validación/renderizado de datos automáticos.
- Frontend: carga/progreso/error/recuperación, revisión y selección, fuente visible,
  corrección de materia, permisos, opciones dinámicas, móvil y teclado.
- Puertas: `composer verify`; npm lint/typecheck/test/contract:check/build.
- Demostración real separada de pruebas fake; registrar qué proveedor y archivos se
  pudieron procesar. No presentar una interfaz mock como importación implementada.

## Avance

- [x] Inspeccionar ingesta, plantilla, ejemplos y límites de I-144.
- [x] Registrar alcance y contrato antes de implementar.
- [x] Persistencia, aplicación por lote y autorización.
- [x] Ingesta PDF/XLSX y extracción con evidencia.
- [x] Catálogo, enlaces, selección docente y fotografía.
- [x] Asistente flotante y revisión de propuestas.
- [x] Verificación, demostración y documentación de resultados (con proveedor simulado;
  aceptación con proveedores reales pendiente según el apartado siguiente).

La ubicación inicial en todas las pantallas de Coordinación fue corregida por
instrucción del responsable: el único acceso al asistente está en **Malla**.

### Evidencia local parcial, 2026-09-27

- Núcleo: `CurricularImportsTest` cubre también límites de palabra y comparación
  del valor vigente. Ejecución focalizada en PostgreSQL aislado
  `silabos_ueb_i145_core_test`, sin migraciones remotas.
  Junto con `ValidationMessagesInSpanishTest`: **16 pruebas, 179 aserciones, todas
  aprobadas**. Pint y PHPStan focalizados del núcleo y del exportador pasan;
  `openapi:export` regenera el contrato e incluye el reintento sin payload (202).
- Regresiones ejecutadas: resultados por asignatura y convocatorias aprobadas;
  Pint y PHPStan del núcleo sin errores. Los atributos de validación faltantes de
  upload/enlaces se comunicaron a sus responsables para la puerta de integración.
- Documentación: esquema, ingesta, pantallas, decisiones, pendientes, trazabilidad,
  configuración de entorno y exportador OpenAPI actualizados. La puerta completa y
  la demostración con proveedor real permanecen a cargo de la integración.
- En el entorno comprobado los proveedores reales permanecen deshabilitados. La
  demostración y las pruebas de extracción usan un gateway simulado; no acreditan
  ejecución real de OpenParser/DeepSeek ni precisión sobre los documentos del tutor.

### Integración y demostración, 2026-09-27

- Lectura nativa del XLSX proporcionado: tres hojas, 138 bloques y 90 685 caracteres
  conservados. Esta comprobación no empleó IA ni confirma la clasificación académica.
- Binding: nueve pruebas y 100 aserciones; variables dinámicas, selección combinada,
  evidencia congelada, compatibilidad de resultados I-144 y exportación Word/PDF.
- Núcleo e ingesta tras revisión: 29 pruebas y 312 aserciones. Se impide reasignar
  silenciosamente una materia identificada, aplicar una fuente cuyo PDF cambió y
  guardar una propuesta si la fuente cambia durante la extracción. El límite
  combinado de archivos es 30 MB. Un nombre de archivo repetido recibe un sufijo,
  sin sobrescribir una fuente anterior.
- Regresión OCR: 12 pruebas y 82 aserciones. Resultados y fallos tardíos no cambian
  el PDF, contenido ni estado de una carga posterior.
- Navegador: SPA/API y base PostgreSQL aislada, lectura de XLSX real, extracción
  simulada con datos sintéticos. Se comprobó autenticación, carga, revisión de citas,
  aplicación de tres datos y recuperación del historial; sin errores JavaScript ni
  desbordamiento horizontal a 360 px. Claro/oscuro comprobados. El proveedor simulado
  y sus fixtures viven fuera del repositorio y de producción.

### Configuración real pendiente

El entorno local consultado anuncia `pdf=false`, `xlsx=true`, `extraction=false`.
La aceptación sobre PDFs y extracción real de los documentos académicos sigue
pendiente de acceso vigente a OpenParser y al proveedor de extracción configurado.
Se solicitó la ruta del archivo de configuración, sin pedir claves en la conversación.
No se han confirmado datos académicos del tutor en producción ni se ha certificado
la precisión del proveedor mediante las pruebas simuladas.

Un concepto nuevo admitido aparece en el catálogo automático después de confirmarlo.
Administración vincula una vez el lugar correspondiente de la plantilla institucional;
los sílabos posteriores resuelven el contenido por carrera y asignatura. Las listas de
habilidades por materia y transversales pueden combinarse en la misma selección docente.
El asistente no modifica automáticamente el diseño institucional ni impone una cantidad
de habilidades que todavía requiere confirmación académica.

### Puertas finales y migración remota

- `composer verify` en PostgreSQL/Redis locales aislados: escaneo de secretos, Pint,
  PHPStan y **420 pruebas / 4921 aserciones aprobadas**.
- Frontend: lint, typecheck, build y contrato aprobados; **209 pruebas aprobadas y
  una omisión preexistente**, 115 consumidores y 126 operaciones API comprobadas.
  El navegador comprobó además crear la primera sección de plantilla y guardar la
  unión de las dos listas curriculares mediante la API real del entorno de demo.
- Base remota verificada mediante libpq y `.pgpass`: `silabos_ueb_db`, cuenta
  `silabos_ueb_app`. Se siguió estado → renombrado idempotente → migrador con bloqueo
  → estado, desde Laravel local y sin SSH. Solo estaba pendiente `000145`.
- Respaldo privado previo, fuera del repositorio:
  `silabos_ueb_db-pre-i145-direct-20260927.dump`, 491264 bytes, modo 0600;
  `pg_restore --list` legible (369 líneas). SHA-256:
  `2b59cb656e2f5827780009b9287e6ab3089bf96f1139b6f108e7a290e5cb6af8`.
  Se verificó el índice del dump; no se restauraron datos productivos en desarrollo.
- Migración aditiva `2026_09_27_000145_create_curricular_imports` completada.
  Conteos posteriores: 43 sílabos y 0 aprobaciones, iguales a los previos; las tres
  tablas nuevas permanecen vacías. No se cargaron documentos, semillas ni datos de
  demostración en esa base. No se ejecutó rollback ni se modificó otra aplicación.
- La publicación de código y migración no acredita el despliegue de API/workers/SPA
  en Dokploy. Su estado HTTP y los proveedores alojados requieren comprobación en
  el ambiente desplegado; no se afirma esa verificación desde la demo local.

## Mejora de claridad del panel (2026-09-27)

El responsable muestra el estado vacío del asistente y pide una presentación más
intuitiva, sin instrucciones repetidas. Alcance: I-145/I-147, COR-13, CU-05,
RF-027..033 y RNF-018..023. PV-19 sigue pendiente para dispositivos reales. No
cambian permisos, API, datos ni flujo de confirmación.

- [x] Sustituir los tres botones de navegación por pestañas con estado activo claro.
- [x] Quitar el aviso redundante; ordenar archivos nuevos, reutilización e indicación
      opcional, con límites legibles y acción principal visible.
- [x] Probar envío, navegación, errores y ventana estrecha; actualizar trazabilidad.

Reversión: restaurar la composición anterior del panel sin cambiar las importaciones
almacenadas.

Frontend: `npm test` aprobó 207 pruebas (una omisión preexistente); `npm run lint`
y `npm run build` aprobaron. Chromium con datos sintéticos verificó modo oscuro a
900 y 360 px, pestañas completas sin desbordamiento horizontal y acción visible.
Al desplegar la indicación en 360 px, el área desplazable mostró el textarea entero
por encima del pie. La validación en dispositivos reales sigue en PV-19.
