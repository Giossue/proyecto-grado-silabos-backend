# Casos de uso

| ID | Caso de uso | Actor principal | Requisitos | Pantallas |
|---|---|---|---|---|
| CU-01 | Iniciar y cerrar sesión, elegir ámbito | Todos | RF-001, RF-002, RF-007 | UI-01, UI-02 |
| CU-02 | Gestionar usuarios y roles | Administrador; Coordinador para docentes de su carrera | RF-003 a RF-006 | ADM-02, ADM-03, COR-15 |
| CU-03 | Gestionar facultades, carreras, malla y paralelos | Administrador; Coordinador en su carrera | RF-008 a RF-016 | ADM-04, COR-13, COR-14 |
| CU-04 | Configurar la plantilla del sílabo | Administrador | RF-017 a RF-026 | ADM-06 |
| CU-05 | Mantener el Proyecto de carrera (campos e importación) | Coordinador | RF-027 a RF-033 en su alcance actual | COR-16 |
| CU-06 | Abrir la convocatoria e iniciar la de la carrera | Administrador; Coordinador | RF-034 a RF-036 | ADM-12, COR-01, COR-04, COR-05 |
| CU-07 | Elaborar el sílabo | Docente | RF-037 a RF-044 | DOC-02, DOC-04 |
| CU-08 | ~~Pedir ayuda al chat de IA~~ (retirado el 2026-11-05) | — | RF-046 a RF-054 retirados | — |
| CU-09 | Validar y enviar el sílabo | Docente | RF-043, RF-045, RF-047 | DOC-05, DOC-07 |
| CU-10 | Revisar y solicitar corrección | Coordinador; Bibliotecario | RF-055 a RF-058 | COR-05, COR-06, COR-08, BIB-02, BIB-03 |
| CU-11 | Corregir y reenviar | Docente | RF-058 a RF-060 | DOC-08 |
| CU-12 | Comparar revisiones | Coordinador | RF-061 | COR-06 |
| CU-13 | Aprobar (Coordinación su etapa; Biblioteca la bibliografía) | Coordinador; Bibliotecario | RF-062, RF-063 | COR-09, BIB-03 |
| CU-14 | Eliminar un sílabo aprobado | Coordinador | RF-064 | COR-10 |
| CU-15 | Firmar al decidir y descargar el PDF | Docente; Coordinador; Bibliotecario | RF-066 a RF-069 | DOC-07, COR-09, BIB-03, DOC-10 |
| CU-16 | Consultar el avance | Todos | RF-036, RF-070 a RF-072 | ADM-01, COR-05, DOC-02, BIB-02 |
| CU-17 | Auditar el proceso | Administrador | RF-073, RF-074 | ADM-10 |

CU-18 (sincronizar datos institucionales) está retirado: no hay integración con sistemas
externos. Única excepción: el docente consulta el catálogo Koha de la biblioteca, solo lectura,
para llenar la bibliografía en CU-07.

## Antes de implementar un caso

Anota en el documento ODD (`odd/tasks/`), en `## Specs`:

- actor, rol y alcance;
- disparador y precondiciones;
- flujo principal, alternativas y errores;
- cambio de estado o dato persistente;
- autorización por registro;
- evento de auditoría y avisos;
- criterios de aceptación y casos `CP`;
- decisiones `PV` de las que depende.
