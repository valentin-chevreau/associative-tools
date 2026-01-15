<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../db.php';

$convoy_id = isset($_GET['convoy_id']) ? (int)$_GET['convoy_id'] : 0;
$root_id   = isset($_GET['root_id']) ? (int)$_GET['root_id'] : 0;
$delta     = isset($_GET['delta']) ? (int)$_GET['delta'] : 0;

if ($convoy_id <= 0 || $root_id <= 0 || !in_array($delta, [-1, 1], true)) {
    $_SESSION['flash_error'] = "Paramètres invalides.";
    header('Location: ' . APP_BASE . '/index.php');
    exit;
}

$isAdmin = !empty($_SESSION['is_admin']);

$stmt = $pdo->prepare("SELECT id, status FROM convoys WHERE id = ?");
$stmt->execute([$convoy_id]);
$convoi = $stmt->fetch();

if (!$convoi) {
    $_SESSION['flash_error'] = "Convoi introuvable.";
    header('Location: ' . APP_BASE . '/index.php');
    exit;
}

$canEdit = ($isAdmin || ($convoi['status'] ?? '') === 'preparation');
if (!$canEdit) {
    http_response_code(403);
    $_SESSION['flash_error'] = "Lecture seule : ce convoi n'est plus modifiable.";
    header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $convoy_id);
    exit;
}

$rootCheck = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND parent_id IS NULL");
$rootCheck->execute([$root_id]);
if (!$rootCheck->fetch()) {
    $_SESSION['flash_error'] = "Catégorie racine invalide.";
    header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $convoy_id);
    exit;
}

$pdo->beginTransaction();
try {
    $sel = $pdo->prepare("SELECT real_count FROM convoy_palettes WHERE convoy_id = ? AND root_category_id = ? FOR UPDATE");
    $sel->execute([$convoy_id, $root_id]);
    $row = $sel->fetch();

    if ($row) {
        $new = max(0, (int)$row['real_count'] + $delta);
        $upd = $pdo->prepare("UPDATE convoy_palettes SET real_count = ? WHERE convoy_id = ? AND root_category_id = ?");
        $upd->execute([$new, $convoy_id, $root_id]);
    } else {
        $new = max(0, $delta);
        $ins = $pdo->prepare("INSERT INTO convoy_palettes (convoy_id, root_category_id, real_count) VALUES (?, ?, ?)");
        $ins->execute([$convoy_id, $root_id, $new]);
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['flash_error'] = "Erreur lors de la mise à jour des palettes.";
    header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $convoy_id);
    exit;
}

header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $convoy_id);
exit;
