<?php
require_once 'db.php';

$convoy_id = isset($_GET['convoy_id']) ? (int)$_GET['convoy_id'] : 0;
if ($convoy_id <= 0) {
    die('Convoi introuvable.');
}

// Vérifier que le convoi existe
$stmt = $pdo->prepare("SELECT * FROM convoys WHERE id = ?");
$stmt->execute([$convoy_id]);
$convoi = $stmt->fetch();
if (!$convoi) {
    die('Convoi introuvable.');
}

// Récupérer les cartons
$listStmt = $pdo->prepare("
  SELECT b.*, c.label AS category_label
  FROM boxes b
  JOIN categories c ON c.id = b.category_id
  WHERE b.convoy_id = ?
  ORDER BY b.id ASC
");
$listStmt->execute([$convoy_id]);
$boxes = $listStmt->fetchAll();

$filename = 'convoi_' . $convoy_id . '_cartons.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Entêtes
fputcsv($output, [
    'ID',
    'Code',
    'Type',
    'Catégorie',
    'Poids_kg',
    'Nb_unites',
    'Commentaire',
]);

foreach ($boxes as $b) {
    fputcsv($output, [
        $b['id'],
        $b['code'],
        $b['kind'],
        $b['category_label'],
        $b['weight_kg'],
        $b['items_count'],
        $b['comment'],
    ]);
}

fclose($output);
exit;
