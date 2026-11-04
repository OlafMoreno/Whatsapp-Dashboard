# Tareas de WhatsApp

Aplicación para que los mensajes entrantes de WhatsApp Cloud API se guarden como tareas en MariaDB y aparezcan en el dashboard del equipo. La aplicación, Apache, PHP, el proxy Nginx y la base de datos se ejecutan en contenedores Docker. Bootstrap se sirve localmente, sin depender de un CDN. No necesitas instalar PHP, Node.js, npm ni un proxy en Windows.

## Iniciar en Windows

1. Instala y abre Docker Desktop.
2. Desde PowerShell, en la carpeta del proyecto, crea el archivo de configuración:

   ```powershell
   Copy-Item .env.example .env
   ```

3. Abre `.env` con el Bloc de notas y cambia estas claves por contraseñas propias:
   - `MYSQL_PASSWORD` y `MYSQL_ROOT_PASSWORD`: protegen MariaDB.
   - `MYSQLADMIN_USER` y `MYSQLADMIN_PASSWORD`: credenciales administradoras de la base de tareas en phpMyAdmin.
   - `WHATSAPP_VERIFY_TOKEN`: secreto que también se copia en la configuración del webhook de Meta.

   Usa letras y números en las contraseñas para evitar tener que escapar caracteres especiales en el archivo `.env`.
4. Construye e inicia la aplicación:

   ```powershell
   docker compose up -d --build
   ```

5. Abre `http://localhost:8080` (o el puerto que hayas puesto en `HOST_PORT`). El equipo puede marcar tareas como resueltas, devolverlas a pendientes o eliminarlas sin iniciar sesión.

El proxy Nginx es el único servicio que publica puertos para las aplicaciones web. Conserva los puertos HTTP existentes y añade HTTPS:

| Servicio | HTTP | HTTPS |
|---|---:|---:|
| Dashboard | `HOST_PORT` (8080 por defecto) | `DASHBOARD_HTTPS_PORT` (8448) |
| Chromium | 8083 | 8084 |
| Webhook | `WEBHOOK_HOST_PORT` (8081) | `WEBHOOK_HTTPS_PORT` (8445) |
| phpMyAdmin | `MYSQLADMIN_HOST_PORT` (8082, solo localhost) | `MYSQLADMIN_HTTPS_PORT` (8446, red local) |

El iframe de Chromium usa HTTPS. El proxy crea una autoridad certificadora (CA) local y un certificado de servidor para `PROXY_CERT_IP` y `PROXY_CERT_HOSTNAME`. El navegador no puede confiar en ellos de forma automática o silenciosa. Para que Chromium funcione como contexto seguro, cada cliente debe confiar en la CA. Descárgala desde `http://IP-DEL-ORDENADOR:HOST_PORT/proxy-ca.crt` (por defecto `http://192.168.9.141:8088/proxy-ca.crt`) o desde **Certificado del proxy** en el dashboard. También puedes abrir **Chromium para aceptar el certificado HTTPS**, revisar la advertencia y continuar solo si reconoces la IP y el certificado; luego vuelve al dashboard y recárgalo. Para confiar permanentemente en Windows, compara la huella SHA-256 de la CA antes de importarla:

```powershell
docker compose cp proxy:/etc/nginx/ssl/ca.pem .\proxy-ca.crt
docker compose exec -T proxy openssl x509 -in /etc/nginx/ssl/ca.pem -noout -fingerprint -sha256
```

Compara la huella del servidor con la calculada en el cliente mediante PowerShell:

```powershell
$cert = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2("$HOME\Downloads\proxy-ca.crt")
$sha = [System.Security.Cryptography.SHA256]::Create()
[BitConverter]::ToString($sha.ComputeHash($cert.RawData)).Replace('-', '')
```

La huella debe coincidir con la del servidor, ignorando los dos puntos. Solo entonces impórtalo:

```powershell
Import-Certificate -FilePath "$HOME\Downloads\proxy-ca.crt" -CertStoreLocation Cert:\CurrentUser\Root
```

