# Checklist frontend

## Tarea y jerarquía

- [ ] La acción principal y el siguiente paso son evidentes.
- [ ] Los botones secundarios textuales no llevan iconos de acción; los indicadores de
      carga y controles exclusivamente icónicos conservan su función.
- [ ] No hay títulos, cards o resúmenes duplicados.
- [ ] La densidad permite escanear trabajo repetido.
- [ ] Tablas se usan para colecciones comparables; páginas para workflows complejos.
- [ ] Etiquetas y estados usan el glosario.

## Datos y permisos

- [ ] No aparecen UUID, rutas, claves o proveedores.
- [ ] El servidor filtra; la UI no depende solo de ocultar controles.
- [ ] Vacío, sin permiso y no encontrado son estados distintos.
- [ ] Filtros/orden/página se preservan en URL cuando conviene.
- [ ] Conteos y filas comparten alcance.

## Formularios

- [ ] Etiquetas, ayudas y requerido son claros.
- [ ] Todo campo de texto que captura datos muestra un ejemplo contextual con `Ej. ...`;
      los buscadores de tablas usan `Buscar por...` con los datos consultados. Las ayudas
      no exponen claves internas, nombres de columnas ni contratos técnicos.
- [ ] Error indica campo, causa y corrección.
- [ ] El botón de enviar de un formulario queda deshabilitado mientras falte un campo obligatorio
      (`aria-required="true"` en el campo y asterisco rojo en la etiqueta) y, si edita datos
      existentes, mientras no haya cambios. Lo hace el componente `Button` solo. Los campos
      visibles no llevan `required`: el navegador mostraría «Por favor, llena este campo» al pasar
      el cursor. Un campo vacío no muestra mensaje ni borde rojo; solo se avisa de un valor mal
      escrito (correo, longitud, formato).
      Lo que el navegador no valida solo (casillas de «elija al menos una», selectores, archivos y
      claves) también deja el botón deshabilitado hasta completarse, con `:disabled` en el botón.
- [ ] Ningún texto de la interfaz (avisos, errores, ayudas, estados vacíos, tooltips, banners)
      termina en punto final. Los puntos entre frases se conservan. Lo que llega del servidor se
      muestra con `withoutFinalPeriod`.
- [ ] Valores heredados muestran origen y aspecto de solo lectura.
- [ ] Pendiente bloquea duplicados sin congelar toda la pantalla.
- [ ] Guardado/guardado fallido/conflicto/cambios sin guardar son visibles.
- [ ] Una versión inmutable explica la consecuencia antes de confirmar.
- [ ] Diálogos y hojas laterales no se cierran al hacer clic fuera. `Esc` los cierra
      solo si no hay cambios sin guardar. Un `Dialog` no muestra `X`: se cierra con una
      acción explícita. Su acción principal tiene un icono semántico y un indicador de
      carga cuando corresponda.
- [ ] En una tabla, la columna de acciones mide lo que su contenido necesita, con el margen
      normal de la celda. Es automático (`styles.css`): vale para las celdas que solo llevan
      botones, en pantallas desde `sm`.
- [ ] En las tablas de captura (más anchas que su contenedor), la columna de acciones queda fija al
      borde derecho: lleva `data-sticky-end` en su encabezado y en sus celdas.
- [ ] El desplazamiento vertical de una zona usa `ScrollArea` (shadcn), no `overflow-y-auto` ni
      `overflow-y-scroll`: la barra nativa del navegador se ve distinta en cada uno.

## IA

- [ ] El chat presenta propuestas. La carga Excel de proyectos de carrera muestra una vista previa
      y exige seleccionar qué aplicar.
- [ ] Nada se escribe en el sílabo ni en el proyecto de carrera sin una acción explícita de la persona.
- [ ] Si la IA está apagada o falla, se explica y el resto de la pantalla sigue usable.
- [ ] No se muestran nombres de proveedor, modelo ni errores técnicos.

## Accesibilidad y responsive

- [ ] Flujo completo por teclado y orden de foco lógico.
- [ ] Foco visible y restaurado en diálogos/navegación.
- [ ] Nombre/estado accesible de controles e iconos.
- [ ] Errores/cambios dinámicos se anuncian.
- [ ] Contraste y significado sin depender del color.
- [ ] Claro y oscuro revisados.
- [ ] 360 px, tablet y escritorio sin scroll horizontal global.
- [ ] Bajo 640 px, cada fila de listado es una tarjeta con todos sus datos etiquetados
      y acciones accesibles; cambiar de ancho no duplica campos editables.
- [ ] La paginación cabe completa y las cuadrículas documentales/hojas de plantilla
      mantienen desplazamiento interno accesible por teclado.
- [ ] Zoom 200 % y texto largo no rompen acciones.

## Calidad

- [ ] Props/emit/contratos TypeScript sin `any` evasivo.
- [ ] Componentes existentes reutilizados.
- [ ] Toda llamada usa el cliente HTTP compartido y respeta CSRF, cookies y errores.
- [ ] Pruebas cubren estados y acciones críticas.
- [ ] Lint, tipos, pruebas, contrato y build pasan.

## Automatización disponible

En el repositorio frontend, antes de cada push:

```bash
npm run lint
npm run typecheck
npm test
npm run contract:check
npm run build
```

`contract:check` compara las llamadas del cliente con el OpenAPI del backend. Estas
comprobaciones reducen regresiones, pero los puntos manuales siguen abiertos hasta
registrar evidencia en `acceptance-status.md` (PV-19). Guion manual:
`manual-review-script.md`.
