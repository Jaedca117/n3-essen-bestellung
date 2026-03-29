<?php

declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Konfiguration fehlt. Bitte config.sample.php nach config.php kopieren und anpassen.';
    exit;
}

$config = require $configPath;

date_default_timezone_set((string) ($config['timezone'] ?? 'UTC'));

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/OrderRepository.php';
