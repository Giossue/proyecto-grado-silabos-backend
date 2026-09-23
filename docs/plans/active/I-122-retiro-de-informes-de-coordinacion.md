# I-122 — Retiro de Informes de Coordinación

## Objetivo

Eliminar la pantalla Informes (COR-12) y su API. No aportaba información que el Panel de
Coordinación no muestre ya: avance, días para la entrega, sílabos por revisar y sin
iniciar.

## Fuente de decisión

Instrucción explícita del responsable del producto (23 de septiembre de 2026): «borra
toda la lógica y si hay una tabla de eso, bórrala».

## Cambios

- [x] Backend: se eliminan `OperationalReportController`, `ViewOperationalReportsRequest`
      y la ruta `GET coordination/reports`, con su prueba en `DocumentOperationsTest`.
- [x] Base de datos: sin cambios. El informe no tenía tabla propia; consultaba `silabos`
      y `convocatorias`, que siguen en uso.
- [x] SPA: se eliminan la página, su cliente, la ruta `/coordinacion/informes` y la
      entrada del menú.
- [x] Documentación: COR-12 queda como retirada; CU-16 se atiende en el Panel.

## Verificación

- Backend: `DocumentOperationsTest`, `OpenApiContractTest`; Pint y PHPStan.
- Frontend: typecheck, lint y pruebas de rutas y del shell.
