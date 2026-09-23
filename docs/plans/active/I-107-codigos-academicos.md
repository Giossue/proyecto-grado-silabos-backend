# I-107: Simplificar los códigos de facultad, carrera y malla

## Estado

Listo local; migración remota pendiente de resolver objetivo y release.

## Trazabilidad

- RF-007..016; RN-005..008; CU-02..03; ADM-04; COR-13; CP-F estructura académica.
- Sin dependencia de PV: instrucción explícita del responsable del producto el
  2026-09-22.

## Resultado demostrable

Facultades no tienen código. Carreras tienen `codigo_carrera` obligatorio y único. La
estructura curricular conserva su cantidad obligatoria de ciclos, sin código de malla.
Los formularios y contratos reflejan estas reglas.

## Decisiones y supuestos

- Esta instrucción reemplaza la regla de I-82 que exigía un código de malla por carrera.
- Se conserva la unicidad de `codigo_carrera`; se vuelve obligatoria en validación y BD.
- La migración aborta si encuentra carreras sin código; no inventa códigos para datos
  existentes.
- Los valores de código presentes en `silabos.contexto_academico` se conservan sin
  modificar en sílabos existentes; el contexto nuevo ya no incluye código de malla.

## Cambios previstos

- Dominio: Facultad no tiene código; Carrera requiere código único; la estructura
  curricular se identifica por la carrera y su cantidad de ciclos.
- Backend: retirar códigos de facultad y malla de modelos, casos de uso, API y auditoría.
- Datos: nueva migración que elimina `facultades.codigo_facultad` y
  `carreras.codigo_malla`, y aplica `NOT NULL` a `carreras.codigo_carrera`.
- Frontend: quitar el código del formulario y listado de facultad; exigir código de
  carrera; retirar el campo de código de malla del alta y configuración curricular.
- Seguridad/auditoría: mantener validación de unicidad en servidor y en PostgreSQL.
- Trabajos/integraciones: los datos históricos de contexto de sílabo no se reescriben.

## Pruebas

- Estructura SQL, solicitudes de alta/edición, API curricular y contextos académicos
  nuevos de sílabo.
- Typecheck, lint, pruebas de componente y verificación de contrato OpenAPI.

## Pasos

- [x] Registrar la decisión explícita y comprobar referencias en ambos repositorios.
- [x] Actualizar persistencia, validación, API y formularios.
- [x] Agregar la migración sin editar migraciones aplicadas.
- [x] Actualizar documentación y trazabilidad.
- [x] Ejecutar las verificaciones aplicables y registrar evidencia.

## Riesgos y reversión

- El DDL elimina los valores actuales de código de facultad y malla. Requiere respaldo
  previo en cualquier entorno con datos.
- La migración aborta antes de eliminar columnas si una carrera existente carece de
  código obligatorio.
- La migración es irreversible; restaurar esos valores exige recuperar el respaldo.

## Evidencia de cierre

- Laravel: 60 pruebas y 592 aserciones focalizadas aprobadas; después se reejecutó
  `AcademicStructureTest` (13 pruebas, 69 aserciones) con la comprobación de que la API
  ya no devuelve `curriculum.code`; `Pint --test` aprobado.
- Vue: 3 pruebas Vitest, typecheck, ESLint y build aprobados; `contract:check` confirma
  125 consumidores SPA frente a 128 operaciones OpenAPI.
- `OpenApiContractTest` pasó dentro de la suite de backend.
- `composer types:check` mantiene 14 errores de PHPStan en archivos ajenos a I-107.
- La migración se ejecutó al migrar el esquema aislado de pruebas. No se verificó ni tocó
  la base remota; `000094` queda para el release actualizado porque una versión anterior
  de la aplicación puede consultar las columnas que se retiran. Seguir el runbook de
  Dokploy al desplegarlo.
- Conflicto pendiente para la operación remota: las instrucciones `AGENTS.md` señalan
  `calidad_software` como objetivo; el runbook de Dokploy describe `silabos_ueb_db` y su
  rol de migración. Resolver el objetivo y el rol sin usar `admin_root` antes de ejecutar
  DDL remoto.
