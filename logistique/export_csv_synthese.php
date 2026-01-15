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

// Récupérer la synthèse par catégorie
$listStmt = $pdo->prepare("
  SELECT b.kind, c.label AS category_label, COUNT(*) AS nb_boxes
  FROM boxes b
  JOIN categories c ON c.id = b.category_id
  WHERE b.convoy_id = ?
  GROUP BY b.kind, c.id
  ORDER BY b.kind, c.label
");
$listStmt->execute([$convoy_id]);
$rows = $listStmt->fetchAll();

$filename = 'convoi_' . $convoy_id . '_synthese_categories.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Entêtes
fputcsv($output, [
    'Type',
    'Categorie',
    'Nb_cartons',
]);

foreach ($rows as $r) {
    $typeLabel = $r['kind'] === 'pharma' ? 'Pharmacie' : 'Denrées';
    fputcsv($output, [
        $typeLabel,
        $r['category_label'],
        $r['nb_boxes'],
    ]);
}

fclose($output);
exit;