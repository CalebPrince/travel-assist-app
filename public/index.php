<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

use App\Router;

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (!str_starts_with($path, '/api/')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return;
}

$router = new Router();
$router->add('POST', '/api/v1/chat', [App\Controllers\ChatController::class, 'handle']);
$router->add('POST', '/api/v1/admin/login', [App\Controllers\AdminAuthController::class, 'login']);
$router->add('POST', '/api/v1/admin/logout', [App\Controllers\AdminAuthController::class, 'logout']);
$router->add('GET', '/api/v1/admin/me', [App\Controllers\AdminAuthController::class, 'me']);
$router->add('POST', '/api/v1/admin/change-password', [App\Controllers\AdminAuthController::class, 'changePassword']);
$router->add('GET', '/api/v1/admin/settings', [App\Controllers\AdminSettingsController::class, 'show']);
$router->add('POST', '/api/v1/admin/settings', [App\Controllers\AdminSettingsController::class, 'update']);
$router->dispatch($_SERVER['REQUEST_METHOD'], $path);
