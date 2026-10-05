<?php
declare(strict_types=1);

// donations/index.php
// Gestion des dons (HelloAsso + manuel) : liste + import CSV + reçus fiscaux
// (manuel uniquement) + statut de la synchronisation automatique HelloAsso.
//
// Module autonome depuis la base unifiée : ne dépend plus de planning.
// La table s'appelle désormais "donations" (anciennement "planning_donations"
// — voir migrations/001_rename_from_planning.sql pour la bascule).

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin(); // l'accès au module est contrôlé par shared/bootstrap.php (droits par module)

require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/includes/helloasso_client.php';

$config = require __DIR__ . '/config.php';

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Connexion base de données indisponible.";
    exit;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$base = function_exists('suite_base') ? suite_base() : '';

// ---------- Normalisation FR (noms / villes / adresses)
function u_trim(string $s): string {
    // remplace espaces insécables par des espaces classiques
    $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $s);
    return trim($s);
}
function u_upper(string $s): string {
    $s = u_trim($s);
    return $s === '' ? '' : (function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s));
}
function u_title(string $s): string {
    $s = u_trim($s);
    if ($s === '') return '';
    if (function_exists('mb_convert_case')) {
        $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    } else {
        $s = ucwords(strtolower($s));
    }
    return $s;
}

/**
 * Normalise une rue française :
 * - supprime les "Rue De La" en remettant de/la/du/des... en minuscules
 * - conserve les traits d'union et apostrophes
 * Ex: "19 Rue De La Mercanderie" -> "19 rue de la Mercanderie"
 */
function normalize_french_street(string $s): string {
    $s = u_trim($s);
    if ($s === '') return '';

    // On applique un Title Case d'abord
    $s = u_title($s);

    // Particules et articles à forcer en minuscules (en français)
    $lower = [
        'De','Du','Des','La','Le','Les','Et','Au','Aux','A','À',
        "D'", "D’", "L'", "L’"
    ];

    foreach ($lower as $w) {
        $lw = function_exists('mb_strtolower') ? mb_strtolower($w, 'UTF-8') : strtolower($w);
        // mot entier (De, Du, La...)
        $s = preg_replace('/\b' . preg_quote($w, '/') . '\b/u', $lw, $s);
        // cas apostrophes (D', D’, L’, L')
        $s = preg_replace('/\b' . preg_quote($w, '/') . '/u', $lw, $s);
    }

    // Types de voies souvent affichés en minuscules dans l'administratif
    $roadTypes = ['Rue','Avenue','Boulevard','Impasse','Chemin','Route','Allée','Place','Quai','Square','Cours','Voie','Résidence','Residence'];
    foreach ($roadTypes as $rt) {
        $lrt = function_exists('mb_strtolower') ? mb_strtolower($rt, 'UTF-8') : strtolower($rt);
        $s = preg_replace('/\b' . preg_quote($rt, '/') . '\b/u', $lrt, $s);
    }

    // Normalise apostrophe typographique
    $s = str_replace("'", "’", $s);

    // Nettoyage des espaces multiples
    $s = preg_replace('/\s{2,}/u', ' ', $s);

    return $s;
}

/**
 * Parse une date FR "dd/mm/yyyy" (ou ISO "yyyy-mm-dd") en DateTimeImmutable.
 * Retourne null si la date est vide ou invalide.
 */
