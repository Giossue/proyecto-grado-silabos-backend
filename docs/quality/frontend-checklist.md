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
- [ ] Valores heredados muestran origen y aspecto de solo lectura.
- [ ] Pendiente bloquea duplicados sin congelar toda la pantalla.
- [ ] Guardado/guardado fallido/conflicto/cambios sin guardar son visibles.
- [ ] Una versión inmutable explica la consecuencia antes de confirmar.
- [ ] Todo `Dialog` se cierra mediante una acción explícita: no muestra `X` ni responde
      al fondo o a `Esc`; su acción principal tiene un icono semántico y conserva un
      indicador de carga cuando corresponda.

## IA

- [ ] Se etiqueta como recomendación, no validación.
- [ ] La fuente citada y su extracto se pueden consultar.
- [ ] Aplicar requiere acción explícita y muestra el cambio.
- [ ] Fallo/ausencia de evidencia no bloquea.
- [ ] Confianza no se presenta con falsa precisión.

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
- [ ] Build, lint, tipos y pruebas pasan.

## Automatización disponible

La puerta del repositorio frontend ejecuta `npm test`, `npm run typecheck` y
`npm run build`. Las comprobaciones automatizadas reducen regresiones, pero los puntos
manuales anteriores permanecen abiertos hasta registrar evidencia en
`acceptance-status.md` con la matriz real de `PV-19`.
