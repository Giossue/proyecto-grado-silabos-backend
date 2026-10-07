# Roles, alcance y permisos

## Modelo

El rol es solo una dimensión. La autorización efectiva se calcula como:

```text
usuario activo
AND rol activo
AND alcance académico permitido
AND asignación vigente, cuando aplique
AND acción válida para el estado
AND recurso perteneciente al rol activo
```

Todas las condiciones se verifican en el servidor. La navegación y los botones reflejan
el permiso, pero no lo sustituyen.

## Matriz base

| Capacidad | Administrador | Coordinador | Docente | Bibliotecario |
|---|---:|---:|---:|---:|
| Gestionar usuarios/roles | Sí | No | No | No |
| Crear/incorporar docentes (I-54) y reenviar su invitación pendiente | Sí, sin cambios | Solo rol Docente pendiente de su carrera activa | No | No |
| Corregir nombre o correo de una cuenta | Sí | Solo docentes vigentes de su carrera activa (I-170); el correo, mientras esté pendiente de activación | No | No |
| Crear, editar y eliminar sin dependencias facultades, carreras y catálogos globales | Sí | Consulta de alcance | Consulta asignada | No |
| Cargar o reemplazar el logo de una facultad (I-123/I-125) | Sí; obligatorio al crearla | No | No | No |
| Asignar coordinación a una carrera | Sí | No | No | No |
| Gestionar mallas y materias | No por defecto | Sí, en su carrera, salvo con convocatoria en curso | Consulta asignada | No |
| Editar ficha académica de una materia (I-187) | Sí, en cualquier carrera desbloqueada | Sí, solo en su carrera desbloqueada | No | No |
| Gestionar materias programadas y paralelos | No por defecto | Sí, en su carrera, salvo con convocatoria en curso o período finalizado | Consulta asignada | No |
| Asignar docentes a paralelos | No por defecto | Sí, en su carrera, salvo con convocatoria en curso o período finalizado | Consulta propia | No |
| Diseñar/publicar plantilla | Sí, salvo con proceso abierto | Consulta/aporte según política | No | No |
| Importar documentos y confirmar datos curriculares (I-145/I-146) | No participa | Sí, en su carrera, con los bloqueos de corrección vigentes | Consulta del contexto de sus sílabos | No |
| Crear y abrir, pausar, reanudar y cerrar el proceso de sílabos | Sí; confirmar el reinicio de todos los estados si cambió la plantilla durante la pausa | Consulta al convocar | No | No |
| Iniciar alcance de su carrera desde convocatoria institucional abierta | No | Sí, si su malla, materias programadas, paralelos y docentes están listos | No | No |
| Pausar/reanudar convocatoria | No | Sí, en su carrera | No | No |
| Cerrar (sobre el proceso; detiene a todas las convocatorias) | Sí | No | No | No |
| Prorrogar fecha límite de entrega (sobre el proceso; alcanza a todas las convocatorias) | Sí | No | No | No |
| Eliminar convocatoria institucional sin actividad docente | Sí | No | No | No |
| Elaborar contenido | No por defecto | No por defecto | Sí, asignado y editable | No |
| Chat para elaborar contenido y unidades | Configuración técnica y campos legibles | No por defecto | Sí, en su sílabo y contexto autorizado | No |
| Enviar/reenviar | No | No | Sí, asignado | No |
| Observar/solicitar corrección | No | Sí, en etapa coordinación | No; corrige y reenvía (I-120) | Comentar en etapa biblioteca |
| Comparar revisiones | Auditoría | Sí | Sí, si es propio/autorizado | No |
| Aprobar | No | Sí | No | No |
| Exportar | Según permiso | Sí en alcance | Sí, propio/autorizado | Consulta del PDF enviado |
| Consultar auditoría | Sí, permiso explícito | Vista limitada | No por defecto | No |
| Revisar la bibliografía al enviar: aprobar o comentar (I-165, I-173) | No | No | No; su envío llega primero a biblioteca | Sí, en toda la universidad |
| Firmar el PDF aprobado (orden docente → coordinación → biblioteca) | No | Sí, en su carrera | Sí, propio | Sí |

I-146 retira la gestión pública de Fuentes académicas y las operaciones del revisor de
IA. La descarga de originales de importación exige carrera activa y pertenencia del
documento a esa importación. El historial conservado no concede permisos nuevos.

`PV-16` decide si el coordinador puede editar excepcionalmente contenido docente. Hasta
resolverlo, la implementación debe negar esa edición. Si se autoriza, exigirá motivo,
permiso específico, señal visible y auditoría detallada.

