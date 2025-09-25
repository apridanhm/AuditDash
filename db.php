<?php
// db.php - bootstrap PDO dari config array
declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';
if (!is_array($cfg) || empty($cfg['db_dsn'])) {
    throw new RuntimeException('config.php tidak valid');
}

$pdo = new PDO(
    $cfg['db_dsn'],
    $cfg['db_user'] ?? '',
    $cfg['db_pass'] ?? '',
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

