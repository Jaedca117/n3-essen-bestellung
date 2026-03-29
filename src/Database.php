<?php

declare(strict_types=1);

final class Database
{
    public static function connect(array $dbConfig): PDO
    {
        $host = (string) ($dbConfig['host'] ?? '127.0.0.1');
        $port = (int) ($dbConfig['port'] ?? 3306);
        $name = (string) ($dbConfig['name'] ?? '');
        $user = (string) ($dbConfig['user'] ?? '');
        $pass = (string) ($dbConfig['pass'] ?? '');
        $charset = (string) ($dbConfig['charset'] ?? 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
