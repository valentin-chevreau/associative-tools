<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$isAdmin = !empty($_SESSION['is_admin']);
if (!$isAdmin) {
    http_response_code(403);
    echo "Accès interdit.";
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['flash_error'] = "Catégorie invalide.";
    header('Location: ' . APP_BASE . '/categories/view.php');
    exit;
}

// Charger la catégorie
$stmt = $pdo->prepare("SELECT id, label, parent_id FROM categories WHERE id = ?");
$stmt->execute([$id]);
$cat = $stmt->fetch();

if (!$cat) {
    $_SESSION['flash_error'] = "Catégorie introuvable.";
    header('Location: ' . APP_BASE . '/categories/view.php');
    exit;
}

$isRoot = ($cat['parent_id'] === null);

// 1) Empêcher suppression si utilisée par des cartons
$usedBoxesStmt = $pdo->prepare("SELECT COUNT(*) FROM boxes WHERE category_id = ?");
$usedBoxesStmt->execute([$id]);
$usedInBoxes = (int)$usedBoxesStmt->fetchColumn();

// 2) Empêcher suppression si racine avec enfants
$childrenCount = 0;
if ($isRoot) {
    $childrenStmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id = ?");
    $childrenStmt->execute([$id]);
    $childrenCount = (int)$childrenStmt->fetchColumn();
}

// 3) Empêcher suppression si utilisée dans convoy_palettes (racine)
$usedPalStmt = $pdo->prepare("SELECT COUNT(*) FROM convoy_palettes WHERE root_category_id = ?");
$usedPalStmt->execute([$id]);
$usedInPalettes = (int)$usedPalStmt->fetchColumn();

if ($usedInBoxes > 0) {
    $_SESSION['flash_error'] = "Suppression impossible : cette catégorie est utilisée par $usedInBoxes carton(s).";
    header('Location: ' . APP_BASE . '/categories/view.php');
    exit;
}

if ($isRoot && $childrenCount > 0) {
    $_SESSION['flash_error'] = "Suppression impossible : cette racine a $childrenCount sous-catégorie(s).";
    header('Location: ' . APP_BASE . '/categories/view.php');
    exit;
}

if ($usedInPalettes > 0) {
    $_SESSION['flash_error'] = "Suppression impossible : cette catégorie est utilisée dans des palettes déclarées.";
    header('Location: ' . APP_BASE . '/categories/view.php');
    exit;
}

// OK -> delete
try {
    $pdo->beginTransaction();

    $del = $pdo->prepare("DELETE FROM categories WHERE id = ?");
    $del->execute([$id]);

    $pdo->commit();

    $_SESSION['flash_success'] = "Catégorie supprimée : " . $cat['label'];
    header('Location: ' . APP_BASE . '/categories/view.php');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_error'] = "Erreur lors de la suppression.";
    header('Location: ' . APP_BASE . '/categories/view.php');
    exit;
}