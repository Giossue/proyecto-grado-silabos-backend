# Proyecto de carrera por Excel estructurado

## Alcance autorizado

El usuario sustituye la carga habitual de documentos con OCR/IA por descargar,
completar e importar un Excel generado desde los campos actuales de Coordinación.
Esta decisión prevalece sobre las instrucciones anteriores que exigían OCR para
leer PDF. Los trabajos anteriores pueden terminar; no se modifican importaciones
en ejecución ni se despliega el cambio.

RF-027..033; CU-05; COR-16; RNF-001..010, RNF-018..028, RNF-029..036.
Las RN y CP formales no tienen enunciados locales para precisar sus identificadores.
PV-14 y PV-18 dejan de condicionar esta carga: no necesita proveedor. PV-16 y los
estados, permisos, confirmación de regeneración y procesos cerrados siguen vigentes.

## Sondeo

Se reutilizan importaciones_ficha, la cobertura y selección por campo, la comparación
con valores actuales, ApplyFichaImport, ProcessLocks e InProgressWork. La plantilla
de campos es dinámica. PhpSpreadsheet ya está instalado y FileDropzone admite xlsx.
El esquema HTTP anterior se conserva para propuestas existentes. Se agregan rutas
de descarga de plantilla y vista previa Excel, sin depender de colas, IA ni OCR.

## Diseño

- Libro con indicaciones y una hoja por campo, con nombres y materias protegidos.
  Solo se desbloquean celdas de contenido. Identificadores internos ocultos y una
  huella de la estructura vinculan el archivo a la carrera y plantilla actuales.
  La protección evita cambios accidentales; la autorización y validación son del servidor.
- Textos y listas: una fila por materia; tablas: las columnas de la plantilla y hasta
  40 filas por materia (límite vigente de FichaFieldValues). Celdas vacías conservan
  valores actuales. Las hojas incluyen las secciones y títulos actuales.
- Importar solo un xlsx; comprobar estructura, alcance por carrera, materias activas,
  contenido y límites. Rechazar fórmulas y cambios en campos de identificación.
  Informar hoja y celda. Los datos se leen sin evaluar fórmulas ni conservar el archivo.
- Vista previa sin cambios en las fichas. Volver a comprobar la huella al aplicar,
  con los bloqueos y confirmación existentes. No hay cambios de esquema; una migración de datos actualiza la ayuda predeterminada sin sobrescribir personalizaciones.
- El frontend ofrece descargar, cargar, revisar y aplicar. La interfaz principal ya
  no solicita PDF ni depende de un proveedor de IA. Se conserva lectura de estados
  históricos y operaciones en curso.

## Trabajo

1. Servicio de contexto y libro; acciones de descarga y vista previa; contrato JSON.
2. Validación al aplicar y adaptación del diálogo existente con shadcn.
3. Regenerar OpenAPI; revisar tipos, estilo, secretos y recorrido local proporcional.
   No añadir ni ejecutar pruebas sin petición del usuario.
4. Actualizar decisiones, arquitectura, pantallas, ayuda y trazabilidad. Terminar el
   plan y hacer commits locales en ambos repositorios, sin push.
