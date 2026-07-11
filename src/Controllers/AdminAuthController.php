<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\AdminAuth;
use App\Support\Database;
use PDO;

class AdminAuthController
{
    public static function login(): void
    {
        header('Content-Type: application/json');

        $input = json_decode((string) file_get_contents('php://input'), true);
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($username === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Username and password are required.']);
            return;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid username or password.']);
            return;
        }

        AdminAuth::issueSession((int) $admin['id'], $admin['username']);
        echo json_encode(['ok' => true, 'username' => $admin['username']]);
    }

    public static function logout(): void
    {
        header('Content-Type: application/json');
        AdminAuth::clearSession();
        echo json_encode(['ok' => true]);
    }

    public static function me(): void
    {
        header('Content-Type: application/json');
        $admin = AdminAuth::requireAuth();
        echo json_encode(['username' => $admin['usr']]);
    }

    public static function changePassword(): void
    {
        header('Content-Type: application/json');
        $admin = AdminAuth::requireAuth();

        $input = json_decode((string) file_get_contents('php://input'), true);
        $currentPassword = (string) ($input['current_password'] ?? '');
        $newPassword = (string) ($input['new_password'] ?? '');

        if (strlen($newPassword) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'New password must be at least 8 characters.']);
            return;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $stmt->execute([$admin['aid']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Current password is incorrect.']);
            return;
        }

        $update = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
        $update->execute([password_hash($newPassword, PASSWORD_BCRYPT), $admin['aid']]);

        echo json_encode(['ok' => true]);
    }
}
