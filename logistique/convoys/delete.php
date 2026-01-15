<?php
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAdmin = !empty($_SESSION['is_admin']);

if (!$isAdmin) {
    die('Accès réservé à l’administrateur.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Convoi introuvable.');
}

// Vérifier que le convoi existe
$stmt = $pdo->prepare("SELECT id, name FROM convoys WHERE id = ?");
$stmt->execute([$id]);
$convoi = $stmt->fetch();

if (!$convoi) {
    die('Convoi introuvable.');
}

// Suppression du convoi
// Les cartons associés seront supprimés automatiquement grâce au ON DELETE CASCADE sur boxes.convoy_id
$del = $pdo->prepare("DELETE FROM convoys WHERE id = ?");
$del->execute([$id]);

// Retour à la liste des convois
header('Location: ' . APP_BASE . '/index.php');
exit;