<?php
$config = require __DIR__ . '/config.php';
session_name($config['session_name']);
session_start();
$pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_pass'], [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function require_login() {
  if (empty($_SESSION['user_id'])) {
    $to = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/audit/';
    header('Location: /login.php?next=' . urlencode($to));
    exit;
  }
}
