# I-161 — Primera prueba del flujo del docente en la base de pruebas

## Contexto, 2026-09-29

El responsable pidió dejar la base remota lista para recorrer el sílabo como docente. Al
hacerlo aparecieron defectos de pantalla y restos de pruebas anteriores en la plantilla.

## Preparación de la base remota

- Período académico nuevo (sept 2026–mar 2027) creado por la API de Administración.
- 18 listas de Proyecto de carrera insertadas para Calidad de Software y Álgebra Lineal,
  con textos «(Dato de prueba)» y evidencia «Datos de prueba (ambiente de pruebas)».
- `pruebas:preparar` ejecutado desde la estación contra la base remota: paralelos A para
  `docente1.pruebas` (Calidad de Software) y `docente2.pruebas` (Álgebra Lineal),
  convocatoria institucional y de carrera abiertas, dos sílabos «sin iniciar». La
  comprobación del logo de facultad mira el disco local, por lo que se dejó un archivo de
  relleno solo en `storage/app/private` de la estación; la base y el servidor no cambian.

## Defectos encontrados y corregidos

- [x] Pantalla: la barra de formato del Texto libre salía vacía para el docente. Los
      botones usan `Tooltip` y el formulario del docente no tiene `TooltipProvider`; el
      editor trae ahora el suyo y su prueba lo monta sin proveedor externo.
- [x] Pantalla: las partes con hoja y una sola pregunta con el mismo nombre no mostraban
      ningún título (la hoja no dibuja el nombre de la pregunta). El título de la parte se
      muestra siempre sobre una parte con hoja.
- [x] Pantalla: al insertar una marca «Docente» en una celda cuya etiqueta está en la
      línea de arriba, el nombre caía al de la sección («Identificación institucional»),
      lo que duplicaba el título en el formulario. `context()` toma ahora lo escrito antes
      en la misma celda. Prueba en `useSheetMarks.test.ts`.
- [x] Pantalla: el Asistente de unidades queda con título, opciones sugeridas, cuadro y
      botón «Enviar»; se retiran los textos explicativos.
- [x] Plantilla remota: la marca mal nombrada de la celda «FORMACIÓN Y EXPERIENCIA…»
      vuelve a ser `formacion_experiencia` y la de Descripción vuelve a `descripcion`;
      las preguntas auto-creadas quedan desconectadas. Se hizo pausando la convocatoria
      institucional, guardando por la API (con regeneración confirmada de los dos sílabos
      de prueba) y reanudándola.

- [x] Pantalla: al abrir un sílabo «sin iniciar», el borrador se inicia solo y aparece el
      formulario; se retiran la tarjeta «Listo para iniciar» y el botón «Iniciar
      borrador». Si la convocatoria está en pausa, se muestra el motivo como antes.

- [x] Pantalla: cada sección del formulario del docente se muestra como tarjeta (mismo
      fondo que la ficha de identificación), con un separador entre secciones.

- [x] Periodos académicos (Administración): cuando alguna carrera tiene su convocatoria
      en curso en un período, la pantalla muestra el aviso «Edición bloqueada» y las
      acciones de ese período quedan deshabilitadas, igual que en la malla y la
      plantilla. El servidor devuelve `lock_reason` por período (`ProcessLocks::lockedPeriodReasons`).

- [x] Pantalla: el sílabo del docente lleva un encabezado fijo como el del editor de
      malla («Volver a mis sílabos», nombre de la materia y botón de tema); el layout ya
      no pone el botón de tema flotante.

- [x] Editar facultad: si ya hay logo se muestra la imagen con una «X»; la zona de
      arrastre aparece solo al quitarlo. Quitarlo sin subir otro no deja guardar (el logo
      es obligatorio) y cancelar deja todo como estaba. El servidor no cambia: solo recibe
      un logo cuando se sube uno nuevo.

## Pendiente

- [ ] Desplegar la pantalla con estas correcciones.
- [x] La parte «Habilidades blandas» era una selección múltiple heredada («el docente
      elige de una lista»). Se retiró y se creó como Contenido de la plantilla con dos
      marcas de Proyecto de carrera (`curricular_habilidades_blandas` y
      `curricular_habilidades_transversales`): el dato aparece solo, ya llenado por
      Coordinación. Hecho pausando y reanudando la convocatoria institucional.
