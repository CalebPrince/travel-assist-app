<?php
declare(strict_types=1);

namespace App\Support;

class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::loadCache();
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $stmt->execute([$key, $value, gmdate('c')]);

        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    private static function loadCache(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];
        $pdo = Database::connection();
        foreach ($pdo->query('SELECT key, value FROM settings') as $row) {
            self::$cache[$row['key']] = $row['value'];
        }
    }
}
