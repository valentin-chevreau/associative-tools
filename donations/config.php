<?php
declare(strict_types=1);

// donations/config.php
//
// Identité de l'organisme utilisée sur les reçus fiscaux (dons manuels
// uniquement — les dons HelloAsso n'ont pas de reçu généré ici).
//
// Ces valeurs sont lues dans le .env unique de la suite (voir .env.example à
// la racine du projet), avec les MÊMES valeurs que l'ancien
// planning/config/config.php, pour que les reçus gardent exactement les mêmes
// mentions légales (nom, RNA, SIRET, adresse).

require_once __DIR__ . '/includes/env.php';

return [
    'org_name'         => (string) env('ORG_NAME', 'ASSOCIATION'),
    'org_rna'          => (string) env('ORG_RNA', ''),
    'org_siret'        => (string) env('ORG_SIRET', ''),
    'org_address'      => (string) env('ORG_ADDRESS', ''),
    'org_city'         => (string) env('ORG_CITY', ''),
    'org_receipt_city' => (string) env('ORG_RECEIPT_CITY', ''),
];
