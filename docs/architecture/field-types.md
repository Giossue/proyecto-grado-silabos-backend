# Campos de documento de la plantilla

Fuente: [I-210](../plans/active/I-210-plantilla-simple.md) y
[ADR-0010](adr/0010-simple-template-documents.md), que reemplaza ADR-0009.

Los campos no tienen tipo. Todos contienen un documento JSON libre: texto, listas
y tablas del mismo editor. La estructura del contenido no cambia su responsable.

| `quien_llena` | Contenido inicial y valor efectivo | Permiso docente |
|---|---|---|
| `docente` | Documento inicial de Administración; luego documento del sílabo | Editar |
| `coordinacion` | Documento de la ficha por materia, copiado al crear el sílabo | Solo lectura |
| `institucional` | Documento de la plantilla, con variables del catálogo | Solo lectura |

Bibliografía es docente. El responsable confirmó el reparto de la tabla de I-210,
las filas libres y los totales escritos por Docencia sin validación. Título y logos son fijos y no son campos.

## API

La API de estructura conserva las operaciones de secciones y campos bajo
`admin/template/{template}/structure`. Las secciones conservan título, orden y
orientación. Un campo recibe `key`, `label`, `help`, `quien_llena`, `content` y orden.
`content` es un documento JSON; las variables solo pueden usar claves de
`template_variables`. Se retiran `type`, `config`, filas fijas y ajuste de ficha.

El servidor comprueba Administración, alcance y bloqueo de edición.
Los cambios posteriores conservan el reinicio al reanudar de I-188. La ficha acepta únicamente campos de Coordinación;
la actualización de borrador acepta únicamente campos docentes. Cada revisión enviada fotografía los documentos y las variables vigentes.

## Persistencia y exportación

Una migración nueva incorpora responsable y contenido documental, carga el formato
oficial de `resources/templates/silabo-oficial.json` y retira columnas y compatibilidad de tipos.
Los valores de ficha y borrador son documentos saneados; al enviar se fotografían los documentos
efectivos con variables resueltas. Word recorre sección y documento de cada campo;
PDF se obtiene del mismo Word. El render histórico permanece para evidencia aprobada.

## Verificación

API y OpenAPI, permisos docentes y de ficha, contenido inicial, snapshot inmutable,
reinicio y estructura de Word/PDF se comprueban en las pruebas indicadas en trazabilidad.
No se afirma aceptación visual institucional por la suite automatizada (PV-07/PV-19).
