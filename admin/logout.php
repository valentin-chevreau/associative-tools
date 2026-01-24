<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

admin_logout();

$redirect = $_GET['next'] ?? suite_base() . '/index.php';
header('Location: ' . $redirect);
exit;