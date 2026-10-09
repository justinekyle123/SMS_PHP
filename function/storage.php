<?php

declare(strict_types=1);

function smsEnvironment(string $key): string
{
    $systemValue = getenv($key);
    if ($systemValue !== false && $systemValue !== '') return $systemValue;

    static $values;
    if ($values === null) {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        $values = is_file($path) ? (parse_ini_file($path, false, INI_SCANNER_RAW) ?: []) : [];
    }

    return trim((string) ($values[$key] ?? ''));
}

function smsDatabase(): PDO
{
    $databasePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sms.sqlite';
    $directory = dirname($databasePath);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $database = new PDO('sqlite:' . $databasePath);
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $database->exec(
        'CREATE TABLE IF NOT EXISTS sms_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            alert_id TEXT,
            provider_id TEXT,
            alert_level TEXT NOT NULL,
            phone_number TEXT NOT NULL,
            message TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ("pending", "successful", "failed")),
            error_message TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $columns = $database->query('PRAGMA table_info(sms_messages)')->fetchAll();
    $hasAlertId = array_reduce($columns, static fn (bool $found, array $column): bool => $found || $column['name'] === 'alert_id', false);
    if (!$hasAlertId) $database->exec('ALTER TABLE sms_messages ADD COLUMN alert_id TEXT');

    return $database;
}

function smsCreatePending(PDO $database, string $level, string $phone, string $message, ?string $alertId = null): int
{
    $now = gmdate('c');
    $statement = $database->prepare(
        'INSERT INTO sms_messages (alert_id, alert_level, phone_number, message, status, created_at, updated_at)
         VALUES (:alert_id, :level, :phone, :message, "pending", :created_at, :updated_at)'
    );
    $statement->execute([
        ':alert_id' => $alertId,
        ':level' => $level,
        ':phone' => $phone,
        ':message' => $message,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int) $database->lastInsertId();
}

function smsUpdate(PDO $database, int $id, string $status, ?string $providerId = null, ?string $error = null): void
{
    $statement = $database->prepare(
        'UPDATE sms_messages
         SET status = :status, provider_id = :provider_id, error_message = :error_message, updated_at = :updated_at
         WHERE id = :id'
    );
    $statement->execute([
        ':status' => $status,
        ':provider_id' => $providerId,
        ':error_message' => $error,
        ':updated_at' => gmdate('c'),
        ':id' => $id,
    ]);
}

function smsRecent(PDO $database, int $limit = 100): array
{
    $statement = $database->prepare('SELECT * FROM sms_messages ORDER BY id DESC LIMIT :limit');
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}
