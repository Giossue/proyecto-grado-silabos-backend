# ADR-0008: Separar la SPA Vue y la API Laravel

## Estado

Aceptado — 2026-09-16.

## Contexto

El producto comenzó como una aplicación Laravel con Inertia. La entrega exige ahora dos
repositorios y artefactos desplegables: frontend y backend, comunicados por HTTP.

## Decisión

- Laravel conserva dominio, autorización, persistencia, archivos, colas, auditoría e IA.
- Toda funcionalidad de producto se expone como JSON versionado bajo `/api/v1`.
- Vue vive en `proyecto-grado-silabos-frontend` y no comparte código compilado con PHP.
- La SPA de primera parte se autentica con Sanctum, cookie HttpOnly y protección CSRF.
- `docs/architecture/openapi.json` es el contrato versionado entre ambos repositorios.
- Los dos artefactos se despliegan por separado; PostgreSQL sigue siendo una sola fuente
  transaccional y el backend continúa como monolito modular.

## Consecuencias

Inertia, Wayfinder, Vite y `resources/js` dejan de ser dependencias del backend. CORS,
dominios stateful, cookies y URLs públicas deben configurarse por ambiente. Los cambios
de contrato requieren regenerar OpenAPI y verificar ambos repositorios antes del corte.

ADR-0001 y ADR-0004 permanecen como historial, pero sus decisiones de presentación y
unidad de despliegue quedan sustituidas por este ADR.
