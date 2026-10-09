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

Bibliografía es docente. El reparto restante de la plantilla oficial conserva el
estado indicado en «Por validar» de I-210. Título y logos son fijos y no son campos.

## API

La API de estructura conserva las operaciones de secciones y campos bajo
`admin/template/{template}/structure`. Las secciones conservan título, orden y
orientación. Un campo recibe `key`, `label`, `help`, `quien_llena`, `content` y orden.
`content` es un documento JSON; las variables solo pueden usar claves de
`template_variables`. Se retiran `type`, `config`, filas fijas y ajuste de ficha.

El servidor comprueba Administración, alcance, bloqueo de edición y confirmación
del reinicio conforme a I-118. La ficha acepta únicamente campos de Coordinación;
la actualización de borrador acepta únicamente campos docentes. Las revisiones
aprobadas conservan sus documentos y no se adaptan a cambios de la plantilla viva.

## Persistencia y exportación

Una migración nueva incorpora responsable y contenido documental, carga el formato
oficial de `config/syllabus_template.php` y retira columnas y compatibilidad de tipos.
El reinicio conserva aprobados y procesos cerrados según I-118/I-144. Los valores de
ficha y borrador son documentos saneados; al enviar se fotografían los documentos
efectivos con variables resueltas. Word recorre sección y documento de cada campo;
PDF se obtiene del mismo Word. El render histórico permanece para evidencia aprobada.

## Verificación

API y OpenAPI, permisos docentes y de ficha, contenido inicial, snapshot inmutable,
reinicio con conservación de aprobados y estructura de Word/PDF requieren pruebas.
No se afirma aceptación visual institucional por la suite automatizada (PV-07/PV-19).
