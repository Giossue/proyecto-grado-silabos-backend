# Proyecto de carrera: Excel estructurado

Coordinación descarga un Excel generado desde las materias activas de su carrera y
los campos Carrera vigentes que el proyecto de carrera muestra (los que tienen algún espacio para
escribir). Lo importado le llega fijo al docente; no crea espacios de elegir. Completa el contenido,
carga el archivo, revisa la vista previa y elige qué aplicar. Este flujo no utiliza
IA, OCR ni trabajos de cola. Funciona con `AI_DRIVER=disabled`.

## Contrato

- `GET /api/v1/coordination/ficha-imports/template`: descarga `proyecto-carrera.xlsx`.
- `POST /api/v1/coordination/ficha-imports/spreadsheet`: multipart `file`, un `.xlsx`
  de hasta 20 MB; devuelve `200` con `preview`: formato, huella de estructura,
  `file_hash` SHA-256 del archivo, campos, materias, comparación, cobertura y pendientes.
- `POST /api/v1/coordination/ficha-imports/apply`: multipart con el mismo `file`,
  `fingerprint`, `file_hash`, `subjects[]`, selección opcional `fields[UUID][]` y
  `confirm_purge` cuando corresponda. Devuelve `200` con `message` y `applied`.

La vista previa tiene `format=ficha_xlsx_v1`, sin ID ni estado persistido. Se retiraron
GET de recuperación y DELETE de descarte. El navegador conserva archivo y vista previa
solo mientras el diálogo está abierto; descartarlos es una acción local. Al cerrar o
recargar se pierden y hay que subir otra vez el archivo. No hay consultas periódicas.

La nueva migración elimina `importaciones_ficha` y sus registros históricos. El flujo
no guarda contenido de vista previa en PostgreSQL, caché, sesión ni disco privado.

## Libro

`FichaWorkbookContext` obtiene el contexto actual y `FichaWorkbook` genera el libro:

- Hoja «Indicaciones» y una hoja por campo. Las hojas se nombran con posición y
  etiqueta; los títulos y secciones proceden de la plantilla de Administración.
- Código y nombre de la asignatura protegidos. Solo las celdas de contenido quedan
  desbloqueadas. La identificación interna está oculta; no se pide escribir claves.
- Textos: una fila por materia y hasta 12 000 caracteres por celda. Las listas usan
  un elemento por línea iniciado con «- », «•», «*» o numeración. Se admiten hasta
  60 elementos de hasta 3 000 caracteres dentro del límite de la celda.
- Tablas: columnas derivadas de la plantilla, incluidos encabezados combinados;
  hasta 40 filas por materia en listas y 1 500 caracteres por celda. En plantillas
  protegidas, las tablas fijas conservan su cantidad de filas y sus textos prellenados
  quedan bloqueados. Solo las respuestas no vacías cuentan
  como contenido encontrado. Las filas fijas no se compactan al dejar una sin respuesta.
- El Excel se genera vacío de contenido. Las celdas vacías no borran valores actuales.
- La protección de Excel previene cambios accidentales. No concede permisos ni
  sustituye las comprobaciones del servidor.

Una hoja muy oculta guarda el formato, la carrera y una huella SHA-256 de los campos
(identificadores, etiquetas, secciones, columnas y contenido de la plantilla) y las
materias (identificador, código, nombre y ciclo). No crea versiones de la plantilla
ni guarda su historia. Cambios en docentes o paralelos no alteran esta huella.

## Validación y persistencia

El servidor resuelve el rol activo y la carrera; exige Coordinación activa y carrera
editable. Reconstruye el contexto desde PostgreSQL y compara la huella, hojas,
encabezados e identificaciones. Un Excel de otra carrera o desactualizado se rechaza.
La misma huella se comprueba nuevamente al aplicar.

El lector no evalúa fórmulas: las rechaza en cualquier hoja. Las celdas de salida se
escriben explícitamente como texto, incluidos nombres y encabezados. Se limita la
expansión del ZIP a 150 MB y el libro a 250 000 celdas previstas; se revisan dimensiones
antes de cargar celdas. Se rechazan datos añadidos fuera del área esperada y cambios
en nombres de hojas, orden, campos o materias. Los errores indican hoja y celda
cuando se pueden localizar. La vista previa se crea solo si todo el archivo es válido
y contiene al menos un campo; los campos vacíos se muestran como pendientes.

El archivo subido se lee desde el temporal de PHP y no se copia al disco privado.
`FichaWorkbookPreview` es un objeto en memoria. Revisar no escribe proyectos de carrera ni registros
de importación. La pantalla vuelve a enviar el archivo al confirmar y el servidor
repite toda la lectura y validación. Compara la huella de estructura y la del archivo
con la revisión mostrada; también comprueba que todas las materias y campos elegidos
pertenezcan a la carga y tengan contenido.

`ApplyFichaImport` bloquea la carrera, comprueba editabilidad y estructura otra vez y
escribe únicamente lo seleccionado en `valores_campo`, dentro de una transacción.
Conserva encabezados, textos fijos, estilos de celdas y permisos de edición docente;
`FieldResponse` valida la estructura nuevamente antes de persistir. Antes de guardar,
`FieldResponse::keepOwnOptions` conserva las opciones propias de la materia (respuestas con
`customOptions`, ver `template-json.md`) cuando el Excel no las cambió; sin opciones propias, lo
importado sigue las de la plantilla (2026-11-16). La auditoría
registra quién aplicó y cuántas materias; no conserva el Excel ni su contenido.
`InProgressWork` exige confirmación para rehacer sílabos en curso y no toca procesos
cerrados. Si falla la aplicación, el diálogo conserva la vista previa para corregir
la selección o volver a intentarlo mientras permanezca abierto.

## Evidencia local

La reproducción con un libro generado desde los seis campos de la plantilla oficial
y dos materias de ejemplo recuperó seis campos por materia, incluida una tabla de
cinco columnas. También rechazó una fórmula, un código de materia alterado, una
carrera distinta y una huella desactualizada. No utilizó servicios externos ni
modificó proyectos de carrera. No sustituye la aceptación funcional con el archivo real de la carrera.

## Despliegue del 26 de octubre de 2026

Se verificó un respaldo completo antes del push. El despliegue registró la migración
`2026_10_26_000229_drop_ficha_imports_table` en `silabos_ueb_db`: la tabla
`importaciones_ficha` ya no existe y quedan 32 tablas. Los propietarios y permisos
del resto de las tablas y secuencias permanecieron iguales. Se comprobaron tipos y
estilo; no se ejecutaron pruebas automatizadas en este cambio. Sigue pendiente la
aceptación funcional con el archivo real de la carrera.