function parse_fr_date(string $s): ?DateTimeImmutable {
    $s = trim($s);
    if ($s === "") return null;

    // dd/mm/yyyy
    if (preg_match("~^(\\d{2})/(\\d{2})/(\\d{4})$~", $s)) {
        $dt = DateTimeImmutable::createFromFormat("d/m/Y", $s);
        return $dt ?: null;
    }

    // yyyy-mm-dd (input[type=date])
    if (preg_match("~^(\\d{4})-(\\d{2})-(\\d{2})$~", $s)) {
        $dt = DateTimeImmutable::createFromFormat("Y-m-d", $s);
        return $dt ?: null;
    }

    // fallback : "2026-01-08 10:30:00"
    try {
        return new DateTimeImmutable($s);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Reçu fiscal (manuel uniquement)
 * - Numéro séquentiel par année : RF-YYYY-0001
 */
function next_receipt_number(PDO $pdo, int $year): string {
    $prefix = sprintf('RF-%04d-', $year);
    $stmt = $pdo->prepare("SELECT receipt_number FROM donations WHERE receipt_number LIKE :pfx ORDER BY receipt_number DESC LIMIT 1");
    $stmt->execute(['pfx' => $prefix . '%']);
    $last = (string)($stmt->fetchColumn() ?: '');

    $n = 0;
    if ($last !== '' && str_starts_with($last, $prefix)) {
        $tail = substr($last, strlen($prefix));
        if (ctype_digit($tail)) $n = (int)$tail;
    }
    $n++;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function normalize_header(string $s): string {
    $s = trim($s);
    $s = mb_strtolower($s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = preg_replace('/[^a-z0-9]+/i', '_', $s);
    return trim((string)$s, '_');
}

function detect_delimiter(string $line): string {
    $candidates = [';', ',', "\t"];
    $best = ';'; $bestCount = -1;
    foreach ($candidates as $d) {
        $count = substr_count($line, $d);
        if ($count > $bestCount) { $bestCount = $count; $best = $d; }
    }
    return $best;
}

function parse_amount($raw): ?float {
    if ($raw === null) return null;
    $s = trim((string)$raw);
    if ($s === '') return null;

    $s = str_replace(["\xc2\xa0", '€', 'EUR'], ['', '', ''], $s);
    $s = str_replace(' ', '', $s);

    // 1.234,56 -> 1234.56
    if (preg_match('/^\-?\d{1,3}(\.\d{3})+,\d+$/', $s)) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '.', $s);
    }

    $s = preg_replace('/[^0-9\.\-]/', '', $s);
    if ($s === '' || $s === '-' || $s === '.' || $s === '-.') return null;

    return (float)$s;
}

function parse_date_any($raw): ?string {
    if ($raw === null) return null;
    $s = trim((string)$raw);
    if ($s === '') return null;

    $fmts = [
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y',
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
        'd-m-Y H:i:s', 'd-m-Y',
        'd.m.Y H:i:s', 'd.m.Y'
    ];
    foreach ($fmts as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt instanceof DateTime) return $dt->format('Y-m-d H:i:s');
    }

    try {
        $dt = new DateTime($s);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function pick_col(array $normHeaders, array $candidates): ?string {
    foreach ($candidates as $cand) {
        if (in_array($cand, $normHeaders, true)) return $cand;
    }
    return null;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > ((int)date('Y') + 1)) $year = (int)date('Y');

$info = [
    'import' => null,
    'errors' => [],
    'debug' => [],
];
$msg = null;

// Vérif table
$tableOk = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'donations'");
    $tableOk = (bool)$stmt->fetchColumn();
} catch (Throwable $e) {
    $tableOk = false;
}

// ==========================================================
// Actions POST
// ==========================================================

// 1) Ajout manuel (hors HelloAsso)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_manual_add'])) {
    if (!$tableOk) {
        $info['errors'][] = "Table donations inexistante : ajout manuel impossible.";
    } else {
        $date   = trim((string)($_POST['donation_date'] ?? ''));
        $amount = (float)str_replace(',', '.', (string)($_POST['amount'] ?? '0'));
        $payment  = trim((string)($_POST['payment_method'] ?? ''));
        $first = trim((string)($_POST['donor_first_name'] ?? ''));
        $last  = trim((string)($_POST['donor_last_name'] ?? ''));
        $demail = trim((string)($_POST['donor_email'] ?? ''));
        $addr   = trim((string)($_POST['donor_address'] ?? ''));
        $postal = trim((string)($_POST['donor_postal_code'] ?? ''));
        $city   = trim((string)($_POST['donor_city'] ?? ''));
        $country= trim((string)($_POST['donor_country'] ?? 'France'));

        // Normalisation (affichage + PDF plus propres)
        $first = u_title($first);
        $last  = u_upper($last);
        $city  = u_upper($city);
        $country = u_title($country);
        $addr  = normalize_french_street($addr);

        $receiptEligible = !empty($_POST['receipt_eligible']) ? 1 : 0;

        if ($amount <= 0) $info['errors'][] = "Montant invalide.";

        $dt = parse_fr_date($date);
        if (!$dt) $info['errors'][] = "Date invalide (attendu : JJ/MM/AAAA ou AAAA-MM-JJ).";

        // Harmonisation avec HelloAsso : on enregistre une heure (heure actuelle) en plus de la date
        if ($dt) {
            $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
            $dt = $dt->setTime((int)$now->format('H'), (int)$now->format('i'), (int)$now->format('s'));
        }

        if ($demail !== '' && !filter_var($demail, FILTER_VALIDATE_EMAIL)) {
            $info['errors'][] = "Email invalide.";
        }

        if (empty($info['errors'])) {
            $source = 'manual';
            $sourceRef = 'MANUAL-' . bin2hex(random_bytes(8));

            $payload = [
                'created_by' => 'manual',
                'receipt_eligible' => $receiptEligible,
            ];

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO donations (
                        source, source_ref,
                        donation_date, amount, currency,
                        donor_first_name, donor_last_name, donor_email,
                        donor_address, donor_postal_code, donor_city, donor_country,
                        payment_method, status,
                        receipt_eligible, raw_payload
                    ) VALUES (
                        :source, :source_ref,
                        :donation_date, :amount, :currency,
                        :first, :last, :email,
                        :addr, :postal, :city, :country,
                        :payment_method, :status,
                        :receipt_eligible, :raw_payload
                    )
                ");
                $stmt->execute([
                    'source' => $source,
                    'source_ref' => $sourceRef,
                    'donation_date' => $dt->format('Y-m-d H:i:s'),
                    'amount' => number_format((float)$amount, 2, '.', ''),
                    'currency' => 'EUR',
                    'first' => ($first !== '' ? $first : null),
                    'last'  => ($last !== '' ? $last : null),
                    'email' => ($demail !== '' ? $demail : null),
                    'addr' => ($addr !== '' ? $addr : null),
                    'postal' => ($postal !== '' ? $postal : null),
                    'city' => ($city !== '' ? $city : null),
                    'country' => ($country !== '' ? $country : null),
                    'payment_method' => ($payment !== '' ? $payment : null),
                    'status' => 'paid',
                    'receipt_eligible' => $receiptEligible,
                    'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]);
                $msg = "Don manuel ajouté.";
            } catch (Throwable $e) {
                $info['errors'][] = "Insertion impossible : " . $e->getMessage();
            }
        }
    }
}

// 1bis) Suppression d'un don manuel (source=manual uniquement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_manual_delete'])) {
    if (!$tableOk) {
        $info['errors'][] = "Table donations inexistante : suppression impossible.";
    } else {
        $id = (int)($_POST['donation_id'] ?? 0);
        if ($id <= 0) {
            $info['errors'][] = "ID invalide.";
        } else {
            $stmt = $pdo->prepare("SELECT id, source FROM donations WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $don = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$don) {
                $info['errors'][] = "Don introuvable.";
            } elseif (($don['source'] ?? '') !== 'manual') {
                $info['errors'][] = "Suppression refusée : seuls les dons manuels sont supprimables.";
            } else {
                $del = $pdo->prepare("DELETE FROM donations WHERE id = :id AND source = 'manual'");
                $del->execute(['id' => $id]);
                $msg = "Don manuel supprimé.";
            }
        }
    }
}

// 2) Génération d'un reçu fiscal (manuel uniquement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_generate_receipt'])) {
    if (!$tableOk) {
        $info['errors'][] = "Table donations inexistante : reçu fiscal impossible.";
    } else {
        $id = (int)($_POST['donation_id'] ?? 0);
        if ($id <= 0) {
            $info['errors'][] = "Don invalide.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM donations WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $don = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$don) {
                $info['errors'][] = "Don introuvable.";
            } elseif ((string)($don['source'] ?? '') !== 'manual') {
                $info['errors'][] = "Reçu fiscal désactivé : ce don provient de HelloAsso.";
            } else {
                $donYear = (int)substr((string)($don['donation_date'] ?? ''), 0, 4);
                if ($donYear < 2000) $donYear = (int)date('Y');

                $receiptNumber = (string)($don['receipt_number'] ?? '');
                if ($receiptNumber === '') $receiptNumber = next_receipt_number($pdo, $donYear);

                try {
                    $stmt = $pdo->prepare("
                        UPDATE donations
                        SET receipt_eligible = 1,
                            receipt_date = COALESCE(receipt_date, CURDATE()),
                            receipt_number = :num
                        WHERE id = :id
                    ");
                    $stmt->execute(['num' => $receiptNumber, 'id' => $id]);
                    $msg = "Reçu fiscal généré (n° {$receiptNumber}).";
                } catch (Throwable $e) {
                    $info['errors'][] = "Impossible de générer le reçu : " . $e->getMessage();
                }
            }
        }
    }
}

