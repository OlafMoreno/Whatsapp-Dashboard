<?php
declare(strict_types=1);

$config = [
    'db_host' => getenv('DB_HOST') ?: 'db',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('MYSQL_DATABASE') ?: 'whatsapp_tasks',
    'db_user' => getenv('MYSQL_USER') ?: 'whatsapp_app',
    'db_password' => getenv('MYSQL_PASSWORD') ?: '',
    'verify_token' => getenv('WHATSAPP_VERIFY_TOKEN') ?: '',
    'app_secret' => getenv('WHATSAPP_APP_SECRET') ?: '',
    'app_mode' => getenv('APP_MODE') ?: 'dashboard',
    'dev_live_reload' => getenv('DEV_LIVE_RELOAD') === 'true',
];

if (!in_array($config['app_mode'], ['dashboard', 'webhook'], true)) {
    error_log('APP_MODE debe ser dashboard o webhook.');
    http_response_code(500);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (($config['app_mode'] === 'webhook' && !in_array($path, ['/health', '/webhook'], true))
    || ($config['app_mode'] === 'dashboard' && $path === '/webhook')) {
    http_response_code(404);
    exit;
}

function jsonResponse(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

if ($path === '/api/dev-version' && $method === 'GET') {
    if ($config['app_mode'] !== 'dashboard' || !$config['dev_live_reload']) {
        jsonResponse(['error' => 'Ruta no encontrada'], 404);
    }
    $files = [];
    foreach ([__DIR__, '/opt/whatsapp-tareas/public'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    $versionParts = [];
    foreach ($files as $file) {
        $fileHash = hash_file('sha256', $file);
        if ($fileHash === false) {
            error_log('No se pudo calcular la versión del archivo: ' . $file);
            jsonResponse(['error' => 'No se pudo comprobar la versión de la aplicación'], 500);
        }
        $versionParts[] = $file . ':' . $fileHash;
    }
    jsonResponse(['version' => hash('sha256', implode("\n", $versionParts))]);
}

if ($path === '/assets/bootstrap.min.css' && $method === 'GET' && $config['app_mode'] === 'dashboard') {
    $stylesheet = '/opt/whatsapp-tareas/public/assets/bootstrap.min.css';
    if (!is_file($stylesheet)) {
        error_log('No se encuentra la hoja de estilos local de Bootstrap.');
        http_response_code(500);
        exit;
    }
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: no-cache');
    if (readfile($stylesheet) === false) {
        error_log('No se pudo leer la hoja de estilos local de Bootstrap.');
        http_response_code(500);
    }
    exit;
}

try {
    $db = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            $config['db_port'],
            $config['db_name']
        ),
        $config['db_user'],
        $config['db_password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $error) {
    error_log('No se pudo conectar con MySQL: ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"Base de datos no disponible"}';
    exit;
}

function normalizeTask(array $task): array
{
    foreach (['id', 'done'] as $field) {
        $task[$field] = (int) $task[$field];
    }
    foreach (['received_at', 'done_at'] as $field) {
        if ($task[$field] !== null) {
            $date = new DateTimeImmutable($task[$field], new DateTimeZone('UTC'));
            $task[$field] = (int) $date->format('U') * 1000 + (int) $date->format('v');
        }
    }
    return $task;
}

function databaseDateFromMilliseconds(int $milliseconds): string
{
    $seconds = intdiv($milliseconds, 1000);
    $millisecondPart = $milliseconds % 1000;
    $date = (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'));
    return $date->format('Y-m-d H:i:s') . sprintf('.%03d', $millisecondPart);
}

function publishEvent(PDO $db, string $name, array $data): void
{
    $insert = $db->prepare('INSERT INTO task_events (name, data, created_at) VALUES (?, ?, ?)');
    $insert->execute([
        $name,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        (int) floor(microtime(true) * 1000),
    ]);

    $eventId = (int) $db->lastInsertId();
    if ($eventId % 100 === 0) {
        $db->exec('DELETE FROM task_events WHERE created_at < ' . ((int) floor(microtime(true) * 1000) - 604800000));
        $db->exec('DELETE FROM task_events WHERE id <= ' . max(0, $eventId - 20000));
    }
}

function messageBody(array $message): string
{
    $type = $message['type'] ?? 'unknown';
    return match ($type) {
        'text' => (string) ($message['text']['body'] ?? ''),
        'image' => !empty($message['image']['caption'])
            ? '📷 ' . $message['image']['caption']
            : '📷 (imagen sin texto)',
        'video' => !empty($message['video']['caption'])
            ? '🎬 ' . $message['video']['caption']
            : '🎬 (vídeo sin texto)',
        'document' => '📄 ' . ($message['document']['caption'] ?? $message['document']['filename'] ?? '(documento)'),
        'audio' => '🎤 (nota de voz)',
        'location' => locationBody($message['location'] ?? []),
        'button' => (string) ($message['button']['text'] ?? ''),
        'interactive' => (string) (
            $message['interactive']['button_reply']['title']
            ?? $message['interactive']['list_reply']['title']
            ?? '(respuesta interactiva)'
        ),
        default => '(' . $type . ')',
    };
}

function locationBody(array $location): string
{
    $label = $location['name'] ?? $location['address'] ?? '';
    $latitude = $location['latitude'] ?? '';
    $longitude = $location['longitude'] ?? '';
    $map = $latitude !== '' && $longitude !== ''
        ? ' https://maps.google.com/?q=' . rawurlencode((string) $latitude . ',' . (string) $longitude)
        : '';
    return trim('📍 ' . $label . $map);
}

function validWebhookSignature(string $body, string $appSecret): bool
{
    if ($appSecret === '') {
        return true;
    }
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    return preg_match('/^sha256=[a-f0-9]{64}$/i', $signature) === 1
        && hash_equals('sha256=' . hash_hmac('sha256', $body, $appSecret), strtolower($signature));
}

function webhook(PDO $db, array $config, string $method): never
{
    if ($method === 'GET') {
        $mode = $_GET['hub_mode'] ?? '';
        $token = $_GET['hub_verify_token'] ?? '';
        if ($mode === 'subscribe' && $config['verify_token'] !== ''
            && hash_equals($config['verify_token'], $token)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $_GET['hub_challenge'] ?? '';
            exit;
        }
        http_response_code(403);
        exit;
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        http_response_code(405);
        exit;
    }

    $rawBody = file_get_contents('php://input');
    if (!validWebhookSignature($rawBody, $config['app_secret'])) {
        error_log('Firma inválida en el webhook de WhatsApp.');
        http_response_code(401);
        exit;
    }
    try {
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        jsonResponse(['error' => 'JSON inválido'], 400);
    }
    if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
        jsonResponse(['error' => 'Evento no soportado'], 400);
    }

    try {
        $db->beginTransaction();
        $insert = $db->prepare(
            'INSERT IGNORE INTO tasks (wa_message_id, from_number, from_name, type, body, received_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                foreach ($value['messages'] ?? [] as $message) {
                    if (empty($message['id']) || empty($message['from']) || empty($message['type'])) {
                        continue;
                    }
                    $from = (string) $message['from'];
                    $fromName = null;
                    foreach ($value['contacts'] ?? [] as $contact) {
                        if (($contact['wa_id'] ?? null) === $from) {
                            $fromName = $contact['profile']['name'] ?? null;
                            break;
                        }
                    }
                    $timestamp = filter_var($message['timestamp'] ?? null, FILTER_VALIDATE_INT);
                    $insert->execute([
                        (string) $message['id'],
                        $from,
                        $fromName,
                        (string) $message['type'],
                        messageBody($message),
                        databaseDateFromMilliseconds(
                            $timestamp !== false && $timestamp > 0
                                ? $timestamp * 1000
                                : (int) floor(microtime(true) * 1000)
                        ),
                    ]);
                    if ($insert->rowCount() === 0) {
                        continue;
                    }
                    $taskId = (int) $db->lastInsertId();
                    $select = $db->prepare('SELECT * FROM tasks WHERE id = ?');
                    $select->execute([$taskId]);
                    $task = normalizeTask($select->fetch());
                    publishEvent($db, 'task:new', $task);
                    error_log(sprintf('Nueva tarea #%d de %s', $task['id'], $task['from_name'] ?: $task['from_number']));
                }
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Error procesando webhook de WhatsApp: ' . $error->getMessage());
        http_response_code(500);
        exit;
    }

    http_response_code(200);
    exit;
}

if ($path === '/health' && $method === 'GET') {
    jsonResponse(['ok' => true]);
}
if ($path === '/webhook') {
    webhook($db, $config, $method);
}

if ($path === '/' || $path === '/index.php') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile('/opt/whatsapp-tareas/public/index.html');
    exit;
}

if ($path === '/api/tasks' && $method === 'GET') {
    $status = $_GET['status'] ?? 'pending';
    $where = match ($status) {
        'done' => 'WHERE done = 1',
        'all' => '',
        default => 'WHERE done = 0',
    };
    $limit = filter_var($_GET['limit'] ?? 500, FILTER_VALIDATE_INT);
    $limit = max(1, min($limit === false ? 500 : $limit, 2000));
    $tasks = $db->query("SELECT * FROM tasks {$where} ORDER BY received_at DESC LIMIT {$limit}")->fetchAll();
    $tasks = array_map('normalizeTask', $tasks);
    $counts = $db->query(
        'SELECT
            COALESCE(SUM(done = 0), 0) AS pending,
            COALESCE(SUM(done = 1), 0) AS done,
            COALESCE(SUM(received_at >= ' . $db->quote(gmdate('Y-m-d 00:00:00')) . '), 0) AS today
         FROM tasks'
    )->fetch();
    jsonResponse([
        'tasks' => $tasks,
        'counts' => [
            'pending' => (int) $counts['pending'],
            'done' => (int) $counts['done'],
            'today' => (int) $counts['today'],
        ],
    ]);
}

if (preg_match('#^/api/tasks/([1-9][0-9]*)$#', $path, $matches)) {
    if ($method !== 'PATCH' && $method !== 'DELETE') {
        header('Allow: PATCH, DELETE');
        http_response_code(405);
        exit;
    }
    $taskId = (int) $matches[1];
    $find = $db->prepare('SELECT * FROM tasks WHERE id = ?');
    if ($method === 'PATCH') {
        try {
            $body = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            jsonResponse(['error' => 'El cuerpo debe ser JSON válido'], 400);
        }
        if (!is_array($body) || !is_bool($body['done'] ?? null)) {
            jsonResponse(['error' => 'Envía { "done": true } o { "done": false }'], 400);
        }
        if ($body['done']) {
            $update = $db->prepare('UPDATE tasks SET done = 1, done_at = ?, done_by = ? WHERE id = ?');
            $update->execute([
                databaseDateFromMilliseconds((int) floor(microtime(true) * 1000)),
                'Equipo',
                $taskId,
            ]);
        } else {
            $update = $db->prepare('UPDATE tasks SET done = 0, done_at = NULL, done_by = NULL WHERE id = ?');
            $update->execute([$taskId]);
        }
        $find->execute([$taskId]);
        $taskRow = $find->fetch();
        if ($taskRow === false) {
            jsonResponse(['error' => 'La tarea no existe'], 404);
        }
        $task = normalizeTask($taskRow);
        publishEvent($db, 'task:update', $task);
        jsonResponse($task);
    }
    if ($method === 'DELETE') {
        $find->execute([$taskId]);
        if (!$find->fetch()) {
            jsonResponse(['error' => 'La tarea no existe'], 404);
        }
        $delete = $db->prepare('DELETE FROM tasks WHERE id = ?');
        $delete->execute([$taskId]);
        publishEvent($db, 'task:delete', ['id' => $taskId]);
        http_response_code(204);
        exit;
    }
}

if ($path === '/api/events' && $method === 'GET') {
    $lastEventId = filter_var($_SERVER['HTTP_LAST_EVENT_ID'] ?? null, FILTER_VALIDATE_INT);
    $cursor = $lastEventId === false || $lastEventId === null
        ? (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM task_events')->fetchColumn()
        : max(0, $lastEventId);
    set_time_limit(0);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    echo ": conectado\n\n";
    flush();
    $lastPing = time();
    $events = $db->prepare('SELECT id, name, data FROM task_events WHERE id > ? ORDER BY id LIMIT 100');
    while (!connection_aborted()) {
        $events->execute([$cursor]);
        $rows = $events->fetchAll();
        foreach ($rows as $event) {
            $cursor = (int) $event['id'];
            echo "id: {$cursor}\nevent: {$event['name']}\ndata: {$event['data']}\n\n";
        }
        if ($rows !== []) {
            flush();
        } elseif (time() - $lastPing >= 15) {
            echo ": ping\n\n";
            flush();
            $lastPing = time();
        }
        usleep(500000);
    }
    exit;
}

if (str_starts_with($path, '/api/')) {
    jsonResponse(['error' => 'Ruta no encontrada'], 404);
}
http_response_code(404);
echo 'No encontrado';
