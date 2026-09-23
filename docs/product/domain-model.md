# Modelo de dominio

## Ámbitos y agregados

### Identidad y alcance

- `Usuario`: persona autenticable, activa o inactiva. Su nombre se guarda normalizado
  (I-32): mayúsculas con tildes, primero nombres y luego apellidos, sin espacios
  sobrantes; `PersonName::normalize` lo aplica en toda escritura.
- `Rol`: valor fijo: Administrador, Coordinador o Docente; no es una entidad ni catálogo.
- `AsignacionRol`: relación RBAC de rol y alcance; una persona puede tener varias. Una
  asignación `coordinador` activa de carrera es coordinación efectiva.
- `DocenteParalelo`: vincula una `AsignacionRol` de docente con un paralelo. No hay tabla de
  coordinación separada ni tabla por rol. Ninguna relación programa inicio o fin:
  `activo` expresa estado actual.

Una persona puede coordinar más de una carrera mediante asignaciones independientes. La
sesión conserva una sola `AsignacionRol` activa: Coordinación siempre confirma la carrera
al entrar y puede sustituirla desde el menú, sin sumar alcances ni privilegios.

### Estructura académica

- `Facultad` (con campus obligatorio), `Carrera`, `Modalidad`, `PeriodoAcademico`.
- `Malla`, `DefinicionCampoMalla`, `Asignatura` con ciclo/orden,
  `ValorCampoAsignatura` y `RequisitoAsignatura`.
- `ProgramacionAsignatura`, `Paralelo`, `DocenteParalelo`.
- `AliasInstitucional` traduce el texto libre de la fuente hacia un catálogo normalizado.

Una `Facultad` agrupa muchas `Carreras` y elige exactamente un campus de la lista fija de
cinco ubicaciones institucionales. `Carrera` hereda el campus de su facultad; no existe
un catálogo ni una entidad independiente de campus. La programación conserva una copia
textual del campus elegido para preservar el contexto histórico del sílabo.
Su logo, cuando existe, es un `ObjetoAlmacenado` privado e inmutable referenciado por
`logo_objeto_id`; la facultad no guarda rutas del sistema de archivos.

El campus es un valor fijo de Facultad (I-106), sin código ni CRUD propio. La modalidad
tampoco es catálogo: son los valores fijos del Reglamento de Régimen Académico
(`StudyModality`). La programación de asignatura no elige ninguno de los dos: hereda el
campus de la facultad de su carrera y la modalidad de la materia si se apartó
(`asignaturas.modalidad`) o de la base aprobada de la carrera (`carreras.modalidad`,
I-35, I-37). La excepción de una materia no cambia ni recalifica la modalidad de la
carrera. La programación conserva las copias porque el sílabo toma de ahí campus y modalidad.

`PeriodoAcademico` es un catálogo institucional único: su código no se repite entre
carreras. Aunque la fuente histórica lo replique por carrera, el producto lo consolida
como la ventana temporal común de la universidad (I-41).

Una `Asignatura` se identifica dentro de su carrera por su código visible, que es el que
leen las personas y el que usa el producto en programaciones, sílabos y documentos.

Las relaciones que ya respaldan un sílabo se protegen y no se eliminan. Las que aún no
tienen dependencias se eliminan; roles, nombramientos y asignaciones se desactivan sin
borrar su evidencia.

El Administrador gobierna las entidades institucionales globales y asigna la coordinación
de una carrera. El Coordinador mantiene su estructura curricular, asignaturas programadas, paralelos y
asignaciones docentes solo dentro de esa carrera. Un periodo académico representa fechas
y la cantidad calculada de semanas lectivas. Al crear uno nuevo, su fecha de inicio debe
ser el día actual en `America/Guayaquil` o una fecha posterior; la fecha final debe ser
igual o posterior a la inicial. Esta restricción no impide corregir registros históricos;
el ciclo representa la posición de una materia dentro de la estructura curricular.

Cada carrera tiene una sola estructura curricular, almacenada directamente en la carrera:
su código y cantidad de ciclos. No hay una entidad, versiones ni estado activable de
`mallas`. Las asignaturas pertenecen directamente a la carrera. Sus atributos académicos
son fijos y obligatorios; ninguna carrera crea columnas, valores EAV o campos arbitrarios.
Las horas totales se derivan de ACD + APE + AA y el servidor las recalcula en cada alta o
edición. Si el desglose no envía una posición, la materia se agrega después de la última
del mismo ciclo. Una relación académica guarda origen, destino y tipo; el color del
diagrama no constituye la regla. El desglose académico y el constructor visual son dos
proyecciones de la estructura curricular de la carrera.

