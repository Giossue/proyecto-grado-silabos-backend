# Archivos y documentos

## Objetos privados

Fuentes, plantillas DOCX y exportaciones viven en un disco privado. La base conserva:

- UUID del objeto;
- nombre lógico y MIME detectado;
- tamaño y huella;
- propietario y alcance;
- clasificación;
- ubicación interna no expuesta;
- fecha y estado.

Las descargas pasan por autorización por registro y usan streaming o URL temporal corta.
El nombre físico no concede acceso.

## Carga segura

- límite de tamaño y extensiones permitidas por capacidad;
- detección de tipo real, no solo nombre;
- nombre generado por servidor;
- cuarentena/escaneo cuando la infraestructura lo permita;
- rechazo de archivos activos no necesarios;
- metadatos y errores sin contenido sensible;
- limpieza de cargas abandonadas mediante trabajo controlado.

## Generación documental

Entrada inmutable:

```text
revision_id + huella_del_snapshot + renderer_version + locale
```

Proceso:

1. autorizar solicitud;
2. fijar una clave idempotente;
3. cargar snapshot de revisión y plantilla;
4. validar marcadores/mapeos;
5. generar DOCX;
6. generar PDF desde la misma entrada aprobada;
7. validar estructura y render visual;
8. calcular huella, guardar objeto y registrar artefacto;
9. notificar finalización.

El motor de generación se seleccionará después de una prueba con el DOCX oficial
(`PV-07`). Se accede mediante `DocumentRenderer`, no desde el dominio.

I-05 incorpora `baseline-ooxml-pdf-v1` como implementación técnica reversible del
puerto. Valida estructura OOXML, XML principal y marcadores PDF antes de publicar; su
salida se rotula como provisional y no demuestra fidelidad visual institucional.

I-34 incorporó PHPWord. I-56 usa `phpword-docx-v3+text-pdf-v2`; I-57 eleva el snapshot a
esquema 4 para conservar `blocks[].page_orientation`, además de `blocks[].document` y
`template_variables`. `TemplateDocumentResolver` sustituye tokens y expande
filas/unidades/totales exclusivamente con esa fotografía.
`TemplateDocumentWord` traduce nodos permitidos a PHPWord: fuentes, marcas, alineación,
listas, fondos, anchos, `gridSpan` y `vMerge`. La planificación abre una sección carta
horizontal, cada unidad posterior inicia página y las cabeceras se repiten al desbordar;
el resto vuelve a la orientación general. No importa HTML ni recursos externos.
`phpword-docx-v4` corrige el desborde de texto en el PDF de LibreOffice: PhpWord armaba
`w:tblGrid` con la fila de más celdas sin contar `gridSpan`; `WordTableGrid` recalcula
la rejilla con los bordes reales de todas las filas, ajusta cada `tcW` a ella, fija el
ancho total en twips y usa `tblLayout=fixed`. Si una palabra no cabe en su celda, la
tabla baja la letra desde la del cuerpo hasta 7 pt (6 pt solo en esa celda); una
cabecera que ni así cabe se escribe en vertical, como «Semana» en el formato UEB.
Las revisiones anteriores sin documento mantienen su lector. Los artefactos existentes
no se modifican; la versión del motor participa en la idempotencia.

I-138: fuera del título y los logos del encabezado, nada del documento está fijo en
código. La sección «Revisión y aprobación» es contenido fijo editable de la plantilla
con las variables `nombre_docente`, `nombre_coordinador`, `fecha_entrega` y
`fecha_revision`; las tres últimas no existen al enviar y `GenerateSyllabusExportJob` las
completa desde la aprobación (fecha de envío, nombre de quien aprobó y fecha de
aprobación, hora de Ecuador). Solo muestran nombres y fechas: la firma digital dentro del
sistema es trabajo aparte.

I-165 agrega los datos automáticos de firma `firma_docente`, `firma_coordinador` y
`firma_bibliotecario`. Su valor es fijo (`SignatureAnchor`): cuatro líneas en blanco para
el sello y la etiqueta «Firma del docente», «Firma de coordinación» o «Firma de
biblioteca». La plantilla decide dónde van. `SignatureStampLocator` busca en el PDF la
etiqueta del rol que firma y centra el sello (150 × 48 pt) justo encima. Si no la
encuentra, usa las etiquetas «Firma:» por orden o, en último caso, el pie de la última
página, con tres posiciones. El formato oficial trae una tabla de tres columnas con
estas variables. La firma es incremental (PAdES) en orden docente → coordinación →
biblioteca, y el PDF no cambia entre firmas.

El PDF continúa como respaldo de texto (ahora incluye texto fijo y variables del diseño);
no se promete maquetación de tablas, colores ni cortes iguales al DOCX. La fidelidad
institucional y el motor PDF siguen sujetos a PV-07.

## Fidelidad

Las pruebas incluyen tablas, saltos, encabezados/pies, caracteres, contenido largo,
repetibles, páginas y fuentes. La equivalencia se evalúa con contenido extraído y render
visual. Nunca declares éxito solo porque el archivo abre.

## Retención

No implementes borrado definitivo hasta resolver `PV-11` y `PV-12`. Una política futura
debe cubrir originales, artefactos, temporales, backups y derecho/obligación de conservar.
