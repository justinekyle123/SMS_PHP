<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/storage.php';

use AndroidSmsGateway\Client;
use AndroidSmsGateway\Domain\Message as GatewayMessage;

function smsJson(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizeSmsNumber(mixed $value): ?string
{
    $number = preg_replace('/[^0-9+]/', '', trim((string) $value)) ?? '';
    if (preg_match('/^09\d{9}$/', $number)) $number = '+63' . substr($number, 1);
    if (preg_match('/^9\d{9}$/', $number)) $number = '+63' . $number;
    if (preg_match('/^0063\d{10}$/', $number)) $number = '+' . substr($number, 2);
    if (preg_match('/^63\d{10}$/', $number)) $number = '+' . $number;
    return preg_match('/^\+639\d{9}$/', $number) ? $number : null;
}

$isJsonRequest = str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
if ($isJsonRequest) {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') smsJson([], 204);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') smsJson(['success' => false, 'error' => 'Use POST.'], 405);

    $expectedKey = smsEnvironment('READY_ALERT_SMS_API_KEY');
    $providedKey = $_SERVER['HTTP_X_READY_ALERT_KEY'] ?? '';
    if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        smsJson(['success' => false, 'error' => 'Unauthorized.'], 401);
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) smsJson(['success' => false, 'error' => 'Invalid JSON body.'], 400);

    $message = trim((string) ($payload['message'] ?? ''));
    $level = strtoupper(trim((string) ($payload['alertLevel'] ?? '')));
    $alertId = trim((string) ($payload['alertId'] ?? '')) ?: null;
    $rawNumbers = $payload['numbers'] ?? [];
    if (!is_array($rawNumbers) || count($rawNumbers) === 0 || count($rawNumbers) > 500) {
        smsJson(['success' => false, 'error' => 'Provide between 1 and 500 phone numbers.'], 422);
    }

    $recipients = [];
    foreach ($rawNumbers as $rawNumber) {
        $number = normalizeSmsNumber($rawNumber);
        if ($number !== null && !in_array($number, $recipients, true)) $recipients[] = $number;
    }
    if (!in_array($level, ['RED', 'YELLOW', 'GREEN'], true) || $message === '' || strlen($message) > 480) {
        smsJson(['success' => false, 'error' => 'Alert level and message are required; message must be 480 characters or fewer.'], 422);
    }
    if (count($recipients) === 0) smsJson(['success' => false, 'error' => 'No valid Philippine mobile numbers were provided.'], 422);

    $database = smsDatabase();
    $recordIds = array_map(
        fn (string $number): int => smsCreatePending($database, $level, $number, $message, $alertId),
        $recipients
    );

    try {
        $login = smsEnvironment('SMS_GATEWAY_USERNAME');
        $password = smsEnvironment('SMS_GATEWAY_PASSWORD');
        if ($login === '' || $password === '') throw new RuntimeException('SMS gateway credentials are not configured on the server.');

        $client = new Client($login, $password);
        $state = $client->Send(new GatewayMessage($message, $recipients, null, null, null, true));
        $messageId = $state->ID();
        foreach ($recordIds as $recordId) smsUpdate($database, $recordId, 'successful', $messageId);
        smsJson([
            'success' => true,
            'status' => 'successful',
            'sent' => count($recipients),
            'failed' => 0,
            'messageId' => $messageId,
            'alertId' => $alertId,
        ]);
    } catch (Throwable $error) {
        foreach ($recordIds as $recordId) smsUpdate($database, $recordId, 'failed', null, $error->getMessage());
        smsJson(['success' => false, 'status' => 'failed', 'sent' => 0, 'failed' => count($recipients), 'error' => $error->getMessage(), 'alertId' => $alertId], 502);
    }
}

$message = trim((string) ($_POST['message'] ?? ''));
$level = strtoupper(trim((string) ($_POST['alert_level'] ?? '')));
$number = normalizeSmsNumber($_POST['number'] ?? '');
$errors = [];
if (!in_array($level, ['RED', 'YELLOW', 'GREEN'], true)) $errors[] = 'Choose RED, YELLOW, or GREEN alert.';
if ($message === '') $errors[] = 'Message is required.';
if (strlen($message) > 480) $errors[] = 'Message must be 480 characters or fewer.';
if ($number === null) $errors[] = 'Use a Philippine mobile number such as 09171234567 or +639171234567.';
if ($errors !== []) {
    http_response_code(422);
    exit(implode(' ', $errors));
}

$database = smsDatabase();
$recordId = smsCreatePending($database, $level, $number, $message);
try {
    $login = smsEnvironment('SMS_GATEWAY_USERNAME');
    $password = smsEnvironment('SMS_GATEWAY_PASSWORD');
    if ($login === '' || $password === '') throw new RuntimeException('SMS gateway credentials are not configured on the server.');
    $client = new Client($login, $password);
    $state = $client->Send(new GatewayMessage($message, [$number], null, null, null, true));
    smsUpdate($database, $recordId, 'successful', $state->ID());
    header('Location: ../index.php?sent=1');
} catch (Throwable $error) {
    smsUpdate($database, $recordId, 'failed', null, $error->getMessage());
    header('Location: ../index.php?sent=0');
}
exit;