Gestionar materias o asignaciones docentes no concede permiso para alterar el contenido
de un sílabo. El Administrador conserva la creación de cuentas y gestión de roles. Como
excepción acotada (I-54), el Coordinador puede crear o incorporar docentes a su carrera
activa desde COR-15. El servidor fija rol y carrera. Si el correo ya existe, conserva
identidad, contraseña y otros roles; no reactiva cuentas ni accesos docentes retirados.
El alta no asigna paralelos: esa acción mantiene sus bloqueos e historial actuales.
El nombre y el correo son datos administrativos de la cuenta: el Administrador los corrige
en cualquier cuenta y, desde I-170, el Coordinador solo en los docentes vigentes de su
carrera activa (COR-15, **Editar docente**) con las mismas reglas: el correo cambia solo
mientras la cuenta esté pendiente de activación y el cambio invalida la invitación
anterior y emite otra. El Docente no modifica su propio perfil; lo solicita a su
coordinación o a Administración.
La edición administrativa de catálogos conserva el valor anterior y el nuevo en auditoría;
no concede al Administrador permisos sobre mallas, materias o contenido docente.
Si el dato alimenta sílabos del proceso vigente, primero se pausa el alcance y el cambio
usa el reinicio confirmado de I-118. Corregir nombre o correo de una cuenta no reinicia
sílabos: las revisiones enviadas ya conservan la identidad fotografiada; desactivar un
docente con trabajo en curso sigue bloqueado y los relevos de docentes o coordinadores
usan sus operaciones atómicas específicas.

## Bibliotecario (I-165, I-173, I-192)

El rol `bibliotecario` no tiene carrera: una cuenta con solo ese rol entra directo. Es la
**primera etapa** de revisión: cada envío del docente crea un registro en
`envios_biblioteca` y llega a su bandeja. Si los libros están, **aprueba la bibliografía**
y el sílabo pasa a coordinación; si no, **comenta** y el sílabo vuelve al docente en
«Corrección solicitada» (todo el sílabo editable); al reenviar, vuelve a biblioteca.
Firma el PDF en tercer lugar, después de docente y coordinación. Administración crea estas
cuentas sin carrera. El rol se acumula con Coordinador o Docente, pero no con
Administrador.

## Acumulación de roles

Una persona puede ser coordinadora de varias carreras y también docente. Cada asignación
de rol conserva su propia carrera y Administración la retira desde «Editar usuario» (I-197) al
finalizarla; nunca se retira el único rol vigente (se deshabilita el acceso). Administración
es una excepción exclusiva: una cuenta con el rol `administrador` activo no puede tener
`coordinador` ni `docente` activos, y tampoco se puede conceder `administrador` mientras
conserve otro rol activo. Las asignaciones finalizadas se conservan como historial.
Al iniciar como Coordinador o Docente debe elegir su ámbito incluso si solo tiene una
asignación activa (I-121); Administración y Bibliotecario entran directo cuando tienen
un único rol. Durante la sesión
puede cambiar de carrera o rol desde el menú de usuario. Solo existe un ámbito activo a la
vez. Abrir un aviso puede activar otro ámbito de la misma persona: el servidor elige, entre
sus asignaciones vigentes, la que corresponde al aviso, registra
`rol_activo.seleccionado` y cada pantalla sigue aplicando sus políticas.

Una carrera solo puede tener una coordinación ejercible: una asignación de rol
`coordinador` activa cuya cuenta también está activa. Para cambiarla, Administración usa
el relevo atómico; no se crea una tabla de "coordinadores" adicional.

No se combinan privilegios de forma implícita para evadir la separación de
responsabilidades. Cada selección sustituye el ámbito de sesión y el evento de auditoría
registra la asignación usada.

## Reglas de consulta

- Las consultas se filtran en base de datos, no después de cargar resultados.
- Un `404` o `403` se elige de forma coherente para no revelar existencia fuera de alcance.
- Descargas reevalúan autorización en cada solicitud; una URL no concede permiso.
- Reportes y conteos respetan el mismo alcance que sus filas de detalle.
- El administrador no obtiene automáticamente facultad académica de aprobación.

## Pruebas mínimas por política

1. acción permitida en recurso propio y estado válido;
2. rol correcto con alcance incorrecto;
3. asignación vencida;
4. recurso de otra carrera/asignación;
5. estado que bloquea la acción;
6. usuario inactivo o sesión revocada;
7. persona con dos roles usando cada uno;
8. consulta masiva, exportación y descarga sin filtración lateral.
