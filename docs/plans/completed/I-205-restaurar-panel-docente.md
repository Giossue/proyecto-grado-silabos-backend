# I-205 — Restaurar el Panel docente (DOC-01)

## Pedido del responsable, 2026-10-07

Docencia vuelve a tener un Panel con el mismo patrón compartido de Coordinación. Esta
decisión sustituye I-200.

## Alcance

- RF-001..007 y RF-034..045; RN-001..004 y RN-017..024; CU-01, CU-02 y CU-07.
- UI-02..04, DOC-01..02 y CP-F identidad/panel/alcance.
- RNF-018..023 y PV-19 para experiencia, accesibilidad y revisión visual.

## Cambios implementados

- Backend: métricas de sílabos por entregar y por corregir, plazo vigente y avance de
  los sílabos propios del docente en la carrera activa.
- Frontend: ruta canónica `/docente/panel`, inicio del rol y acceso **Panel** en el menú
  lateral antes de **Mis sílabos**.
- Carga: dos indicadores, tarjeta de plazo y cuatro pasos del avance docente.
- Documentación y trazabilidad: DOC-01 vuelve al inventario vigente e I-200 queda como
  antecedente superado.

## Verificación

- TypeScript y ESLint sin errores en los archivos afectados.
- Pint y sintaxis PHP sin errores en los archivos afectados.
- Las pruebas existentes de panel, rutas, navegación, acceso y carga quedaron alineadas;
  no se ejecutaron por tratarse de una corrección acotada.
- La aceptación visual en dispositivos reales permanece en PV-19.
