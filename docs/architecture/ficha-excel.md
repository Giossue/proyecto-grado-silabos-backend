# Proyecto de carrera: Excel estructurado

Coordinación descarga un Excel generado desde las materias activas de su carrera y
los campos vigentes cuyo responsable sea `coordinacion`. Completa el contenido,
carga el archivo, revisa la vista previa y elige qué aplicar. Este flujo no utiliza
IA, OCR, Redis ni trabajos de cola. Funciona con `AI_DRIVER=disabled`.

## Contrato

- `GET /api/v1/coordination/ficha-imports/template`: descarga `proyecto-carrera.xlsx`.
- `POST /api/v1/coordination/ficha-imports/spreadsheet`: multipart `file`, un `.xlsx`
  de hasta 20 MB; devuelve `200` con `import` lista para revisar.
- `GET /api/v1/coordination/ficha-imports`: recupera la vista previa. `available`
  indica la disponibilidad del flujo de carga; no consulta al proveedor de IA.
- `POST /api/v1/coordination/ficha-imports/{import}/apply`: aplica materias y campos
  elegidos, con la confirmación de regeneración existente cuando corresponda.
- `DELETE /api/v1/coordination/ficha-imports/{import}`: descarta la vista previa.

La importación nueva identifica `format=ficha_xlsx_v1`. Los registros anteriores
devuelven `format=null`. Los demás campos JSON se conservan. El POST histórico de
documentos permanece por compatibilidad y está marcado como obsoleto en OpenAPI;
no se ofrece en la interfaz nueva. Los trabajos anteriores pueden terminar y sus
propuestas todavía se pueden consultar, descartar y aplicar.

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
  hasta 40 filas por materia y 1 500 caracteres por celda. Se omiten filas vacías.
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
`importaciones_ficha` conserva nombre, tamaño, huella y valores para la vista previa;
el archivo no se conserva. Preparar la vista previa no modifica `valores_campo`.
`ApplyFichaImport` sigue aplicando únicamente lo seleccionado en una transacción,
conserva los encabezados de las tablas y respeta los permisos de edición docente.
Si ninguna celda de una materia seleccionada se puede aplicar, rechaza la operación.
`InProgressWork` exige confirmación para rehacer sílabos en curso y no toca procesos
cerrados. Las importaciones en ejecución no se reemplazan por otra carga.

## Evidencia local

La reproducción con un libro generado desde los seis campos de la plantilla oficial
y dos materias de ejemplo recuperó seis campos por materia, incluida una tabla de
cinco columnas. También rechazó una fórmula, un código de materia alterado, una
carrera distinta y una huella desactualizada. No utilizó servicios externos ni
modificó fichas. No sustituye la aceptación funcional con el archivo real de la carrera.
