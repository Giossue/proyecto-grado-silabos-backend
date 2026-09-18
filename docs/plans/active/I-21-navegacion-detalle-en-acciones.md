# I-21: Navegación a detalle desde Acciones

## Estado

En curso.

## Trazabilidad

- RNF-001..036 como cobertura transversal de usabilidad y accesibilidad.
- CU-04, CU-06 y CU-07; ADM-05, COR-02 y DOC-02.
- Los CP individuales no constan formalmente en el repositorio.
- No depende de una decisión `POR VALIDAR`: no modifica permisos, estados, rutas ni
  reglas de negocio.

## Resultado demostrable

Los nombres y versiones de las filas no navegan al detalle. Cada tabla presenta la
acción aplicable dentro de su menú `TableActionsMenu` de tres puntos. El seguimiento de
convocatorias se abre en un `Sheet` lateral, sin una página de detalle separada.

## Cambios previstos

- Frontend: agregar la columna Acciones a Convocatorias, Mis sílabos y Plantillas;
  conservar los enlaces tipados dentro de su menú compartido y abrir el seguimiento de
  convocatorias en un `Sheet` lateral.
- Pruebas: proteger que las acciones aplicables se abran desde Acciones.
- Datos, backend, seguridad/auditoría y trabajos: sin cambios.

## Pruebas

- Formato, ESLint y comprobación de tipos de Vue.
- Prueba arquitectónica del patrón de acciones.
- Build de producción Vite.

## Pasos

- [x] Inventariar las tablas con navegación directa a detalle.
- [x] Mover las acciones de detalle al menú Acciones compartido.
- [x] Sustituir el detalle de convocatoria por un `Sheet` de seguimiento y retirar su ruta.
- [ ] Actualizar trazabilidad y verificar.

## Riesgos y reversión

- El `Sheet` conserva la misma consulta y autorización de servidor; solo cambia la
  presentación y el disparador.
- Revertir restaura la página de detalle, sin migraciones ni efectos persistentes.
