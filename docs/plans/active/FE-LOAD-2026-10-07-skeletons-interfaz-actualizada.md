# FE-LOAD-2026-10-07: Skeletons de la interfaz actualizada

## Estado

En curso.

## Trazabilidad

RF-001..026/034..075; RNF-018..023/029..034; RN-001..034 como contexto sin
alterar reglas de dominio; CU-01..04/06..18; UI-02..04; ADM-01..08/10..12,
COR-01/03..10/13..16, DOC-02..10 y BIB-01..03; CP-F identidad, panel,
catálogos, configuración académica, plantilla, sílabo, revisión y documentos;
CP-N experiencia, accesibilidad y mantenibilidad; PV-19.

## Resultado demostrable

Cada carga inicial reproduce la composición vigente de su pantalla después de los
cambios del 5 al 7 de octubre: panel de tres indicadores, Proyecto de carrera,
Facultades y Carreras con pestañas, constructor de plantilla por hojas, edición del
sílabo por pestañas y tablas con o sin acciones según su interacción real.

## Decisiones y supuestos

- La interfaz renderizada y las decisiones confirmadas hasta I-204 son la referencia.
- No existe una decisión `POR VALIDAR` que cambie esta corrección visual reversible.
- Las cantidades desconocidas durante la consulta se representan con una muestra
  estable; el rol, modo y etapa ya conocidos sí determinan la composición.
- Los estados de acciones conservan `Spinner`; este plan cubre cargas de contenido con
  `Skeleton`.

## Cambios previstos

- Dominio: sin cambios.
- Backend: sin cambios de ejecución ni contrato.
- Datos: sin migraciones.
- Frontend: actualizar composiciones compartidas, perfiles por pantalla y sus llamadas.
- Seguridad/auditoría: un anuncio accesible por región, sin controles interactivos.
- Trabajos/integraciones: sin cambios.

## Pruebas

Comprobación estática de TypeScript y ESLint, más revisión del diff y de comentarios.
PV-19 conserva la aceptación visual en dispositivos reales.

## Pasos

- [x] Inventariar las cargas y compararlas con la interfaz actual.
- [ ] Actualizar tablas, panel, configuración académica y documentos.
- [ ] Ajustar cada llamada con su estructura real.
- [ ] Sincronizar documentación y trazabilidad.
- [ ] Ejecutar comprobaciones estáticas aplicables y cerrar el plan.

## Riesgos y reversión

Las cargas no conocen la cantidad final de datos y usan una muestra visual. Revertir
los componentes y sus parámetros restaura la presentación anterior sin afectar API,
permisos, datos ni migraciones.

## Evidencia de cierre

Pendiente.
