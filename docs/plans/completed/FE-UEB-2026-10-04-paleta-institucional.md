# FE-UEB-2026-10-04: Paleta institucional de la interfaz

## Estado

Completado el 2026-10-04. Implementación y comprobación local cerradas;
aceptación institucional en dispositivos reales pendiente en PV-19.

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

## Cambios realizados

Nota 2026-10-04: la primera versión (sidebar azul completo, fondos teñidos, borde rojo
en el acceso, cifras en azul) se revirtió a pedido del responsable del producto por
verse mal combinada. La versión vigente (`9ac168f` del frontend) solo toca tokens de
`src/styles.css`: primario, foco y menú activo en azul; barra roja en el ítem activo;
fondos y sidebar neutros. Verificación: ESLint, tipos y pruebas de `AppShell`. La
lista siguiente describe la versión revertida y se conserva como historial.

- Frontend: tokens centralizados en `src/styles.css`, azul principal, acentos rojos,
  fondos azul suaves, sidebar azul y navegación activa distinguible.
- Acceso: contenedor compartido con logo ampliado, fondo azul tenue y borde rojo.
- Encabezados y métricas: acento institucional, iconos con fondo azul suave y cifras
  en el color principal; versión clara del azul para el tema oscuro.
- Navegación: selección clara con indicador rojo y foco visible, conservada al abrir
  grupos y al colapsar el menú. Los textos secundarios del usuario heredan tonos
  apropiados para el fondo del sidebar.
- Estados: ajuste de los tonos de error, éxito y advertencia para mantener contraste
  sobre las nuevas superficies. Botones y badges destructivos usan su fondo completo
  y el texto correspondiente en modo oscuro.
- Documentación: arquitectura frontend, patrón de colores y trazabilidad.
- Dominio, API, base de datos, permisos, estados, auditoría e integraciones: sin cambios.

## Pruebas

Comprobar contraste de textos, botones, navegación y foco; revisar acceso, navegación
activa y superficies en ambos temas y en móvil/escritorio. Ejecutar las verificaciones
frontend aplicables a los archivos modificados y registrar sus resultados. No se
requieren migraciones ni la puerta backend para este ajuste visual.

## Pasos

- [x] Identificar origen, alcance, trazabilidad y pendientes.
- [x] Ajustar tokens, sidebar y acceso en el repositorio frontend.
- [x] Sincronizar arquitectura, patrones de pantalla y trazabilidad.
- [x] Registrar comprobaciones automatizadas, contraste y revisión visual local.

## Riesgos y reversión

La navegación activa o un texto secundario pueden perder contraste al variar el fondo.
La verificación debe cubrir sus estados normal, activo, hover y foco en ambos temas.
La reversión consiste en restaurar los tokens y estilos de marca de este cambio; no
afecta datos persistidos ni documentos.

## Evidencia de cierre

Responsable: Codex. Ambiente: frontend local con Vite y Chromium sin interfaz,
API local interceptada y datos sintéticos. Revisión frontend comprobada:
`ad3e4fb`; documentación base `5cc1992e`. No se usaron cuentas ni datos reales.

| Comprobación | Resultado |
|---|---|
| `npm run lint` | ESLint y cuatro casos de arquitectura aprobados |
| `npm run typecheck` | Aprobado |
| `npm test -- src/components/AppShell.test.ts src/pages/AuthFlowPages.test.ts src/pages/WorkspacePage.test.ts` | Tres archivos y 14 pruebas aprobados |
| Acceso y panel en Chromium | 12 combinaciones: claro/oscuro, 360/768/1440 px, sin desbordamiento horizontal ni errores de página |
| Contraste de tokens y composiciones | 43 pares por tema; mínimo 4,53:1 en claro y 4,54:1 en oscuro, incluidos textos secundarios, botones, alertas, tintes y navegación |
| Menú móvil y colapsado | Fondo institucional, selección, foco y estado activo al combinar apertura y hover comprobados en ambos temas |
| Encabezados | Sombra interior roja comprobada; las utilidades de borde no sustituyen su color |
| `git diff --check` | Aprobado en los archivos modificados |

Las capturas locales se guardaron en `/tmp/silabos-ueb-palette-review/` para inspección;
son evidencia temporal con datos sintéticos. No se ejecutó build ni la puerta backend:
la verificación fue proporcional al ajuste de presentación. No se agregaron pruebas
que reprodujeran los valores CSS. Esta revisión local no cierra PV-19 ni declara
conformidad de accesibilidad de toda la aplicación.