### Programación por período

`ProgramacionAsignatura` vincula una materia de la carrera con el período en que se dicta.
Sus paralelos y asignaciones docentes forman la organización operativa de esa
programación; no son una nueva carrera o programa aprobado por el CES.

`Paralelo` no tiene estado individual: antes de relacionarse con un sílabo puede
eliminarse; después se conserva como evidencia histórica. Su disponibilidad se deriva
de la programación y el período.

El estado temporal del período no se persiste: se deriva de `fecha_inicio` y
`fecha_fin` con la fecha local de `America/Guayaquil`. Antes del inicio está
**Próximo**, dentro del rango inclusivo está **En curso** y después de `fecha_fin` está
**Finalizado**. Al finalizar, la programación, sus paralelos, asignaciones docentes y
sílabos se conservan como historia de solo lectura. Las operaciones directas y los
relevos aplican la misma protección en el servidor (I-64).

### Configuración

- `PlantillaSilabo` institucional única, sin versiones (I-32/I-111): su documento JSON
  contiene secciones, bloques, tablas y configuración visual. Las definiciones de campo
  permanecen relacionales por sus referencias transaccionales. Se edita en el sitio.
- `FuenteAcademica` es un documento de la Coordinación de la carrera: nombre,
  descripción y contenido JSON estructurado editable. No tiene versiones; sus
  fragmentos son un índice reemplazable y la evidencia de IA conserva su propia
  fotografía del contenido.

No hay publicación: la estructura se comprueba al abrir o reanudar el proceso. Un sílabo
sin enviar lee la plantilla en vivo; una revisión enviada conserva su copia completa y ya
no depende de ella durante el flujo normal. Si se corrige la plantilla o una base académica
durante una pausa, todos los sílabos afectados se reemplazan previa confirmación, incluso
los enviados o aprobados y sus análisis de IA. La corrección y la regeneración son una
sola transacción; los procesos cerrados conservan su historia (I-118).

### Proceso de sílabos y convocatoria

`ProcesoSilabos` es el calendario institucional: nombre, período académico, plantilla
institucional, fecha de inicio, fecha de entrega y estado (`abierto`, `pausado`,
`cerrado`). Lo administra Administración porque el calendario académico oficial obliga
a todas las facultades. Se abre desde el período académico con el período fijado, nombre
y plantilla automáticos; la creación y apertura ocurren en una sola transacción. La base
impone un solo proceso abierto o pausado a la vez y conserva los cerrados como historial
(I-120).

Una convocatoria institucional puede eliminarse junto con sus alcances y sílabos
generados únicamente si nunca hubo un evento `silabo.borrador_iniciado` en ninguno de
sus expedientes. La eliminación es administrativa, transaccional y auditada.

`Convocatoria` cuelga de un proceso obligatorio y vincula carrera, período heredado del
proceso, el subconjunto no vacío de fuentes activas elegido por Coordinación, agrupación,
fechas y estado (`preparacion`, `abierta`, `pausada`, `cerrada`).
Hereda la plantilla, el período y las fechas del proceso al prepararse; las fechas se
copian para validar localmente la ventana de envío. La prórroga institucional actualiza
la entrega del proceso y la etapa `borrador` de todas sus convocatorias; el inicio no se
prorroga. Solo se abre con el proceso abierto; al abrirla, la plantilla debe estar completa y las fuentes elegidas activas.
La selección queda congelada al iniciar el alcance y no se amplía al reanudarlo.

Una convocatoria está **en curso** cuando ella está abierta y su proceso también. Esa
condición habilita a los docentes y, por lo mismo, congela lo que sostiene su trabajo:
con el proceso abierto no se edita la plantilla; los catálogos institucionales, cuentas
y roles continúan administrables. Con una
convocatoria en curso no se edita la estructura curricular, las fuentes, programaciones, paralelos ni asignaciones
genéricas de esa carrera. En un período que aún no finaliza, el relevo docente es la
única excepción, porque traslada de forma atómica la responsabilidad y el historial.
Para corregir se pausa: Administración
el proceso —detiene a toda la universidad—, Coordinación su convocatoria —solo su
carrera—. Los expedientes ya creados conservan la plantilla con la que nacieron; cambiar
la del proceso solo alcanza a las convocatorias que se abran después.

### Sílabo

