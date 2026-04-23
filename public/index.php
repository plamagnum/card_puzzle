<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function fetchStudents(PDO $pdo): array
{
    return $pdo->query("SELECT users.*, teams.name AS team_name, teams.color AS team_color FROM users LEFT JOIN teams ON teams.id = users.team_id WHERE users.role = 'student' ORDER BY users.created_at ASC")->fetchAll();
}

function fetchTeams(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM teams ORDER BY id ASC')->fetchAll();
}

function fetchTasks(PDO $pdo, array $user): array
{
    if (($user['role'] ?? '') === 'admin') {
        $sql = 'SELECT tasks.*, users.full_name, users.class_name FROM tasks INNER JOIN users ON users.id = tasks.user_id ORDER BY tasks.updated_at DESC';
        return $pdo->query($sql)->fetchAll();
    }

    $stmt = $pdo->prepare('SELECT * FROM tasks WHERE user_id = ? ORDER BY updated_at DESC');
    $stmt->execute([(int) $user['id']]);
    return $stmt->fetchAll();
}

function fetchRounds(PDO $pdo): array
{
    $sql = "SELECT rounds.*, winner.full_name AS winner_name, teams.name AS winner_team_name
            FROM rounds
            LEFT JOIN users AS winner ON winner.id = rounds.winner_user_id
            LEFT JOIN teams ON teams.id = rounds.winner_team_id
            ORDER BY rounds.created_at DESC";
    return $pdo->query($sql)->fetchAll();
}

function fetchActiveRound(PDO $pdo): ?array
{
    $sql = "SELECT rounds.*, winner.full_name AS winner_name, teams.name AS winner_team_name
            FROM rounds
            LEFT JOIN users AS winner ON winner.id = rounds.winner_user_id
            LEFT JOIN teams ON teams.id = rounds.winner_team_id
            WHERE rounds.status = 'active'
            ORDER BY rounds.started_at DESC, rounds.id DESC
            LIMIT 1";
    $round = $pdo->query($sql)->fetch();

    if (!$round) {
        return null;
    }

    $catalog = getPuzzleCatalog();
    $round['puzzle'] = $catalog[$round['template_key']] ?? null;
    return $round;
}

function fetchRoundStats(PDO $pdo): array
{
    $teamWins = $pdo->query("SELECT teams.name, teams.color, COUNT(rounds.id) AS wins FROM teams LEFT JOIN rounds ON rounds.winner_team_id = teams.id GROUP BY teams.id ORDER BY wins DESC, teams.id ASC")->fetchAll();
    $userWins = $pdo->query("SELECT users.full_name, users.class_name, COUNT(rounds.id) AS wins FROM users LEFT JOIN rounds ON rounds.winner_user_id = users.id WHERE users.role = 'student' GROUP BY users.id ORDER BY wins DESC, users.full_name ASC LIMIT 10")->fetchAll();
    return ['teams' => $teamWins, 'users' => $userWins];
}

function fetchActivityLogs(PDO $pdo): array
{
    $sql = "SELECT activity_logs.*, users.full_name
            FROM activity_logs
            LEFT JOIN users ON users.id = activity_logs.user_id
            ORDER BY activity_logs.created_at DESC
            LIMIT 50";
    return $pdo->query($sql)->fetchAll();
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function completeRound(PDO $pdo, array $user, int $roundId): void
{
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM rounds WHERE id = ? FOR UPDATE");
        $stmt->execute([$roundId]);
        $round = $stmt->fetch();

        if (!$round || $round['status'] !== 'active') {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'message' => 'Раунд уже завершено або ще не запущено.'], 409);
        }

        if ($round['winner_user_id']) {
            $pdo->rollBack();
            jsonResponse([
                'ok' => false,
                'locked' => true,
                'message' => 'Хтось із суперників вже переміг.',
                'winnerName' => $round['winner_name'] ?? null,
            ], 409);
        }

        $insert = $pdo->prepare('INSERT INTO round_completions (round_id, user_id, team_id, is_winner) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP, is_winner = 1');
        $insert->execute([$roundId, (int) $user['id'], $user['team_id'] ? (int) $user['team_id'] : null]);

        $update = $pdo->prepare("UPDATE rounds SET status = 'finished', winner_user_id = ?, winner_team_id = ?, finished_at = NOW() WHERE id = ?");
        $update->execute([(int) $user['id'], $user['team_id'] ? (int) $user['team_id'] : null, $roundId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['ok' => false, 'message' => 'Не вдалося зберегти результат раунду.'], 500);
    }

    $teamName = $user['team_name'] ?: 'Без команди';
    jsonResponse([
        'ok' => true,
        'locked' => true,
        'winnerName' => $user['full_name'],
        'winnerTeam' => $teamName,
        'message' => 'Вітаємо! Ви першими склали пазл.',
    ]);
}

