# Convocatoria y flujo del sílabo

## Trazabilidad

- RF-034 a RF-045 y RF-055 a RF-065.
- CU-06, CU-07 y CU-09 a CU-14.
- RN-017 a RN-027 y RN-033.
- DOC-01 a DOC-09; COR-01 a COR-10.
- PV-03, PV-04, PV-05, PV-06, PV-08 y PV-16.

## Comportamiento

- Administración fija el período, la plantilla y las fechas en el proceso institucional;
  cada carrera hereda esos datos. I-146 retira la selección de Fuentes académicas.
  Antes de abrirlo, el logo de la facultad debe haber sido cargado por Administración
  desde ADM-04.
- COR-01 comprueba la preparación completa del período vigente: materias programadas,
  paralelos, docente vigente por paralelo, plantilla y logo. Si está lista, Coordinación
  abre su convocatoria desde el Panel. Los documentos de ingesta no son un requisito.
- COR-01 y DOC-01 muestran el plazo heredado con inicio, entrega y un estado temporal
  explícito. Docencia continúa hacia sus sílabos y Coordinación abre el seguimiento del
  alcance ya creado. El calendario se deriva del proceso y sus fechas límite; no se guarda
  una copia adicional para el Panel.
- Cada paralelo genera su propio sílabo y `por_paralelo` es la agrupación predeterminada;
  `por_oferta` se conserva como alternativa registrada conforme a DT-11.
- COR-02 prioriza el listado de convocatorias e inicia el alcance desde la acción de
  cada período; conserva allí los errores hasta corregirlos.
- El sistema genera el conjunto esperado de sílabos sin duplicar el canónico.
- El docente edita por secciones, hereda datos maestros y ve los errores de validación.
- El autoguardado y el guardado explícito evitan pérdida y muestran su estado.
- La concurrencia no sobrescribe silenciosamente trabajo de otro colaborador.
- Enviar/reenviar ejecuta validaciones obligatorias y crea una revisión inmutable.
- `borrador` identifica el trabajo editable e `iniciado_en` conserva cuándo comenzó;
  un borrador puede estar incompleto hasta la validación de envío.
- El coordinador solicita corrección con un comentario general o aprueba.
- COR-06 mantiene la revisión como página completa; la corrección se pide con un
  comentario general en el panel lateral derecho (I-177: no hay observaciones por campo).
- Biblioteca devuelve con motivos predefinidos; coordinación y decanato, con un
  comentario general. El docente corrige y reenvía una revisión nueva.
- La comparación muestra cambios de campos, filas y secciones.
- Reabrir conserva la revisión aprobada y registra causa/actor.

## Criterios críticos

- Una transición no permitida falla sin cambios parciales.
- Abrir sin un logo propio de la facultad falla sin crear sílabos.
- Repetir una petición con la misma clave idempotente no crea dos revisiones.
- Los conteos de convocatoria coinciden con los expedientes filtrados; no se calcula
  porcentaje de llenado por cantidad de campos.
- Un borrador no puede cambiar su versión de plantilla después de creado.
- Ningún rol puede editar una revisión enviada o aprobada.
