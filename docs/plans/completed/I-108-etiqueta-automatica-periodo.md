# I-108: Etiqueta automática del período académico

## Estado

Implementación local completada; el cálculo de semanas fue separado y ampliado por I-109.

## Decisión

Administración no escribe un código al registrar un período académico. El servidor
genera la etiqueta a partir del rango elegido, con el formato:

```text
MesInicio-MesFin Año
```

Por ejemplo: `Agosto-Diciembre 2026`.

El identificador técnico `periodos_academicos.codigo_periodo_academico` se conserva
porque lo referencian procesos, convocatorias, búsquedas, auditoría y fotografías
históricas. La persona administradora solo edita las fechas; al cambiar el rango se
regenera la etiqueta.

`cantidad_semanas_lectivas` permanece como un dato calculado del período. El servidor
cuenta las semanas de lunes a domingo que toca el rango, incluidas las semanas parciales.

## Trazabilidad

- RF-008..016, RF-017..026; RN-005..009; CU-03..04; ADM-04, ADM-12, DOC-04.
- I-41: el período es institucional y único.
- I-57: la plantilla usa las semanas lectivas del período.
- I-77: la etiqueta del período es única y el estado se deriva de las fechas.
- Solicitud explícita del responsable del producto del 2026-09-23.

## Implementación

- Backend: `AcademicPeriodLabel` genera meses y año; las requests ya no
  requieren `code` para `periodo` y las acciones lo persisten de forma autoritativa.
- Frontend: alta y edición ocultan el código y muestran las semanas calculadas en solo
  lectura.
- Pruebas: alta sin código, formato generado, edición del rango y ausencia del campo
  en las hojas Vue.

## Riesgo

Los códigos históricos se conservan hasta que se edite su rango. No se reescriben
fotografías de sílabos ni identificadores de procesos existentes.