// 3) Import HelloAsso CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    if (!$tableOk) {
        $info['errors'][] = "Table donations inexistante : import impossible.";
    } elseif (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $info['errors'][] = "Aucun fichier CSV reçu.";
    } else {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $orig = $_FILES['csv_file']['name'] ?? 'export.csv';

        $dryRun = !empty($_POST['dry_run']);
        $source = 'helloasso';

        $firstLine = '';
        $fh0 = fopen($tmp, 'rb');
        if ($fh0) {
            $firstLine = (string)fgets($fh0);
            fclose($fh0);
        }
        $delimiter = detect_delimiter($firstLine ?: ';');

        $fh = fopen($tmp, 'rb');
        if (!$fh) {
            $info['errors'][] = "Impossible d’ouvrir le fichier uploadé.";
        } else {
            // lecture entêtes
            $headers = fgetcsv($fh, 0, $delimiter);
            if (!$headers || count($headers) < 2) {
                $info['errors'][] = "CSV invalide (entêtes manquantes). Séparateur détecté: " . ($delimiter === "\t" ? 'TAB' : $delimiter);
            } else {
                // Normalize headers
                $norm = [];
                $mapIdx = []; // norm_header => index
                foreach ($headers as $i => $hcol) {
                    $nh = normalize_header((string)$hcol);
                    if ($nh === '') $nh = "col_$i";
                    // évite doublons
                    $baseNh = $nh; $k = 2;
                    while (isset($mapIdx[$nh])) { $nh = $baseNh . '_' . $k; $k++; }
                    $norm[$i] = $nh;
                    $mapIdx[$nh] = $i;
                }

                $info['debug'][] = "Fichier : " . h($orig);
                $info['debug'][] = "Séparateur : " . ($delimiter === "\t" ? 'TAB' : $delimiter);
                $info['debug'][] = "Colonnes détectées (" . count($norm) . ") : " . implode(', ', array_slice($norm, 0, 30)) . (count($norm) > 30 ? " …" : "");

                // Candidats HelloAsso fréquents (normalisés)
                // NB: HelloAsso a plusieurs exports (paiements / dons / campagnes) : on ajoute des variantes.
                $colDate = pick_col($norm, [
                    'date_du_paiement', // export "paiements" (constaté)
                    'date_de_paiement', 'date_paiement',
                    'date_du_versement', 'date_de_versement', 'date_versement',
                    'date_de_transaction', 'date_transaction',
                    'date', 'date_de_creation', 'date_creation', 'created_at'
                ]);
                $colAmount = pick_col($norm, [
                    'montant_total', // export "paiements"
                    'montant', 'montant_ttc', 'total', 'amount',
                    'montant_du_tarif', 'montant_du_don', 'don', 'don_supplementaire',
                    'montant_en_eur', 'montant_eur'
                ]);

                // Réf unique (très variable)
                $colRef = pick_col($norm, [
                    // export "paiements" (constaté)
                    'reference_commande', 'reference_paiement',
                    // variantes possibles
                    'reference', 'reference_helloasso', 'id_transaction', 'transaction_id',
                    'id_paiement', 'id_payment', 'numero_de_transaction', 'numero_transaction',
                    'numero', 'id'
                ]);

                // Identité payeur / donateur
                $colFirst = pick_col($norm, ['prenom_payeur','prenom', 'first_name', 'donateur_prenom', 'donor_first_name']);
                $colLast  = pick_col($norm, ['nom_payeur','nom', 'last_name', 'donateur_nom', 'donor_last_name', 'raison_sociale']);
                $colEmail = pick_col($norm, ['email_payeur','email','e_mail','courriel','donateur_email','donor_email']);

                $colCampaign = pick_col($norm, ['campagne', 'campaign', 'page', 'formulaire', 'form', 'evenement', 'event']);
                $colPayment  = pick_col($norm, ['moyen_de_paiement', 'paiement', 'payment_method', 'mode_de_paiement', 'mode_paiement']);
                $colStatus   = pick_col($norm, ['statut', 'status', 'etat']);

                // Adresse (si présent)
                $colAddr = pick_col($norm, ['adresse', 'address', 'adresse_1', 'adresse1']);
                $colZip  = pick_col($norm, ['code_postal', 'cp', 'postal_code']);
                $colCity = pick_col($norm, ['ville', 'city']);
                $colCountry = pick_col($norm, ['pays', 'country']);

                if (!$colDate || !$colAmount) {
                    $info['errors'][] = "Impossible de trouver les colonnes indispensables (date + montant).";
                    $info['errors'][] = "Colonnes détectées : " . implode(', ', $norm);
                } else {
                    $inserted = 0; $updated = 0; $skipped = 0; $rows = 0; $bad = 0;

                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    $sqlIns = "
                        INSERT INTO donations
                          (source, source_ref, donation_date, amount, currency,
                           donor_first_name, donor_last_name, donor_email,
                           donor_address, donor_postal_code, donor_city, donor_country,
                           campaign, payment_method, status, receipt_eligible, raw_payload)
                        VALUES
                          (:source, :source_ref, :donation_date, :amount, :currency,
                           :first, :last, :email,
                           :addr, :zip, :city, :country,
                           :campaign, :payment_method, :status, :receipt_eligible, :raw_payload)
                        ON DUPLICATE KEY UPDATE
                          donation_date = VALUES(donation_date),
                          amount = VALUES(amount),
                          currency = VALUES(currency),
                          donor_first_name = VALUES(donor_first_name),
                          donor_last_name = VALUES(donor_last_name),
                          donor_email = VALUES(donor_email),
                          donor_address = VALUES(donor_address),
                          donor_postal_code = VALUES(donor_postal_code),
                          donor_city = VALUES(donor_city),
                          donor_country = VALUES(donor_country),
                          campaign = VALUES(campaign),
                          payment_method = VALUES(payment_method),
                          status = VALUES(status),
                          receipt_eligible = VALUES(receipt_eligible),
                          raw_payload = VALUES(raw_payload)
                    ";
                    $stmtIns = $pdo->prepare($sqlIns);

                    if (!$dryRun) $pdo->beginTransaction();

                    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                        $rows++;
                        // Skip empty lines
                        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) { $skipped++; continue; }

                        $get = function(?string $col) use ($mapIdx, $row) {
                            if (!$col || !isset($mapIdx[$col])) return null;
                            $idx = $mapIdx[$col];
                            return $row[$idx] ?? null;
                        };

                        $rawDate = $get($colDate);
                        $rawAmount = $get($colAmount);

                        $dt = parse_date_any($rawDate);
                        $amt = parse_amount($rawAmount);

                        if (!$dt || $amt === null) {
                            $bad++;
                            continue;
                        }

                        // Source ref
                        $ref = $get($colRef);
                        $ref = $ref !== null ? trim((string)$ref) : '';
                        if ($ref === '') {
                            // fallback hash: date + amount + email + names
                            $seed = $dt . '|' . number_format((float)$amt, 2, '.', '') . '|'
                                  . (string)$get($colEmail) . '|' . (string)$get($colFirst) . '|' . (string)$get($colLast);
                            $ref = 'hash_' . substr(sha1($seed), 0, 20);
                        }

                        $statusRaw = $get($colStatus);
                        $statusRaw = trim((string)($statusRaw ?? ''));
                        $statusNorm = 'paid';
                        if ($statusRaw !== '') {
                            $sr = mb_strtolower($statusRaw);
                            if (str_contains($sr, 'rembours') || str_contains($sr, 'refund')) $statusNorm = 'refunded';
                            elseif (str_contains($sr, 'annul') || str_contains($sr, 'cancel')) $statusNorm = 'cancelled';
                            elseif (str_contains($sr, 'en_attente') || str_contains($sr, 'pending') || str_contains($sr, 'attente')) $statusNorm = 'pending';
                            elseif (str_contains($sr, 'valide') || str_contains($sr, 'paye') || str_contains($sr, 'paid')) $statusNorm = 'paid';
                            else $statusNorm = 'unknown';
                        }

                        // HelloAsso: généralement EUR
                        $currency = 'EUR';

                        // Eligibilité reçu fiscal (règle simple: payé + montant > 0)
                        $eligible = ($statusNorm === 'paid' && $amt > 0.0) ? 1 : 0;

                        $payload = [];
                        foreach ($norm as $i => $nh) {
                            $payload[$nh] = $row[$i] ?? null;
                        }

                        $params = [
                            'source' => $source,
                            'source_ref' => $ref,
                            'donation_date' => $dt,
                            'amount' => number_format((float)$amt, 2, '.', ''),
                            'currency' => $currency,
                            'first' => $get($colFirst),
                            'last' => $get($colLast),
                            'email' => $get($colEmail),
                            'addr' => $get($colAddr),
                            'zip' => $get($colZip),
                            'city' => $get($colCity),
                            'country' => $get($colCountry),
                            'campaign' => $get($colCampaign),
                            'payment_method' => $get($colPayment),
                            'status' => $statusNorm,
                            'receipt_eligible' => $eligible,
                            'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)
                        ];

                        if ($dryRun) {
                            $inserted++;
                        } else {
                            $stmtIns->execute($params);
                            // rowCount 1 = insert, 2 = update (souvent), 0 possible si identique
                            $rc = $stmtIns->rowCount();
                            if ($rc === 1) $inserted++;
                            elseif ($rc >= 2) $updated++;
                            else $updated++;
                        }
                    }

                    if (!$dryRun) $pdo->commit();

                    $info['import'] = [
                        'rows' => $rows,
                        'inserted' => $inserted,
                        'updated' => $updated,
                        'skipped' => $skipped,
                        'bad' => $bad,
                        'dry' => $dryRun,
                        'mapped' => [
                            'date' => $colDate,
                            'amount' => $colAmount,
                            'ref' => $colRef,
                            'first' => $colFirst,
                            'last' => $colLast,
                            'email' => $colEmail,
                            'campaign' => $colCampaign,
                            'payment' => $colPayment,
                            'status' => $colStatus,
                        ]
                    ];
                }
            }

            fclose($fh);
        }
    }
}