Cierra y vuelve a abrir el navegador. Configura `PROXY_CERT_IP` en `.env` con la IPv4 LAN que muestra `ipconfig` en el equipo servidor; si cambia esa IP, reinicia el proxy para emitir un certificado de servidor nuevo. Limita los puertos de aplicación a la red local en el firewall y no los publiques directamente en Internet. El puerto HTTP de phpMyAdmin queda ligado a localhost; para acceso desde la red usa HTTPS.

El puerto del dashboard se publica en la red local para que el televisor y el equipo vean las tareas automáticamente. Abre `http://IP-DEL-ORDENADOR:8080/?tv=1` (sustituye `8080` por `HOST_PORT`) en el televisor para mostrar las tareas pendientes en una vista grande; el modo TV no muestra controles para cambiar las tareas. Los mensajes nuevos aparecen resaltados y vuelven a poner la lista al principio. El puerto de MariaDB no se publica; solo lo usan los servicios dentro de Docker. **Cualquier usuario con acceso al dashboard puede marcar tareas, devolverlas a pendientes o eliminarlas.** Limita los puertos publicados a la red local mediante el firewall y no los redirijas al Internet público: los mensajes y teléfonos se pueden ver sin contraseña en esa red.

Abre `http://localhost:8082` para entrar en phpMyAdmin desde el servidor o `https://IP-DEL-ORDENADOR:8446` desde la red local. El acceso HTTPS requiere confiar primero en el certificado autofirmado. Inicia sesión con `MYSQLADMIN_USER` y `MYSQLADMIN_PASSWORD`, configurados en `.env`; esa cuenta puede crear, modificar y borrar tablas y datos dentro de la base de tareas. No tiene permisos sobre otras bases ni puede administrar las cuentas del servidor. MariaDB crea la cuenta directamente durante su inicialización; no hay un contenedor auxiliar. Si cambias el usuario o la contraseña después de haber inicializado la base de datos, actualiza la cuenta dentro de MariaDB; los scripts de inicialización no se vuelven a ejecutar sobre un volumen existente. MariaDB no expone su puerto ni guarda credenciales de inicio de sesión.

Comandos útiles:

```powershell
docker compose logs -f web
docker compose logs -f webhook
docker compose logs -f proxy
docker compose logs -f db
docker compose up -d --build
docker compose exec -T db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > .\copia.sql
```

Los datos persisten en el volumen Docker `tareas-db`. Las fechas de recepción y finalización se guardan como `DATETIME(3)` en UTC; el timestamp Unix que llega en el webhook se convierte al guardarlo y la API sigue enviando milisegundos Unix al dashboard. MariaDB ejecuta [`sql/init.sql`](./sql/init.sql) y [`sql/init-phpmyadmin.sh`](./sql/init-phpmyadmin.sh) al crear la base de datos por primera vez. Para las bases existentes, [`sql/migrate-task-datetimes.sql`](./sql/migrate-task-datetimes.sql) convierte las fechas de recepción y finalización de milisegundos Unix a `DATETIME(3)` UTC; haz una copia de seguridad antes de ejecutarla. Para hacer una copia de seguridad consistente, usa `mariadb-dump` desde el contenedor de MariaDB antes de copiar o cambiar archivos de la base de datos.

## Cambios en directo

El código PHP (incluido `php/.htaccess`) y la carpeta `public/` se montan desde el proyecto en modo solo lectura dentro de los contenedores. Al guardar cambios en `php/` o `public/`, el servicio los sirve sin reconstruir la imagen y el dashboard se recarga automáticamente. La comprobación de cambios se activa con `DEV_LIVE_RELOAD=true` y se puede desactivar en `.env` si no la necesitas.

Los cambios en `Dockerfile`, `docker-compose.yml` o dependencias requieren recrear o reconstruir los servicios. Los cambios al esquema de la base de datos requieren una migración SQL; editar `sql/init.sql` no modifica una base ya inicializada.

## Recibir tareas desde WhatsApp

El proxy local publica el webhook por HTTPS en `WEBHOOK_HTTPS_PORT` (8445 por defecto), pero su certificado autofirmado no sirve para Meta. Meta necesita un certificado confiable y una URL HTTPS pública. Configura el proxy/túnel HTTPS público que ya uses para reenviar **GET y POST** de `https://TU-DOMINIO/webhook` a `http://127.0.0.1:8081/webhook`, conservando los parámetros de consulta del GET de verificación y el cuerpo y las cabeceras del POST. En Meta configura como URL de devolución de llamada `https://TU-DOMINIO/webhook`; no incluyas `/api` al final.

