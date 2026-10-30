<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Desactivar buffer de salida para logs en tiempo real en Docker
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
ini_set('zlib.output_compression', '0');
ini_set('implicit_flush', '1');
for ($i = 0; $i < ob_get_level(); $i++) {
    ob_end_flush();
}
ob_implicit_flush(1);

function logMsg(string $msg): void {
    $time = date('Y-m-d H:i:s');
    echo "[{$time}] [EMAIL-WORKER] {$msg}" . PHP_EOL;
}

// =========================================================================
// ⚙️ CONFIGURACIÓN DE FILTROS
// =========================================================================

function parseEnvList(string $envKey): array {
    $value = getenv($envKey);
    if ($value === false || trim($value) === '') {
        return [];
    }
    // Separa por comas, quita espacios extras y descarta elementos vacíos
    $items = array_map('trim', explode(',', $value));
    return array_values(array_filter($items, fn($item) => $item !== ''));
}

$excludedSenders   = parseEnvList('EXCLUDED_SENDERS');
$allowedRecipients = parseEnvList('ALLOWED_RECIPIENTS');

logMsg("Filtros cargados -> Excluidos: [" . implode(', ', $excludedSenders) . "]");
logMsg("Filtros cargados -> Alias permitidos: [" . implode(', ', $allowedRecipients) . "]");

// =========================================================================

logMsg("=== INICIANDO VERIFICACIÓN DE CORREOS CON FILTROS ===");

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    logMsg("Autoload de Composer cargado.");
} else {
    logMsg("❌ CRÍTICO: No se encuentra vendor/autoload.php");
    exit(1);
}

use PhpImap\Mailbox;

$dbHost = getenv('DB_HOST') ?: 'db';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('MYSQL_DATABASE') ?: 'whatsapp_tasks';
$dbUser = getenv('MYSQL_USER') ?: 'whatsapp_app';
$dbPass = getenv('MYSQL_PASSWORD');

$gmailUser = getenv('GMAIL_USER');
$gmailPass = getenv('GMAIL_PASS');

if (!$gmailUser || !$gmailPass) {
    logMsg("⚠️ OMITIDO: GMAIL_USER o GMAIL_PASS no configurados.");
    exit(0);
}

function publishEvent(PDO $db, string $name, array $data): void
{
    $insert = $db->prepare('INSERT INTO task_events (name, data, created_at) VALUES (?, ?, ?)');
    $nowMs = (int) floor(microtime(true) * 1000);
    $insert->execute([
        $name,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $nowMs,
    ]);

    $eventId = (int) $db->lastInsertId();
    if ($eventId % 100 === 0) {
        $db->exec('DELETE FROM task_events WHERE created_at < ' . ($nowMs - 604800000));
        $db->exec('DELETE FROM task_events WHERE id <= ' . max(0, $eventId - 20000));
    }
}

