# Trabajo pendiente

Corte histórico del 30 de agosto de 2026, con actualización I-56 a continuación.
Este documento separa lo que falta según de quién
depende, porque son cosas de naturaleza distinta y no se resuelven igual.

## Retirada de fuentes y del revisor de IA, 2026-09-27 (I-146)

Por instrucción expresa del responsable del producto se retiran el CRUD de Fuentes
académicas, el requisito de fuentes para convocar y el revisor de IA basado en una
base vectorizada. Se conserva la importación documental I-145 y su evidencia interna,
la generación asistida de contenido/unidades mediante chat y la configuración técnica
de IA. La retirada no elimina datos históricos ni implica migración destructiva.

La implementación y verificación corresponden al plan I-146. Las tareas antiguas de
activar embeddings, reconstruir el índice o terminar el revisor dejan de ser pendientes
activos. PV-12/PV-13/PV-14/PV-18 siguen aplicando a los servicios de extracción y
elaboración que permanecen; no reabren el alcance retirado.

## Datos curriculares por asignatura, 2026-09-27 (I-144)

Primera unidad I-144: registro y confirmación manual de resultados de aprendizaje,
fotografía por sílabo y variables automáticas para la
plantilla. Matriz y avance en `active/I-144-analisis-datos-curriculares-y-autollenado.md`
y `active/I-144-matriz-calidad-software.md`. I-147 retiró la escritura manual; sus
datos antiguos permanecen como respaldo cuando no hay importación confirmada.

Pendiente de decisión: autoridad de cada fuente en conflicto, agrupación de contenidos
en unidades, cardinalidad de habilidades y significado de APE cero. La discrepancia
I-118 quedó resuelta el 2026-09-27 mediante la delegación del responsable del producto:
se conservan los aprobados y la revisión aprobada tras reapertura; las correcciones
confirmadas regeneran expedientes en curso sin aprobación conservada, incluidos los
enviados. En resultados de aprendizaje, el efecto se limita a su asignatura. Esta
resolución no constituye una aprobación del tutor ni de la institución.

## Importación curricular desde documentos, 2026-09-27 (I-145)

La corrección del responsable del producto establece documentos y revisión por lote
como entrada principal. Se implementan conceptos de texto/lista/tabla, valores por
carrera/asignatura, PDF/XLSX y documentos previos de ingesta, extracción con referencias,
confirmación selectiva, datos automáticos y opciones docentes obtenidas de una o
varias listas. I-147 deja este asistente como único recorrido de confirmación y muestra
los datos efectivos con procedencia; el formulario manual de I-144 fue retirado.
Estado y evidencia en
`active/I-145-asistente-importacion-curricular.md`.

Persistencia/aplicación tienen pruebas locales de aislamiento, concurrencia,
evidencia, discrepancias, regeneración y conservación de aprobados. La puerta completa,
la demostración con documentos reales y la revisión visual deben quedar registradas
en ese plan antes de declarar la entrega terminada. Esta implementación no prueba por
sí sola precisión de extracción ni vigencia académica.

Pendientes académicos: autoridad institucional de fuentes discrepantes, agrupación en
unidades, cardinalidad de habilidades y efecto de APE cero. La selección explícita
de un candidato permite resolver una importación concreta sin inventar esas reglas.
Pendiente funcional separado: generación asistida de la planificación a partir del
contexto confirmado. Nuevos conceptos de los tipos admitidos son configuración;
nuevos formatos de archivo o reglas académicas pueden exigir desarrollo.

## Paridad funcional y visual de la SPA, 2026-09-17 (I-87)

La auditoría posterior a I-85/I-86 confirmó que los componentes canónicos están
presentes, pero la adaptación distribuida todavía difiere en estados de carga,
redirección posterior al acceso, rutas históricas y composición de algunas páginas
consolidadas. I-87 establece la corrección página por página, skeletons basados en la
geometría final y un contrato único de navegación, sin recuperar Inertia ni ejecutar
Playwright local/preview. El plan está en
`active/I-87-paridad-funcional-visual-spa.md`.

## Corrección de paridad visual distribuida, 2026-09-16 (I-85)

I-84 separó correctamente la frontera API/SPA, pero sustituyó la interfaz Vue original
por una versión simplificada. I-85 porta la presentación canónica del monolito a la SPA
sin recuperar Inertia ni alterar la separación. El plan, matriz de pantallas y puertas de
aceptación están en `active/I-85-paridad-visual-spa-distribuida.md`.

## Actualización de separación distribuida, 2026-09-16 (I-84)

