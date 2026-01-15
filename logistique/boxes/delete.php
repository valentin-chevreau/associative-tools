<?php
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAdmin = !empty($_SESSION['is_admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['flash_error'] = "Paramètre invalide.";
    header('Location: ' . APP_BASE . '/index.php');
    exit;
}

// 🔒 Admin only
if (!$isAdmin) {
    http_response_code(403);
    $_SESSION['flash_error'] = "Accès interdit.";
    header('Location: ' . APP_BASE . '/index.php');
    exit;
}

// Récupérer convoi associé
$stmt = $pdo->prepare("SELECT convoy_id FROM boxes WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    $_SESSION['flash_error'] = "Carton introuvable.";
    header('Location: ' . APP_BASE . '/index.php');
    exit;
}

$convoy_id = (int)$row['convoy_id'];

// Supprimer le carton
$del = $pdo->prepare("DELETE FROM boxes WHERE id = ?");
$del->execute([$id]);

$_SESSION['flash_success'] = "Carton supprimé.";
header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $convoy_id);
exit;