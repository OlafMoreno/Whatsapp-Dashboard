# Tareas de WhatsApp

Aplicación para que los mensajes entrantes de WhatsApp Cloud API se guarden como tareas en MariaDB y aparezcan en el dashboard del equipo. La aplicación, Apache, PHP y la base de datos se ejecutan en contenedores Docker. Bootstrap se sirve localmente, sin depender de un CDN. No necesitas instalar PHP, Node.js, npm ni Caddy en Windows.

## Iniciar en Windows

1. Instala y abre Docker Desktop.
2. Desde PowerShell, en la carpeta del proyecto, crea el archivo de configuración:

   ```powershell
   Copy-Item .env.example .env
   ```

3. Abre `.env` con el Bloc de notas y cambia estas claves por contraseñas propias:
   - `DASHBOARD_PASSWORD`: protege la aplicación.
   - `MYSQL_PASSWORD` y `MYSQL_ROOT_PASSWORD`: protegen MariaDB.
   - `WHATSAPP_VERIFY_TOKEN`: secreto que también se copia en la configuración del webhook de Meta.

   Usa letras y números en las contraseñas para evitar tener que escapar caracteres especiales en el archivo `.env`.
4. Construye e inicia la aplicación:

   ```powershell
   docker compose up -d --build
   ```

5. Abre `http://localhost:8080` (o el puerto que hayas puesto en `HOST_PORT`). El tablero se puede ver sin iniciar sesión; el administrador introduce sus credenciales únicamente para completar o eliminar tareas.

El puerto del dashboard se publica en la red local para que el televisor y el equipo vean las tareas automáticamente. Abre `http://IP-DEL-ORDENADOR:8080/?tv=1` (sustituye `8080` por `HOST_PORT`) en el televisor para mostrar las tareas pendientes en una vista grande; el navegador del televisor puede usar pantalla completa si se desea. Los mensajes nuevos aparecen resaltados y vuelven a poner la lista al principio. El puerto de MariaDB no se publica; solo lo usan los servicios dentro de Docker. Limita el puerto indicado en `HOST_PORT` a tu red local mediante el firewall y no lo redirijas al Internet público: los mensajes y teléfonos se pueden ver sin contraseña en esa red.

El dashboard y el webhook son servicios separados: el primero escucha en `HOST_BIND:HOST_PORT` y el segundo en `WEBHOOK_HOST_BIND:WEBHOOK_HOST_PORT` (por defecto `127.0.0.1:8081`). Cada servicio solo responde a sus propias rutas; el puerto del webhook no sirve el dashboard ni su API de tareas. Mantén el puerto del dashboard limitado a la red local.

Comandos útiles:

```powershell
docker compose logs -f web
docker compose logs -f webhook
docker compose logs -f db
docker compose up -d --build
docker compose exec -T db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > .\copia.sql
```

Los datos persisten en el volumen Docker `tareas-db` y el SQL inicial está en [`sql/init.sql`](./sql/init.sql). MariaDB ejecuta este archivo al crear la base de datos por primera vez. Para hacer una copia de seguridad consistente, usa `mariadb-dump` desde el contenedor de MariaDB antes de copiar o cambiar archivos de la base de datos.

## Cambios en directo

El código PHP (incluido `php/.htaccess`) y la carpeta `public/` se montan desde el proyecto en modo solo lectura dentro de los contenedores. Al guardar cambios en `php/` o `public/`, el servicio los sirve sin reconstruir la imagen y el dashboard se recarga automáticamente. La comprobación de cambios se activa con `DEV_LIVE_RELOAD=true` y se puede desactivar en `.env` si no la necesitas.

Los cambios en `Dockerfile`, `docker-compose.yml` o dependencias requieren recrear o reconstruir los servicios. Los cambios al esquema de la base de datos requieren una migración SQL; editar `sql/init.sql` no modifica una base ya inicializada.

## Recibir tareas desde WhatsApp

El dashboard y el televisor pueden estar en la red interna, pero Meta necesita acceder a `/webhook` desde Internet mediante una URL HTTPS. Este proyecto no configura Caddy, un túnel ni un proxy. Configura una pasarela o túnel HTTPS en este ordenador para reenviar `https://TU-DOMINIO/webhook` a `http://127.0.0.1:8081/webhook`. No publiques el puerto 8081 directamente en Internet ni dirijas otras rutas a ese servicio. Si la pasarela se ejecuta en otro contenedor, configura `WEBHOOK_HOST_BIND=0.0.0.0` y limita el puerto `WEBHOOK_HOST_PORT` en el firewall para permitir solo el acceso de la pasarela.

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
- Servicio y puerto separados para el webhook de WhatsApp, con acceso limitado a la ruta `/webhook`.
- Modo pantalla grande mediante `/?tv=1`, con nuevos mensajes destacados al llegar.
- Marcar y desmarcar tareas, registro de quién las completó y cuándo, y eliminación de tareas hechas.
- Acciones de administrador protegidas aparte; usa **Acceso administrador** desde un navegador autorizado para actualizar tareas.
- Evita duplicados cuando Meta reenvía un mensaje.
- Guarda mensajes de texto, imágenes, vídeos, documentos, audio, ubicaciones y respuestas interactivas.
- Comprueba la firma `X-Hub-Signature-256` cuando se configura `WHATSAPP_APP_SECRET`.
- Respuestas de WhatsApp opcionales: `REPLY_ON_RECEIVE=true` para confirmar la recepción y `NOTIFY_ON_DONE=true` para avisar al completarla. Requieren `WHATSAPP_ACCESS_TOKEN` y `WHATSAPP_PHONE_NUMBER_ID`.

Los mensajes de texto libres de WhatsApp solo se pueden enviar durante las 24 horas siguientes al último mensaje de esa persona. Después de ese plazo se necesita una plantilla aprobada por Meta.

## Archivos de configuración

- [`.env.example`](./.env.example): plantilla. Cópiala como `.env` y cambia las contraseñas y los tokens.
- `.env`: archivo local con secretos que crearás a partir de `.env.example`; Git lo ignora. No lo compartas.
- [`sql/init.sql`](./sql/init.sql): tablas e índices de las tareas y del canal de eventos.
- [`docker-compose.yml`](./docker-compose.yml): contenedores separados para el dashboard, el webhook y MariaDB.
- [`php/index.php`](./php/index.php): aplicación PHP, API y webhook.
- [`public/assets/bootstrap.min.css`](./public/assets/bootstrap.min.css): Bootstrap 5.3.3 servido localmente para el dashboard.

`sql/init.sql` se ejecuta automáticamente al crear el volumen de la base de datos. Para instalar una versión posterior del esquema, prepara una migración SQL y haz primero una copia de seguridad; reiniciar el contenedor no vuelve a inicializar un volumen existente.
