<?php
declare(strict_types=1);

namespace App\Support;

class AdminAuth
{
    public const COOKIE = 'travel_assist_admin';
    private const TTL = 60 * 60 * 8; // 8 hours

    public static function issueSession(int $adminId, string $username): void
    {
        $token = Jwt::encode([
            'aid' => $adminId,
            'usr' => $username,
            'iat' => time(),
            'exp' => time() + self::TTL,
        ], (string) Settings::get('jwt_secret'));

        setcookie(self::COOKIE, $token, [
            'expires' => time() + self::TTL,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isHttps(),
        ]);
    }

    public static function clearSession(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isHttps(),
        ]);
    }

    public static function currentAdmin(): ?array
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if ($token === '') {
            return null;
        }

        return Jwt::decode($token, (string) Settings::get('jwt_secret'));
    }

    /** Ends the request with a 401 JSON body if there is no valid admin session. */
    public static function requireAuth(): array
    {
        $admin = self::currentAdmin();
        if ($admin === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        return $admin;
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) === '443';
    }
}
