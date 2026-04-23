<?php

declare(strict_types=1);

function envRequired(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException(sprintf('Не задано обов’язкову змінну середовища %s.', $name));
    }

    return $value;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = envRequired('DB_HOST');
    $port = envRequired('DB_PORT');
    $name = envRequired('DB_NAME');
    $user = envRequired('DB_USER');
    $password = envRequired('DB_PASSWORD');

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
    $adminUsername = envRequired('ADMIN_USERNAME');
    $adminPassword = envRequired('ADMIN_PASSWORD');
    $adminExists = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND username = ? LIMIT 1");
    $adminExists->execute([$adminUsername]);

    if (!$adminExists->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO users (role, username, full_name, password_hash) VALUES ('admin', ?, ?, ?)");
        $stmt->execute([$adminUsername, 'Адміністратор', password_hash($adminPassword, PASSWORD_DEFAULT)]);
    }
}
