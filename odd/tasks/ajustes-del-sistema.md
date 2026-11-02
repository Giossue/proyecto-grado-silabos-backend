# Ajustes del sistema desde Administración

Objetivo: Notificaciones, Asistente de IA y Biblioteca (Koha) se configuran en una pantalla de Administración en vez de variables de entorno.
Rama: `main` (política del repositorio). Pruebas: `php artisan test` (Pest) y `npm test` en el frontend.
Riesgo: item 2 (guarda una credencial: la clave de la IA).

## Specs

- S1. Estos ajustes dejan de vivir en Environment Settings y pasan a «configuraciones de el admin»:
  `NOTIFICATION_EMAILS`, `NOTIFICATION_DIGEST`, `NOTIFICATION_DIGEST_TIME`; `AI_DRIVER`, `AI_BASE_URL`,
  `AI_MODEL`, `AI_API_KEY`, `AI_CONNECT_TIMEOUT`, `AI_TIMEOUT`; `CATALOG_DRIVER`, `KOHA_BASE_URL`.
- S2. «acá vivirá: /admin/configuracion/»: una pestaña «Sistema» en la Configuración de Administración
  (junto a Perfil y Seguridad), en `/admin/configuracion/sistema`, con tres secciones: Notificaciones,
  Asistente de IA y Biblioteca (Koha). Solo el rol Administrador la ve y la cambia.
- S3. Los valores se guardan en la base. Mientras un valor no se guardó desde la pantalla, se usa la
  variable de entorno (migración sin corte). La pantalla dice de dónde sale cada valor.
- S4. «toca proteger bien eso» (la clave de la IA): cifrada en la base con `APP_KEY`; nunca se devuelve
  ni se muestra (solo «Clave guardada · termina en …XXXX»); se puede reemplazar o borrar, no leer.
- S5. Guardar IA o Biblioteca pide la contraseña actual. Cada cambio queda en la auditoría con quién y
  qué campos cambió, nunca el valor de la clave.
- S6. «Probar conexión» para la IA y para Koha, con la configuración guardada.
- S7. Los cambios valen sin reiniciar: peticiones, tareas en cola y el resumen diario leen el valor nuevo.
- S8. `APP_KEY` sigue en el entorno. Si cambia, hay que volver a escribir la clave de la IA.

## Tasks

| ID | Specs | Ruta | Estado | Commit |
|----|-------|------|--------|--------|
| T1 | S1, S3, S4, S5, S6, S7, S8 | inline | pendiente | |
| T2 | S2, S4, S5, S6 | inline | pendiente | |
| T3 | S1, S3, S8 | inline | pendiente | |

- T1: backend (tabla `ajustes_sistema`, servicio que aplica los ajustes sobre la configuración, API con contraseña, auditoría, prueba de conexión, pruebas).
- T2: frontend (pantalla, menú de Administración, pruebas).
- T3: documentación (pantallas, despliegue: el entorno pasa a respaldo, decisiones, trazabilidad).

Ruta: inline (una unidad por repositorio, sin trabajo paralelo que justifique delegar).

## Log

- L1 (2026-11-02, usuario): «esto mejor no lo quitamos de acá y lo ponemos dentro de configuraciones de el admin? # Notificaciones NOTIFICATION_EMAILS=true NOTIFICATION_DIGEST=true NOTIFICATION_DIGEST_TIME=08:00 / igual con esto # IA AI_DRIVER=deepseek AI_BASE_URL=https://api.deepseek.com AI_MODEL=deepseek-flash AI_API_KEY=[clave omitida] AI_CONNECT_TIMEOUT=2 AI_TIMEOUT=50 / pero claro, toca proteger bien eso / y creo que lo mismo con # Biblioteca CATALOG_DRIVER=koha KOHA_BASE_URL=http://biblioteca.ueb.edu.ec / para que ya no vivan en Environment Settings»
- L2 (2026-11-02): se propuso el plan (pantalla, valores en la base con el entorno como respaldo, clave cifrada y nunca visible, contraseña para guardar, auditoría, probar conexión). Usuario: «ok hagamoslo». Se le recomendó rotar la clave de DeepSeek porque la pegó en el chat.
- L3 (2026-11-02, usuario): «una cosa, por si acaso, acá vivirá: /admin/configuracion/». S2 pasa a pestaña «Sistema» en `/admin/configuracion/sistema`.
