# Demostración del sistema

Recorrido completo con las cuatro cuentas del seeder: Administración prepara plantilla y
convocatoria, Coordinación prepara la carrera y revisa, Docente elabora y envía,
Biblioteca aprueba la bibliografía y los tres firman. Usa solo datos sintéticos y la base
local.

## 1. Preparación

`migrate:fresh` borra toda la base. Antes de ejecutarlo, compruebe que `.env` apunta a la
base local (`127.0.0.1:55432`, base `silabos_ueb`).

Backend:

```bash
cp .env.example .env
composer install
docker compose up -d
php artisan key:generate
php artisan migrate:fresh --seed --force
php artisan db:seed --class=SyllabusTemplateSeeder --force
```

El segundo seeder carga la plantilla oficial (`resources/templates/silabo-oficial.json`).

Opcional en `.env`, antes de levantar los procesos:

- `AI_DRIVER=baseline`: el asistente de unidades usa un simulador que responde con texto
  fijo, sin proveedor externo.
- La ficha se importa con la plantilla Excel descargada desde el sitio. No requiere
  proveedor de IA, OCR ni cola.
- `GOTENBERG_URL=http://127.0.0.1:3000` con `docker run --rm -p 3000:3000 gotenberg/gotenberg:8`:
  el PDF sale igual al Word. Sin Gotenberg el PDF es de texto simple.
- `QUEUE_RETRY_AFTER` debe superar los 120 s de la exportación; el valor
  predeterminado de 960 s cumple esa condición.

Levante dos procesos en terminales separadas:

```bash
php artisan serve
```

```bash
php artisan queue:work \
  --queue=critica,documentos,ia,general --tries=3
```

`composer dev` también procesa estas colas: `AppServiceProvider` reemplaza su `queue:listen`
por uno con `--queue=critica,documentos,ia,general`.

Frontend, en el repositorio `proyecto-grado-silabos-frontend`:

```bash
cp .env.example .env    # VITE_API_URL=http://localhost:8000
npm ci
npm run dev
```

Abra `http://localhost:5173`.

## 2. Cuentas y datos sembrados

Todas usan la contraseña `Demo-2026!` (`database/seeders/DatabaseSeeder.php`).

| Rol           | Nombre             | Correo                       | Alcance           |
| ------------- | ------------------ | ---------------------------- | ----------------- |
| Administrador | Administrador Demo | `admin@silabos.test`         | Toda la institución |
| Coordinador   | Coordinadora Demo  | `coordinador@silabos.test`   | Carrera Software  |
| Docente       | Docente Demo       | `docente@silabos.test`       | Carrera Software  |
| Bibliotecario | Bibliotecaria Demo | `bibliotecario@silabos.test` | Todas las carreras |

El seeder crea además: Facultad de Ciencias de la Ingeniería (campus Matriz), carrera
Software (`SOFTWARE`, 8 ciclos), período `2026-2027` (1 de mayo de 2026 a 31 de marzo de
2027), la materia `SW-601` Arquitectura de Software (ciclo 6) y su paralelo `A` matutino
con Docente Demo asignado.

## 3. Recorrido

### Administración: logo, plantilla y convocatoria

1. Ingrese con `admin@silabos.test`.
2. En **Facultades y carreras**, edite la facultad y cargue su logo (PNG sin fondo). Sin
   logo, Coordinación no puede abrir su convocatoria.
3. En **Plantilla**, revise las secciones y campos. Cada campo indica **Quién llena**:
   Docente, Carrera (ficha) o Fijo. Abra **Opciones avanzadas → Editar JSON** para ver la plantilla como dato:
   permite **Descargar**, **Cargar archivo** y **Aplicar y reemplazar**. Si la plantilla
   aparece vacía, cargue ahí `resources/templates/silabo-oficial.json`.
4. En **Convocatorias**, el período `2026-2027` aparece sin convocatoria. En sus acciones
   elija **Abrir convocatoria**, ponga **Inicio de la elaboración** (hoy) y una **Fecha
   límite de entrega** futura, y confirme.
