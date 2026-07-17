<?php

declare(strict_types=1);

$dsn = getenv('TEST_DB_DSN');
if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "TEST_DB_DSN is required.\n");
    exit(1);
}

$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
]);

$migrations = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($migrations, SORT_NATURAL);
foreach ($migrations as $migration) {
    $sql = file_get_contents($migration);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$migration}");
    }
    $pdo->exec($sql);
    echo basename($migration) . " applied\n";
}
