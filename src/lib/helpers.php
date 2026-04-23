<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pullFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $flash;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(?string $token): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        exit('Помилка CSRF-захисту. Оновіть сторінку та повторіть дію.');
    }
}

function detectOs(string $userAgent): string
{
    $map = [
        'Windows' => 'Windows',
        'Mac OS X' => 'macOS',
        'Android' => 'Android',
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'Linux' => 'Linux',
    ];

    foreach ($map as $needle => $label) {
        if (stripos($userAgent, $needle) !== false) {
            return $label;
        }
    }

    return 'Невідомо';
}

function detectBrowser(string $userAgent): string
{
    $map = [
        'Edg/' => 'Edge',
        'Chrome/' => 'Chrome',
        'Firefox/' => 'Firefox',
        'Safari/' => 'Safari',
        'OPR/' => 'Opera',
    ];

    foreach ($map as $needle => $label) {
        if (stripos($userAgent, $needle) !== false) {
            return $label;
        }
    }

    return 'Невідомо';
}

function detectDeviceType(string $userAgent): string
{
    if (stripos($userAgent, 'iPad') !== false || stripos($userAgent, 'Tablet') !== false) {
        return 'tablet';
    }

    if (stripos($userAgent, 'Mobile') !== false || stripos($userAgent, 'Android') !== false) {
        return 'mobile';
    }

    return 'desktop';
}

function logRequest(PDO $pdo, ?int $userId): void
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $apiAction = $_GET['api'] ?? null;

    // Не логувати постійне опитування стану гри, щоб не засмічувати таблицю.
    if ($apiAction === 'game-state') {
        return;
    }

    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Невідомо', 0, 255);
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 64);

    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, ip_address, user_agent, os_name, browser_name, device_type, request_path, request_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $userId,
        $ip,
        $userAgent,
        detectOs($userAgent),
        detectBrowser($userAgent),
        detectDeviceType($userAgent),
        substr($path, 0, 180),
        substr($_SERVER['REQUEST_METHOD'] ?? 'GET', 0, 10),
    ]);
}

function shapeForPiece(string $shapeMode, int $index): string
{
    if ($shapeMode === 'square') {
        return 'inset(0 round 14px)';
    }

    $mixedShapes = [
        'polygon(0 12%, 84% 0, 100% 80%, 10% 100%)',
        'polygon(12% 0, 100% 12%, 86% 100%, 0 88%)',
        'polygon(0 18%, 78% 0, 100% 58%, 24% 100%)',
        'polygon(18% 0, 100% 24%, 82% 100%, 0 76%)',
    ];

    $irregularShapes = [
        'polygon(0 16%, 70% 0, 100% 26%, 90% 100%, 12% 88%)',
        'polygon(22% 0, 100% 18%, 80% 100%, 0 78%, 8% 20%)',
        'polygon(0 20%, 52% 0, 100% 34%, 82% 100%, 0 92%)',
        'polygon(10% 0, 100% 10%, 100% 82%, 26% 100%, 0 56%)',
        'polygon(0 12%, 88% 0, 100% 72%, 52% 100%, 0 84%)',
    ];

    if ($shapeMode === 'mixed') {
        return $mixedShapes[$index % count($mixedShapes)];
    }

    return $irregularShapes[$index % count($irregularShapes)];
}

function formatDateTime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '—';
    }

    return date('d.m.Y H:i', $timestamp);
}
