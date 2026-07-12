<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

// Resolves (or lazily creates) the anonymous visitor session shared by the
// advisor chat and the plan builder. Identity lives in a signed JWT cookie;
// the matching row in `sessions` anchors any messages or plans to that visitor.
class Session
{
    private const COOKIE = 'travel_assist_session';
    private const TTL = 60 * 60 * 24 * 7; // 7 days

    public static function resolve(PDO $pdo): string
    {
        $secret = (string) Settings::get('jwt_secret', 'dev-secret-change-me');
        $token = $_COOKIE[self::COOKIE] ?? '';
        $payload = $token !== '' ? Jwt::decode($token, $secret) : null;

        if ($payload !== null && isset($payload['sid'])) {
            return (string) $payload['sid'];
        }

        $sessionId = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare('INSERT INTO sessions (id, created_at) VALUES (?, ?)');
        $stmt->execute([$sessionId, gmdate('c')]);

        $newToken = Jwt::encode([
            'sid' => $sessionId,
            'iat' => time(),
            'exp' => time() + self::TTL,
        ], $secret);

        setcookie(self::COOKIE, $newToken, [
            'expires' => time() + self::TTL,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $sessionId;
    }
}
