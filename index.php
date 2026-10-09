<?php
declare(strict_types=1);

require __DIR__ . '/function/storage.php';

$messages = [
    'RED' => 'EARTHQUAKE SHAKING DETECTED! Drop, Cover, and Hold On immediately! Report status when safe.',
    'YELLOW' => 'AFTERSHOCK WARNING! Standby for potential tremors and inspect local structures.',
    'GREEN' => 'ALL CLEAR: Tremors subsided. Conduct roll call and mark your emergency status.',
];
$database = smsDatabase();
$records = smsRecent($database);
$selectedLevel = $_GET['level'] ?? 'RED';
if (!array_key_exists($selectedLevel, $messages)) $selectedLevel = 'RED';
$sendResult = $_GET['sent'] ?? null;
$latestRecord = $records[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ready Alert SMS</title>
    <style>
        :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, sans-serif; background: #08111f; color: #e5edf7; }
        body { margin: 0; background: radial-gradient(circle at top right, #17324b, #08111f 45%); min-height: 100vh; }
        main { width: min(1100px, calc(100% - 32px)); margin: 0 auto; padding: 42px 0 64px; }
        h1, h2, p { margin-top: 0; } h1 { margin-bottom: 8px; font-size: clamp(2rem, 5vw, 3.5rem); } h2 { font-size: 1.15rem; }
        .muted { color: #91a4bb; } .panel { background: rgba(13, 28, 46, .88); border: 1px solid #27415d; border-radius: 16px; padding: 22px; margin-top: 24px; box-shadow: 0 18px 50px rgba(0,0,0,.22); }
        form { display: grid; gap: 16px; } label { display: grid; gap: 7px; color: #b8c9dc; font-size: .82rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        input, textarea, button { font: inherit; border-radius: 9px; border: 1px solid #385673; padding: 11px 12px; } input, textarea { background: #091827; color: #f5f8fc; } textarea { min-height: 82px; resize: vertical; }
        .levels { display: flex; flex-wrap: wrap; gap: 10px; } .levels label { display: flex; flex: 1 1 120px; } .levels input { position: absolute; opacity: 0; } .levels span { cursor: pointer; text-align: center; padding: 13px; border-radius: 9px; border: 1px solid #385673; }
        .levels input:checked + span { outline: 2px solid #f5f8fc; outline-offset: 2px; } .red { background: #9f2f3d; } .yellow { background: #9a7211; } .green { background: #187453; }
        button { background: #e9f1fa; color: #0b1a2b; cursor: pointer; font-weight: 800; } button:hover { background: white; }
        table { width: 100%; border-collapse: collapse; } th, td { text-align: left; vertical-align: top; padding: 13px 10px; border-bottom: 1px solid #233b54; } th { color: #8fa8c1; font-size: .75rem; text-transform: uppercase; } td { font-size: .9rem; } .message { min-width: 260px; white-space: pre-wrap; color: #cbd9e8; } .status { font-weight: 800; text-transform: capitalize; } .successful { color: #5de0a4; } .pending { color: #f4cf62; } .failed { color: #ff7b83; } .empty { color: #91a4bb; padding: 16px 0; }
        @media (max-width: 700px) { main { width: min(100% - 20px, 1100px); padding-top: 24px; } .panel { padding: 16px; } table, thead, tbody, tr, th, td { display: block; } thead { display: none; } tr { padding: 14px 0; border-bottom: 1px solid #233b54; } td { border: 0; padding: 5px 0; } td::before { content: attr(data-label); display: block; color: #7890a9; font-size: .7rem; font-weight: 800; text-transform: uppercase; margin-bottom: 3px; } .message { min-width: 0; } }
    </style>
</head>
<body>
<main>
    <h1>Ready Alert SMS</h1>
    <p class="muted">Send the same tri-alarm messages used by the Ready Alert frontend and review every result.</p>
    <?php if ($sendResult === '1'): ?>
        <div class="panel successful"><strong>SMS sent successfully.</strong> The gateway accepted the message and it is recorded below.</div>
    <?php elseif ($sendResult === '0'): ?>
        <div class="panel failed"><strong>SMS send failed.</strong> Check the error shown in the delivery history below.</div>
    <?php endif; ?>
    <section class="panel">
        <h2>Frontend connection</h2>
        <?php if ($latestRecord): ?>
            <p class="successful"><strong>Connected.</strong> The PHP service has received an alert request.</p>
            <p class="muted">Latest request: <?= htmlspecialchars($latestRecord['updated_at']) ?> · <?= htmlspecialchars($latestRecord['status']) ?> · <?= htmlspecialchars($latestRecord['alert_id'] ?? 'manual SMS') ?></p>
        <?php else: ?>
            <p class="failed"><strong>No frontend request received yet.</strong> Check the React/Vercel SMS URL, API keys, and PHP service URL.</p>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>Send alert message</h2>
        <form method="post" action="function/sms.php">
            <div class="levels">
                <?php foreach ($messages as $level => $message): ?>
                    <label>
                        <input type="radio" name="alert_level" value="<?= htmlspecialchars($level) ?>" <?= $selectedLevel === $level ? 'checked' : '' ?> onchange="document.getElementById('message').value = this.dataset.message" data-message="<?= htmlspecialchars($message, ENT_QUOTES) ?>">
                        <span class="<?= strtolower($level) ?>"><?= htmlspecialchars($level) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <label>Phone number (09XXXXXXXXX or +639XXXXXXXXX)<input name="number" inputmode="tel" required placeholder="09171234567"></label>
            <label>Message<textarea id="message" name="message" required><?= htmlspecialchars($messages[$selectedLevel]) ?></textarea></label>
            <button type="submit">Send SMS</button>
        </form>
    </section>
    <section class="panel">
        <h2>SMS delivery history</h2>
        <?php if (!$records): ?>
            <p class="empty">No SMS attempts have been recorded yet.</p>
        <?php else: ?>
            <table><thead><tr><th>Status</th><th>Alert</th><th>Recipient</th><th>Message</th><th>Alert ID</th><th>Updated</th></tr></thead><tbody>
            <?php foreach ($records as $record): ?>
                <tr><td data-label="Status" class="status <?= htmlspecialchars($record['status']) ?>"><?= htmlspecialchars($record['status']) ?></td><td data-label="Alert"><?= htmlspecialchars($record['alert_level']) ?></td><td data-label="Recipient"><?= htmlspecialchars($record['phone_number']) ?></td><td data-label="Message" class="message"><?= htmlspecialchars($record['message']) ?><?php if ($record['error_message']): ?><br><span class="failed"><?= htmlspecialchars($record['error_message']) ?></span><?php endif; ?></td><td data-label="Alert ID" class="muted"><?= htmlspecialchars($record['alert_id'] ?? '') ?></td><td data-label="Updated" class="muted"><?= htmlspecialchars($record['updated_at']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>