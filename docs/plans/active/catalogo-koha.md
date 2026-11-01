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
4. Campos de búsqueda: `author`, `title`, `isbn` y `any` (palabra clave). Cada palabra es un prefijo y todas deben
   aparecer. Resultados de 20 en 20 (`page` de 1 a 100) con el total que informa Koha.
5. Cada facultad guarda las bibliotecas de Koha donde buscan sus docentes (`facultades.bibliotecas_koha_facultad`,
   vacío: todas). Administración las elige con casillas por nombre desde la lista pública de Koha: pestaña «Bibliotecas» al editar y segundo paso al crear (todas marcadas = todas). El filtro es
   `AND (branch:X OR branch:Y)` y la signatura sale del ejemplar de esa biblioteca.
6. El resultado trae autor, título, año, ciudad, editorial, ISBN y signatura. El docente elige
   y la fila de la tabla se llena en la pantalla (repositorio del frontend).

## Pendiente

- Pantalla en el repositorio del frontend: caja de búsqueda con selector (Autor, Título, ISBN, Todo),
  lista de resultados y llenado de la fila de la tabla de bibliografía.
- Acceso de la biblioteca (PV-22). Al cerrarlo se borra este plan y la decisión queda en
  `DECISIONS_STATUS.md`.

### De la biblioteca

- API con usuario de solo lectura: «pronto». HTTPS: «probablemente sí». Hoy solo responde por `http`.
- El servidor de pruebas (Hostinger, Brasil, IP `187.127.6.234`) no llega a Koha: la conexión se agota a los
  15 s (prueba del 2026-11-01). Desde Ecuador sí responde. Hay que pedir a TI de la UEB que permita esa IP.
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
