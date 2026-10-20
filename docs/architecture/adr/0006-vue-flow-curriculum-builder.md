# ADR-0006: Usar Vue Flow como motor visual de mallas

- Estado: Aceptado. Vigente: Vue Flow dibuja la malla en el frontend (pantalla Malla de
  Coordinación, `/coordinacion/malla`).
- Fecha: 2026-08-30
- Responsables: responsable del producto y equipo de implementación
- Reemplaza / reemplazado por: —

## Contexto

Coordinación necesita leer y construir una malla como diagrama académico por ciclos y
relaciones. El stack confirmado es Vue 3; AI Elements implementa su ejemplo de workflow
sobre React Flow y no es reutilizable directamente. Construir zoom, desplazamiento,
selección, nodos y conexiones desde cero agregaría riesgo sin valor de dominio.

La malla de Software de ocho ciclos es una referencia, pero otras carreras o versiones
pueden cambiar cantidad de ciclos, campos y presentación. PostgreSQL debe continuar como
fuente de verdad y el editor debe contar con una alternativa de formulario.

## Opciones consideradas

1. Vue Flow: integración nativa con Vue 3, TypeScript, nodos/aristas personalizados y
   paquetes independientes para controles, minimapa y barra de nodo; licencia MIT.
2. AntV X6: mayor superficie para diagramación general y enrutamiento, a cambio de más
   peso y una integración Vue adicional.
3. Rete.js: adecuado para programación visual y ejecución de grafos; su motor de flujo
   de datos no aporta valor a una malla académica.
4. Implementación propia o AI Elements/React Flow: duplicaría infraestructura o mezclaría
   React dentro del starter Vue.

## Decisión

Adoptar `@vue-flow/core` y `@vue-flow/node-toolbar`. Los controles de navegación son
propios (`CurriculumNavigationControls.vue`). Todo se encapsula en
`src/components/domain/academic/curriculum` del repositorio frontend
(`CurriculumCanvas.vue`), usado por `CoordinationAcademicStructurePage` en modo
`curriculum`.

Vue Flow es únicamente una proyección interactiva. Los nodos se reconstruyen desde ciclo
y orden; las aristas desde los requisitos entre asignaturas. Las mutaciones van a la API
JSON (`/api/v1/coordination/academic/…`), que ejecuta los casos de uso de
Laravel. Los datos de cada asignatura se editan en formularios sobre el mismo contrato.

## Consecuencias

- Positivas: se reutiliza infraestructura estable y el diseño de cada tarjeta sigue bajo
  control del sistema de componentes Vue/shadcn-vue.
- Costes/riesgos: CSS adicional, paquete frontend nuevo, revisión de accesibilidad y
  comportamiento específico del lienzo en pantallas pequeñas.
- Cómo verificar: lockfile, auditoría npm, typecheck/build, prueba del contrato UI y
  pruebas feature de persistencia/autorización independientes del lienzo.
- Condición para revisar: incompatibilidad con Vue/Vite fijados, abandono del paquete o
  imposibilidad demostrada de satisfacer teclado/lector aun con la alternativa de tabla.

## Trazabilidad

RF-008..016; RN-005..008; CU-03; ADM-04; COR-13..15; plan I-18.
