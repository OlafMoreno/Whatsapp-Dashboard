# Tareas de WhatsApp

Aplicación para que los mensajes entrantes de WhatsApp Cloud API se guarden como tareas en MariaDB y aparezcan en un dashboard privado. La aplicación, Apache, PHP y la base de datos se ejecutan en contenedores Docker. No necesitas instalar PHP, Node.js, npm ni Caddy en Windows.

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

5. Abre `http://localhost:8080` e inicia sesión con `DASHBOARD_USER` y `DASHBOARD_PASSWORD`.

El puerto se publica solo en el propio ordenador por defecto. Para que entre el resto del equipo, cambia `HOST_BIND` a `0.0.0.0` en `.env`, reinicia con `docker compose up -d` y abre `http://IP-DEL-ORDENADOR:8080` en los otros equipos. El puerto de MariaDB no se publica; solo lo usa la aplicación dentro de Docker. Permite el puerto del dashboard únicamente en la red local del firewall y no lo redirijas al Internet público.

Comandos útiles:

```powershell
docker compose logs -f web
docker compose logs -f db
docker compose up -d --build
docker compose exec -T db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > .\copia.sql
```

Los datos persisten en el volumen Docker `tareas-db` y el SQL inicial está en [`sql/init.sql`](./sql/init.sql). MariaDB ejecuta este archivo al crear la base de datos por primera vez. Para hacer una copia de seguridad consistente, usa `mariadb-dump` desde el contenedor de MariaDB antes de copiar o cambiar archivos de la base de datos.

## Recibir tareas desde WhatsApp

El dashboard puede ser interno, pero Meta necesita acceder a `/webhook` desde Internet mediante una URL HTTPS. Este proyecto no configura Caddy, un túnel ni un proxy. Usa una URL pública HTTPS que ya tengas y configúrala para dirigir las peticiones a `/webhook` en el puerto local `8080`. Mantén protegida la ruta principal del dashboard con las credenciales de `.env`.

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
- Marcar y desmarcar tareas, registro de quién las completó y cuándo, y eliminación de tareas hechas.
- Evita duplicados cuando Meta reenvía un mensaje.
- Guarda mensajes de texto, imágenes, vídeos, documentos, audio, ubicaciones y respuestas interactivas.
- Comprueba la firma `X-Hub-Signature-256` cuando se configura `WHATSAPP_APP_SECRET`.
- Respuestas de WhatsApp opcionales: `REPLY_ON_RECEIVE=true` para confirmar la recepción y `NOTIFY_ON_DONE=true` para avisar al completarla. Requieren `WHATSAPP_ACCESS_TOKEN` y `WHATSAPP_PHONE_NUMBER_ID`.

Los mensajes de texto libres de WhatsApp solo se pueden enviar durante las 24 horas siguientes al último mensaje de esa persona. Después de ese plazo se necesita una plantilla aprobada por Meta.

## Archivos de configuración

- [`.env.example`](./.env.example): plantilla. Cópiala como `.env` y cambia las contraseñas y los tokens.
- `.env`: archivo local con secretos que crearás a partir de `.env.example`; Git lo ignora. No lo compartas.
- [`sql/init.sql`](./sql/init.sql): tablas e índices de las tareas y del canal de eventos.
- [`php/index.php`](./php/index.php): aplicación PHP, API y webhook.
- [`docker-compose.yml`](./docker-compose.yml): contenedores de PHP/Apache y MariaDB.

`sql/init.sql` se ejecuta automáticamente al crear el volumen de la base de datos. Para instalar una versión posterior del esquema, prepara una migración SQL y haz primero una copia de seguridad; reiniciar el contenedor no vuelve a inicializar un volumen existente.
