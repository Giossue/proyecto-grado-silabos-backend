# I-127 — Simplificación de Administración

Estado: en curso (2026-09-23).

Revisión de todo lo que hace el rol Administrador: se corrigen cuatro defectos y se retiran
caminos duplicados.

## Defectos

1. Cambios cosméticos (nombre/logo de facultad, logo institucional) purgaban el trabajo en
   curso de los sílabos. Solo los cambios estructurales deben regenerar.
2. Desactivar una cuenta no cerraba su coordinación: la carrera seguía "coordinada".
   Ahora desactivar cierra sus nombramientos de coordinación.
3. Administración recibía 403 al editar su propia fila en Usuarios. La cuenta propia se
   edita desde Perfil.
4. Cambiar el propio correo dejaba la cuenta sin verificar (y al único administrador fuera).
   Regla única: el correo de una cuenta activada no se cambia, tampoco por su titular.

## Simplificaciones

5. Un solo camino para la coordinación: Carreras → Asignar/Reemplazar coordinador. Se
   retira el estado `asignacion_coordinador` y la lista de candidatos excluye cuentas de
   administración y pendientes.
6. Se retira `deactivate_outgoing` del reemplazo: desactivar es una acción aparte.
7. Convocatorias: la fecha de entrega se mueve solo con Prorrogar.
8. Se retiran pantallas y código sin uso; los accesos del Panel llevan a su destino.

## Verificación

`composer verify` en backend; typecheck y vitest en frontend.
