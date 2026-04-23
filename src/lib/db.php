<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'card_puzzle';
    $user = getenv('DB_USER') ?: 'app_user';
    $password = getenv('DB_PASSWORD') ?: 'app_password';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function seedDefaults(PDO $pdo): void
{
    // Додаємо дві команди, якщо база ще порожня.
    $teamCount = (int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
    if ($teamCount === 0) {
        $stmt = $pdo->prepare('INSERT INTO teams (name, color) VALUES (?, ?), (?, ?)');
        $stmt->execute(['Команда Північ', '#3b82f6', 'Команда Південь', '#f97316']);
    }

    // Створюємо адміністратора за замовчуванням.
    $adminUsername = getenv('ADMIN_USERNAME') ?: 'admin';
    $adminPassword = getenv('ADMIN_PASSWORD') ?: 'admin12345';
    $adminExists = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND username = ? LIMIT 1");
    $adminExists->execute([$adminUsername]);

    if (!$adminExists->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO users (role, username, full_name, password_hash) VALUES ('admin', ?, ?, ?)");
        $stmt->execute([$adminUsername, 'Адміністратор', password_hash($adminPassword, PASSWORD_DEFAULT)]);
    }
}
