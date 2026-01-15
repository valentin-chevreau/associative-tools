<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

admin_logout();

header('Location: ' . suite_base() . '/');
exit;
