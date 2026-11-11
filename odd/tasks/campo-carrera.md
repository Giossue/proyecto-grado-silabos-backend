# Campo «Carrera» (une docente y coordinación) y página del proyecto de carrera

Objetivo: dejar dos tipos de campo, Institucional y Carrera. En un campo Carrera el coordinador escribe el contenido de cada materia con el editor de texto y deja espacios para que el docente responda; el docente solo llena esos espacios. El proyecto de carrera de cada materia pasa de ventana a página. Rama: main (commits locales, sin push).
Entrega: commits por unidad en backend y frontend (`proyecto-grado-silabos-frontend/`). Pruebas: Pest (comando seguro del Log), Vitest. Continúa `odd/tasks/tipos-de-respuesta.md` (su motor de tipos de respuesta se reutiliza).

## Specs

- S1. Dos tipos de campo: «dejemos dos tipos de campos, uno que es el fijo y uno de carrera, ya no docente y coordinador, o sea, el fijo es el que ya existe que yo pongo ahí mis cosas, y el carrera que es docente y coordinador, y el coordinador podrá configurar esas cosas». Se llaman **Institucional** y **Carrera** en todo lo que ve el usuario («ya no es fijo, es institucional y carrera»): el valor guardado `institucional` no cambia y en pantallas, avisos y documentación «Fijo» pasa a «Institucional» (también en textos ya existentes como «Un campo Fijo no lleva tipos de respuesta…»). `docente` y `coordinacion` se unen en Carrera.
- S2. El coordinador, por materia, escribe un campo Carrera en el mismo editor del proyecto de carrera de hoy (la cinta con negrita, cursiva, subrayado, viñetas y numeración; captura del usuario de «Habilidades blandas»): «la idea es que se vea algo así mismo». A esa cinta se suma un botón para dejar **espacios para el docente**: Texto libre, Número, Elegir una, Elegir varias (con sus opciones escritas ahí). Lo que el coordinador escribe le llega fijo al docente. El texto fijo que puso Administración en la plantilla sigue sin poder cambiarse.
- S3. Casos que deben funcionar (confirmados por el usuario: «ok, si, así mismo»): (a) el coordinador escribe todo y no deja espacios: el docente no edita ese campo (como el «coordinación» de hoy); (b) el campo queda como un solo espacio libre: el docente lo escribe entero (como el «docente» de hoy); (c) el coordinador escribe un texto y deja una lista para elegir, por ejemplo en «Métodos».
- S4. El docente solo llena los espacios; al enviar, los espacios sin responder impiden enviar (reglas y mensajes de `tipos-de-respuesta`).
- S5. «mejor no usemos modales, sino que creemos página para poder configurar todo esto»: el proyecto de carrera de cada materia se abre en una página propia (secciones a la izquierda, editor a la derecha, guardar), no en una ventana. La pestaña «Respuestas del docente» y el menú por espacio de la ventana desaparecen.
- S6. Datos guardados: los campos `docente` y `coordinacion` pasan a Carrera con una migración que conserva lo escrito (proyectos de carrera, sílabos en curso, revisiones enviadas intactas). Hacer respaldo antes de migrar la base de desarrollo. Los sílabos ya enviados o aprobados no cambian.
- S7. Bloqueo: con la convocatoria abierta no se edita el proyecto de carrera (igual que hoy).
- S8. Administración, en la plantilla, elige entre Institucional y Carrera (ya no Docente/Carrera/Fijo) y puede seguir poniendo espacios de respuesta desde su cinta.
- S9. PDF y Word: lo que escribió el coordinador y lo que respondió el docente salen como texto, sin controles.
- S10. Documentación al día; tesis solo con visto bueno del usuario.

## Tasks

- [ ] T1 (S1, S6, S8) Responsable único «Carrera» y nombre «Institucional»: migración, dominio, validación, plantilla del administrador
- [ ] T2 (S2, S3, S4, S7, S9) Contenido por materia: el coordinador escribe y deja espacios; el docente llena solo los espacios; envío, Word/PDF
- [ ] T3 (S5) Página del proyecto de carrera con el editor y el botón de espacios
- [ ] T4 (S10) Documentación

## Log

- L1 (2026-11-10, usuario, con captura de la pestaña «Respuestas del docente» y del editor de «Habilidades blandas»): «Nop, sabes qué, creo que debemos cambiar el enfoque, verás, mejor hagamos algo, dejemos dos tipos de campos, uno que es el fijo y uno de carrera, ya no docente y coordinador, o sea, el fijo es el que ya existe que yo pongo ahí mis cosas, y el carrera que es docente y coordinador, y el coordinador podrá configurar esas cosas, pero no así, esto sigue feo, o mejor no usemos modales, sino que creemos página para poder configurar todo esto, la idea es que se vea algo así mismo, si ves ese componente? algo así».
- L2 (propuesta aceptada con «ok, si, así mismo»): Fijo = el de hoy; Carrera = el coordinador lo escribe en el editor con la cinta y un botón para dejar espacios (texto libre, número, elegir una, elegir varias); el docente solo llena los espacios; casos (a), (b), (c) de S3; página propia por materia; los campos docente/coordinación pasan a Carrera con cuidado y respaldo.
- L3 (asunciones del orquestador, a confirmar con el usuario al cerrar): (i) un campo Carrera que el coordinador deja tal cual viene de la plantilla, con su espacio vacío, es el caso (b): no cuenta como «Sin llenar»; por eso el paso «proyecto de carrera completo» de la preparación de la convocatoria deja de exigir llenar todos los campos (se elige la regla más simple coherente con S3 y se anota la elegida). (ii) La importación desde Excel sigue funcionando para los campos Carrera (escribe su contenido; no crea espacios de elegir). (iii) Migración: un campo `coordinacion` cuyo contenido por materia no deja espacios queda sin edición para el docente (caso a); un campo `docente` sin fila de la materia sigue con el contenido de la plantilla (caso b).
- L4 (2026-11-10, usuario): «una cosa, ya no es fijo, es institucional y carrera, ahí si». Se reescriben S1 y S8. El primer trabajador delegado se detuvo sin cambios antes de recibir esta corrección.
- Pruebas backend, comando seguro: `env -i PATH="$PATH" HOME="$HOME" LANG="$LANG" DB_DATABASE=silabos_ueb_test php -d memory_limit=2G vendor/bin/pest`. NUNCA `php artisan test` (el shell exporta .env y apunta a la base de desarrollo `silabos_ueb`). Migrar la base de desarrollo: `env -i PATH="$PATH" HOME="$HOME" LANG="$LANG" php artisan migrate` solo después del respaldo.