// 4) Synchronisation HelloAsso manuelle ("Synchroniser maintenant")
$haSyncResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_helloasso_sync_now'])) {
    if (!$tableOk) {
        $info['errors'][] = "Table donations inexistante : synchronisation impossible.";
    } else {
        $syncDays = (int)($_POST['sync_days'] ?? 90);
        if ($syncDays < 1) $syncDays = 90;
        $syncDryRun = !empty($_POST['sync_dry_run']);

        // Date de début personnalisée (ex: backfill depuis la création de
        // l'association) : si renseignée et valide, elle prime sur la
        // fenêtre en jours ci-dessus.
        $syncFromDate = trim((string)($_POST['sync_from_date'] ?? ''));
        if ($syncFromDate !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $syncFromDate);
            if (!$d || $d->format('Y-m-d') !== $syncFromDate) {
                $info['errors'][] = "Date de début personnalisée invalide, la fenêtre en jours a été utilisée à la place.";
                $syncFromDate = '';
            }
        }

        try {
            $haSyncResult = helloasso_run_sync($pdo, $syncDays, $syncDryRun, $syncFromDate !== '' ? $syncFromDate : null);
            if ($haSyncResult['fatal_error'] !== null) {
                $info['errors'][] = "Synchronisation HelloAsso impossible : " . $haSyncResult['fatal_error'];
                $haSyncResult = null;
            } else {
                $msg = $syncDryRun
                    ? "Simulation terminée : {$haSyncResult['payments_fetched']} paiement(s) trouvé(s) chez HelloAsso, "
                      . "{$haSyncResult['inserted']} correspondraient à un don (aucune écriture en base)."
                    : "Synchronisation HelloAsso terminée : {$haSyncResult['inserted']} inséré(s), "
                      . "{$haSyncResult['updated']} mis à jour, {$haSyncResult['errors']} erreur(s).";
            }
        } catch (HelloAssoConfigException $e) {
            $info['errors'][] = "Configuration HelloAsso manquante : " . $e->getMessage();
        } catch (Throwable $e) {
            $info['errors'][] = "Erreur inattendue pendant la synchronisation : " . $e->getMessage();
        }
    }
}

// Totaux année
$start = sprintf('%d-01-01 00:00:00', $year);
$end   = sprintf('%d-12-31 23:59:59', $year);

