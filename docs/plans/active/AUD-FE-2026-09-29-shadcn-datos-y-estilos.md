# AUD-FE-2026-09-29: Auditoría del frontend sobre datos, estilos y shadcn

## Estado

En curso. Solicitud del responsable del producto el 2026-09-29: analizar todo el
frontend y determinar si los datos de negocio, estilos y controles respetan una base
reutilizable con colores globales y shadcn.

## Trazabilidad

- RF-001..026 y RF-034..074; CU-01..04 y CU-06..17: superficies activas de la SPA.
- RNF-018..023 y RNF-029..034: accesibilidad, consistencia y mantenibilidad.
- RN-001..034: conservar permisos, estados, snapshots y procedencia de datos.
- UI-01..04, ADM-01..07 y ADM-09..12, COR-01..10 y COR-13..16, DOC-01..10.
- CP-F01..35 y CP-N01..16: referencia al catálogo formal; esta auditoría no cierra
  aceptación funcional ni asigna pruebas a IDs individuales sin su definición.
- I-10, I-13 e I-101: tokens semánticos y componentes compartidos.
- ADR-0006 y ADR-0007: motores específicos de malla y edición documental.
- PV-07 y PV-19 permanecen abiertos para fidelidad documental y dispositivos reales.
  PV-16 no afecta la inspección y no se amplía el permiso del coordinador.

## Resultado demostrable

Un inventario completo del código fuente del repositorio hermano
`proyecto-grado-silabos-frontend`, con hallazgos verificables por archivo y línea,
distinción entre datos reales, ejemplos y constantes del contrato, y propuestas
concretas de componentes y tokens existentes para las excepciones.

## Decisiones y supuestos

- El pedido se interpreta como una auditoría con diagnóstico. La aplicación no se
  refactoriza como parte de este análisis.
- La presencia de HTML dentro de una primitiva shadcn no prueba una infracción; se
  revisan los controles construidos fuera de las primitivas y las composiciones de
  dominio.
- Se comprueba alcance de importación antes de presentar código sin uso como una
  incidencia visible en producción.
- Las geometrías documentales y del lienzo se contrastan con I-101 y los ADR aceptados.
  Su retirada sería una decisión posterior, no una corrección silenciosa del análisis.

## Cambios previstos

- Dominio, backend, datos y contratos: sin cambios funcionales.
- Frontend: inspección de páginas, layouts, componentes, API, estado, utilidades,
  estilos, configuración, pruebas y recursos públicos.
- Documentación: este plan conservará cobertura, resultados y evidencia de cierre.

## Verificación

- Inventario y búsqueda global, con análisis de templates y dependencias locales.
- Lectura de cada excepción y de sus consumidores.
- Comprobaciones existentes del frontend proporcionales al diagnóstico; sin acceso a
  bases de datos, migraciones ni despliegues.

## Pasos

- [x] Leer instrucciones, dependencias, lockfile y documentación de interfaz.
- [x] Revisar el antecedente I-101 y los límites de los motores específicos.
- [ ] Inventariar todo el código y determinar qué se importa desde la aplicación.
- [ ] Revisar datos fijos, colores, CSS local y controles fuera de shadcn.
- [ ] Verificar las comprobaciones aplicables y registrar sus límites.
- [ ] Entregar diagnóstico y prioridades con evidencia.

## Riesgos y reversión

Las búsquedas pueden detectar primitivas legítimas, muestras de previsualización,
fixtures de pruebas y geometría calculada. Cada hallazgo se confirma antes de concluir.
El cambio durable es documental y puede revertirse retirando el plan; no se escribe en
la base ni se modifica el comportamiento de la SPA.

## Evidencia de cierre

Pendiente de completar con el inventario y los hallazgos confirmados.
