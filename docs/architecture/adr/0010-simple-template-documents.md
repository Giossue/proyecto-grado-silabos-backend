# ADR-0010: Plantilla de documentos libres por responsable

## Estado

Aceptado — 2026-10-09, por pedido del responsable en I-210. Reemplaza ADR-0009.

## Contexto

Los tipos y sus formularios particulares complican la plantilla institucional. I-210
establece que la plantilla representa el documento completo del PDF de referencia.

## Decisión

La única plantilla contiene secciones ordenadas y campos. Cada campo conserva título,
ayuda opcional, `quien_llena` (`docente`, `coordinacion`, `institucional`) y un documento
JSON libre con texto, listas y tablas. Se retiran los tipos, su registro y la
compatibilidad de campos anteriores. No existen tablas especiales por sección.

Administración define el contenido inicial. Docencia edita los campos docentes;
Coordinación llena sus campos una vez por materia en la ficha y el sílabo los hereda
sin permitir ajustes docentes. Los campos institucionales se leen desde la plantilla
con las variables del sistema resueltas. Bibliografía corresponde a Docencia.

Título y logos pertenecen al encabezado fijo, fuera de los campos. Las secciones
pueden ser horizontales. El snapshot congela cada documento resuelto y Word/PDF
renderizan esos documentos sin depender de la plantilla viva.

Una migración nueva carga la plantilla oficial desde `config/syllabus_template.php`
y reemplaza el trabajo en curso. Solo esta migración conserva aprobados, revisiones
vinculadas tras reapertura y procesos cerrados conforme a I-118/I-144. Esta excepción
no cambia I-188: los cambios futuros de plantilla conservan su reinicio al reanudar.
No modifica migraciones aplicadas.

## Validación del responsable

Confirmado en la continuación de I-210: se usa el reparto de la tabla del plan,
Docencia puede agregar filas libremente y escribe los totales sin cálculo ni validación.
PV-01, PV-02, PV-07, PV-16 y PV-19 mantienen sus alcances existentes.

## Consecuencias

Constructor, ficha, edición docente, validación y exportación usan un formato
documental común. El cambio exige un despliegue coordinado de API y SPA, respaldo
verificado antes de migrar y conservación del render histórico de revisiones aprobadas.
