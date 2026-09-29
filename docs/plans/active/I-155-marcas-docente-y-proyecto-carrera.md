# I-155 — Marcas en la hoja: dato automático, !Docente y @ProyectoCarrera

## Decisión del responsable, 2026-09-29

«¿Quién llena esta parte?» (I-151, I-154) se sustituye por marcas dentro de la hoja. Al
crear una parte, Administración solo elige su forma: párrafo, lista o tabla. Dentro de
la hoja, texto y celdas pueden mezclar:

| Marca | Quién la llena | Nodo del documento |
|---|---|---|
| Texto normal | Nadie: es fijo | `text` |
| **Dato automático** | El sistema, según lo declarado en el código (`config/syllabus_variables.php`) | `variable` institucional |
| **!Docente** | El docente, libremente | `field` / `column` (ya existentes) |
| **@ProyectoCarrera** | Coordinación, con el agente, al importar sus documentos | `variable` `curricular_<clave>` |

Respuestas del responsable:

1. El nombre de cada @ProyectoCarrera se hereda del nombre de la **sección**. Se puede
   cambiar. Dos marcas en la misma sección necesitan nombres distintos.
2. @ProyectoCarrera es obligatorio para Coordinación, igual que la malla. Si falta un
   dato de la plantilla para la carrera o para alguna materia programada, **no se
   puede abrir la convocatoria**. El docente nunca lo escribe.
3. Habilidades blandas (el docente elige de una lista del proyecto): queda como hoy, una
   pregunta !Docente de selección cuyas opciones vienen de un @ProyectoCarrera. Sin
   cambios en esta entrega. La lista usada como opciones también es obligatoria.

## Alcance y trazabilidad

- ADM-06, COR-16, apertura de convocatoria (I-46); RF-017..026; I-145, I-151, I-152.
- Sin cambios de esquema: los conceptos siguen en `conceptos_curriculares`. La relación
  concepto → sección se calcula leyendo la plantilla (nodos `variable` y
  `options_sources`), no se guarda.
- «La plantilla manda» se precisa: la importación busca los @ProyectoCarrera **usados**
  en la plantilla, con el nombre de la sección donde aparecen como contexto.

## Trabajo

### Backend
- [ ] `ProjectDataUsage`: datos @ProyectoCarrera usados en la plantilla y sus secciones.
- [ ] `POST admin/template/{template}/project-data`: declara un @ProyectoCarrera (nombre,
      lista o texto, por asignatura o por carrera) y devuelve la variable.
- [ ] Importación: `known_concepts` = datos usados, con `sections`; sin datos usados se
      rechaza.
- [ ] Apertura de convocatoria: falta de datos del Proyecto de carrera como requisito
      (`missingAcademicData`, `can_open`, paso del tablero).
- [ ] Catálogo de variables con `group` (`automatico` | `proyecto_carrera`).
- [ ] Retirar `content_type: automatic` de creación de partes y secciones.

### Frontend
- [ ] Menú Insertar: Dato automático, !Docente, @ProyectoCarrera (existentes + nuevo con
      nombre de la sección). Fichas con prefijo y color propio.
- [ ] Atajos `!` y `@` en la hoja; `{` para datos automáticos.
- [ ] Párrafos y listas editables en la hoja, no solo tablas.
- [ ] Crear parte: solo la forma. Se retira `TemplatePartKindPicker`.
- [ ] Proyecto de carrera y apertura de convocatoria muestran qué falta.

### Datos, documentación y verificación
- [ ] Plantilla remota: revisar RA, contenidos mínimos y conducta ética con las marcas.
- [ ] `DECISIONS_STATUS.md`, `screens.md`, trazabilidad, especificación de plantillas.
- [ ] `composer verify`; lint, typecheck, Vitest y build del frontend.
