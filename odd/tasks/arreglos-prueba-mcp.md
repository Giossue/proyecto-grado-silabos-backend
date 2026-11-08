# Arreglos de la prueba con los 4 navegadores (2026-11-08)

Objetivo: corregir los detalles que salieron al recorrer el sistema como admin, coordinación, docente y biblioteca.
Rama: `main` (commit sin push). Pruebas: Pest (backend), Vitest (frontend). Entrega: commits por tarea.

## Specs

Cada S cita el hallazgo tal como se le informó al usuario.

- S1 Guía de pasos: «el banner dice "1 de 6" y el panel "1 de 5"». Banner y panel cuentan lo mismo.
- S2 Guía: «no se actualiza sin recargar». Tras crear facultad, carrera, cuenta, materia, paralelo, docente, proyecto de carrera o abrir la convocatoria, banner y panel reflejan el paso nuevo sin recargar.
- S3 Guía: «falta el paso "Proyecto de carrera"». La guía de coordinación incluye completar el proyecto de carrera antes de abrir la convocatoria.
- S4 Guía: «dice "Todo listo" aunque la convocatoria todavía no se puede abrir». Solo dice «Todo listo / Ya puede abrir la convocatoria» cuando de verdad se puede abrir; y tras abrirla no sigue diciendo «Ya puede abrir».
- S5 Guía: con 0 carreras, «Asignar un coordinador» mostraba «Coordinación actualmente activa». No debe decirlo si no hay carreras ni coordinación.
- S6 Guía: el orden no quedaba claro (los paralelos necesitan docente). El orden de pasos sigue el orden real de dependencias.
- S7 Convocatoria: «Aviso falso al abrir la convocatoria: "Los docentes conservan lo del período anterior", aunque no haya período anterior». El aviso solo aparece si se cumple, y dice algo cierto.
- S8 «el título de "Nueva convocatoria" no está centrado»: se alinea como los demás modales.
- S9 Formularios: «el logo de la facultad es obligatorio aunque la guía dice que puede ir después». El logo es opcional al crear la facultad (o la guía deja de decir lo contrario; se elige opcional).
- S10 Formularios: «"Crear carrera" se puede pulsar con campos vacíos». Deshabilitado hasta tener los obligatorios.
- S11 Formularios: «los errores no se borran al corregir». Un error de campo se quita al cambiar ese campo.
- S12 Formularios: «el error de código repetido sale dos veces» (diálogo de materia) y el mensaje genérico «El valor del campo código ya está en uso». Sale una vez y dice algo claro, por ejemplo «Ya existe una materia con ese código».
- S13 Fechas: «no se puede registrar un período que ya empezó (no deja elegir días pasados)». El inicio de clases de un período admite fechas pasadas.
- S14 Docente: «un docente recién creado no se puede asignar hasta que active su cuenta, y nada lo explica». Se explica dónde se elige el docente.
- S15 Docente: «por qué no se puede enviar solo se ve al pasar el mouse, y ese mensaje se sale de la pantalla». El motivo se ve sin hover y no se sale de la pantalla.
- S16 Docente: «el diálogo de firma trata de "tú"». Trato de «usted» («Arrastre su archivo… o haga clic»).
- S17 Docente: «puede reenviar sin cambiar nada». Decisión: al reenviar sin cambios desde la corrección, la confirmación lo advierte («No ha cambiado nada desde que pidieron la corrección»). No se bloquea (el revisor pudo equivocarse).
- S18 Docente: «Teacher with no convocation sees "No tiene sílabos asignados" with no context». Explica que todavía no hay convocatoria abierta cuando ese es el caso.
- S19 Docente: las tarjetas de sílabos se ordenan por ciclo (y luego por nombre).
- S20 Textos en inglés: «"Loading", el calendario y la malla para lectores de pantalla». Todo en español.
- S21 Diseño: «en la malla, la leyenda tapa "Nivel I"».
- S22 Diseño: «el ojo de vista previa está a la derecha de la X»: va a la izquierda de la X.
- S23 Diseño: «las gráficas muestran 1.2, 1.4… en vez de números enteros».
- S24 Login/activación: el panel lateral fija «Gestión de sílabos de la Carrera de Software»; debe ser institucional, sin una carrera fija.
- S25 Accesibilidad: los campos de horas tienen `aria-valuemax=0`.
- S26 Bibliografía: «la columna "Código" muestra "Libro físico"». Código muestra el código del ejemplar (o queda vacío), no el tipo de material.
- S27 «si se escribe muy rápido en la tabla de unidades, podría perderse un cambio». Investigar; si se reproduce, ningún cambio se pierde.

Fuera de alcance: vista previa del proyecto de carrera sin modalidad/campus (es a propósito, depende del paralelo); falta de worker de colas en local (es del entorno).

## Tasks

| ID | Specs | Ruta | Estado | Commit |
|----|-------|------|--------|--------|
| T1 | S1–S8 | delegada (escritor paralelo A) | hecho | front b8b6b9d |
| T2 | S9–S14, S25 | delegada (escritor paralelo B); S13 aplicada por el padre | hecho | front 1a13d28 |
| T3 | S16, S20–S24 | delegada (escritor paralelo C) | hecho | front ffee83d |
| T4 | S15, S17–S19, S27 | delegada (escritor paralelo D) | hecho (S26 no aplica) | front f41b79a |

Ruta: 4 unidades independientes con superficies separadas y varios minutos de trabajo cada una (disparador de escritores en paralelo). El padre hace git, la revisión de cada diff y la verificación final.

## Log

- L1 (2026-11-08, usuario): «Arregla todo»
- L2: la lista viene del informe de la prueba con los navegadores Admin, Coordinador, Docente y Bibliotecario sobre la base local vaciada.
- L3: S26 no es un error: «Código» muestra el tipo de material por decisión del 2026-11-02 (DECISIONS_STATUS, migración 2026_11_02_000258). No se cambia sin que el usuario lo decida.
- L4: S27 no se reprodujo: cada campo tiene su temporizador y cada guardado lee el valor y la versión al ejecutarse. Queda una prueba de regresión.
- L5: S7 confirmado: no se copia nada del período anterior (CopySyllabusContent solo copia entre paralelos de la misma convocatoria).
- L6: verificación final: backend 763/763, frontend 826/826, typecheck, lint, PHPStan, contrato (137/147) y Pint en verde.
