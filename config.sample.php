<?php

declare(strict_types=1);

return [
    'app_name' => 'N3 Essen-Bestellung',
    'timezone' => 'Europe/Berlin',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'meine_datenbank',
        'user' => 'db_user',
        'pass' => 'db_passwort',
        'charset' => 'utf8mb4',
        // Sehr wichtig bei geteilter Datenbanknutzung mit anderen Anwendungen.
        'table_prefix' => 'n3_essen_',
    ],
];
