<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Database;
use App\Support\GroqClient;
use App\Support\Session;
use PDO;
use Throwable;

// Backs the plan builder: turns a completed intake into one generated,
// phased checklist (stored per visitor session) and persists which items
// the visitor has ticked off so the dashboard survives a page reload.
class PlanController
{
    private const INTENTS = ['study', 'travel'];

    // Generate a fresh plan from the wizard's intake answers.
    public static function create(): void
    {
        header('Content-Type: application/json');

        $input = json_decode((string) file_get_contents('php://input'), true);
        $intake = is_array($input['intake'] ?? null) ? $input['intake'] : [];
        $intent = (string) ($intake['intent'] ?? '');

        if (!in_array($intent, self::INTENTS, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'A valid intent (study or travel) is required.']);
            return;
        }

        try {
            $plan = self::generatePlan($intent, $intake);
        } catch (Throwable $e) {
            http_response_code(502);
            echo json_encode(['error' => 'Could not generate your plan.', 'detail' => $e->getMessage()]);
            return;
        }

        $pdo = Database::connection();
        $sessionId = Session::resolve($pdo);
        $now = gmdate('c');

        $stmt = $pdo->prepare(
            'INSERT INTO plans (session_id, intent, intake_json, plan_json, checked_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            $intent,
            json_encode($intake),
            json_encode($plan),
            '[]',
            $now,
            $now,
        ]);

        echo json_encode([
            'id' => (int) $pdo->lastInsertId(),
            'plan' => $plan,
            'checked' => [],
        ]);
    }

    // Return the visitor's most recent plan, so the dashboard can resume.
    public static function show(): void
    {
        header('Content-Type: application/json');

        $pdo = Database::connection();
        $sessionId = Session::resolve($pdo);

        $stmt = $pdo->prepare(
            'SELECT id, plan_json, checked_json FROM plans WHERE session_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            echo json_encode(['plan' => null]);
            return;
        }

        echo json_encode([
            'id' => (int) $row['id'],
            'plan' => json_decode((string) $row['plan_json'], true),
            'checked' => json_decode((string) $row['checked_json'], true) ?: [],
        ]);
    }

    // Persist the visitor's checked-off items for a given plan.
    public static function progress(): void
    {
        header('Content-Type: application/json');

        $input = json_decode((string) file_get_contents('php://input'), true);
        $planId = (int) ($input['id'] ?? 0);
        $checked = $input['checked'] ?? null;

        if ($planId <= 0 || !is_array($checked)) {
            http_response_code(400);
            echo json_encode(['error' => 'A plan id and a checked list are required.']);
            return;
        }

        // Keep only scalar item keys, so we store a clean, predictable array.
        $checked = array_values(array_filter($checked, 'is_string'));

        $pdo = Database::connection();
        $sessionId = Session::resolve($pdo);

        // Scope the update to this visitor's own plan — a session can never
        // touch another visitor's checklist even if it guesses the id.
        $stmt = $pdo->prepare(
            'UPDATE plans SET checked_json = ?, updated_at = ? WHERE id = ? AND session_id = ?'
        );
        $stmt->execute([json_encode($checked), gmdate('c'), $planId, $sessionId]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Plan not found for this session.']);
            return;
        }

        echo json_encode(['ok' => true]);
    }

    private static function generatePlan(string $intent, array $intake): array
    {
        $systemPrompt = file_get_contents(__DIR__ . '/../Support/Prompts/plan_generator.txt');

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => self::describeIntake($intent, $intake)],
        ];

        $raw = GroqClient::complete($messages, [
            'max_tokens' => 2500,
            'response_format' => ['type' => 'json_object'],
        ]);

        $plan = json_decode($raw, true);
        if (!is_array($plan) || !isset($plan['phases']) || !is_array($plan['phases'])) {
            throw new \RuntimeException('The AI returned an unexpected plan format. Please try again.');
        }

        return $plan;
    }

    // Render the wizard answers as a readable brief for the model. Only the
    // fields the wizard actually collected are included.
    private static function describeIntake(string $intent, array $intake): string
    {
        $labels = [
            'intent' => 'Journey type',
            'level' => 'Level of study',
            'field' => 'Field / program of interest',
            'purpose' => 'Purpose of travel',
            'passport' => 'Passport / citizenship country',
            'destination' => 'Target destination',
            'timeline' => 'Timeline',
            'budget' => 'Budget',
        ];

        $intentText = $intent === 'study' ? 'Studying abroad' : 'General travel';
        $lines = ["Journey type: {$intentText}"];

        foreach ($labels as $key => $label) {
            if ($key === 'intent') {
                continue;
            }
            $value = trim((string) ($intake[$key] ?? ''));
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }

        return "Here is the visitor's intake. Produce their personalized plan as JSON.\n\n"
            . implode("\n", $lines);
    }
}