5. Opcional: **Usuarios y roles** (alta manual o importación Excel), **Auditoría** y
   **Ayuda de pantallas**.

### Coordinación: carrera lista y apertura

1. Ingrese con `coordinador@silabos.test`. El **Panel** muestra lo que falta para abrir la
   convocatoria de la carrera.
2. En **Malla** y **Materias y asignaciones**, compruebe `SW-601`, su paralelo `A` y el
   docente asignado. Para probar la copia entre paralelos, cree el paralelo `B` y asígnele
   también a Docente Demo.
3. En **Proyecto de carrera**, abra la **Ficha** de `SW-601` y llene todos sus campos. En
   un campo active **El docente puede editarlo**. Con IA configurada, pruebe también
   **Importar desde Excel**, descargue la plantilla, complete el contenido y revise
   el archivo antes de aplicar.
4. En el **Panel**, pulse **Abrir convocatoria de la carrera**. Se crea un sílabo por
   paralelo y el docente recibe un aviso.

### Docente: elaborar y enviar

1. Ingrese con `docente@silabos.test` y abra **Mis sílabos** → `SW-601` paralelo `A`.
2. Llene los campos; se guardan solos. Los campos de la ficha llegan llenos y solo se
   editan los marcados por Coordinación. Revise la **Vista previa**.
3. Opcional: **Asistente de unidades** (requiere `AI_DRIVER`) y, en el paralelo `B`,
   **Copiar desde paralelo**.
4. Pulse **Enviar a revisión** y confirme. Si falta algún campo, el sistema lo señala y
   no envía.

### Coordinación: revisión

1. En **Revisión**, abra la revisión 1.
2. Pulse **Solicitar corrección** con un comentario. Como Docente, corrija y reenvíe: se
   crea la revisión 2. Como Coordinación, use **Comparar con la anterior**.
3. Pulse **Aprobar revisión**. El sílabo pasa a Biblioteca.

### Biblioteca: bibliografía

1. Ingrese con `bibliotecario@silabos.test` y abra **Revisiones de biblioteca**.
2. Pruebe **Devolver al docente** marcando motivos. El docente corrige y reenvía; el
   sílabo vuelve directo a Biblioteca.
3. Pulse **Aprobar bibliografía**. El sílabo queda aprobado y el worker genera el Word y
   el PDF en la cola `documentos`.

### Firmas

El orden es Docente → Coordinación → Biblioteca. Cada uno firma desde su pantalla del
sílabo con **Firmar electrónicamente**: elige su archivo `.p12` y escribe la clave. La
firma se hace en el navegador; al servidor solo llega el PDF firmado. El servidor
comprueba que la firma sea íntegra y que el titular del certificado coincida con el
nombre de la cuenta.

Para la demostración, cree certificados de prueba cuyo titular coincida con cada cuenta:

```bash
openssl req -x509 -newkey rsa:2048 -sha256 -days 30 -nodes \
  -subj "/C=EC/O=ENTIDAD DE PRUEBA/CN=DOCENTE DEMO" \
  -keyout docente.key -out docente.crt
openssl pkcs12 -export -inkey docente.key -in docente.crt \
  -passout pass:clave-docente -out docente.p12
```

Repita con `COORDINADORA DEMO` y `BIBLIOTECARIA DEMO`. Deje
`SIGNATURE_TRUSTED_CA_BUNDLE` vacío: con el archivo de entidades de Ecuador, un
certificado hecho en casa se rechaza.

Con las tres firmas, **Documentos** permite descargar el Word y el PDF firmado. Antes no
se descarga nada.

## 4. Qué comprobar

- Cada rol ve solo su menú y su alcance.
- Cada envío crea una revisión nueva y las anteriores no cambian.
- Un sílabo aprobado no se edita.
- Word y PDF se generan sin acción manual; la descarga exige las tres firmas.
- Con `AI_DRIVER=disabled` la importación Excel funciona; solo el asistente de IA queda desactivado.
- **Auditoría** registra los pasos del recorrido.

Anote fecha, versión del código, rol de cada paso y resultado. No registre contraseñas,
cookies, claves de certificados ni capturas con datos personales.
