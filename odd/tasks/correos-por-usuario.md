# Cada usuario elige qué avisos recibe por correo

Objetivo: Docente, Coordinación y Biblioteca eligen qué avisos les llegan por correo; Administración solo conserva el interruptor general.
Rama: `main` (política del repositorio). Pruebas: `php artisan test` (Pest) y `npm test` en el frontend.
Riesgo: item 3 (cambia quién decide el envío de correos y la API de ajustes del sistema).

## Specs

- S1. «el coordinador, docente y biblioteca deben poder elegir si quieren o no recibir notificaciones por
  correo y que tipo de notificaciones» / «el admin no debe decidir eso, sino cada usuario». Sección
  «Notificaciones» en la Configuración de cada usuario de esos roles, con un interruptor por cada tipo de
  aviso por correo de sus roles. Sin elegir, todos llegan (como hasta ahora).
- S2. Avisos por correo que se pueden elegir. Docente: convocatoria abierta, convocatoria reanudada,
  corrección solicitada, sílabo devuelto a borrador, sílabo aprobado. Coordinación: resumen diario.
  Biblioteca: «en biblioteca falta que le lleguen correos»: sílabo aprobado por Coordinación (por
  revisar) y sílabo reenviado a biblioteca.
- S3. Coordinación: «sílabo enviado» y «reenviado» «No es necesario que quede esto en la campana»: ya no
  se crean esos avisos (Coordinación los ve en Revisión y en el resumen diario).
- S4. Administración conserva en Configuración › Sistema solo «Enviar avisos por correo» (interruptor
  general) y la hora del resumen; sale el interruptor «Resumen diario» (ahora lo decide cada coordinador).
- S5. Los correos de la cuenta (invitación, restablecer contraseña, verificar correo) no se pueden apagar.

## Tasks

| ID | Specs | Ruta | Estado | Commit |
|----|-------|------|--------|--------|
| T1 | S1, S2, S3, S4, S5 | inline | hecho (617 pruebas) | ver log |
| T2 | S1, S4 | inline | pendiente | |
| T3 | S1–S5 | inline | pendiente | |

- T1: backend (preferencias por usuario, catálogo por rol, envío filtrado, resumen filtrado, sin avisos de envío a Coordinación, correos de Biblioteca, API, pruebas).
- T2: frontend (pestaña «Notificaciones» en Configuración, Sistema sin «Resumen diario», pruebas).
- T3: documentación (pantallas, decisiones, despliegue, trazabilidad).

Ruta: inline (una unidad por repositorio; no hay trabajo paralelo que justifique delegar).

## Log

- L1 (2026-11-02, usuario): «Sabes que me dijeron ahora, que el coordinador, docente y biblioteca deben poder elegir si quieren o no recibir notificaciones por correo y que tipo de notificaciones... Mira, primero hagamos una lista por rol de que recibe cada uno en el correo / que al parecer lo que se hizo está mal, que el admin no debe decidir eso, sino cada usuario....»
- L2 (2026-11-02): se entregó la lista por rol y se propuso la sección por usuario con interruptores y el interruptor general para Administración.
- L3 (2026-11-02, usuario): «ok unas cosas por eliminar: No recibe un correo por cada envío: «sílabo enviado» y «reenviado» quedan solo en la campana. No es necesario que quede esto en la campana / en biblioteca falta que le lleguen correos / si, así me gusta»
