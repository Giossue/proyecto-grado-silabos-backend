# ADR-0010: Plantilla de documentos libres por responsable

## Estado

Aceptado — 2026-10-09, por pedido del responsable en I-210. Reemplaza ADR-0009.
Ampliado por [ADR-0011](0011-template-as-data.md): la plantilla completa, con formato
y bloques, es un dato JSON.

## Contexto

Los tipos y sus formularios particulares complican la plantilla institucional. I-210
establece que la plantilla representa el documento completo del PDF de referencia.

## Decisión

La única plantilla contiene secciones ordenadas y campos. Cada campo conserva título,
`quien_llena` (`docente`, `coordinacion`, `institucional`) y un documento
JSON libre con texto, listas y tablas. Se retiran los tipos, su registro y la
compatibilidad de campos anteriores. No existen tablas especiales por sección.

Administración define el contenido inicial. Docencia edita los campos docentes;
Coordinación llena sus campos una vez por materia en la ficha y el sílabo los hereda.
El docente no los cambia, salvo que Coordinación active en la ficha de esa materia el
permiso para que los edite (I-230). Los campos institucionales se leen desde la plantilla
con las variables del sistema resueltas. Bibliografía corresponde a Docencia.

Título y logos pertenecen al encabezado fijo, fuera de los campos. Las secciones
pueden ser horizontales. El snapshot congela cada documento resuelto y Word/PDF
renderizan esos documentos sin depender de la plantilla viva.

Una migración nueva cargó la plantilla oficial y reemplazó el trabajo en curso; conservó
aprobados y procesos cerrados. Hoy la plantilla oficial vive en
`resources/templates/silabo-oficial.json`. Los cambios posteriores de plantilla
regeneran los sílabos al reanudar el proceso, incluidos los aprobados (ADR-0002). No
modifica migraciones aplicadas.

## Validación del responsable

Confirmado en la continuación de I-210: se usa el reparto de la tabla del plan y
Docencia puede agregar filas libremente. Con ADR-0011, el bloque de unidades exige al
enviar las 16 semanas y las horas de la malla.
PV-01, PV-02, PV-07, PV-16 y PV-19 mantienen sus alcances existentes.

## Consecuencias

Constructor, ficha, edición docente, validación y exportación usan un formato
documental común. El cambio exige un despliegue coordinado de API y SPA, respaldo
verificado antes de migrar y conservación del render histórico de revisiones aprobadas.
