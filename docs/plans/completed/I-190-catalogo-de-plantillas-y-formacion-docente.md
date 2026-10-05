# I-190 — Catálogo de plantillas de campo y formación del docente en su perfil

> Implementación local verificada (backend 345 pruebas, Pint y PHPStan; frontend 306 pruebas, vue-tsc y ESLint). La migración `000197` la aplica el deploy; esta tarea no se conectó a la remota.

## Pedido del responsable, 2026-10-05

1. Al agregar un campo, poder elegir plantillas oficiales ya armadas (Descripción, Distribución y planificación…) y modificarlas después.
2. La Identificación institucional no se edita. Su único dato del docente que nunca cambia, «Formación y experiencia académica-investigativa», se escribe una vez en el perfil del docente y se reutiliza.
3. Catálogo solo oficial, en el código; la distribución es una sola tabla.

## Cambios

- `config/syllabus_template.php` (`catalog`) y `SyllabusTemplateCatalog`: 10 plantillas. «Distribución y planificación (16 semanas)» trae una tabla con encabezado y 16 filas. `POST .../fields` acepta `preset`.
- Contenido inicial por campo (`configuracion.initial_content` del bloque, sin columna nueva): lo copian los sílabos nuevos (`InheritMasterValues`) y Administración lo edita en «Propiedades del campo».
- Migración `000197`: `usuarios.formacion_experiencia_usuario`; el campo `formacion_experiencia` pasa a heredado con origen `perfil_docente`, no editable por el docente en el sílabo.
- `PUT /authentication/formation` (`UpdateTeacherFormation`): guarda la formación y actualiza los sílabos `sin_iniciar`, `borrador` y `correccion_solicitada` del docente; los enviados no cambian. Sin formación, la validación pide completarla en el perfil.
- Frontend: selector «Plantilla» al agregar campo, editor de contenido inicial y formulario de formación en el perfil del docente.

## Fuera de alcance

IA que llena la ficha desde los documentos de la carrera (siguiente plan) y plantillas guardadas por el administrador.
