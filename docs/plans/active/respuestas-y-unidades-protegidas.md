# Respuestas y unidades protegidas

## Alcance confirmado

RF-017..033, RF-037..045; RN-009..012; CU-04, CU-05, CU-07, CU-09;
ADM-06, COR-16 y DOC-04. Se reutilizan responsables y permiso docente por campo.
No se añade configuración visible por celda. Las CP relacionadas son las de plantilla,
ficha, edición y envío de la trazabilidad vigente; no hay IDs locales más precisos.

El usuario confirma que Administración define texto, formato y encabezados; la persona
responsable completa espacios de respuesta. Solo las tablas de listas permiten variar
filas. Nombres y resultados de unidades pertenecen a Carrera; semanas, actividades y
horas pertenecen al docente. Se conserva la validación de horas por componente.

## Diseño

- Política documental genérica compartida entre API y SPA: texto no vacío fijo,
  párrafos y celdas vacíos para respuestas; tabla con encabezados y una fila totalmente
  vacía como lista; otras tablas con filas fijas. Bloques siguen sus esquemas JSON.
- Edición de respuestas mediante controles dentro del documento. Servidor valida
  contenido fijo, estructura, columnas y límites de filas en ficha y sílabo.
- Plantilla oficial: catálogo de cuatro unidades con nombre y resultado en un campo
  de Carrera; planificación semanal en un campo docente separado, con unidades
  numeradas. La ficha y el Excel incluyen el catálogo sin controles nuevos.
- Compatibilidad explícita de definición para que fotografías y plantillas antiguas
  conserven su interpretación. Una convocatoria abierta no se modifica por despliegue.

## Pasos

1. Implementar protección documental y editor de respuestas; integrar ficha, Excel,
   sílabo, copia y validación de contenido obligatorio.
2. Actualizar plantilla oficial, conservar horas y semanas, revisar migración de datos
   sin tocar procesos cerrados ni cambiar una plantilla con proceso abierto.
3. Adaptar pruebas existentes y documentar reglas, contrato y trazabilidad. No añadir
   ni ejecutar pruebas sin petición; revisar tipos, estilo, referencias y secretos.
4. Hacer commit y push a main. Si activar la nueva plantilla exige pausar y rehacer
   trabajo en curso, preparar el resultado y solicitar esa confirmación al final.

## Pendientes relacionados

PV-01 usa Administración como autoridad actual; PV-07 mantiene el formato oficial
como referencia. PV-16 no se amplía: Coordinación edita la ficha, no el sílabo.
Se confirma la concordancia de horas por solicitud actual; 16 semanas y casos de
prácticas permanecen con el comportamiento vigente y su pendiente institucional.
