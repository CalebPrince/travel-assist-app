<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Database;
use App\Support\GroqClient;
use App\Support\Session;
use PDO;
use Throwable;

class ChatController
{
    private const HISTORY_LIMIT = 20;

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
        $sessionId = Session::resolve($pdo);

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

    private static function loadHistory(PDO $pdo, string $sessionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT role, content FROM messages WHERE session_id = ? ORDER BY id ASC LIMIT ' . self::HISTORY_LIMIT
        );
        $stmt->execute([$sessionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function callGroq(array $history): string
    {
        $systemPrompt = file_get_contents(__DIR__ . '/../Support/Prompts/immigration_advisor.txt');

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $m) {
            $messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        return GroqClient::complete($messages);
    }
}
