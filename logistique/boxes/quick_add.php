<?php
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAdmin = !empty($_SESSION['is_admin']);

$convoy_id   = (int)($_GET['convoy_id'] ?? 0);
$category_id = (int)($_GET['category_id'] ?? 0);
$qty         = (int)($_GET['qty'] ?? 1);

if ($qty < 1) $qty = 1;

if ($convoy_id <= 0 || $category_id <= 0) {
    http_response_code(400);
    $_SESSION['flash_error'] = "Paramètres invalides.";
    header('Location: ' . APP_BASE . '/index.php');
    exit;
}

// Convoi
$stmt = $pdo->prepare("SELECT status FROM convoys WHERE id = ?");
$stmt->execute([$convoy_id]);
$convoi = $stmt->fetch();

if (!$convoi) {
    http_response_code(404);
    $_SESSION['flash_error'] = "Convoi introuvable.";
    header('Location: ' . APP_BASE . '/index.php');
    exit;
}

// 🔒 Non-admin : uniquement en préparation
if (!$isAdmin && ($convoi['status'] ?? '') !== 'preparation') {
    http_response_code(403);
    $_SESSION['flash_error'] = "Lecture seule : ce convoi n'est plus en préparation.";
    header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $convoy_id);
    exit;
}

// Catégorie -> racine
$cat = $pdo->prepare("SELECT root_id FROM categories WHERE id = ?");
$cat->execute([$category_id]);
$crow = $cat->fetch();

if (!$crow) {
    http_response_code(404);
    $_SESSION['flash_error'] = "Catégorie introuvable.";
    header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $convoy_id);
    exit;
}

$root_id = (int)$crow['root_id'];

for ($i = 0; $i < $qty; $i++) {
    $insert = $pdo->prepare("
        INSERT INTO boxes (convoy_id, category_id, root_category_id, code)
        VALUES (?, ?, ?, NULL)
    ");
    $insert->execute([$convoy_id, $category_id, $root_id]);

    $boxId = (int)$pdo->lastInsertId();
    $code  = 'C' . str_pad((string)$boxId, 6, '0', STR_PAD_LEFT);

    $upd = $pdo->prepare("UPDATE boxes SET code = ? WHERE id = ?");
    $upd->execute([$code, $boxId]);
}

$_SESSION['flash_success'] = "Carton ajouté.";
header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $convoy_id);
exit;