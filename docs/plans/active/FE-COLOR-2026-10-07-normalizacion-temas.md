# FE-COLOR-2026-10-07: Normalización de colores en claro y oscuro

## Estado
En curso

## Trazabilidad
RNF-018..023, RNF-029..034; UI-01..04 y pantallas ADM/COR/DOC/BIB vigentes;
CP-N experiencia, accesibilidad y mantenibilidad; PV-19.

## Resultado demostrable

La interfaz operativa usa una sola paleta semántica coherente en tema claro y oscuro.
Texto, superficies, acciones, controles, estados, foco y navegación conservan contraste;
los colores documentales, gráficos y de unidades académicas mantienen su significado.

## Decisiones y supuestos

- No hay una puerta P0: el cambio no modifica dominio, permisos, datos ni contratos.
- La marca institucional conserva azul y rojo; el tema oscuro puede usar una variante
  clara del azul para que `primary` funcione como texto, borde y superficie interactiva.
- La paleta del documento, los gráficos, los estados y las unidades curriculares queda
  fuera de la unificación visual porque comunica información propia.
- PV-19 mantiene pendiente la aceptación en navegadores y dispositivos reales.

## Cambios previstos
- Dominio: ninguno.
- Backend: ninguno.
- Datos: ninguno.
- Frontend: auditar tokens, contrastes y usos locales; normalizar primitivas y pantallas.
- Seguridad/auditoría: ninguno.
- Trabajos/integraciones: ninguno.

## Pruebas

- Comprobación calculada de contraste para pares semánticos de ambos temas.
- ESLint del frontend y comprobación de tipos.
- Búsqueda final de colores literales y excepciones de tema fuera de los alcances permitidos.
- La revisión visual real permanece pendiente en PV-19.

## Pasos
- [x] Inventariar tokens, colores literales y variantes locales de tema.
- [ ] Ajustar los pares semánticos centrales y documentar sus contrastes.
- [ ] Normalizar primitivas compartidas y usos operativos locales.
- [ ] Revisar pantallas representativas y excepciones permitidas.
- [ ] Ejecutar verificaciones estáticas y actualizar trazabilidad.
- [ ] Cerrar y mover el plan a completados.

## Riesgos y reversión

El riesgo es alterar el significado de colores funcionales o el aspecto del documento.
La reversión consiste en restaurar los tokens y clases del frontend; no hay migraciones,
datos ni contratos que revertir.

## Evidencia de cierre

Pendiente.
