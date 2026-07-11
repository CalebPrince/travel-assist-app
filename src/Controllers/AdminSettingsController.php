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

        $apiKey = (string) Settings::get('anthropic_api_key', '');

        echo json_encode([
            'anthropic_api_key_set' => $apiKey !== '',
            'anthropic_api_key_preview' => self::maskKey($apiKey),
            'anthropic_model' => Settings::get('anthropic_model', 'claude-sonnet-5'),
        ]);
    }

    public static function update(): void
    {
        header('Content-Type: application/json');
        AdminAuth::requireAuth();

        $input = json_decode((string) file_get_contents('php://input'), true) ?? [];

        if (array_key_exists('anthropic_api_key', $input)) {
            $apiKey = trim((string) $input['anthropic_api_key']);
            if ($apiKey !== '') {
                Settings::set('anthropic_api_key', $apiKey);
            }
        }

        if (array_key_exists('anthropic_model', $input)) {
            $model = trim((string) $input['anthropic_model']);
            if ($model !== '') {
                Settings::set('anthropic_model', $model);
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
