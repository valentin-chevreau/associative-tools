<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

admin_logout();

$next = (string)($_GET['next'] ?? '');
if ($next === '') $next = suite_base() . '/index.php';

header('Location: ' . $next);
exit;