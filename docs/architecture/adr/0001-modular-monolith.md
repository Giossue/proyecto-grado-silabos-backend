# ADR-0001: Monolito modular Laravel

- Estado: Aceptado. La parte de interfaz (Inertia) la reemplaza
  [ADR-0008](0008-separate-spa-api.md): la interfaz es una SPA Vue separada.
- Fecha: 2026-08-14
- Trazabilidad: DP-11, RNF-029, arquitectura v0.1.

## Contexto

El producto tiene un único dominio transaccional, equipo académico pequeño, reglas que
requieren consistencia y un despliegue institucional todavía por validar. Crear
microservicios aumentaría contratos, despliegues y fallos distribuidos sin beneficio
demostrado.

## Decisión

Usar Laravel 13 como monolito modular y unidad de despliegue del backend. Los módulos
viven en `app/Modules` con límites explícitos y las integraciones externas se
encapsulan detrás de contratos. La interfaz es una SPA Vue/TypeScript en otro
repositorio que consume la API (ADR-0008).

## Consecuencias

- Transacciones e identidad simples en un único proceso.
- Menor coste de operación y desarrollo inicial.
- Se necesitan reglas/pruebas de arquitectura para evitar un monolito desordenado
  (ver [modules.md](../modules.md)).
- Un módulo podría extraerse solo si métricas y límites demuestran la necesidad.
