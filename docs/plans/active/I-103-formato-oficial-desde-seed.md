# I-103 — Contenedor vacío y formato oficial explícito

## Objetivo

La acción «Nueva plantilla» debe crear únicamente el contenedor institucional vacío.
El formato del sílabo real se instala de forma explícita con «Formatear» o mediante
`SyllabusTemplateSeeder`, usando la misma lógica de aplicación en ambos casos.

## Fuente de decisión

Se revisó el PDF `SILABOCALIDAD_SWPAO_II_2626_signed-signed (1).pdf` entregado el
2026-09-20. Tiene diez páginas y muestra:

- una ficha institucional con celdas combinadas y datos heredados;
- descripción, objetivos, resultados y habilidades blandas;
- planificación por unidades con cabeceras agrupadas, semanas, horas y totales;
- metodología, técnicas, recursos, TAC/IA y ambientes;
- indicadores de evaluación, escala y perfil de egreso;
- conducta ética, bibliografía básica/complementaria y revisión/aprobación.

No se precargan respuestas docentes: la seed solo define estructura, etiquetas, tipos,
tablas y diseño JSON.

## Decisiones

- La creación HTTP llama `SyllabusTemplateProvisioner::createEmpty()`.
- El botón «Formatear» llama `POST /admin/template/{template}/format`.
- El formato solo se aplica si la plantilla no tiene secciones, bloques ni campos; no
  puede sobrescribir una edición del administrador.
- La seed es idempotente: instala el formato si no hay plantilla o si existe un
  contenedor vacío; deja intacta una plantilla ya estructurada.
- `config/syllabus_template.php` es la definición declarativa del formato. No hay
  bloques institucionales creados desde el código de la pantalla.

## Implementación

- [x] Separar creación vacía y aplicación del formato en el provisionador.
- [x] Crear la acción HTTP, ruta, auditoría y contrato OpenAPI de «Formatear».
- [x] Conectar la pantalla administrativa con «Crear plantilla vacía» y «Formatear».
- [x] Ajustar los tipos de listas/narrativa y la sección ética al PDF revisado.
- [x] Actualizar pruebas y documentación que describían una creación ya formateada.
- [x] Ejecutar las suites backend (296 pruebas), las pruebas SPA (96), typecheck,
      build, lint y contrato OpenAPI.

La puerta `composer verify` queda pendiente por 13 diagnósticos PHPStan preexistentes
en otros requests/controladores (`ReorderTemplateBlocksRequest`,
`SaveFieldDefinitionRequest`, `OperationalReportController`, `ConvocationController` y
`SyllabusController`); ninguno corresponde a este cambio.

## Criterios de aceptación

1. Después de crear una plantilla, se observan cero secciones, cero bloques y cero
   campos.
2. «Formatear» crea las doce áreas y las tablas oficiales sin datos de respuesta.
3. Un segundo intento de formatear devuelve validación y conserva la estructura.
4. El seed no duplica ni pisa una plantilla existente.
5. La acción queda registrada como `plantilla.formateada`.
