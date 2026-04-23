<?php

declare(strict_types=1);

function currentUser(PDO $pdo): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT users.*, teams.name AS team_name, teams.color AS team_color FROM users LEFT JOIN teams ON teams.id = users.team_id WHERE users.id = ? LIMIT 1');
    $stmt->execute([(int) $userId]);

    return $stmt->fetch() ?: null;
}

function requireLogin(PDO $pdo): array
{
    $user = currentUser($pdo);
    if (!$user) {
        flash('error', 'Спочатку увійдіть у систему.');
        redirectTo('/');
    }

    return $user;
}

function requireAdmin(PDO $pdo): array
{
    $user = requireLogin($pdo);
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Доступ дозволено лише адміністратору.');
    }

    return $user;
}

function loginStudent(PDO $pdo, string $fullName, string $className): void
{
    $fullName = trim($fullName);
    $className = trim($className);

    if ($fullName === '' || $className === '') {
        flash('error', 'Вкажіть ім’я та клас.');
        redirectTo('/');
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'student' AND full_name = ? AND class_name = ? LIMIT 1");
    $stmt->execute([$fullName, $className]);
    $user = $stmt->fetch();

    if (!$user) {
        $teamId = autoAssignTeamId($pdo);
        $insert = $pdo->prepare("INSERT INTO users (role, full_name, class_name, team_id) VALUES ('student', ?, ?, ?)");
        $insert->execute([$fullName, $className, $teamId]);
        $_SESSION['user_id'] = (int) $pdo->lastInsertId();
        flash('success', 'Реєстрацію завершено. Ви автоматично додані до команди.');
        return;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    flash('success', 'Раді бачити вас знову.');
}

function loginAdmin(PDO $pdo, string $username, string $password): void
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'admin' AND username = ? LIMIT 1");
    $stmt->execute([trim($username)]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, (string) $admin['password_hash'])) {
        flash('error', 'Невірний логін або пароль адміністратора.');
        redirectTo('/');
    }

    $_SESSION['user_id'] = (int) $admin['id'];
    flash('success', 'Вхід адміністратора виконано успішно.');
}

function logoutUser(): void
{
    session_unset();
    session_destroy();
    session_start();
}

function autoAssignTeamId(PDO $pdo): ?int
{
    $teams = $pdo->query('SELECT teams.id, COUNT(users.id) AS members FROM teams LEFT JOIN users ON users.team_id = teams.id AND users.role = "student" GROUP BY teams.id ORDER BY members ASC, teams.id ASC')->fetchAll();

    return $teams[0]['id'] ?? null;
}