Mantén `WEBHOOK_HOST_BIND=127.0.0.1` si el proxy/túnel público se ejecuta en Windows en este ordenador. No publiques los puertos del webhook directamente en Internet; la conexión pública debe terminar en HTTPS con un certificado válido. Si el proxy público se ejecuta en otro contenedor, configura `WEBHOOK_HOST_BIND=0.0.0.0` y limita el puerto HTTP mediante el firewall solo a ese proxy. El endpoint permite únicamente `/webhook` y `/health`.

1. En Meta for Developers crea o abre una aplicación y añade el producto WhatsApp.
2. Abre la configuración del webhook e introduce:
   - **URL de devolución de llamada:** `https://TU-DOMINIO/webhook`
   - **Token de verificación:** el valor de `WHATSAPP_VERIFY_TOKEN` en `.env`
3. Verifica y guarda la configuración, y suscríbete al campo `messages`.
4. Copia el App Secret de Meta en `WHATSAPP_APP_SECRET` en `.env` y reinicia el servicio:

   ```powershell
   docker compose up -d
   ```

5. Envía un mensaje al número de WhatsApp y confirma que aparece en **Por hacer**.

Si la aplicación de Meta está en modo desarrollo, solo recibirá mensajes de sus destinatarios de prueba. Para recibir mensajes de cualquier persona, configura la aplicación de Meta para producción.

## Funciones

- Resumen de tareas pendientes, recibidas hoy y completadas.
- Filtros para ver las pendientes, las hechas o todas; búsqueda por contenido, nombre y teléfono.
- Actualizaciones en directo cuando otro miembro del equipo recibe, completa o elimina una tarea.
- Vista pública de solo lectura para el televisor: mensajes y cambios aparecen sin iniciar sesión ni pedir intervención al administrador; la página se actualiza automáticamente si se interrumpe la conexión.
- El equipo puede completar, reabrir y borrar tareas desde la vista normal sin iniciar sesión.
- Proxy Nginx único delante del dashboard, Chromium, phpMyAdmin y webhook, con endpoints HTTP y HTTPS.
- Modo pantalla grande mediante `/?tv=1`, con nuevos mensajes destacados al llegar.
- Marcar tareas como resueltas y registrar cuándo; el sistema muestra **Equipo** como responsable si no se inicia sesión.
- Usa **Administrar tareas hechas** para consultar, reabrir o eliminar tareas completadas sin iniciar sesión.
- Evita duplicados cuando Meta reenvía un mensaje.
- Guarda mensajes de texto, imágenes, vídeos, documentos, audio, ubicaciones y respuestas interactivas.
- Comprueba la firma `X-Hub-Signature-256` cuando se configura `WHATSAPP_APP_SECRET`.

## Archivos de configuración

- [`.env.example`](./.env.example): plantilla. Cópiala como `.env` y cambia las contraseñas y los tokens.
- `.env`: archivo local con secretos que crearás a partir de `.env.example`; Git lo ignora. No lo compartas.
- [`sql/init.sql`](./sql/init.sql): tablas e índices de las tareas y del canal de eventos.
- [`docker-compose.yml`](./docker-compose.yml): contenedores para el dashboard, el webhook, Chromium, phpMyAdmin, el proxy y MariaDB.
- [`docker/nginx-proxy.conf`](./docker/nginx-proxy.conf): rutas HTTP/HTTPS del proxy y soporte WebSocket para Chromium.
- [`php/index.php`](./php/index.php): aplicación PHP, API y webhook.
- [`public/assets/bootstrap.min.css`](./public/assets/bootstrap.min.css): Bootstrap 5.3.3 servido localmente para el dashboard.

`sql/init.sql` se ejecuta automáticamente al crear el volumen de la base de datos. Para instalar una versión posterior del esquema, prepara una migración SQL y haz primero una copia de seguridad; reiniciar el contenedor no vuelve a inicializar un volumen existente.
