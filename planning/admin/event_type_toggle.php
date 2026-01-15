<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';

if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_authenticated'])) {
    header('Location: ' . $config['base_url'] . '/admin/login.php');
    exit;
}

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $config['base_url'] . '/admin/event_types.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . $config['base_url'] . '/admin/event_types.php');
    exit;
}

// Toggle
$stmt = $pdo->prepare("SELECT is_active FROM event_types WHERE id = :id");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $new = ((int)$row['is_active'] === 1) ? 0 : 1;
    $stmt = $pdo->prepare("UPDATE event_types SET is_active = :new WHERE id = :id");
    $stmt->execute(['new' => $new, 'id' => $id]);
}

header('Location: ' . $config['base_url'] . '/admin/event_types.php?ok=toggled');
exit;