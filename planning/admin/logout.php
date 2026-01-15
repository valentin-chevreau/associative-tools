<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config = require __DIR__ . '/../config/config.php';

// On enlève juste le flag admin
unset($_SESSION['admin_authenticated']);
unset($_SESSION['is_admin']);

header('Location: ' . $config['base_url'] . '/admin/login.php');
exit;