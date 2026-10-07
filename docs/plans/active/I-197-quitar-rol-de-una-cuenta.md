# I-197 — Quitar un rol de una cuenta (Administración)

## Pedido del responsable, 2026-10-07

El administrador no tenía cómo retirar un rol: solo agregar otro o deshabilitar la cuenta.

## Reglas

- `DELETE /api/v1/admin/users/{user}/roles/{assignment}`, solo Administrador.
- La asignación queda finalizada (`asignacion_rol_activa = false`) como historial.
- No se puede quitar el único rol vigente: para eso se deshabilita el acceso.
- Un docente con paralelos en un periodo que ya no admite cambios no pierde el rol
  (misma comprobación que «Quitar de la carrera»); sus paralelos vigentes se liberan.
- Se cierran las sesiones de la cuenta y se audita `usuario.rol_retirado`.

## Interfaz

«Editar usuario» muestra «Roles vigentes» (si hay más de uno) con «Quitar» y confirmación.

## Verificación

`ManagedUserUpdateTest` (quitar, único rol, ajeno y no administrador); typecheck, lint y
pruebas del frontend.
