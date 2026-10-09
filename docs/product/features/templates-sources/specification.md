# Plantilla y datos curriculares

## Trazabilidad

RF-017..026, RNF de integridad, seguridad y mantenibilidad, RN-009..016, CU-04/CU-07,
ADM-05/ADM-06, COR ficha de materia, DOC-02/DOC-04, CP-F plantilla/borrador/documentos.
PV-01, PV-02, PV-07, PV-16 y PV-19 permanecen abiertos en su alcance.

## Plantilla simple (I-210)

La UEB mantiene una sola plantilla institucional, sin versiones ni publicación (I-32).
La plantilla es el documento completo del PDF de referencia indicado en I-210.
Título y logos son fijos, fuera de los campos. Administración agrega, quita, ordena
y edita secciones y campos; una sección puede tener orientación horizontal.

Cada campo tiene título, ayuda opcional, un responsable y contenido documental libre.
Texto, listas y tablas pertenecen al mismo editor, sin tipos ni tablas especiales
por sección. Docencia escribe sobre el contenido inicial de sus campos. Coordinación
llena sus campos una vez por materia en la ficha; el sílabo los hereda y Docencia no
los ajusta. Los campos institucionales conservan el contenido de la plantilla y pueden
llevar variables del sistema. La bibliografía es del docente.

Las variables existentes cubren estructura académica, materia, convocatoria, docente,
horas/créditos y firmas/fechas. No existe una tabla automática especial: las variables
se insertan dentro del documento de un campo. La ficha, el snapshot y las exportaciones
usan el mismo documento; una revisión enviada conserva su copia completa e inmutable.

El formato inicial de `config/syllabus_template.php` contiene las doce secciones de
I-210: Identificación, Descripción, Objetivos, Resultados, Habilidades blandas,
Planificación de unidades horizontal, Metodología y ambientes, Evaluación, Perfil de
egreso, Conducta ética, Bibliografía y Revisión/aprobación.

## Cambios y conservación

Una corrección de plantilla sigue I-118/I-144: con el ámbito pausado y confirmación,
el cambio y la regeneración son una transacción. Se reemplazan los expedientes en
curso sin aprobación, incluidas revisiones, IA y exportaciones. Se conservan aprobados,
sus revisiones vinculadas tras reapertura y procesos cerrados. La migración I-210 aplica
ese mismo criterio al cargar el formato del PDF; nunca ejecuta reinicios destructivos
del esquema ni seeders de demostración.

La API comprueba todos los permisos en servidor. Un borrador incompleto se guarda;
la validación determinística señala los contenidos pendientes sin depender de IA.
El Word y su PDF se construyen desde documentos fotografiados. Un sílabo histórico
sigue renderizando después de editar la plantilla viva.

## Pendientes de I-210

El reparto restante de responsables, la escritura manual de totales y la edición
libre de filas permanecen «Por validar» hasta confirmación del responsable. No se
convierten en reglas de aceptación por este documento. Los bloqueos y respuestas se
registran en el plan.

## Importación curricular

I-146 retiró el CRUD de Fuentes académicas y la recuperación vectorial. Los documentos
PDF/XLSX de importación son temporales (I-152); al guardar o descartar se eliminan.
Coordinación confirma los datos dentro de su carrera y conserva extracto y procedencia.
La importación de bibliografía de ficha deja de alimentar el sílabo porque su campo
corresponde a Docencia. No se amplían permisos de otras carreras ni del coordinador
sobre el borrador docente (PV-16).
