<?php
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAdmin  = !empty($_SESSION['is_admin']);

$convoy_id = isset($_GET['convoy_id']) ? (int)$_GET['convoy_id'] : 0;
$root_id   = isset($_GET['root_id']) ? (int)$_GET['root_id'] : 0;
$delta     = isset($_GET['delta']) ? (int)$_GET['delta'] : 0;

if ($convoy_id <= 0 || $root_id <= 0 || !in_array($delta, [-1, 1], true)) {
    $_SESSION['flash_error'] = "Paramètres invalides.";
    header('Location: index.php');
    exit;
}

// Convoi
$stmt = $pdo->prepare("SELECT status FROM convoys WHERE id = ?");
$stmt->execute([$convoy_id]);
$convoi = $stmt->fetch();

if (!$convoi) {
    $_SESSION['flash_error'] = "Convoi introuvable.";
    header('Location: index.php');
    exit;
}

// 🔒 Non-admin : uniquement en préparation
if (!$isAdmin && ($convoi['status'] ?? '') !== 'preparation') {
    http_response_code(403);
    $_SESSION['flash_error'] = "Lecture seule : ce convoi n'est plus en préparation.";
    header('Location: convoi.php?id=' . $convoy_id);
    exit;
}

// Root must be root category
$rootCheck = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND parent_id IS NULL");
$rootCheck->execute([$root_id]);
if (!$rootCheck->fetch()) {
    $_SESSION['flash_error'] = "Catégorie racine invalide.";
    header('Location: convoi.php?id=' . $convoy_id);
    exit;
}

try {
    $pdo->beginTransaction();

    $sel = $pdo->prepare("SELECT real_count FROM convoy_palettes WHERE convoy_id = ? AND root_category_id = ? FOR UPDATE");
    $sel->execute([$convoy_id, $root_id]);
    $row = $sel->fetch();

    if ($row) {
        $current = (int)$row['real_count'];
        $new = max(0, $current + $delta);

        $upd = $pdo->prepare("UPDATE convoy_palettes SET real_count = ? WHERE convoy_id = ? AND root_category_id = ?");
        $upd->execute([$new, $convoy_id, $root_id]);
    } else {
        $new = max(0, $delta);
        $ins = $pdo->prepare("INSERT INTO convoy_palettes (convoy_id, root_category_id, real_count) VALUES (?, ?, ?)");
        $ins->execute([$convoy_id, $root_id, $new]);
    }

    $pdo->commit();
    $_SESSION['flash_success'] = "Palettes mises à jour.";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_error'] = "Erreur mise à jour palettes.";
}

header('Location: convoi.php?id=' . $convoy_id);
exit;