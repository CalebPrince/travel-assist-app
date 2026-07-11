<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Database;
use App\Support\Jwt;
use App\Support\Settings;
use PDO;
use RuntimeException;
use Throwable;

class ChatController
{
    private const SESSION_COOKIE = 'travel_assist_session';
    private const SESSION_TTL = 60 * 60 * 24 * 7; // 7 days
    private const HISTORY_LIMIT = 40;

    public static function handle(): void
    {
        header('Content-Type: application/json');

        $input = json_decode((string) file_get_contents('php://input'), true);
        $message = trim((string) ($input['message'] ?? ''));

        if ($message === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Message is required.']);
            return;
        }

        $pdo = Database::connection();
        $sessionId = self::resolveSession($pdo);

        $insert = $pdo->prepare('INSERT INTO messages (session_id, role, content, created_at) VALUES (?, ?, ?, ?)');
        $insert->execute([$sessionId, 'user', $message, gmdate('c')]);

        $history = self::loadHistory($pdo, $sessionId);

        try {
            $reply = self::callAnthropic($history);
        } catch (Throwable $e) {
            http_response_code(502);
            echo json_encode(['error' => 'AI service unavailable.', 'detail' => $e->getMessage()]);
            return;
        }

        $insert->execute([$sessionId, 'assistant', $reply, gmdate('c')]);

        echo json_encode(['reply' => $reply]);
    }

    private static function resolveSession(PDO $pdo): string
    {
        $secret = Settings::get('jwt_secret', 'dev-secret-change-me');
        $token = $_COOKIE[self::SESSION_COOKIE] ?? '';
        $payload = $token !== '' ? Jwt::decode($token, (string) $secret) : null;

        if ($payload !== null && isset($payload['sid'])) {
            return (string) $payload['sid'];
        }

        $sessionId = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare('INSERT INTO sessions (id, created_at) VALUES (?, ?)');
        $stmt->execute([$sessionId, gmdate('c')]);

        $newToken = Jwt::encode([
            'sid' => $sessionId,
            'iat' => time(),
            'exp' => time() + self::SESSION_TTL,
        ], (string) $secret);

        setcookie(self::SESSION_COOKIE, $newToken, [
            'expires' => time() + self::SESSION_TTL,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $sessionId;
    }

    private static function loadHistory(PDO $pdo, string $sessionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT role, content FROM messages WHERE session_id = ? ORDER BY id ASC LIMIT ' . self::HISTORY_LIMIT
        );
        $stmt->execute([$sessionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function callAnthropic(array $history): string
    {
        $apiKey = Settings::get('anthropic_api_key');
        if (!$apiKey) {
            throw new RuntimeException('The Anthropic API key has not been configured yet in the admin settings.');
        }

        $systemPrompt = file_get_contents(__DIR__ . '/../Support/Prompts/immigration_advisor.txt');
        $model = Settings::get('anthropic_model', 'claude-sonnet-5');

        $messages = array_map(
            static fn (array $m): array => ['role' => $m['role'], 'content' => $m['content']],
            $history
        );

        $payload = json_encode([
            'model' => $model,
            'max_tokens' => 1500,
            'system' => $systemPrompt,
            'messages' => $messages,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKey,
                    'anthropic-version: 2023-06-01',
                ]),
                'content' => $payload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('https://api.anthropic.com/v1/messages', false, $context);
        if ($response === false) {
            throw new RuntimeException('Failed to reach the Anthropic API.');
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        $decoded = json_decode($response, true);

        if ($status !== 200) {
            $apiMessage = $decoded['error']['message'] ?? 'Unknown API error';
            throw new RuntimeException("Anthropic API error ({$status}): {$apiMessage}");
        }

        $text = '';
        foreach ($decoded['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        return $text;
    }
}
