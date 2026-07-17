<?php

namespace App;

class Database
{
    private static ?\PDO $instance = null;

    public static function getInstance(): \PDO
    {
        if (self::$instance === null) {
            $dsn  = $_ENV['DB_DSN']  ?? 'mysql:host=db;dbname=db;charset=utf8mb4';
            $user = $_ENV['DB_USER'] ?? 'db';
            $pass = $_ENV['DB_PASS'] ?? 'db';

            self::$instance = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$instance;
    }
}
