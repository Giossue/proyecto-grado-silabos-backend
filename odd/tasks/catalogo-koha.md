# Búsqueda de libros en el catálogo Koha

Objetivo: el docente busca un libro en el Koha de la biblioteca y se llena la fila de bibliografía.
Rama: `main` (política del repositorio). Pruebas: `php artisan test` (Pest) y `npm test` en el frontend.
Estado: **POR VALIDAR** con la biblioteca (PV-22). Trazabilidad: RF-037 a RF-044, CU-07, DOC-04.

## Specs

- S1. Al llenar la bibliografía, el docente busca un libro por autor, título o ISBN en el catálogo
  Koha y se completan sus datos. Solo se extraen datos de los libros; no se leen préstamos ni
  ejemplares.
- S2. Solo lectura: no se escribe en Koha ni se guardan sus datos fuera del sílabo. Es la única
  excepción a «sin integración con sistemas institucionales» (aprobada por el usuario el 2026-10-31).
- S3. Detrás del contrato `BibliographicCatalog`, como `AiChatGateway`. `CATALOG_DRIVER` vale
  `disabled` por defecto; `koha` usa las consultas públicas del OPAC (`opac-search.pl` en RSS y
  `opac-export.pl` en MARCXML). Si la UEB concede un usuario de API, solo cambia la implementación.
- S4. El docente nunca llama a Koha: llama a `GET /api/v1/teacher/library-catalog/search` y el
  servidor consulta. La dirección de Koha sale de la configuración, no de la petición.
- S5. Campos `author`, `title`, `isbn` y `any`. Cada palabra es un prefijo y todas deben aparecer.
  Resultados de 20 en 20 (`page` de 1 a 100) con el total que informa Koha.
- S6. Cada facultad guarda sus bibliotecas de Koha (`facultades.bibliotecas_koha_facultad`; vacío:
  todas). Administración las elige con casillas desde la lista pública de Koha: pestaña «Bibliotecas»
  al editar y segundo paso al crear. Filtro `AND (branch:X OR branch:Y)`; la signatura sale del
  ejemplar de esa biblioteca.
- S7. El resultado trae autor, título, año, ciudad, editorial, ISBN y signatura. El docente elige y
  la fila se llena en la pantalla. «Código» es el tipo de libro: «Libro físico» (todo lo de Koha) o
  `e-libro` en los digitales, que no están en Koha.

## Tasks

| ID | Specs | Ruta | Estado | Evidencia |
|---|---|---|---|---|
| T1 | S1, S5 | inline | hecho | Koha local probado: autor, título, ISBN y palabra clave |
| T2 | S2, S3 | inline | hecho | Contrato, datos y excepción en `Library/Domain` |
| T3 | S3 | inline | hecho | `Disabled` y `KohaPublic` en `Library/Infrastructure/Gateways` |
| T4 | S3 | inline | hecho | `config/catalog.php`, `.env.example`, `AppServiceProvider` |
| T5 | S4, S5 | inline | hecho | Ruta, permiso de docente, límite, solicitud y controlador |
| T6 | S1–S6 | inline | hecho | Pruebas con `Http::fake` (búsqueda, MARCXML, errores, permisos, límites) |
| T7 | S4 | inline | hecho | OpenAPI regenerado y documentación |
| T8 | S1, S5 | inline | hecho | Probado contra el Koha local y el catálogo público de la UEB |
| T9 | S7 | inline | hecho | Frontend `746ee58`, `fd6f771`, `3470804` |
| T10 | S2 | — | bloqueado | Acceso de la biblioteca (PV-22) e IP del servidor permitida por TI |

## Log

- L1 (2026-10-31, pedido del usuario, reconstruido del plan anterior): que el docente, al llenar la
  bibliografía, busque un libro por autor, título o ISBN en el catálogo Koha de la biblioteca y se
  completen sus datos.
- L2 (biblioteca): API con usuario de solo lectura «pronto»; HTTPS «probablemente sí». Hoy solo
  responde por `http`.
- L3 (2026-11-01): el servidor de pruebas (Hostinger, Brasil, IP `187.127.6.234`) no llega a Koha:
  la conexión se agota a los 15 s. Desde Ecuador sí responde. Hay que pedir a TI de la UEB que
  permita esa IP.
- L4 (2026-11-02): migrado de `docs/plans/active/` al formato ODD.
- Siguiente paso: al cerrar T10, borrar este documento y dejar la decisión en `DECISIONS_STATUS.md`.
