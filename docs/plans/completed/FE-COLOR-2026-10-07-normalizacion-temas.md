# FE-COLOR-2026-10-07: Normalización de colores en claro y oscuro

## Estado
Completado

## Trazabilidad
RNF-018..023, RNF-029..034; UI-01..04 y pantallas ADM/COR/DOC/BIB vigentes;
CP-N experiencia, accesibilidad y mantenibilidad; PV-19.

## Resultado demostrable

La interfaz operativa usa una sola paleta semántica coherente en tema claro y oscuro.
Texto, superficies, acciones, controles, estados, foco y navegación conservan contraste;
los colores documentales, gráficos y de unidades académicas mantienen su significado.

## Decisiones y supuestos

- No hay una puerta P0: el cambio no modifica dominio, permisos, datos ni contratos.
- La marca institucional conserva azul y rojo; el tema oscuro usa una variante clara del
  azul para que `primary` funcione como texto, borde y superficie interactiva.
- La paleta del documento, los gráficos, los estados y las unidades curriculares queda
  fuera de la unificación visual porque comunica información propia.
- PV-19 mantiene pendiente la aceptación en navegadores y dispositivos reales.

## Cambios realizados

- Dominio, backend, datos, seguridad, auditoría, trabajos e integraciones: sin cambios.
- Frontend: tokens de tema, sombras, estados, campos y navegación normalizados.
- Primitivas: eliminadas las variantes cromáticas locales `dark:`; pestañas transparentes.
- Excepciones: paletas documental, gráfica y curricular conservadas de forma aislada.

## Verificación

- Cálculo WCAG: texto normal ≥ 5,29:1, azul semántico usado como texto ≥ 4,58:1,
  texto sobre azul ≥ 4,61:1 y bordes de campo ≥ 3,19:1 en ambos temas.
- `npx eslint src`: aprobado.
- `npm run typecheck`: aprobado.
- Búsqueda de `dark:` y colores Tailwind literales fuera de pruebas: sin resultados.
- Los colores hexadecimales restantes fuera de `styles.css` pertenecen a
  `documentPalette.ts`, la paleta documental aislada.
- `git diff --check` en frontend y backend: aprobado.
- No se ejecutaron suites funcionales porque el cambio solo modifica estilos semánticos.
  La aceptación visual real continúa pendiente en PV-19.

## Pasos

- [x] Inventariar tokens, colores literales y variantes locales de tema.
- [x] Ajustar los pares semánticos centrales y documentar sus contrastes.
- [x] Normalizar primitivas compartidas y usos operativos locales.
- [x] Revisar pantallas representativas y excepciones permitidas.
- [x] Ejecutar verificaciones estáticas y actualizar trazabilidad.
- [x] Cerrar y mover el plan a completados.

## Riesgos y reversión

El riesgo es alterar el significado de colores funcionales o el aspecto del documento.
La reversión consiste en restaurar los tokens y clases del frontend; no hay migraciones,
datos ni contratos que revertir.

## Evidencia de cierre

I-197 queda registrado en `DECISIONS_STATUS.md`, la arquitectura, la especificación de
pantallas y la matriz de trazabilidad. La interfaz operativa ya no contiene bifurcaciones
cromáticas por componente; los temas se resuelven desde `src/styles.css`.
