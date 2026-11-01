# Búsqueda de libros en el catálogo Koha

Pedido del usuario (2026-10-31): que el docente, al llenar la bibliografía, busque un libro por
autor, título o ISBN en el catálogo Koha de la biblioteca y se completen sus datos. Solo se
extraen datos de los libros; no se leen préstamos ni ejemplares.

Trazabilidad: RF-037 a RF-044 (elaborar el sílabo), CU-07, DOC-04. Cambia el alcance: antes
no había integración con sistemas institucionales; el usuario aprobó una excepción de solo
lectura (2026-10-31).

Estado: **POR VALIDAR** con la biblioteca. La UEB todavía no concede el acceso; se prueba con
el Koha local (`~/Documentos/koha-lab`) y con las consultas públicas del catálogo real.

## Decisiones

1. Solo lectura. No se escribe en Koha ni se guardan sus datos fuera del sílabo.
2. Detrás de un contrato del dominio, `BibliographicCatalog`, igual que `AiChatGateway`.
   `CATALOG_DRIVER` vale `disabled` por defecto; `koha` usa las consultas públicas del OPAC
   (`opac-search.pl` en RSS y `opac-export.pl` en MARCXML). Si la UEB concede un usuario de API,
   solo cambia la implementación.
3. El docente nunca llama a Koha: llama a `GET /api/v1/teacher/library-catalog/search` y el
   servidor consulta. La dirección de Koha sale de la configuración, no de la petición.
4. Campos de búsqueda: `author`, `title`, `isbn` y `any` (palabra clave).
5. El resultado trae autor, título, año, ciudad, editorial, ISBN y signatura. El docente elige
   y la fila de la tabla se llena en la pantalla (repositorio del frontend).

## Pendiente

- Pantalla en el repositorio del frontend: caja de búsqueda con selector (Autor, Título, ISBN, Todo),
  lista de resultados y llenado de la fila de la tabla de bibliografía.
- Acceso de la biblioteca (PV-22). Al cerrarlo se borra este plan y la decisión queda en
  `DECISIONS_STATUS.md`.

### De la biblioteca

- API con usuario de solo lectura: «pronto». HTTPS: «probablemente sí». Hoy solo responde por `http`.
- «Código» quedó resuelto con el sílabo de referencia: es la signatura (`e-libro` en los digitales, que
  no están en Koha).

## Pasos

- [x] Probar el Koha local: búsqueda por autor, título, ISBN y palabra clave.
- [x] Contrato, datos y excepción en `Library/Domain`.
- [x] Implementaciones `Disabled` y `KohaPublic` en `Library/Infrastructure/Gateways`.
- [x] Configuración `config/catalog.php`, `.env.example` y enlace en `AppServiceProvider`.
- [x] Ruta, permiso de docente, límite de peticiones, solicitud y controlador.
- [x] Pruebas con `Http::fake` (búsqueda, MARCXML, errores, permisos, límites).
- [x] OpenAPI regenerado y documentación (decisión, alcance, módulos, pendientes).
- [x] Probar contra el Koha local y contra el catálogo público de la UEB.
