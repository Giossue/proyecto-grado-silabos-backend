# FE-UEB-2026-10-04: Paleta institucional de la interfaz

## Estado

En curso. Implementación y evidencia de verificación pendientes.

## Trazabilidad

| Tipo | IDs cubiertos | Evidencia prevista |
|---|---|---|
| Requisito | RNF-018..023, RNF-029..034 | Tokens compartidos y comprobaciones frontend |
| Función, regla y caso de uso | RF/RN/CU vigentes, sin cambios | Presentación compartida sin modificar contratos, permisos ni estados |
| Interfaz | UI-01..04; pantallas ADM/COR/DOC/BIB vigentes | Acceso y navegación en claro/oscuro, móvil y escritorio |
| Prueba | CP-N de UX, accesibilidad y mantenibilidad | Contraste, revisión visual y verificaciones del repositorio frontend |
| Pendiente | PV-19 | La matriz de navegadores/dispositivos reales permanece pendiente |

## Resultado demostrable

La interfaz incorpora el azul `#2d4b6f` y el rojo `#ac231e` proporcionados por el
usuario el 2026-10-04 para dar más presencia a la identidad de la UEB. El azul
organiza acciones, navegación y superficies suaves; el rojo destaca acentos de marca.
Los dos temas mantienen textos, foco y estados distinguibles.

## Decisiones y supuestos

El origen es la solicitud del responsable del producto, que relaciona estos colores
con el logo. No se dispone de una guía oficial de identidad de la UEB; esta selección
no se atribuye a una norma institucional. Se permite combinar y variar los tonos con
la paleta existente para conservar legibilidad y jerarquía.

Los colores semánticos de éxito, advertencia y acción destructiva conservan su función
propia; el rojo de marca no comunica por sí solo un error. La paleta documental, el
papel y los colores guardados en plantillas y sílabos son independientes del tema de
la interfaz. PV-19 no bloquea este ajuste reversible, pero mantiene pendiente la
aceptación en dispositivos y navegadores reales.

## Cambios previstos

- Frontend: tokens centralizados en `src/styles.css`, azul principal, acentos rojos,
  fondos azul suaves, sidebar azul y navegación activa distinguible.
- Acceso: branding coherente en claro y oscuro con los tokens compartidos.
- Documentación: arquitectura frontend, patrón de colores y trazabilidad.
- Dominio, API, base de datos, permisos, estados, auditoría e integraciones: sin cambios.

## Pruebas

Comprobar contraste de textos, botones, navegación y foco; revisar acceso, navegación
activa y superficies en ambos temas y en móvil/escritorio. Ejecutar las verificaciones
frontend aplicables a los archivos modificados y registrar sus resultados. No se
requieren migraciones ni la puerta backend para este ajuste visual.

## Pasos

- [x] Identificar origen, alcance, trazabilidad y pendientes.
- [ ] Ajustar tokens, sidebar y acceso en el repositorio frontend.
- [x] Sincronizar arquitectura, patrones de pantalla y trazabilidad.
- [ ] Registrar comprobaciones automatizadas, contraste y revisión visual local.

## Riesgos y reversión

La navegación activa o un texto secundario pueden perder contraste al variar el fondo.
La verificación debe cubrir sus estados normal, activo, hover y foco en ambos temas.
La reversión consiste en restaurar los tokens y estilos de marca de este cambio; no
afecta datos persistidos ni documentos.

## Evidencia de cierre

Pendiente de implementación y verificación. La revisión local no cierra PV-19.