if (isset($_GET['api'])) {
    $action = (string) $_GET['api'];

    if ($action === 'game-state') {
        $activeRound = fetchActiveRound($pdo);
        jsonResponse([
            'ok' => true,
            'round' => $activeRound ? [
                'id' => (int) $activeRound['id'],
                'status' => $activeRound['status'],
                'winnerName' => $activeRound['winner_name'],
                'winnerTeam' => $activeRound['winner_team_name'],
                'templateKey' => $activeRound['template_key'],
            ] : null,
        ]);
    }

    if ($action === 'complete-round') {
        $user = requireLogin($pdo);
        if (($user['role'] ?? '') !== 'student') {
            jsonResponse(['ok' => false, 'message' => 'Лише учні можуть завершувати раунди.'], 403);
        }

        verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
        $roundId = (int) ($payload['roundId'] ?? 0);
        if ($roundId <= 0) {
            jsonResponse(['ok' => false, 'message' => 'Некоректний раунд.'], 422);
        }

        completeRound($pdo, $user, $roundId);
    }

    jsonResponse(['ok' => false, 'message' => 'Невідомий API-метод.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'student_login':
            loginStudent($pdo, (string) ($_POST['full_name'] ?? ''), (string) ($_POST['class_name'] ?? ''));
            redirectTo('/');

        case 'admin_login':
            loginAdmin($pdo, (string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
            redirectTo('/');

        case 'logout':
            logoutUser();
            flash('success', 'Ви вийшли з системи.');
            redirectTo('/');

        case 'save_task':
            $user = requireLogin($pdo);
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $details = trim((string) ($_POST['details'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'new');
            $allowedStatuses = ['new', 'in_progress', 'done'];

            if ($title === '' || $details === '' || !in_array($status, $allowedStatuses, true)) {
                flash('error', 'Заповніть назву, опис і коректний статус задачі.');
                redirectTo('/');
            }

            if ($taskId > 0) {
                if (($user['role'] ?? '') === 'admin') {
                    $stmt = $pdo->prepare('UPDATE tasks SET title = ?, details = ?, status = ?, user_id = ? WHERE id = ?');
                    $stmt->execute([$title, $details, $status, (int) ($_POST['user_id'] ?? $user['id']), $taskId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE tasks SET title = ?, details = ?, status = ? WHERE id = ? AND user_id = ?');
                    $stmt->execute([$title, $details, $status, $taskId, (int) $user['id']]);
                }
                flash('success', 'Задачу оновлено.');
            } else {
                $ownerId = ($user['role'] ?? '') === 'admin' ? (int) ($_POST['user_id'] ?? $user['id']) : (int) $user['id'];
                $stmt = $pdo->prepare('INSERT INTO tasks (user_id, title, details, status) VALUES (?, ?, ?, ?)');
                $stmt->execute([$ownerId, $title, $details, $status]);
                flash('success', 'Задачу створено.');
            }
            redirectTo('/');

        case 'delete_task':
            $user = requireLogin($pdo);
            $taskId = (int) ($_POST['task_id'] ?? 0);
            if (($user['role'] ?? '') === 'admin') {
                $stmt = $pdo->prepare('DELETE FROM tasks WHERE id = ?');
                $stmt->execute([$taskId]);
            } else {
                $stmt = $pdo->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?');
                $stmt->execute([$taskId, (int) $user['id']]);
            }
            flash('success', 'Задачу видалено.');
            redirectTo('/');

        case 'save_user_team':
            requireAdmin($pdo);
            $stmt = $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ? AND role = 'student'");
            $stmt->execute([(int) ($_POST['team_id'] ?? 0), (int) ($_POST['user_id'] ?? 0)]);
            flash('success', 'Команду учня оновлено.');
            redirectTo('/');

        case 'delete_user':
            requireAdmin($pdo);
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
            $stmt->execute([(int) ($_POST['user_id'] ?? 0)]);
            flash('success', 'Учня видалено.');
            redirectTo('/');

        case 'start_round':
            $admin = requireAdmin($pdo);
            $templateKey = (string) ($_POST['template_key'] ?? '');
            $catalog = getPuzzleCatalog();
            $puzzle = $catalog[$templateKey] ?? null;

            if (!$puzzle) {
                flash('error', 'Оберіть один з доступних шаблонів пазла.');
                redirectTo('/');
            }

            $pdo->beginTransaction();
            $pdo->exec("UPDATE rounds SET status = 'finished', finished_at = COALESCE(finished_at, NOW()) WHERE status = 'active'");
            $stmt = $pdo->prepare("INSERT INTO rounds (template_key, title, level_label, status, started_at, created_by) VALUES (?, ?, ?, 'active', NOW(), ?)");
            $stmt->execute([$templateKey, $puzzle['title'], $puzzle['level'], (int) $admin['id']]);
            $pdo->commit();
            flash('success', 'Новий раунд успішно запущено.');
            redirectTo('/');

        case 'finish_round':
            requireAdmin($pdo);
            $stmt = $pdo->prepare("UPDATE rounds SET status = 'finished', finished_at = NOW() WHERE id = ?");
            $stmt->execute([(int) ($_POST['round_id'] ?? 0)]);
            flash('success', 'Раунд завершено адміністратором.');
            redirectTo('/');
    }
}

$user = currentUser($pdo);
$flash = pullFlash();
$teams = fetchTeams($pdo);
$students = fetchStudents($pdo);
$tasks = $user ? fetchTasks($pdo, $user) : [];
$rounds = fetchRounds($pdo);
$activeRound = fetchActiveRound($pdo);
$roundStats = fetchRoundStats($pdo);
$activityLogs = $user && ($user['role'] ?? '') === 'admin' ? fetchActivityLogs($pdo) : [];
$catalog = getPuzzleCatalog();
$csrfToken = csrfToken();
?>
<!DOCTYPE html>
<html lang="uk" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geo Puzzle School</title>
    <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <div>
            <p class="eyebrow">Geo Puzzle School</p>
            <h1>Пазли з географії для 5–11 класів</h1>
            <p class="muted">Навчальний fullstack застосунок на PHP, JavaScript, MySQL, nginx та Docker Compose.</p>
        </div>
        <div class="topbar-actions">
            <button type="button" id="themeToggle" class="secondary-button">🌗 Тема</button>
            <?php if ($user): ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="secondary-button">Вийти</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($flash): ?>
        <section class="flash flash-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
        </section>
    <?php endif; ?>

    <?php if (!$user): ?>
        <main class="grid two-columns">
            <section class="panel">
                <h2>Швидка реєстрація учня</h2>
                <p class="muted">Учень вказує лише ім’я та клас. Система сама визначає команду.</p>
                <form method="post" class="stack-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="student_login">
                    <label>
                        Ім’я та прізвище
                        <input type="text" name="full_name" placeholder="Наприклад, Марія Коваль" required>
                    </label>
                    <label>
                        Клас
                        <input type="text" name="class_name" placeholder="7-Б" required>
                    </label>
                    <button type="submit">Увійти / зареєструватися</button>
                </form>
            </section>

            <section class="panel">
                <h2>Вхід адміністратора</h2>
                <p class="muted">Типовий логін: <strong>admin</strong>, пароль: <strong>admin12345</strong>.</p>
                <form method="post" class="stack-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="admin_login">
                    <label>
                        Логін
                        <input type="text" name="username" value="admin" required>
                    </label>
                    <label>
                        Пароль
                        <input type="password" name="password" value="admin12345" required>
                    </label>
                    <button type="submit">Увійти як адміністратор</button>
                </form>
            </section>
        </main>
    <?php else: ?>
        <main class="dashboard-grid">
            <section class="panel hero-card">
                <div class="hero-header">
                    <div>
                        <p class="eyebrow">Профіль</p>
                        <h2><?= e($user['full_name']) ?></h2>
                        <p class="muted"><?= e($user['role'] === 'admin' ? 'Адміністратор системи' : 'Учень ' . ($user['class_name'] ?: '')) ?></p>
                    </div>
                    <?php if (($user['role'] ?? '') === 'student'): ?>
                        <span class="team-badge" style="--team-color: <?= e((string) ($user['team_color'] ?? '#64748b')) ?>;">
                            <?= e((string) ($user['team_name'] ?? 'Без команди')) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="stats-inline">
                    <article>
                        <span>Активний раунд</span>
                        <strong><?= $activeRound ? e($activeRound['title']) : 'Немає' ?></strong>
                    </article>
                    <article>
                        <span>Рівень</span>
                        <strong><?= $activeRound ? e($activeRound['level_label']) : '—' ?></strong>
                    </article>
                    <article>
                        <span>Переможець</span>
                        <strong><?= $activeRound && $activeRound['winner_name'] ? e($activeRound['winner_name']) : 'Ще немає' ?></strong>
                    </article>
                </div>
            </section>

            <?php if (($user['role'] ?? '') === 'student'): ?>
                <section class="panel game-panel">
                    <div class="panel-title-row">
                        <div>
                            <h2>Ігрове поле</h2>
                            <p class="muted">Перетягуйте фрагменти мишею або пальцем. Після перемоги гра блокується автоматично.</p>
                        </div>
                        <?php if ($activeRound && $activeRound['puzzle']): ?>
                            <span class="level-pill"><?= e($activeRound['puzzle']['level']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($activeRound && $activeRound['puzzle']): ?>
                        <div class="game-status" id="gameStatus">
                            <?php if ($activeRound['winner_name']): ?>
                                Переможець раунду: <?= e($activeRound['winner_name']) ?> (<?= e((string) $activeRound['winner_team_name']) ?>)
                            <?php else: ?>
                                Активний пазл: <?= e($activeRound['puzzle']['type']) ?> «<?= e($activeRound['puzzle']['title']) ?>». <?= e($activeRound['puzzle']['hint']) ?>
                            <?php endif; ?>
                        </div>
                        <div
                            id="puzzleApp"
                            class="puzzle-app"
                            data-round-id="<?= e((string) $activeRound['id']) ?>"
                            data-locked="<?= $activeRound['winner_name'] ? '1' : '0' ?>"
                            data-csrf-token="<?= e($csrfToken) ?>"
                            data-puzzle='<?= e(json_encode($activeRound['puzzle'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Адміністратор ще не запустив раунд.</div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="panel">
                    <div class="panel-title-row">
                        <div>
                            <h2>Керування раундами</h2>
                            <p class="muted">Адміністратор обирає географічний об’єкт, запускає новий раунд і відкриває наступний.</p>
                        </div>
                    </div>
                    <form method="post" class="stack-form compact-form">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="start_round">
                        <label>
                            Шаблон пазла
                            <select name="template_key" required>
                                <?php foreach ($catalog as $key => $puzzle): ?>
                                    <option value="<?= e($key) ?>"><?= e($puzzle['type'] . ' · ' . $puzzle['title'] . ' · ' . $puzzle['level']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit">Стартувати новий раунд</button>
                    </form>
                    <?php if ($activeRound): ?>
                        <form method="post" class="inline-form spacing-top">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="finish_round">
                            <input type="hidden" name="round_id" value="<?= e((string) $activeRound['id']) ?>">
                            <button type="submit" class="secondary-button">Завершити активний раунд вручну</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="panel">
                <div class="panel-title-row">
                    <div>
                        <h2><?= ($user['role'] ?? '') === 'admin' ? 'Усі задачі' : 'Мої задачі' ?></h2>
                        <p class="muted">Користувач має CRUD лише для власних задач, а адміністратор — для всіх.</p>
                    </div>
                </div>
                <form method="post" class="stack-form compact-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_task">
                    <?php if (($user['role'] ?? '') === 'admin'): ?>
                        <label>
                            Учень-власник задачі
                            <select name="user_id" required>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?= e((string) $student['id']) ?>"><?= e($student['full_name'] . ' (' . $student['class_name'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <label>
                        Назва задачі
                        <input type="text" name="title" placeholder="Наприклад, Повторити материки" required>
                    </label>
                    <label>
                        Опис
                        <textarea name="details" rows="3" placeholder="Короткий план підготовки або навчальне завдання" required></textarea>
                    </label>
                    <label>
                        Статус
                        <select name="status">
                            <option value="new">Нова</option>
                            <option value="in_progress">У процесі</option>
                            <option value="done">Виконано</option>
                        </select>
                    </label>
                    <button type="submit">Зберегти задачу</button>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <?php if (($user['role'] ?? '') === 'admin'): ?><th>Учень</th><?php endif; ?>
                            <th>Назва</th>
                            <th>Статус</th>
                            <th>Опис</th>
                            <th>Дії</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <tr>
                                <?php if (($user['role'] ?? '') === 'admin'): ?>
                                    <td><?= e($task['full_name'] . ' (' . $task['class_name'] . ')') ?></td>
                                <?php endif; ?>
                                <td><?= e($task['title']) ?></td>
                                <td><?= e($task['status']) ?></td>
                                <td><?= e($task['details']) ?></td>
                                <td>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Видалити задачу?');">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_task">
                                        <input type="hidden" name="task_id" value="<?= e((string) $task['id']) ?>">
                                        <button type="submit" class="danger-button">Видалити</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$tasks): ?>
                            <tr><td colspan="<?= ($user['role'] ?? '') === 'admin' ? '5' : '4' ?>">Задач поки немає.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-title-row">
                    <div>
                        <h2>Результати гри</h2>
                        <p class="muted">Дашборд показує перемоги команд, найкращих учнів та історію раундів.</p>
                    </div>
                </div>
                <div class="stats-grid">
                    <article>
                        <h3>Перемоги команд</h3>
                        <ul class="stat-list">
                            <?php foreach ($roundStats['teams'] as $team): ?>
                                <li><span><?= e($team['name']) ?></span><strong><?= e((string) $team['wins']) ?></strong></li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                    <article>
                        <h3>ТОП учнів</h3>
                        <ul class="stat-list">
                            <?php foreach ($roundStats['users'] as $winner): ?>
                                <li><span><?= e($winner['full_name'] . ' (' . $winner['class_name'] . ')') ?></span><strong><?= e((string) $winner['wins']) ?></strong></li>
                            <?php endforeach; ?>
                            <?php if (!$roundStats['users']): ?>
                                <li><span>Ще немає перемог</span><strong>0</strong></li>
                            <?php endif; ?>
                        </ul>
                    </article>
                </div>
                <div class="table-wrap spacing-top">
                    <table>
                        <thead>
                        <tr>
                            <th>Раунд</th>
                            <th>Рівень</th>
                            <th>Статус</th>
                            <th>Переможець</th>
                            <th>Команда</th>
                            <th>Час старту</th>
                            <th>Час завершення</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rounds as $round): ?>
                            <tr>
                                <td><?= e($round['title']) ?></td>
                                <td><?= e($round['level_label']) ?></td>
                                <td><?= e($round['status']) ?></td>
                                <td><?= e((string) ($round['winner_name'] ?? '—')) ?></td>
                                <td><?= e((string) ($round['winner_team_name'] ?? '—')) ?></td>
                                <td><?= e(formatDateTime($round['started_at'])) ?></td>
                                <td><?= e(formatDateTime($round['finished_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rounds): ?>
                            <tr><td colspan="7">Історія раундів ще порожня.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if (($user['role'] ?? '') === 'admin'): ?>
                <section class="panel">
                    <div class="panel-title-row">
                        <div>
                            <h2>Користувачі та команди</h2>
                            <p class="muted">Адміністратор може перерозподіляти учнів між командами або видаляти записи.</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Учень</th>
                                <th>Клас</th>
                                <th>Команда</th>
                                <th>Дії</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?= e($student['full_name']) ?></td>
                                    <td><?= e($student['class_name']) ?></td>
                                    <td>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="save_user_team">
                                            <input type="hidden" name="user_id" value="<?= e((string) $student['id']) ?>">
                                            <select name="team_id">
                                                <?php foreach ($teams as $team): ?>
                                                    <option value="<?= e((string) $team['id']) ?>" <?= (int) $team['id'] === (int) $student['team_id'] ? 'selected' : '' ?>><?= e($team['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="secondary-button">Оновити</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="post" class="inline-form" onsubmit="return confirm('Видалити учня?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= e((string) $student['id']) ?>">
                                            <button type="submit" class="danger-button">Видалити</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$students): ?>
                                <tr><td colspan="4">Учні ще не зареєструвалися.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="panel full-width">
                    <div class="panel-title-row">
                        <div>
                            <h2>Технічна статистика</h2>
                            <p class="muted">Останні входи та дії з IP, user-agent, ОС, браузером і типом пристрою.</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Користувач</th>
                                <th>IP</th>
                                <th>User-Agent</th>
                                <th>OS</th>
                                <th>Браузер</th>
                                <th>Пристрій</th>
                                <th>Шлях</th>
                                <th>Метод</th>
                                <th>Час</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($activityLogs as $log): ?>
                                <tr>
                                    <td><?= e((string) ($log['full_name'] ?? 'Гість')) ?></td>
                                    <td><?= e($log['ip_address']) ?></td>
                                    <td><?= e($log['user_agent']) ?></td>
                                    <td><?= e($log['os_name']) ?></td>
                                    <td><?= e($log['browser_name']) ?></td>
                                    <td><?= e($log['device_type']) ?></td>
                                    <td><?= e($log['request_path']) ?></td>
                                    <td><?= e($log['request_method']) ?></td>
                                    <td><?= e(formatDateTime($log['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    <?php endif; ?>
</div>
<script src="/assets/js/app.js"></script>
</body>
</html>
