# Visión del producto

## Problema

Los sílabos se elaboran y revisan con archivos de oficina y mensajes sueltos. El docente
copia a mano datos académicos; la coordinación recibe archivos, devuelve comentarios y
controla versiones por su cuenta. Así es difícil saber qué versión vale, quién decidió
un cambio y si lo aprobado coincide con lo entregado.

## Producto

Aplicación web con inicio de sesión. Administración abre una convocatoria por período.
Cada sílabo nace de una plantilla institucional única y trae ya los datos de la malla,
del paralelo y del proyecto de carrera de la asignatura. El docente completa lo suyo y lo envía. Coordinación revisa el contenido y Biblioteca
verifica la bibliografía. Cada uno firma con su firma electrónica al decidir: el docente
al enviar, Coordinación y Biblioteca al aprobar. Al aprobarse, el sistema genera el PDF
con los tres sellos.

## Objetivo de valor

- un solo estado visible para cada sílabo;
- menos transcripción: los datos de malla, paralelo y proyecto de carrera llegan solos;
- plantilla configurable sin cambiar código ni esquema de base;
- devoluciones con comentario, no archivos de ida y vuelta;
- revisiones enviadas inmutables y comparables;
- PDF generado desde la revisión aprobada, con los sellos de las firmas;
- auditoría de las acciones importantes.

## Usuarios

| Rol | Qué hace |
|---|---|
| Administrador | Usuarios y roles, facultades y carreras, plantilla, convocatorias y auditoría. |
| Coordinador | Malla, paralelos, docentes y Proyecto de carrera de su carrera; inicia su convocatoria; revisa la etapa de coordinación y la aprueba firmando. |
| Docente | Elabora, corrige y envía el sílabo de sus paralelos; firma en el primer envío. |
| Bibliotecario | Verifica la bibliografía de toda la universidad: aprueba firmando o devuelve con motivos. |

Una persona puede tener varios roles, salvo Administrador, que es exclusivo. El detalle
está en `roles-and-permissions.md`.

## Alcance funcional

1. Usuarios, roles y estructura académica (facultades, carreras, malla, paralelos).
2. Plantilla del sílabo como datos (secciones, campos y bloques).
3. Proyecto de carrera: datos por asignatura, con importación mediante plantilla Excel.
4. Convocatoria por período y un sílabo por paralelo.
5. Elaboración con autoguardado, validación.
6. Revisión: coordinación y luego biblioteca; corrección y reenvío.
7. Firma electrónica al decidir y PDF automático al aprobar.
8. Avisos cuando hay que actuar, ayuda por pantalla, accesibilidad y auditoría.

## Fuera de alcance

- aula virtual (EVEA), matrículas, calificaciones u horarios;
- aplicación móvil nativa;
- escribir en sistemas institucionales externos;
- sustituir a las autoridades académicas;
- que la IA apruebe, rechace o bloquee un sílabo;
- desplegar para toda la UEB sin un piloto previo en la Carrera de Software.

## Indicadores de éxito

Se medirán con línea base e instrumentos aprobados:

- sílabos entregados y aprobados a tiempo;
- tiempo de elaboración, revisión y corrección;
- devoluciones por sílabo;
- éxito, tiempo y errores en pruebas de usabilidad;
- fidelidad del Word/PDF frente al formato oficial.

Los umbrales dependen de `PV-03`, `PV-04`, `PV-17` y `PV-18`
(`docs/plans/pendientes.md`).
