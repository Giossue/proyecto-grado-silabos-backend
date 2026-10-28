# Principios de seguridad

1. **Denegar por defecto.** Cada ruta, consulta, mutación y descarga exige autorización.
2. **Alcance por registro.** El rol no basta: también cuentan carrera, asignación, vigencia
   y estado.
3. **Mínimo privilegio.** Usuarios, base, almacenamiento e integraciones reciben
   solo permisos necesarios.
4. **Defensa en profundidad.** Form Request, Policy, dominio y constraint protegen capas
   diferentes.
5. **Datos privados por diseño.** Archivos y contenido no viven en rutas públicas.
6. **Evidencia inmutable.** Revisiones, aprobaciones y auditoría no se sobrescriben.
7. **Secretos fuera del código.** Configuración por ambiente y rotación.
8. **Salida segura.** Escapar por defecto; nunca insertar HTML recibido.
9. **Asincronía controlada.** Jobs idempotentes, acotados y observables.
10. **IA no confiable.** Lo que recibe son datos, no instrucciones; su salida se valida y
    no decide.
11. **Privacidad/minimización.** Solo datos necesarios, propósito y retención definidos.
12. **Fallos seguros.** Un error no concede acceso, corrompe historial ni muestra internals.

OWASP ASVS 5.0 se usa como catálogo de referencia para elegir controles aplicables. No se
afirma conformidad sin evidencia. Los controles concretos están en `hardening.md` y
`threat-model.md`.

