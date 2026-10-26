# ADR-0012: Formato fijo y respuestas por responsable

- Estado: Aceptado por el responsable del producto, 2026-10-26.
- Amplía ADR-0010 y ADR-0011. El pedido actual reemplaza la edición libre del formato
  por Docencia y Coordinación; Administración conserva el editor completo.

## Decisión

La plantilla sigue siendo JSON y se conservan los tres responsables por campo y el
interruptor de edición docente de la ficha. No se añaden permisos por celda ni tablas
de base de datos. Las definiciones nuevas o importadas con formato 2 llevan
`response_policy: protected`. Las definiciones guardadas anteriores sin esa clave
conservan su comportamiento hasta que Administración actualice la plantilla.

El texto no vacío, los encabezados, los estilos, las columnas y las celdas combinadas
son parte del formato fijo. Los párrafos vacíos y las celdas de datos vacías reciben
respuestas. Un campo completamente vacío admite un documento libre de respuesta.
Un campo de texto fijo sin espacios de respuesta recibe un párrafo de respuesta al
final. Un párrafo entre textos fijos permite texto y saltos de línea; una celda vacía
permite contenido enriquecido. Los bloques conservan sus esquemas y reglas JSON.

Una tabla con una o más filas de encabezados y una sola fila de datos completamente
vacía es una lista. Se repite esa fila sin alterar encabezados, estilos ni columnas;
el sitio permite hasta 100 filas totales y el Excel hasta 40 filas de datos. Las otras
tablas conservan todas sus filas. El contenido fijo no cuenta como respuesta para
completar una ficha o enviar un sílabo.

`FieldResponse` comprueba la estructura en el servidor al guardar ficha, aplicar Excel,
guardar o copiar el sílabo y validar el envío. `FieldResponseEditor` solo presenta los
espacios de respuesta como editables. El servidor autoriza el campo completo antes
de comprobar su contenido. No se confía en bloqueos del navegador o del Excel.

## Unidades

La plantilla oficial separa «Unidades y resultados de aprendizaje», de Carrera, de
«Planificación semanal», del Docente. El primer campo es una tabla fija con cuatro
filas numeradas y columnas para nombre y resultado. Su permiso de edición docente
empieza desactivado y sigue siendo una decisión explícita de Coordinación.

Las cuatro unidades de la planificación se identifican por el número de la tabla
anterior. El bloque nuevo `planificacion_semanal` conserva las reglas de semanas y
horas ACD, APE y AA; no tiene respuestas para nombres ni resultados. El bloque
anterior `unidades` permanece disponible para interpretar documentos anteriores.

Un cambio de docente conserva la ficha y reinicia la planificación en curso con las
reglas existentes. Un cambio de plantilla o ficha sigue exigiendo pausa y confirmación
de regeneración; nada de una convocatoria cerrada se reescribe. Al cerrar una
convocatoria, los borradores y los sílabos sin iniciar guardan también una fotografía
del documento y su definición en el contexto JSON existente. Las revisiones enviadas
y aprobadas conservan sus fotografías originales. Así, una edición posterior de la
plantilla no cambia la consulta de los documentos que se cierren desde este cambio;
los cierres anteriores sin fotografía conservan su mecanismo de archivo previo.

## Activación y límites

El despliegue entrega el código y la nueva plantilla oficial, sin migración de esquema
ni modificación automática de la plantilla activa. Activar el formato exige usar las
acciones administrativas existentes con la convocatoria pausada. Para la activación
inicial sin ningún sílabo, `template:protect-responses --apply` permite actualizar la
plantilla conservando los IDs actuales y todos los valores de ficha. Exige ausencia
de sílabos y de procesos abiertos; se ejecuta después de verificar respaldo, base y
propietario. Audita el mantenimiento como Sistema, sin atribuirlo a una cuenta.
Antes de reanudar,
Coordinación completa el catálogo de unidades de cada materia y se confirma la
regeneración del trabajo en curso. Las fotografías anteriores conservan su definición.

No se añadieron ni ejecutaron pruebas automatizadas en este cambio. Se adaptaron
casos y datos existentes; quedan pendientes la ejecución y la aceptación visual del
formato nuevo, del Excel y del Word/PDF. Las referencias Word anteriores se mantienen
para comprobar compatibilidad histórica cuando se ejecute la suite.

## Activación realizada

El 2026-10-26 se activó en `silabos_ueb_db`, después del push de backend
`e44bb065` y frontend `8250323`. Se verificaron identidad y tablas centinela, el
propietario de la aplicación y el respaldo privado
`antes-activar-respuestas-20261026T140201Z.dump` (SHA-256
`7178b76e083c7c45ae2d170bd250d680d0f997e8ce47ca0601d6dd1777f698b3`).

La transacción se ejecutó como el propietario verificado. Comparó la huella de la
plantilla preparada, todos los valores de ficha, los campos existentes, los estilos,
las variables, el esquema y los permisos antes de confirmar. La lectura posterior
confirmó 20 campos, siete de Coordinación, 16 valores preservados, cero sílabos,
32 tablas, 195 migraciones y un evento de auditoría de activación. No se ejecutaron
migraciones. La convocatoria institucional permaneció pausada.

El push activa el despliegue configurado en Dokploy; esta evidencia no confirma la
terminación del despliegue ni una aceptación visual en el sitio. Coordinación debe
completar el catálogo de unidades antes de reanudar.

## Trazabilidad

RF-017..033, RF-037..045; RN-009..012; CU-04, CU-05, CU-07, CU-09;
ADM-06, COR-16, DOC-04. PV-16 no cambia.
