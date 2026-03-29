<?php

declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Konfiguration fehlt. Bitte config.sample.php nach config.php kopieren und anpassen.';
    exit;
}

$config = require $configPath;

date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Berlin'));

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AppRepository.php';
require_once __DIR__ . '/AppService.php';
require_once __DIR__ . '/helpers.php';
