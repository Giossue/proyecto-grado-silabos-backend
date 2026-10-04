# FE-LOAD-2026-10-04-CONFIG: Carga completa de Configuración

## Estado

En curso. Complementa FE-LOAD-2026-10-04 tras la pantalla omitida señalada por el usuario.

## Trazabilidad

RF-001..007; RNF-018..023; RN-001..004; CU-01/02; UI-04;
CP-F identidad/segundo factor y CP-N experiencia/accesibilidad; PV-19.

## Resultado y decisiones

Configuración conserva su encabezado y navegación. Seguridad presenta el esqueleto del
formulario de contraseña y del segundo factor mientras consulta este último; después
de responder, incluso con error, revela el contenido y el aviso correspondiente.
Perfil representa sus campos mientras la sesión no tiene todavía sus datos.
Se conserva la consulta única existente y la autorización de identidad. No se agregan
esperas artificiales, requisitos académicos ni decisiones pendientes.

## Pasos y verificación

- [ ] Componer los campos y ayudas de Perfil y Seguridad con Skeleton local.
- [ ] Integrar la carga de la pantalla con el estado real de sesión/segundo factor.
- [ ] Probar carga, respuesta y error, consulta única, navegación estable, responsive,
  temas y movimiento reducido; ejecutar lint, tipos y pruebas afectadas.
- [ ] Actualizar fuentes de interfaz y trazabilidad con la evidencia.

## Riesgos y reversión

La finalización de la consulta de segundo factor debe liberar el formulario de
contraseña incluso si falla. Revertir los componentes de presentación y su evento
restablece la composición previa; no se modifican datos ni contratos de la API.

## Evidencia

Pendiente.