El código vigente ya no usa Inertia ni contiene frontend dentro de Laravel. La API vive
en `proyecto-grado-silabos-backend` y la SPA en `proyecto-grado-silabos-frontend`, con
contrato `/api/v1`, OpenAPI, Sanctum/CSRF e imágenes independientes. Las cifras de
archivos, pruebas, páginas y CI que aparecen más abajo son cortes históricos del
monolito y no deben interpretarse como inventario del código actual. El plan se cerró el
16 de septiembre de 2026 y su evidencia se conserva en
`completed/I-84-separacion-frontend-backend-api.md`.

## Actualización del editor, 2026-09-06 (I-56)

Implementado el editor acotado de plantilla: formato, celdas combinadas, variables,
campos, formulario docente y exportación DOCX. Sin laboratorio ni servidor de oficina.
La evidencia está en `active/I-56-editor-de-plantilla.md`. No se cierra la aceptación
institucional ni se certifican Word/PDF o dispositivos reales (PV-07/PV-19).

La puerta global actual no está verde: ESLint encuentra un archivo externo dentro de
`temp/.venv`; Prettier señala nueve archivos de otros módulos. La suite completa tiene
tres fallos de interfaz ajenos al incremento (placeholder de `ManagedUserSheet`,
iconos secundarios y estado vacío de `PeriodPreparationSheet`). Esos archivos no se
modificaron. Corresponde resolverlos en el mantenimiento de calidad general; no debilitar
sus pruebas ni borrar la carpeta externa. Los estados y conteos que siguen son el corte
histórico, no una afirmación sobre la puerta actual.

## Actualización de tablas, 2026-09-08 (I-57 e I-58)

La planificación admite cualquier cantidad de unidades y filas, valida semanas y horas
contra la malla y exporta cada unidad en orientación horizontal. Administración ya puede
definir desde la interfaz la repetición, cabecera, columnas, tipos, roles y totales de una
tabla; `@` identifica datos automáticos y `$` datos repetibles. La implementación y sus
recorridos Chromium están verificados. Quedan la revisión perceptiva/dispositivos reales
de PV-19 y la fidelidad institucional del DOCX de PV-07.

I-59 retiró después todo ese constructor de ADM-06. La página conserva únicamente la
hoja vacía; el código y los datos históricos permanecen temporalmente para no destruir
revisiones mientras se decide el reemplazo.

## 1. Código: sin deuda funcional conocida

No hay `TODO` ni `FIXME` nuevos en I-19. Formato, análisis estático, tipos, compilación y
las pruebas afectadas pasan. La puerta canónica `composer verify` pasó con PostgreSQL y
Redis levantados mediante Podman: **265 pruebas y 2536 aserciones**.

Los incrementos anteriores conservan su estado. I-16 implementa y verifica la edición
académica por alcance y la identidad administrada; permanece activo solo por la revisión
manual de interfaz que exige la Definition of Done.

I-18 instala y encapsula Vue Flow, implementa el constructor flexible y su alternativa
de formulario, y conserva activa la revisión manual del lienzo exigida por la Definition
of Done.

I-19 incorpora la selección explícita de carrera para Coordinación y el cambio de ámbito
desde el menú. I-20 reemplaza su colección de cards por una sola **Malla** actual por
carrera, editable activa o inactiva, con eliminación protegida y contexto académico
histórico en los sílabos.

I-15 cerró el último hueco funcional conocido: el plazo de la convocatoria ya tiene fecha
de inicio, Administración puede prorrogar la entrega con motivo y el vencimiento bloquea el envío; el relevo de un
docente es un acto único con sustento documental; y la coordinación encargada se distingue
de la titular.

## 2. Depende de una persona: revisión manual

Es el trabajo que queda del lado de los autores. No se puede automatizar y bloquea el
cierre de casi todos los planes, porque la Definition of Done lo exige.

| Qué                                            | Dónde aplica                 | Por qué no está hecho                                 |
| ---------------------------------------------- | ---------------------------- | ----------------------------------------------------- |
| Teclado, foco y lector de pantalla             | Las 29 páginas operativas    | Requiere una persona navegando                        |
| Contraste percibido, zoom 200 % y 360 px       | Todas las superficies        | La comprobación hecha es de cálculo, no de percepción |
| Claro y oscuro en dispositivos reales          | Toda la interfaz             | `PV-19` fija la matriz de navegadores                 |
| Prueba con usuarios `DT-07`                    | Revisión y aprobación (I-04) | Necesita docentes reales                              |
| Fidelidad del DOCX contra el documento oficial | Documentos (I-05)            | Depende de `PV-07`                                    |

El guion paso a paso está en `docs/quality/manual-review-script.md`.

La ejecución de CI en remoto dejó de estar pendiente el 2026-08-26: el flujo
`Verificación` corre en GitHub Actions sobre cada push a `main` y pasa en verde.

## 3. Depende de la UEB: 16 puertas abiertas

No son tareas de programación: son decisiones que solo la institución puede tomar. El
sistema está construido para no suplantarlas, y por eso muestra avisos donde una de ellas
sigue abierta.

