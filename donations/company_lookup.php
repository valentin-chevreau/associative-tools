<?php
declare(strict_types=1);

// donations/company_lookup.php — recherche d'entreprise (SIREN, SIRET ou nom) pour le formulaire de don.
// Réponse JSON : {results:[{siren,siret,name,street,postal_code,city,active,diffusible}]} ou {error}.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin(); // l'accès au module est contrôlé par shared/bootstrap.php (droits par module)
require_once __DIR__ . '/includes/company_lookup.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');

$res = company_lookup((string)($_GET['q'] ?? ''));
if (isset($res['error'])) http_response_code(502);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
