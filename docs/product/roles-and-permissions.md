# Roles, alcance y permisos

## Modelo

El permiso efectivo se calcula en el servidor así:

```text
usuario activo
AND rol activo de la sesión
AND carrera del rol (si el rol la tiene)
AND asignación vigente (docente del paralelo, por ejemplo)
AND estado que admite la acción
```

El menú y los botones reflejan el permiso, pero no lo reemplazan.

## Roles

| Rol | Carrera | Notas |
|---|---|---|
| Administrador | No | Exclusivo: no se combina con otro rol activo. |
| Coordinador | Sí | Una sola coordinación activa por carrera. Se asigna en «Usuarios y roles»; si la carrera ya tiene coordinador, se confirma el reemplazo y el saliente deja de coordinarla en el mismo paso. En la convocatoria en curso no queda ninguna firma del saliente: lo que aprobó vuelve a «En coordinación» para que el entrante lo revise y firme, y biblioteca verifica de nuevo. El contenido y la firma del docente no cambian. |
| Docente | Sí | Trabaja solo en los sílabos de sus paralelos. |
| Bibliotecario | No | Revisa toda la universidad. No se combina con Coordinador ni Docente. |

## Matriz

| Capacidad | Administrador | Coordinador | Docente | Bibliotecario |
|---|---|---|---|---|
| Usuarios y roles (también por Excel) | Sí; solo Administración elimina cuentas pendientes; reenvía la invitación solo si venció | Solo agrega docentes a su carrera (también desde Excel) y los quita de ella; reenvía la invitación vencida de sus docentes; no edita, activa ni elimina cuentas | No | No |
| Facultades, carreras (también por Excel), logos y coordinador de carrera | Sí | No | No | No |
| Malla: asignaturas, requisitos y ciclos (también por Excel) | No | Sí, en su carrera, con la convocatoria de la carrera pausada o sin abrir | No | No |
| Paralelos y docente de cada paralelo | No | Sí, en su carrera, también con la convocatoria abierta | No | No |
| Proyecto de carrera de la asignatura e importación | Solo el proyecto de carrera, por API; no tiene pantalla | Sí, en su carrera, con la convocatoria de la carrera pausada o sin abrir | No | No |
| Marcar un campo del proyecto de carrera como editable por el docente | No | Sí | Edita ese campo en su sílabo | No |
| Plantilla | Sí, con la convocatoria pausada o sin convocatoria | No | No | No |
| Convocatoria: crear, pausar, reanudar, prorrogar, cerrar, eliminar | Sí | No | No | No |
| Convocatoria de la carrera: iniciar, pausar, reanudar | No | Sí | No | No |
| Elaborar, validar y enviar el sílabo | No | No | Sí, en sus paralelos, con la convocatoria en curso; firma en el primer envío | No |
| Copiar el sílabo de otro paralelo propio | No | No | Sí | No |
| Chat de IA | No | No | Sí, en su sílabo | No |
| Revisar: aprobar (firmando) o pedir corrección | No | Sí, etapa de coordinación de su carrera | No | Sí, etapa de biblioteca |
| Comparar revisiones | No | Sí | No | No |
| Reiniciar un sílabo («Reiniciar sílabo», con motivo; solo con el sílabo aprobado y la convocatoria abierta, no en pausa; borra todo) | No | Sí, en su carrera | No | No |
| Firmar con su `.p12` (una vez por sílabo, al decidir) | No | Sí, al aprobar | Sí, al primer envío | Sí, al aprobar |
| Ver y descargar el PDF (apenas se genera; no hay Word) | No | Sí, en su carrera | Sí, de sus paralelos | Sí, de los sílabos que llegaron a biblioteca |
| Auditoría | Sí | No | No | No |

Las decisiones de revisión solo se admiten con la convocatoria de la carrera abierta o
pausada; cerrada, la revisión queda de consulta.

`PV-16` decide si Coordinación puede editar contenido del docente. Hasta resolverlo, no
puede.

## Ámbito de la sesión

- Con una sola asignación vigente (un solo rol y una sola carrera), la persona entra directo
  a su Panel, sea cual sea su rol.
- Con varias asignaciones (por ejemplo, docente en dos carreras, o docente y
  coordinador), la persona elige en `/rol`.
- Solo hay un rol activo a la vez. «Cambiar carrera o rol», en el menú de usuario, cierra
  el rol activo (queda en auditoría como `rol_activo.cerrado`) y lleva a `/rol` para elegir otro.
- Abrir un aviso puede activar otro rol de la misma persona: el servidor elige la
  asignación que corresponde al aviso y lo registra en auditoría.

Fuente: `app/Modules/Identity/Application/ActiveRole.php`.

## Cuentas

- Administración crea cuentas y asigna roles. La persona recibe un enlace para verificar
  su correo y crear su contraseña.
- Coordinación solo agrega docentes a su carrera. Si el correo ya existe, sea cual sea su
  estado, conserva la cuenta tal cual y solo le agrega el rol docente de esa carrera; si
  está pendiente y su enlace venció, se le envía uno nuevo a su propio correo. Si no existe,
  se crea y recibe el enlace de registro como en el alta de Administración. Coordinación
  no corrige nombre ni correo, no activa ni elimina cuentas: eso es de Administración.
  Si la invitación de un docente de su carrera venció sin aceptarse, Coordinación puede
  reenviarla (igual que Administración); mientras está vigente, nadie la reenvía. Un docente puede serlo de varias carreras y facultades.
- Administración y Biblioteca no pueden ser docentes ni coordinadores, en ningún orden
  (`RoleCombination`). Se valida en la aplicación al asignar un rol, no en la base.
- El correo solo se corrige, en Administración, mientras la cuenta está pendiente de
  activación. Corregirlo invalida el enlace anterior y envía otro.
- El docente no edita su nombre ni su correo; lo pide a Administración.
- Un rol se retira desde «Editar usuario». El único rol vigente no se retira: se
  desactiva la cuenta.

## Reglas de consulta

- Los filtros por alcance se aplican en la base de datos, no después de cargar.
- Fuera de alcance se responde `403` o `404` de forma coherente.
- Cada descarga vuelve a autorizar; una URL no da permiso.
- Los conteos del Panel respetan el mismo alcance que las filas.

## Pruebas mínimas por política

1. acción permitida en recurso propio y estado válido;
2. rol correcto con carrera incorrecta;
3. asignación ya no vigente;
4. recurso de otra carrera o paralelo;
5. estado que bloquea la acción;
6. usuario inactivo o sesión cerrada;
7. persona con dos roles usando cada uno;
8. listado, exportación y descarga sin filtrar datos ajenos.
