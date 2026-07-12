<?php
declare(strict_types=1);

$dbPath = __DIR__ . '/app.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$schema = file_get_contents(__DIR__ . '/schema.sql');
$pdo->exec($schema);

function seedSettingIfMissing(PDO $pdo, string $key, string $value): void
{
    $check = $pdo->prepare('SELECT 1 FROM settings WHERE key = ?');
    $check->execute([$key]);

    if ($check->fetchColumn() === false) {
        $insert = $pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)');
        $insert->execute([$key, $value, gmdate('c')]);
    }
}

// JWT signing secret shared by admin sessions and advisor chat sessions.
seedSettingIfMissing($pdo, 'jwt_secret', bin2hex(random_bytes(32)));

// Advisor configuration, editable later from the admin settings page.
seedSettingIfMissing($pdo, 'groq_api_key', '');
seedSettingIfMissing($pdo, 'groq_model', 'llama-3.3-70b-versatile');

// Create a one-time default admin account if none exists yet.
$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
if ($adminCount === 0) {
    $password = bin2hex(random_bytes(6));
    $hash = password_hash($password, PASSWORD_BCRYPT);

    $insert = $pdo->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, ?)');
    $insert->execute(['admin', $hash, gmdate('c')]);

    echo str_repeat('=', 60) . PHP_EOL;
    echo 'Created default admin account:' . PHP_EOL;
    echo '  username: admin' . PHP_EOL;
    echo "  password: {$password}" . PHP_EOL;
    echo 'Log in at /admin/login.html and change this password immediately.' . PHP_EOL;
    echo str_repeat('=', 60) . PHP_EOL;
}

echo "Migrations applied to {$dbPath}" . PHP_EOL;
