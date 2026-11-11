# Guion de revisión manual

Cubre lo que ninguna prueba automática demuestra: teclado, foco, lector de pantalla,
contraste percibido, zoom, ancho reducido y el recorrido real con los cuatro roles.

Marca cada casilla solo si se cumple. Si algo falla, anota la página y qué ocurrió al
final del documento.

## Antes de empezar

- Prepara la demostración local con `docs/runbooks/demo.md`: cuatro cuentas del seeder,
  plantilla oficial y worker de colas.
- Fija el tema en **Claro** u **Oscuro**, nunca en «Sistema».
- Ancho reducido: `F12` y vista de dispositivo con **360 px**.
- Lector de pantalla en Linux: `Orca` se activa con `Super + Alt + S`.

## Muestra a revisar

Estas páginas cubren los patrones del sistema; el resto los reutiliza.

| # | Página | Ruta | Patrón |
|---|---|---|---|
| 1 | Acceso | `/ingresar` | Formulario simple y errores |
| 2 | Convocatorias | `/admin/convocatorias` | Indicadores arriba y botón «Antes de empezar»; los demás roles los ven arriba de `/coordinacion/revisiones`, `/docente/mis-silabos` y `/biblioteca/revisiones`, con el aviso de plazo |
| 3 | Facultades y carreras | `/admin/estructura-academica` | Pestañas, tabla, menú de fila y hoja lateral |
| 4 | Usuarios y roles | `/admin/usuarios` | Listado con estados, filtros y acciones |
| 5 | Plantilla | `/admin/plantilla` | Editor sobre la hoja y «Opciones avanzadas → Editar JSON» |
| 6 | Proyecto de carrera | `/coordinacion/proyecto-carrera` | Proyecto de carrera por asignatura |
| 7 | Sílabo | `/docente/mis-silabos` → abrir uno | Editor a pantalla completa y autoguardado |
| 8 | Revisión | `/coordinacion/revisiones` → abrir una | Lectura densa, decisión y firma |
| 9 | Revisión de bibliografía | `/biblioteca/revisiones` → abrir una | Motivos, aprobación y firma |

## 1. Teclado y foco

Recorre cada página **solo con el teclado**.

- [ ] `Tab` avanza en el orden visual, sin saltos ni trampas.
- [ ] **Siempre se ve dónde está el foco.**
- [ ] El menú lateral es alcanzable y se abre con `Enter` o `Espacio`.
- [ ] La campana de avisos es alcanzable, anuncia los pendientes y abre la lista con
      cualquiera de los cuatro roles.
- [ ] El menú de tres puntos de una tabla se abre con teclado y sus opciones se recorren
      con flechas.
- [ ] Al abrir un diálogo o una hoja lateral, el foco entra y **no se escapa** al fondo.
- [ ] `Esc` cierra el diálogo si no hay cambios sin guardar, y el foco vuelve al botón
      que lo abrió.
- [ ] Tras guardar, el panel se cierra **una sola vez** y no reaparece.
- [ ] Ningún control necesita el ratón.

## 2. Lector de pantalla

Con Orca activo, sobre las páginas 3, 7 y 9.

- [ ] El título de cada página se anuncia al entrar.
- [ ] Los campos se anuncian con su etiqueta.
- [ ] Un error dice **qué campo** y **qué corregir**.
- [ ] Los botones de solo icono anuncian su propósito.
- [ ] La tabla se anuncia con sus encabezados de columna.
- [ ] Al abrir una hoja lateral se anuncia su título.

## 3. Contraste percibido

- [ ] En **ambos temas**, el texto secundario se lee sin esfuerzo.
- [ ] Las etiquetas de estado no dependen solo del color.
- [ ] El borde de un campo se ve antes de tocarlo (en alto contraste se refuerza).
- [ ] La tarjeta se distingue del fondo.
- [ ] La hoja del sílabo y de la plantilla se ve blanca y legible en tema oscuro.

## 4. Zoom al 200 %

Con `Ctrl` y `+` hasta el 200 %, en las páginas 3, 5 y 7.

- [ ] No se pierde contenido ni aparece desplazamiento horizontal de la página.
- [ ] Nada se solapa ni queda cortado.
- [ ] Los botones siguen alcanzables.

## 5. Ancho de 360 px

- [ ] El menú lateral se abre con «Abrir el menú» y no tapa el contenido.
- [ ] Las tablas se ven como tarjetas con sus datos etiquetados.
- [ ] Las hojas laterales ocupan el ancho completo y siguen usables.
- [ ] Ningún texto se corta a media palabra.

## 6. Recorrido con los cuatro roles

Sigue `docs/runbooks/demo.md` de principio a fin y comprueba:

- [ ] **Administración** abre la convocatoria con fechas y ve la plantilla como quedará
      el sílabo.
- [ ] **Coordinación** completa el proyecto de carrera y abre la convocatoria de la carrera.
- [ ] **Docente** llena el sílabo, ve qué falta antes de enviar y lo envía.
- [ ] **Coordinación** pide corrección, compara revisiones y aprueba.
- [ ] **Biblioteca** devuelve con motivos y, tras el reenvío, aprueba la bibliografía.
- [ ] Cada rol recibe aviso solo cuando debe actuar o cuando cambió su trabajo.

## 7. Firmas y documentos

Con los certificados de prueba de `docs/runbooks/demo.md`.

- [ ] El archivo `.p12` y la clave se eligen y escriben con teclado.
- [ ] El docente firma en el primer envío y reenvía sin firmar tras una corrección.
- [ ] Biblioteca firma al enviar a coordinación; Coordinación firma al aprobar.
- [ ] Una clave incorrecta o un certificado vencido muestran un mensaje junto al campo.
- [ ] Un certificado cuyo titular no coincide con la cuenta es rechazado.
- [ ] El PDF se descarga apenas se genera; no hay descarga de Word.
- [ ] El PDF muestra un sello por firmante en el cuadro de firmas.

## Defectos encontrados

| Página | Qué ocurrió | Qué se esperaba |
|---|---|---|
| | | |

## Al terminar

Si no hay defectos, anota la fecha, el commit y quién revisó en
`docs/quality/acceptance-status.md`. Cada defecto se corrige con su prueba de regresión
o se anota en `docs/plans/pendientes.md`.
