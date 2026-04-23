<?php

declare(strict_types=1);

session_start();

date_default_timezone_set('UTC');

require_once __DIR__ . '/config/puzzles.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/auth.php';

$pdo = db();
seedDefaults($pdo);
$user = currentUser($pdo);
logRequest($pdo, $user['id'] ?? null);
