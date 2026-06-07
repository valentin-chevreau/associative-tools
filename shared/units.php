<?php
// shared/units.php — Chargement des unités depuis logistique_units
// Usage : $units = load_units($pdo);
// Retourne un tableau indexé par code : ['carton' => ['fr'=>…,'ua'=>…,'en'=>…], …]

function load_units(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $rows = $pdo->query("SELECT code, label_fr, label_ua, label_en FROM logistique_units ORDER BY sort_order, code")
                    ->fetchAll(PDO::FETCH_ASSOC);
        $cache = [];
        foreach ($rows as $r) {
            $cache[(string)$r['code']] = [
                'fr' => (string)$r['label_fr'],
                'ua' => (string)$r['label_ua'],
                'en' => (string)$r['label_en'],
            ];
        }
    } catch (Exception $e) {
        // Fallback si la table n'existe pas encore
        $cache = [
            'carton'  => ['fr'=>'Carton',  'ua'=>'коробок',  'en'=>'box'],
            'paire'   => ['fr'=>'Paire',   'ua'=>'пар',      'en'=>'pair'],
            'unité'   => ['fr'=>'Unité',   'ua'=>'одиниць',  'en'=>'unit'],
            'sac'     => ['fr'=>'Sac',     'ua'=>'мішків',   'en'=>'bag'],
            'palette' => ['fr'=>'Palette', 'ua'=>'палет',    'en'=>'pallet'],
            'lot'     => ['fr'=>'Lot',     'ua'=>'партій',   'en'=>'lot'],
            'boîte'   => ['fr'=>'Boîte',   'ua'=>'коробок',  'en'=>'box'],
            'rouleau' => ['fr'=>'Rouleau', 'ua'=>'рулонів',  'en'=>'roll'],
        ];
    }
    return $cache;
}

// Traduit une unité dans la langue demandée
// $lang : 'fr' | 'ua' | 'en'
function translate_unit(string $code, string $lang, PDO $pdo): string {
    $units = load_units($pdo);
    return $units[$code][$lang] ?? $code;
}
