<?php

declare(strict_types=1);

return [
    'app_name' => 'Vereins-Essen',
    'app_logo' => 'assets/vereinslogo.png', // Optional: Pfad relativ zu /public
    'timezone' => 'Europe/Berlin',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'meine_datenbank',
        'user' => 'db_user',
        'pass' => 'db_passwort',
        'charset' => 'utf8mb4',
        'table_prefix' => 'n3_essen_',
    ],
];
