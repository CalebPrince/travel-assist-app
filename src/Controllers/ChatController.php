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
            $reply = self::callGroq($history);
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

    private const GROQ_ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    private static function callGroq(array $history): string
    {
        $apiKey = Settings::get('groq_api_key');
        if (!$apiKey) {
            throw new RuntimeException('The Groq API key has not been configured yet in the admin settings.');
        }

        $systemPrompt = file_get_contents(__DIR__ . '/../Support/Prompts/immigration_advisor.txt');
        $model = Settings::get('groq_model', 'llama-3.3-70b-versatile');

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $m) {
            $messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        $payload = json_encode([
            'model' => $model,
            'max_tokens' => 1500,
            'messages' => $messages,
        ]);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];

        [$status, $response] = function_exists('curl_init')
            ? self::postViaCurl($payload, $headers)
            : self::postViaStream($payload, $headers);

        $decoded = json_decode($response, true);

        if ($status !== 200) {
            $apiMessage = $decoded['error']['message'] ?? 'Unknown API error';
            throw new RuntimeException("Groq API error ({$status}): {$apiMessage}");
        }

        return (string) ($decoded['choices'][0]['message']['content'] ?? '');
    }

    /** @return array{0: int, 1: string} */
    private static function postViaCurl(string $payload, array $headers): array
    {
        $ch = curl_init(self::GROQ_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Failed to reach the Groq API: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $response];
    }

    /** @return array{0: int, 1: string} */
    private static function postViaStream(string $payload, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::GROQ_ENDPOINT, false, $context);
        if ($response === false) {
            throw new RuntimeException('Failed to reach the Groq API.');
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return [$status, $response];
    }
}