| Puerta | Decisión                                                     | Quién decide                   |
| ------ | ------------------------------------------------------------ | ------------------------------ |
| PV-01  | Autoridad que emite, aprueba y publica la plantilla          | Coordinación/autoridad         |
| PV-02  | Precedencia entre malla, proyecto, guías y disposiciones     | Autoridad académica            |
| PV-03  | Periodo exacto de pilotaje y aceptación                      | Dirección/coordinación         |
| PV-04  | Responsable de aceptación funcional                          | Dirección/coordinación         |
| PV-05  | Cantidad real de docentes, paralelos, asignaciones y sílabos | Coordinación                   |
| PV-07  | DOCX oficial y reglas de exportación                         | Autoridad de plantilla         |
| PV-11  | Conservación, backup, RPO y RTO                              | Técnico/autoridad              |
| PV-12  | Base legal, finalidad y aviso de privacidad                  | UEB/datos                      |
| PV-13  | Hardware disponible para IA local                            | Personal técnico/autores       |
| PV-14  | Modelos locales de embeddings y generación                   | Autores/evaluación experta     |
| PV-15  | Correo institucional y contenido de avisos                   | Técnico/coordinación           |
| PV-16  | Edición excepcional de contenido por coordinador             | Coordinación                   |
| PV-17  | Instrumento y población para medir el proceso actual         | Dirección/autores              |
| PV-18  | Umbrales de utilidad y precisión de IA                       | Coordinación/docentes expertos |
| PV-19  | Navegadores y dispositivos reales                            | Técnico/usuarios               |
| PV-20  | Línea, sublínea, director, pares y fechas académicas         | Integración Curricular         |

`PV-09` y `PV-10` se cerraron en I-11 con el acceso al respaldo institucional. El riesgo
asumido al cerrarlas está anotado en `decisions-pending.md`: se sostienen en evidencia
técnica directa, no en confirmación escrita de la UEB.

## 4. Estado de los planes

Los planes siguen en `docs/plans/active/` a propósito. El propio criterio del proyecto lo
exige: _«Un plan no se mueve por código escrito; se mueve cuando cumple Definition of
Done»_, y la Definition of Done incluye la revisión manual de interfaz del punto 2.

| Plan       | Implementación         | Qué falta para cerrarlo                                        |
| ---------- | ---------------------- | -------------------------------------------------------------- |
| I-00       | completa               | CI ejecutada en remoto                                         |
| I-01       | completa               | revisión manual de interfaz                                    |
| I-02       | completa               | revisión manual y `PV-01`, `PV-02`, `PV-07`                    |
| I-03       | completa               | revisión manual y `PV-05`                                      |
| I-04       | completa               | revisión manual, `DT-07` y `PV-16`                             |
| I-05       | completa               | fidelidad del DOCX y `PV-07`, `PV-11`, `PV-12`, `PV-15`        |
| I-06       | revisor retirado por I-146 | Historial conservado; evaluación solo del chat y extracción vigentes |
| I-07       | retirada el 2026-08-27 | ninguna; el módulo se eliminó                                  |
| I-08       | completa               | pruebas con participantes y dispositivos reales                |
| I-09, I-10 | completas              | revisión manual de interfaz                                    |
| I-11       | completa               | ninguna; cerró `PV-09` y `PV-10`                               |
| I-12       | completa               | ninguna; solo renombrado                                       |
| I-13       | completa               | revisión manual de interfaz                                    |
| I-14       | completa               | ninguna; solo renombrado                                       |
| I-15       | completa               | revisión manual de interfaz; cerró `PV-06` y `DT-08` a `DT-11` |
| I-16       | completa               | revisión manual de interfaz                                    |
| I-18       | completa               | revisión manual del lienzo                                     |
| I-19       | completa               | revisión manual de cards, `Sheet`, foco y dispositivos reales  |
| I-20       | completa               | revisión manual de Malla, estados, foco y dispositivos reales  |
| I-40       | completa               | revisión manual de COR-14; DT-12 antes de automatizar el cierre de períodos |
| I-57, I-58 | completas              | revisión manual de interfaz; fidelidad PV-07 y dispositivos PV-19           |
| I-59       | completa                | revisión visual de la hoja vacía                                            |

## 5. Orden sugerido

1. Llevar a Coordinación `PV-07`, que aún bloquea declarar oficial el documento generado.
   `PV-08` quedó cerrada el 2026-09-08 y `PV-06` se cerró el
   2026-08-26 con la consulta registrada en
   `references/entrevista-2026-08-26-hallazgos.md`.
2. Resolver `PV-12` antes de acercar cualquier dato personal real al sistema.
3. Hacer la revisión manual de interfaz del punto 2, que desbloquea el cierre de diez
   planes a la vez.
4. Ejecutar la CI en remoto para cerrar I-00.