`Silabo` identifica el expediente canónico por asignatura, período y carrera. Fija en
`contexto_academico` una fotografía de la estructura curricular, materia y su programación al momento de crearse,
por lo que cambios posteriores no reescriben el expediente. Puede agrupar
docentes/paralelos compatibles o registrar una excepción justificada.

`RevisionSilabo` es una fotografía inmutable del contenido enviado. El borrador actual
puede editarse con control de concurrencia; cada envío crea otra revisión.

El contenido dinámico usa definiciones de campo más valores y filas repetibles, no DDL
por plantilla:

- `ValorCampo` para valores escalares/estructurados;
- `FilaRepetible` para listas y tablas;
- referencias explícitas a `DefinicionCampo` y `VersionPlantilla`.

### Revisión

- `Observacion`: campo, sección o documento; autor, revisión, estado y fecha.
- `RespuestaObservacion`: histórica y de solo lectura. Desde I-120 el docente no
  responde observaciones: corrige el sílabo y lo reenvía.
- `Aprobacion`: actor, revisión, fecha, metadatos y huella.
- `Reapertura`: causa, actor, revisión aprobada de origen y nueva revisión.

### Validación e IA

- `EjecucionValidacion` y `ResultadoValidacion`: reglas determinísticas reproducibles.
- `EjecucionIA`: entrada/huella, modelo, parámetros, estado y tiempos.
- `RecomendacionIA`: sugerencia informativa.
- `EvidenciaRecomendacion`: fuente citada con extracto y huella del contenido.
- `RetroalimentacionIA`: aceptar, ignorar o no útil; la aplicación explícita es humana.

No mezcles ambos subsistemas. Una validación puede bloquear si una regla aprobada lo
establece; una recomendación de IA nunca bloquea por sí sola.

### Operación

- `ObjetoAlmacenado` y `ArtefactoExportacion`.
- `Notificacion`, `EventoAuditoria`, `EventoOutbox`, `EjecucionTrabajo`.

## Invariantes

1. Cada acción protegida exige usuario activo y permiso efectivo sobre el recurso.
2. No se crean dos coordinaciones activas superpuestas para una misma carrera.
3. La única plantilla institucional activa crea nuevos sílabos en una convocatoria de cualquier carrera.
4. Una revisión enviada/aprobada es inmutable en el flujo normal y contiene su propia
   copia de la plantilla; I-118 permite eliminar el agregado completo únicamente durante
   un reinicio institucional confirmado de un proceso vigente y pausado.
5. Todo envío o reenvío inserta una revisión; nunca actualiza la anterior.
6. Una aprobación apunta a una revisión concreta.
7. Una reapertura no altera la aprobación previa.
8. La comparación solo usa revisiones del mismo expediente autorizado.
9. Una recomendación solo cita fuentes activas fijadas por esa convocatoria, y su
   evidencia es una fotografía inmutable del contenido citado.
10. Word y PDF se generan desde la copia que guarda la propia revisión.
11. Redis y el servicio de IA pueden fallar sin corromper el expediente.
12. La estructura curricular única de una carrera se define por
    `cantidad_ciclos_malla`; `codigo_carrera` es obligatorio y único, y no existe código
    de malla ni estado propio de esa estructura (I-107).
13. Una materia o relación de requisito pertenece a una única carrera mediante la
    asignatura.
14. Programar materias y abrir procesos exige asignaturas activas, programadas con sus
    paralelos y docentes, no una malla activable.
15. Todo sílabo y toda revisión conservan el contexto académico fijado al crearse.
16. Las horas totales de una materia se derivan de sus componentes horarios activos y no
    de un valor ingresado manualmente.

## Tipos de campo de plantilla

- grupo/sección;
- texto corto;
- texto largo con presentación estructurada;
- número;
- fecha;
- selección única o múltiple;
- booleano;
- lista o tabla repetible;
- cálculo;
- referencia a maestro;
- campo condicional.

Cada definición tiene clave estable, etiqueta, ayuda, tipo, obligatoriedad, visibilidad,
origen, permisos, reglas, posición y habilitación de IA.

La cobertura funcional de las doce secciones se detalla en `syllabus-sections.md`.

## Datos estructurados frente a narrativa

Códigos, horas, créditos, resultados, habilidades, TIC/TAC/IA y bibliografía se guardan
de forma estructurada cuando requieran cálculo, validación o referencia. La plantilla
persiste la composición visual de la narrativa, listas y tablas como JSON validado; el
docente no escribe ese JSON a mano ni se usa Markdown como tipo de campo. Nunca se
extrae un dato exacto desde texto libre si existe una fuente estructurada.