$totals = ['count'=>0,'amount'=>0.0,'eligible'=>0,'eligible_amount'=>0.0];
if ($tableOk) {
    $stmt = $pdo->prepare("
        SELECT
          COUNT(*) AS cnt,
          COALESCE(SUM(amount),0) AS amount,
          SUM(CASE WHEN receipt_eligible=1 THEN 1 ELSE 0 END) AS elig_cnt,
          COALESCE(SUM(CASE WHEN receipt_eligible=1 THEN amount ELSE 0 END),0) AS elig_amount
        FROM donations
        WHERE donation_date BETWEEN :start AND :end
          AND status <> 'cancelled'
    ");
    $stmt->execute(['start'=>$start,'end'=>$end]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totals['count'] = (int)($t['cnt'] ?? 0);
    $totals['amount'] = (float)($t['amount'] ?? 0);
    $totals['eligible'] = (int)($t['elig_cnt'] ?? 0);
    $totals['eligible_amount'] = (float)($t['elig_amount'] ?? 0);
}

// Statut de la synchro automatique HelloAsso (tâche planifiée, voir
// cron/sync_helloasso_donations.php) — purement informatif ici.
$haSyncState = null;
if ($tableOk) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'donations_helloasso_sync_state'");
        if ($stmt->fetchColumn()) {
            $haSyncState = $pdo->query("SELECT * FROM donations_helloasso_sync_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        $haSyncState = null;
    }
}

// Liste récente
$rows = [];
if ($tableOk) {
    $stmt = $pdo->prepare("
        SELECT id, source, donation_date, amount, currency,
               donor_first_name, donor_last_name, donor_email,
               donor_address, donor_postal_code, donor_city, donor_country,
               campaign, payment_method, status, receipt_eligible, receipt_number
        FROM donations
        WHERE donation_date BETWEEN :start AND :end
          AND status <> 'cancelled'
        ORDER BY donation_date DESC
        LIMIT 200
    ");
    $stmt->execute(['start'=>$start,'end'=>$end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Onglet actif du bloc "Actions" (Synchro / Ajout manuel / Import CSV) :
// on rouvre l'onglet correspondant à l'action qui vient d'être soumise, pour
// que le message de résultat reste visible ; "Synchroniser" par défaut sinon.
$activeTab = 'sync';
if (isset($_POST['do_manual_add'])) $activeTab = 'manual';
elseif (isset($_POST['do_import'])) $activeTab = 'import';
elseif (isset($_POST['do_helloasso_sync_now'])) $activeTab = 'sync';

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dons — Touraine-Ukraine</title>
  <link rel="stylesheet" href="<?= h($base) ?>/assets/css/suite_nav.css">
  <style>
  /* Complète le design system tu-v2 : composants spécifiques à cette page
     (auto-complétion d'adresse) qui n'ont pas d'équivalent générique. */
  .addr-suggest{position:relative;}
  .addr-list{position:absolute;left:0;right:0;top:100%;z-index:20;border:1px solid var(--tu-ink-100);background:#fff;border-radius:12px;box-shadow:0 10px 20px rgba(0,0,0,.08);max-height:220px;overflow:auto;margin-top:6px;}
  .addr-item{padding:10px 12px;cursor:pointer;font-size:13px;}
  .addr-item:hover{background:var(--tu-sand-50);}
  </style>
</head>
<body class="tu-v2">

<?php require_once dirname(__DIR__) . '/shared/suite_nav.php'; suite_nav_v2_render('donations', 'donations-list'); ?>

<div class="tu-main">
  <div class="tu-topbar">
    <div class="tu-bc">
      <a href="<?= h($base . '/index.php') ?>" style="color:inherit;text-decoration:none;">Accueil</a>
      <span class="tu-bc-sep">›</span>
      <span class="tu-bc-cur">Dons</span>
    </div>
    <div class="tu-topbar-acts">
      <a href="<?= h($base) ?>/donations/index.php?year=<?= (int)($year-1) ?>" class="tu-btn tu-btn-s tu-btn-sm">← <?= (int)($year-1) ?></a>
      <a href="<?= h($base) ?>/donations/index.php?year=<?= (int)($year+1) ?>" class="tu-btn tu-btn-s tu-btn-sm"><?= (int)($year+1) ?> →</a>
    </div>
  </div>

  <div class="tu-pg">

    <div class="tu-ph">
      <div>
        <div class="tu-ph-title">Dons</div>
        <div class="tu-ph-sub">Année <?= (int)$year ?> · HelloAsso + dons manuels, reçus fiscaux</div>
      </div>
    </div>

<?php if (!$tableOk): ?>
  <div style="background:var(--tu-red-soft);border:1.5px solid rgba(192,67,42,.25);border-radius:14px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;">
    <strong>Table "donations" absente.</strong> Exécute les migrations SQL fournies (donations/migrations/) sur ta base.
  </div>
<?php endif; ?>

<?php if ($msg): ?>
  <div style="background:var(--tu-green-soft);border:1.5px solid rgba(58,158,142,.25);border-radius:14px;padding:12px 16px;margin-bottom:16px;color:var(--tu-green-main);font-size:13px;">
    <?= h($msg) ?>
  </div>
<?php endif; ?>

<?php if (!empty($info['errors'])): ?>
  <div style="background:var(--tu-red-soft);border:1.5px solid rgba(192,67,42,.25);border-radius:14px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;">
    <strong>Erreurs</strong>
    <ul style="margin:8px 0 0; padding-left:18px;">
      <?php foreach ($info['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if (!empty($info['debug'])): ?>
  <div class="tu-card tu-mb4" style="padding:16px 18px;font-size:12px;color:var(--tu-ink-300);">
    <strong style="color:var(--tu-ink-700);">Diagnostic import</strong>
    <ul style="margin:8px 0 0; padding-left:18px;">
      <?php foreach ($info['debug'] as $d): ?><li><?= $d ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if (!empty($info['import'])): ?>
  <div style="background:var(--tu-green-soft);border:1.5px solid rgba(58,158,142,.25);border-radius:14px;padding:12px 16px;margin-bottom:16px;color:var(--tu-green-main);font-size:13px;">
    <strong>Import terminé<?= !empty($info['import']['dry']) ? ' (simulation)' : '' ?></strong>
    <div style="margin-top:6px;">
      Lignes lues : <strong><?= (int)$info['import']['rows'] ?></strong> ·
      Importées : <strong><?= (int)$info['import']['inserted'] ?></strong> ·
      Mises à jour : <strong><?= (int)$info['import']['updated'] ?></strong> ·
      Ignorées vides : <strong><?= (int)$info['import']['skipped'] ?></strong> ·
      Erreurs format : <strong><?= (int)$info['import']['bad'] ?></strong>
    </div>
  </div>
<?php endif; ?>

    <!-- KPIs -->
    <div class="tu-kpi-grid">
      <div class="tu-kpi teal"><div class="tu-kpi-val"><?= (int)$totals['count'] ?></div><div class="tu-kpi-lbl">Dons enregistrés</div></div>
      <div class="tu-kpi amber"><div class="tu-kpi-val"><?= h(number_format((float)$totals['amount'], 0, ',', ' ')) ?> €</div><div class="tu-kpi-lbl">Montant total (hors annulés)</div></div>
      <div class="tu-kpi green"><div class="tu-kpi-val"><?= (int)$totals['eligible'] ?></div><div class="tu-kpi-lbl">Éligibles reçu fiscal</div></div>
      <div class="tu-kpi red"><div class="tu-kpi-val"><?= h(number_format((float)$totals['eligible_amount'], 0, ',', ' ')) ?> €</div><div class="tu-kpi-lbl">Montant éligible</div></div>
    </div>

<div class="tu-card tu-mb4" style="padding:22px;">
  <div class="tu-sec-div"><span class="tu-sec-div-lbl">Actions</span><div class="tu-sec-div-line"></div></div>

  <div class="tu-tabs tu-mb4" role="tablist">
    <?php if ($tableOk): ?>
    <button type="button" class="tu-tab <?= $activeTab === 'sync' ? 'tu-on' : '' ?>" data-tab="sync">Synchroniser HelloAsso</button>
    <?php endif; ?>
    <button type="button" class="tu-tab <?= $activeTab === 'manual' ? 'tu-on' : '' ?>" data-tab="manual">Ajouter un don manuel</button>
    <button type="button" class="tu-tab <?= $activeTab === 'import' ? 'tu-on' : '' ?>" data-tab="import">Importer un CSV</button>
  </div>

  <?php if ($tableOk): ?>
  <div class="tu-tab-panel" data-panel="sync" style="<?= $activeTab === 'sync' ? '' : 'display:none;' ?>">

  <?php if (!$haSyncState || empty($haSyncState['last_sync_finished_at'])): ?>
    <p style="margin:0;font-size:13px;color:var(--tu-ink-300);">Aucune synchronisation n'a encore tourné (tâche planifiée pas encore active, ou jamais lancée manuellement).</p>
  <?php else: ?>
    <p style="margin:0;font-size:13px;">
      Dernière synchro : <strong><?= h(date('d/m/Y H:i', strtotime((string)$haSyncState['last_sync_finished_at']))) ?></strong>
      — <?= (int)($haSyncState['last_sync_ok'] ?? 0) === 1 ? '<span class="tu-bdg tu-bdg-green">OK</span>' : '<span class="tu-bdg tu-bdg-red">Échec</span>' ?>
    </p>
    <p style="margin-top:8px;font-size:12px;color:var(--tu-ink-300);">
      Importés : <strong><?= (int)($haSyncState['last_sync_inserted'] ?? 0) ?></strong> ·
      Mis à jour : <strong><?= (int)($haSyncState['last_sync_updated'] ?? 0) ?></strong> ·
      Erreurs : <strong><?= (int)($haSyncState['last_sync_errors'] ?? 0) ?></strong>
    </p>
    <?php if (!empty($haSyncState['last_error'])): ?>
      <p style="margin-top:6px;font-size:12px;color:var(--tu-red-main);">Dernière erreur : <?= h((string)$haSyncState['last_error']) ?></p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($haSyncResult): ?>
    <div style="margin-top:14px;background:<?= $haSyncResult['dry_run'] ? 'var(--tu-sand-50)' : ($haSyncResult['errors'] === 0 ? 'var(--tu-green-soft)' : 'var(--tu-red-soft)') ?>;border:1px solid var(--tu-ink-100);border-radius:14px;padding:12px 16px;font-size:13px;">
      <strong><?= $haSyncResult['dry_run'] ? 'Résultat de la simulation' : 'Résultat de la synchronisation' ?></strong>
      <span style="color:var(--tu-ink-300);font-size:12px;"> (<?= h($haSyncResult['from']) ?> → <?= h($haSyncResult['to']) ?>)</span>
      <div style="margin-top:6px;font-size:12px;color:var(--tu-ink-300);">
        Paiements reçus de l'API : <strong style="color:var(--tu-ink-700);"><?= (int)$haSyncResult['payments_fetched'] ?></strong> ·
        Insérés<?= $haSyncResult['dry_run'] ? ' (simulés)' : '' ?> : <strong style="color:var(--tu-ink-700);"><?= (int)$haSyncResult['inserted'] ?></strong> ·
        Mis à jour : <strong style="color:var(--tu-ink-700);"><?= (int)$haSyncResult['updated'] ?></strong> ·
        Ignorés (hors don) : <strong style="color:var(--tu-ink-700);"><?= (int)$haSyncResult['skipped_not_donation'] ?></strong> ·
        Erreurs : <strong style="color:var(--tu-ink-700);"><?= (int)$haSyncResult['errors'] ?></strong>
      </div>
      <?php if (!empty($haSyncResult['last_error'])): ?>
        <div style="margin-top:6px;font-size:12px;color:var(--tu-red-main);">Dernière erreur : <?= h((string)$haSyncResult['last_error']) ?></div>
      <?php endif; ?>
      <?php if (!empty($haSyncResult['campaigns'])): ?>
        <div style="margin-top:10px;font-size:12px;color:var(--tu-ink-300);">
          Formulaires (campagnes) rencontrés cette synchro — utile pour repérer le <code>formSlug</code> à ajouter à <code>HELLOASSO_EXCLUDE_FORM_SLUGS</code> si un formulaire d'adhésion s'y glisse :
          <ul style="margin:6px 0 0; padding-left:18px;">
            <?php foreach ($haSyncResult['campaigns'] as $slug => $c): ?>
              <li><code><?= h($slug) ?></code> — <?= (int)$c['count'] ?> paiement(s)</li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <form method="post" style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <input type="hidden" name="do_helloasso_sync_now" value="1">
    <div class="tu-form-field" style="min-width:220px;">
      <span class="tu-lbl">Fenêtre</span>
      <select class="tu-input" name="sync_days">
        <option value="90">90 derniers jours</option>
        <option value="30">30 derniers jours</option>
        <option value="400">400 derniers jours (backfill initial)</option>
      </select>
    </div>
    <div class="tu-form-field" style="min-width:200px;">
      <span class="tu-lbl">Date de début personnalisée</span>
      <input class="tu-input" type="date" name="sync_from_date" value="">
      <div class="tu-hint">Remplace la fenêtre ci-dessus si renseignée — ex: 01/01/2022 pour tout reprendre depuis la création de l'association.</div>
    </div>
    <label style="display:inline-flex;gap:8px;align-items:center;font-size:13px;padding-bottom:11px;">
      <input type="checkbox" name="sync_dry_run" value="1" checked>
      Simulation (ne rien écrire en base)
    </label>
    <button type="submit" class="tu-btn tu-btn-p">Synchroniser maintenant</button>
  </form>

  <p style="margin-top:12px;margin-bottom:0;font-size:11px;color:var(--tu-ink-300);">Les dons HelloAsso apparaissent ci-dessous avec les mêmes règles que l'import CSV (non modifiables/supprimables ici). Nécessite <code>donations/.env</code> renseigné (HELLOASSO_CLIENT_ID / SECRET / ORG_SLUG).</p>
  </div><!-- /panel sync -->
  <?php endif; ?>

  <div class="tu-tab-panel" data-panel="manual" style="<?= $activeTab === 'manual' ? '' : 'display:none;' ?>">
  <p style="margin-top:0;margin-bottom:16px;font-size:12px;color:var(--tu-ink-300);">À utiliser uniquement pour les dons hors HelloAsso. Les reçus fiscaux ne pourront être générés que pour ces dons manuels.</p>

  <form method="post" autocomplete="off">
    <input type="hidden" name="do_manual_add" value="1">

    <div class="tu-form-grid tu-form-grid-3 tu-mb4">
      <div class="tu-form-field">
        <span class="tu-lbl">Date du don *</span>
        <input class="tu-input" type="text" name="donation_date" placeholder="JJ/MM/AAAA" required>
      </div>
      <div class="tu-form-field">
        <span class="tu-lbl">Montant (€) *</span>
        <input class="tu-input" type="number" name="amount" step="0.01" min="0" placeholder="0,00" required>
      </div>
      <div class="tu-form-field">
        <span class="tu-lbl">Mode de paiement</span>
        <select class="tu-input" name="payment_method">
          <option value="">Choisir…</option>
          <option value="Espèces">Espèces</option>
          <option value="Chèque">Chèque</option>
          <option value="Virement">Virement</option>
          <option value="CB (hors HelloAsso)">CB (hors HelloAsso)</option>
        </select>
      </div>
    </div>

    <div class="tu-form-grid tu-mb4">
      <div class="tu-form-field">
        <span class="tu-lbl">Prénom</span>
        <input class="tu-input" type="text" name="donor_first_name" placeholder="Prénom">
      </div>
      <div class="tu-form-field">
        <span class="tu-lbl">Nom</span>
        <input class="tu-input" type="text" name="donor_last_name" placeholder="Nom">
      </div>
    </div>

    <div class="tu-form-field tu-mb4">
      <span class="tu-lbl">Email (optionnel)</span>
      <input class="tu-input" type="email" name="donor_email" placeholder="email@exemple.com">
    </div>

    <div class="tu-form-field tu-mb4 addr-suggest">
      <span class="tu-lbl">Adresse (optionnel)</span>
      <input class="tu-input" id="addr_input" type="text" name="donor_address" placeholder="N° et rue">
      <div id="addr_list" class="addr-list" style="display:none;"></div>
    </div>

    <div class="tu-form-grid tu-form-grid-3 tu-mb4">
      <div class="tu-form-field">
        <span class="tu-lbl">Code postal</span>
        <input class="tu-input" id="addr_postal" type="text" name="donor_postal_code" placeholder="37000">
      </div>
      <div class="tu-form-field">
        <span class="tu-lbl">Ville</span>
        <input class="tu-input" id="addr_city" type="text" name="donor_city" placeholder="Tours">
      </div>
      <div class="tu-form-field">
        <span class="tu-lbl">Pays</span>
        <input class="tu-input" type="text" name="donor_country" value="France">
      </div>
    </div>

    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-top:6px;">
      <label style="display:inline-flex;gap:8px;align-items:center;font-size:13px;font-weight:700;">
        <input type="checkbox" name="receipt_eligible" value="1" checked>
        Don éligible au reçu fiscal
      </label>
      <button class="tu-btn tu-btn-p" type="submit">Ajouter le don</button>
    </div>

    <p class="tu-hint" style="margin-top:12px;margin-bottom:0;">Le don sera enregistré avec <code>source=manual</code> et <code>status=paid</code>. L’heure est enregistrée automatiquement (heure courante) pour harmoniser avec HelloAsso.</p>
  </form>

  <script>
  (function(){
    const input = document.getElementById("addr_input");
    const list  = document.getElementById("addr_list");
    const cp    = document.getElementById("addr_postal");
    const city  = document.getElementById("addr_city");
    if(!input || !list) return;

    let t = null;
    function hide(){ list.style.display="none"; list.innerHTML=""; }
    function show(items){
      list.innerHTML = items.map((it, idx) => `<div class="addr-item" data-idx="${idx}">${it.label}</div>`).join("");
      list.style.display = items.length ? "block" : "none";
      list.onclick = (e) => {
        const el = e.target.closest(".addr-item");
        if(!el) return;
        const item = items[parseInt(el.getAttribute("data-idx"),10)];
        input.value = item.street || item.label;
        if(item.postcode && cp) cp.value = item.postcode;
        if(item.city && city) city.value = item.city;
        hide();
      };
    }

    input.addEventListener("input", () => {
      const q = input.value.trim();
      if(t) clearTimeout(t);
      if(q.length < 4){ hide(); return; }
      t = setTimeout(async () => {
        try{
          const url = "https://api-adresse.data.gouv.fr/search/?q=" + encodeURIComponent(q) + "&limit=6";
          const res = await fetch(url);
          const json = await res.json();
          const feats = (json.features || []).map(f => {
            const p = (f && f.properties) ? f.properties : {};
            const street = [p.housenumber, p.street].filter(Boolean).join(' ').trim()
              || (p.name || p.label || '');
            return {
              street,
              postcode: p.postcode || '',
              city: p.city || ''
            };
          });

          // Normalisation "rue de la" côté front (confort) – le back renormalise aussi
          function normalizeStreetFr(s){
            s = (s||'').trim();
            if(!s) return s;
            s = s.toLowerCase();
            // remonte la 1ère lettre de chaque mot
            s = s.replace(/(^|[\s\-’'])\p{L}/gu, m => m.toUpperCase());
            // particules en minuscules
            s = s.replace(/\b(De|Du|Des|La|Le|Les|Et|Au|Aux|A|À)\b/g, m => m.toLowerCase());
            s = s.replace(/\b(d'|d’|l'|l’)\b/gi, m => m.toLowerCase());
            // types de voie en minuscules
            s = s.replace(/\b(Rue|Avenue|Boulevard|Impasse|Chemin|Route|Allée|Place|Quai|Square|Cours|Voie|Résidence|Residence)\b/g, m => m.toLowerCase());
            return s;
          }

          const items = feats.map(it => ({
            label: [normalizeStreetFr(it.street), (it.postcode && it.city ? `${it.postcode} ${it.city}` : '')].filter(Boolean).join(' — '),
            street: normalizeStreetFr(it.street),
            postcode: it.postcode,
            city: it.city
          }));
          show(items);
        }catch(err){ hide(); }
      }, 250);
    });

    document.addEventListener("click", (e) => {
      if(e.target === input || list.contains(e.target)) return;
      hide();
    });
  })();
  </script>
  </div><!-- /panel manual -->

  <div class="tu-tab-panel" data-panel="import" style="<?= $activeTab === 'import' ? '' : 'display:none;' ?>">
  <p style="margin-top:0;margin-bottom:16px;font-size:12px;color:var(--tu-ink-300);">
    Astuce : l’export HelloAsso est souvent en <strong>;</strong> (point-virgule). L’import détecte automatiquement.
  </p>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="do_import" value="1">
    <div class="tu-form-field tu-mb4">
      <span class="tu-lbl">Fichier CSV *</span>
      <input class="tu-input" type="file" name="csv_file" accept=".csv,text/csv" required>
    </div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;">
      <label style="display:inline-flex;gap:8px;align-items:center;font-size:13px;">
        <input type="checkbox" name="dry_run" value="1" checked>
        Simulation (ne rien écrire en base)
      </label>
      <button type="submit" class="tu-btn tu-btn-p">Importer</button>
    </div>
    <p class="tu-hint" style="margin-top:12px;">
      Obligatoire : une colonne <strong>date</strong> et une colonne <strong>montant</strong> (le reste est optionnel).
      Si l’outil n’arrive pas à “matcher” les colonnes, il te liste les entêtes détectées pour ajuster.
    </p>
  </form>
  </div><!-- /panel import -->

</div><!-- /tu-card Actions -->

<script>
(function(){
  var tabs = document.querySelectorAll('.tu-tab[data-tab]');
  var panels = document.querySelectorAll('.tu-tab-panel[data-panel]');
  tabs.forEach(function(btn){
    btn.addEventListener('click', function(){
      tabs.forEach(function(b){ b.classList.remove('tu-on'); });
      btn.classList.add('tu-on');
      panels.forEach(function(p){
        p.style.display = (p.getAttribute('data-panel') === btn.getAttribute('data-tab')) ? '' : 'none';
      });
    });
  });
})();
</script>

<div class="tu-card" style="overflow:hidden;">
  <div style="padding:18px 22px 0;">
    <div class="tu-sec-div" style="margin-bottom:0;"><span class="tu-sec-div-lbl">Derniers dons (<?= (int)$year ?>)</span><div class="tu-sec-div-line"></div></div>
  </div>

  <?php if (empty($rows)): ?>
    <p style="text-align:center;padding:28px;color:var(--tu-ink-300);font-size:13px;">Aucun don sur la période.</p>
  <?php else: ?>
    <table class="tu-tbl">
      <thead>
        <tr>
          <th>Date</th>
          <th>Donateur</th>
          <th>Montant</th>
          <th>Paiement</th>
          <th>Campagne</th>
          <th>Reçu</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $name = trim(($r['donor_first_name'] ?? '') . ' ' . ($r['donor_last_name'] ?? ''));
            $email = (string)($r['donor_email'] ?? '');
            $isManual = (($r['source'] ?? '') === 'manual');
            // Affichage du moyen de paiement : quand le don vient bien de
            // HelloAsso (source='helloasso', que ce soit via la synchro API
            // ou un ancien import CSV HelloAsso), on affiche "HelloAsso"
            // de façon homogène plutôt que la valeur brute stockée
            // ("Carte bancaire", "Card (HelloAsso)"...), sans modifier la
            // donnée en base.
            $isHelloAsso = (($r['source'] ?? '') === 'helloasso');
            $paymentLabel = $isHelloAsso ? 'HelloAsso' : ($r['payment_method'] ?? '—');
            // Le "campaign" vient tel quel de HelloAsso (formSlug) ou du CSV :
            // certains anciens formulaires HelloAsso n'ont jamais eu de slug
            // personnalisé et l'API ne renvoie alors qu'un identifiant
            // numérique brut ("2") — on l'affiche de façon un peu plus
            // lisible plutôt qu'un chiffre nu, sans rien changer en base.
            $campaignRaw = trim((string)($r['campaign'] ?? ''));
            $campaignLabel = $campaignRaw === '' ? '—' : (ctype_digit($campaignRaw) ? "Formulaire n°{$campaignRaw}" : $campaignRaw);
          ?>
          <tr>
            <td style="font-size:12px;color:var(--tu-ink-300);"><?= h(date('d/m/Y H:i', strtotime((string)$r['donation_date']))) ?></td>
            <td>
              <div style="font-weight:700;font-size:13px;"><?= h($name ?: '—') ?></div>
              <?php if ($email): ?><div style="font-size:11px;color:var(--tu-ink-300);"><?= h($email) ?></div><?php endif; ?>
            </td>
            <td style="font-weight:700;font-size:13px;"><?= h(number_format((float)$r['amount'], 2, ',', ' ')) ?> €</td>
            <td style="font-size:13px;"><?= h($paymentLabel) ?></td>
            <td style="font-size:12px;color:var(--tu-ink-300);"><?= h($campaignLabel) ?></td>
            <td>
              <?php if (!empty($r['receipt_number'])): ?>
                <a class="tu-bdg tu-bdg-teal" target="_blank" href="<?= h($base) ?>/donations/receipt_view.php?id=<?= (int)$r['id'] ?>" style="text-decoration:none;"><?= h($r['receipt_number']) ?></a>
                <a target="_blank" href="<?= h($base) ?>/donations/receipt_pdf.php?id=<?= (int)$r['id'] ?>" style="margin-left:6px;font-size:11px;color:var(--tu-ink-300);">PDF</a>
              <?php elseif ((int)($r['receipt_eligible'] ?? 0) === 1): ?>
                <span class="tu-bdg tu-bdg-amber">éligible</span>
              <?php else: ?>
                <span style="color:var(--tu-ink-300);">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isManual): ?>
                <div class="tu-tbl-acts">
                  <?php if (empty($r['receipt_number']) && (int)($r['receipt_eligible'] ?? 0) === 1): ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="do_generate_receipt" value="1">
                      <input type="hidden" name="donation_id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="tu-btn tu-btn-t tu-btn-xs">Générer reçu</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ce don manuel ?');">
                    <input type="hidden" name="do_manual_delete" value="1">
                    <input type="hidden" name="donation_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="tu-btn tu-btn-d tu-btn-xs">Supprimer</button>
                  </form>
                </div>
              <?php else: ?>
                <span style="color:var(--tu-ink-300);">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

  </div><!-- /tu-pg -->
</div><!-- /tu-main -->
</body>
</html>