function databaseDateFromMilliseconds(int $milliseconds): string
{
    $seconds = intdiv($milliseconds, 1000);
    $millisecondPart = $milliseconds % 1000;
    $date = (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'));
    return $date->format('Y-m-d H:i:s') . sprintf('.%03d', $millisecondPart);
}

function normalizeTask(array $task): array
{
    foreach (['id', 'done'] as $field) {
        if (isset($task[$field])) {
            $task[$field] = (int) $task[$field];
        }
    }
    foreach (['received_at', 'done_at'] as $field) {
        if (!empty($task[$field])) {
            $date = new DateTimeImmutable($task[$field], new DateTimeZone('UTC'));
            $task[$field] = (int) $date->format('U') * 1000 + (int) $date->format('v');
        }
    }
    return $task;
}

try {
    $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $mailbox = new Mailbox(
        '{imap.gmail.com:993/imap/ssl/novalidate-cert}[Gmail]/Todos',
        $gmailUser,
        $gmailPass,
        null,
        'UTF-8'
    );
    $minutes = (int) (getenv('EMAIL_LOOKBACK_HOURS') ?: 1)*60*60;
    $limitTimestamp = time() - ($minutes);
    $sinceDate = date('d-M-Y', $limitTimestamp);
    logMsg("Buscando correos en IMAP desde hace {$minutes} minutos ('{$sinceDate}')...");

    $mailIds = $mailbox->searchMailbox('SINCE "' . $sinceDate . '"');
    $totalFound = count($mailIds);
    logMsg("Candidatos encontrados en Gmail: {$totalFound}");

    $insertedCount = 0;
    $skippedOldCount = 0;
    $skippedExcludedSenderCount = 0;
    $skippedAliasCount = 0;
    $skippedDuplicateCount = 0;

    foreach ($mailIds as $index => $mailId) {
        logMsg("--> Analizando correo #{$mailId} (" . ($index + 1) . "/{$totalFound})...");
        
        $mail = $mailbox->getMail($mailId, false);
        $mailTimestamp = $mail->date ? strtotime($mail->date) : time();

        // 1. Filtro de tiempo
        if ($mailTimestamp < $limitTimestamp) {
            logMsg("    ⏭️ Ignorado: Fuera del rango de 1 hora.");
            $skippedOldCount++;
            continue;
        }

        $senderEmail = strtolower(trim($mail->fromAddress));
        $fromName    = $mail->fromName ?: $senderEmail;
        $subject     = $mail->subject ?: 'Sin asunto';

        // 2. Filtro de Remitentes Excluidos
        $isExcludedSender = false;
        foreach ($excludedSenders as $excluded) {
            if (str_contains($senderEmail, strtolower($excluded))) {
                $isExcludedSender = true;
                break;
            }
        }

        if ($isExcludedSender) {
            logMsg("    🚫 Ignorado por Remitente Excluido: <{$senderEmail}>");
            $skippedExcludedSenderCount++;
            continue;
        }

        // 3. Filtro de Alias de Destino
        if (!empty($allowedRecipients)) {
            // Recopilamos todas las direcciones destinatarias (To, Cc y Delivered-To)
            $allRecipients = array_merge(
                array_keys($mail->to),
                array_keys($mail->cc),
                array_keys($mail->toString ? [$mail->toString => ''] : [])
            );
            $allRecipientsLower = array_map('strtolower', $allRecipients);

            $matchedAlias = false;
            foreach ($allowedRecipients as $alias) {
                if (in_array(strtolower($alias), $allRecipientsLower, true)) {
                    $matchedAlias = true;
                    break;
                }
            }

            if (!$matchedAlias) {
                logMsg("    ⏭️ Ignorado por Alias: No fue enviado a ninguno de los alias configurados.");
                $skippedAliasCount++;
                continue;
            }
        }

        //$body      = $mail->textPlain ?: strip_tags($mail->textHtml);
        $messageId = $mail->messageId ?: "uid-{$mailId}";
        
        $msTimestamp   = (int) ($mailTimestamp * 1000);
        $receivedAtStr = databaseDateFromMilliseconds($msTimestamp);

        logMsg("    De: {$fromName} <{$senderEmail}>");
        logMsg("    Asunto: {$subject}");

        $checkStmt = $pdo->prepare("SELECT id FROM tasks WHERE email_message_id = :email_id LIMIT 1");
        $checkStmt->execute([':email_id' => $messageId]);

        if (!$checkStmt->fetch()) {
            // 2. Insertar solo si no existe
            $stmt = $pdo->prepare("
                INSERT INTO tasks (
                    source, email_message_id, sender_email, from_name, 
                    subject, type, body, received_at
                ) VALUES (
                    'email', :email_id, :sender_email, :from_name, 
                    :subject, 'email', :body, :received_at
                )
            ");

            $stmt->execute([
                ':email_id'     => $messageId,
                ':sender_email' => $senderEmail,
                ':from_name'    => $fromName,
                ':subject'      => $subject,
                ':body'         => trim($subject),
                ':received_at'  => $receivedAtStr
            ]);
        }

        if ($stmt->rowCount() > 0) {
            $taskId = (int) $pdo->lastInsertId();
            logMsg("    ✨ ¡NUEVA TAREA CREADA! ID: #{$taskId}");

            $select = $pdo->prepare('SELECT * FROM tasks WHERE id = ?');
            $select->execute([$taskId]);
            $task = normalizeTask($select->fetch());

            publishEvent($pdo, 'task:new', $task);
            logMsg("    📢 Evento 'task:new' enviado al Dashboard.");
            $insertedCount++;
        } else {
            logMsg("    🔁 Ignorado: Correo duplicado en la BD.");
            $skippedDuplicateCount++;
        }
    }

    logMsg("=== RESUMEN DE FILTRADO ===");
    logMsg("Nuevas tareas creadas: {$insertedCount}");
    logMsg("Omitidos por Remitente Excluido: {$skippedExcludedSenderCount}");
    logMsg("Omitidos por Alias no coincidente: {$skippedAliasCount}");
    logMsg("Omitidos por Duplicado: {$skippedDuplicateCount}");
    logMsg("Omitidos por antigüedad (> 1h): {$skippedOldCount}");
    logMsg("===========================" . PHP_EOL);

} catch (\Throwable $e) {
    logMsg("❌ ERROR EN WORKER IMAP: " . $e->getMessage());
}