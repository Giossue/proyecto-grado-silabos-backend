# I-85 — Simplificación de la estructura del sílabo

## Decisión de trabajo

Por solicitud explícita del responsable del producto, la estructura persistente se
simplifica sin convertir el contenido en un documento JSON opaco:

- `plantillas_silabo` conserva únicamente la raíz institucional, su configuración de
  documento y la revisión de configuración.
- `secciones_plantilla` y `bloques_plantilla` se unifican conceptualmente en
  `componentes_plantilla`, con `tipo` y `componente_padre_id`.
- `definiciones_campo` conserva la definición de los campos porque los valores, la IA,
  las validaciones y las exportaciones los referencian.
- `silabos` y `valores_campo` siguen separados: uno identifica el expediente y el otro
  almacena sus respuestas.
- Todos los campos son obligatorios al enviar el sílabo. Un borrador puede permanecer
  incompleto; por ello la obligatoriedad se aplica en la validación de envío y no como
  `NOT NULL` en cada valor.
- El flujo conserva `sin_iniciar`, `borrador`, `en_revision`,
  `correccion_solicitada` y `aprobado`. `iniciado_en` registra el inicio del trabajo;
  no se persiste ni se expone un porcentaje calculado por cantidad de campos llenos.

## Alcance

- Migración de datos y claves foráneas desde secciones/bloques hacia componentes.
- Modelos, acciones, requests, controladores, consultas, triggers y pruebas.
- Retiro de `nombre`, `descripcion`, `activo` y `obligatorio` donde ya no expresan una
  regla de negocio.
- Retiro de `porcentaje_completitud` del sílabo y de los resultados de validación; se
  conservan únicamente conteos y resultados que expliquen errores o advertencias.
- Actualización de documentación del esquema y trazabilidad.

## No incluido

- Guardar toda la plantilla o el contenido del sílabo en un único JSON.
- Cambiar el flujo de convocatorias, revisiones inmutables o decisiones de IA.
- Ejecutar la migración contra la base remota de producción.

## Trazabilidad

- I-32, I-33, I-52, I-59, I-72.
- RF-017..026; RN-009..012; CU-04; ADM-05..07.
- RNF-001, RNF-010, RNF-022 y RNF-025.

## Plan de ejecución

- [x] Añadir la migración compatible con los datos actuales.
- [x] Unificar los modelos y usos de secciones/bloques.
- [x] Aplicar la regla de campos obligatorios al envío y conservar el estado `borrador`.
- [x] Retirar el cálculo y transporte del porcentaje de llenado del sílabo.
- [x] Actualizar pruebas, contrato y documentación.
- [x] Ejecutar verificaciones proporcionales y revisar el diff.

## Verificación

- `php artisan test`: 268 pruebas pasan; queda aislada la prueba de Redis porque el
  servicio de pruebas no está disponible en `127.0.0.1:56379`.
- `./vendor/bin/pint --test`: correcto.
- `git diff --check` y `php -l` sobre los PHP modificados: correctos.
- Frontend: `npm run typecheck`, `npm run lint`, `npm run test -- --run` (81 pruebas) y
  `npm run contract:check`: correctos.
