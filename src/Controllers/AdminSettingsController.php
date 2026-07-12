<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\AdminAuth;
use App\Support\Settings;

class AdminSettingsController
{
    public static function show(): void
    {
        header('Content-Type: application/json');
        AdminAuth::requireAuth();

        $apiKey = (string) Settings::get('groq_api_key', '');

        echo json_encode([
            'groq_api_key_set' => $apiKey !== '',
            'groq_api_key_preview' => self::maskKey($apiKey),
            'groq_model' => Settings::get('groq_model', 'llama-3.1-8b-instant'),
        ]);
    }

    public static function update(): void
    {
        header('Content-Type: application/json');
        AdminAuth::requireAuth();

        $input = json_decode((string) file_get_contents('php://input'), true) ?? [];

        if (array_key_exists('groq_api_key', $input)) {
            $apiKey = trim((string) $input['groq_api_key']);
            if ($apiKey !== '') {
                Settings::set('groq_api_key', $apiKey);
            }
        }

        if (array_key_exists('groq_model', $input)) {
            $model = trim((string) $input['groq_model']);
            if ($model !== '') {
                Settings::set('groq_model', $model);
            }
        }

        echo json_encode(['ok' => true]);
    }

    private static function maskKey(string $key): string
    {
        if ($key === '') {
            return '';
        }

        $tail = substr($key, -4);
        return str_repeat('•', max(0, strlen($key) - 4)) . $tail;
    }
}
