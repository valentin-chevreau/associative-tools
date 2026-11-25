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
require_once __DIR__ . '/includes/receipt.php';

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
/**
 * Enregistre le justificatif d'un don en nature (PDF, JPEG ou PNG, 5 Mo max) dans uploads/donations/
 * (dossier hors dépôt, servi uniquement par donations/proof.php). Renvoie le chemin relatif, ou null si aucun fichier.
 * @throws RuntimeException
 */
function store_donation_proof(?array $f): ?string {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new RuntimeException("Le téléversement du justificatif a échoué.");
    if ((int)$f['size'] > 5 * 1024 * 1024) throw new RuntimeException("Justificatif trop lourd (5 Mo maximum).");
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
    if ($ext === null) throw new RuntimeException("Justificatif : formats acceptés PDF, JPEG ou PNG.");
    $dir = dirname(__DIR__) . '/uploads/donations';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException("Impossible de créer le dossier uploads/donations (droits d'écriture ?).");
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!@move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException("Impossible d'enregistrer le justificatif.");
    return 'donations/' . $name;
}

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

// Résultat d'un envoi de reçu par e-mail (donations/receipt_send.php)
if (!empty($_SESSION['receipt_flash']) && is_array($_SESSION['receipt_flash'])) {
    $rf = $_SESSION['receipt_flash'];
    unset($_SESSION['receipt_flash']);
    if (!empty($rf['ok'])) $msg = (string)($rf['text'] ?? '');
    else $info['errors'][] = (string)($rf['text'] ?? 'Envoi impossible.');
}

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

        // Type de donateur / de don (entreprise, don en nature, mécénat de compétences)
        $donorType  = (($_POST['donor_type'] ?? '') === 'company') ? 'company' : 'individual';
        $kind       = (string)($_POST['donation_kind'] ?? 'cash');
        if (!isset(donation_kinds()[$kind])) $kind = 'cash';
        $company    = $donorType === 'company' ? trim((string)($_POST['donor_company_name'] ?? '')) : '';
        $siren      = $donorType === 'company' ? receipt_digits((string)($_POST['donor_siren'] ?? '')) : '';
        $inKindDesc = $kind !== 'cash' ? trim(str_replace("\r", '', (string)($_POST['in_kind_description'] ?? ''))) : '';
        $valBasis   = $kind !== 'cash' ? (string)($_POST['valuation_basis'] ?? '') : '';
        $valSource  = $kind !== 'cash' ? trim((string)($_POST['valuation_source'] ?? '')) : '';
        $noCounter  = ($kind !== 'cash' && !empty($_POST['no_counterpart'])) ? 1 : 0;
        if ($kind !== 'cash') $payment = '';

        // Normalisation (affichage + PDF plus propres)
        $first = u_title($first);
        $last  = u_upper($last);
        $city  = u_upper($city);
        $country = u_title($country);
        $addr  = normalize_french_street($addr);

        $receiptEligible = !empty($_POST['receipt_eligible']) ? 1 : 0;

        if ($amount <= 0) $info['errors'][] = "Montant invalide.";

        if ($donorType === 'company') {
            if ($company === '') $info['errors'][] = "Raison sociale de l'entreprise obligatoire.";
            if ($receiptEligible && !receipt_siren_valid($siren)) $info['errors'][] = "SIREN ou SIRET invalide (9 ou 14 chiffres) : il est obligatoire sur le reçu d'une entreprise.";
        }
        if ($kind !== 'cash') {
            $bases = donation_valuation_bases();
            if ($inKindDesc === '') $info['errors'][] = "Décrivez précisément les biens ou services donnés (nature et quantités).";
            if (!isset($bases[$valBasis]) || !in_array($donorType, $bases[$valBasis]['for'], true) || !in_array($kind, $bases[$valBasis]['kinds'], true)) {
                $info['errors'][] = "Choisissez la base de valorisation qui correspond à ce type de don et de donateur.";
            }
            if ($valSource === '') $info['errors'][] = "Indiquez qui a communiqué la valorisation et comment (attestation, facture…) : sans elle, le reçu ne peut pas être émis.";
            if (!$noCounter) $info['errors'][] = "Confirmez que le donateur ne reçoit aucune contrepartie directe : sinon il s'agit de parrainage, qui n'ouvre pas droit au reçu fiscal.";
            if ($donorType === 'company' && $company === '' && $last === '') $info['errors'][] = "Identité du donateur manquante.";
            if ($donorType === 'individual' && $last === '' && $first === '') $info['errors'][] = "Nom du donateur obligatoire pour un don en nature.";
        }

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

        $proofPath = null;
        if (empty($info['errors']) && $kind !== 'cash') {
            try { $proofPath = store_donation_proof($_FILES['proof'] ?? null); }
            catch (Throwable $e) { $info['errors'][] = $e->getMessage(); }
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
                        donor_type, donor_company_name, donor_siren, donation_kind,
                        in_kind_description, valuation_basis, valuation_source, no_counterpart, proof_path,
                        receipt_eligible, raw_payload
                    ) VALUES (
                        :source, :source_ref,
                        :donation_date, :amount, :currency,
                        :first, :last, :email,
                        :addr, :postal, :city, :country,
                        :payment_method, :status,
                        :donor_type, :company, :siren, :kind,
                        :in_kind_description, :valuation_basis, :valuation_source, :no_counterpart, :proof_path,
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
                    'donor_type' => $donorType,
                    'company' => ($company !== '' ? $company : null),
                    'siren' => ($siren !== '' ? $siren : null),
                    'kind' => $kind,
                    'in_kind_description' => ($inKindDesc !== '' ? $inKindDesc : null),
                    'valuation_basis' => ($valBasis !== '' ? $valBasis : null),
                    'valuation_source' => ($valSource !== '' ? $valSource : null),
                    'no_counterpart' => $noCounter,
                    'proof_path' => $proofPath,
                    'receipt_eligible' => $receiptEligible,
                    'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]);
                audit_log('donations', 'create', 'donation', (int)$pdo->lastInsertId(),
                    'Don manuel du ' . $dt->format('d/m/Y') . ' — ' . number_format((float)$amount, 2, ',', ' ') . ' €',
                    ['montant' => round((float)$amount, 2), 'moyen' => $payment !== '' ? $payment : null, 'eligible_recu' => $receiptEligible, 'type_donateur' => $donorType, 'nature' => $kind, 'justificatif' => $proofPath !== null]);
                $msg = $kind === 'cash' ? "Don manuel ajouté." : "Don en nature ajouté : il sera valorisé à " . number_format((float)$amount, 2, ',', ' ') . " € sur le reçu.";
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
            $stmt = $pdo->prepare("SELECT id, source, amount, donation_date, receipt_number, receipt_group_id FROM donations WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $don = $stmt->fetch(PDO::FETCH_ASSOC);
            $inGroup = false;
            if ($don) {
                $gq = $pdo->prepare("SELECT COUNT(*) FROM donations WHERE receipt_group_id = :i");
                $gq->execute(['i' => $id]);
                $inGroup = ((int)($don['receipt_group_id'] ?? 0) > 0) || ((int)$gq->fetchColumn() > 0);
            }

            if (!$don) {
                $info['errors'][] = "Don introuvable.";
            } elseif ($inGroup) {
                $info['errors'][] = "Suppression refusée : ce don fait partie d'un reçu groupé déjà émis.";
            } elseif (($don['source'] ?? '') !== 'manual') {
                $info['errors'][] = "Suppression refusée : seuls les dons manuels sont supprimables.";
            } else {
                $del = $pdo->prepare("DELETE FROM donations WHERE id = :id AND source = 'manual'");
                $del->execute(['id' => $id]);
                audit_log('donations', 'delete', 'donation', $id,
                    'Don manuel du ' . date('d/m/Y', strtotime((string)$don['donation_date'])) . ' — ' . number_format((float)$don['amount'], 2, ',', ' ') . ' €',
                    ['montant' => round((float)$don['amount'], 2), 'recu_numero' => ($don['receipt_number'] ?? '') !== '' ? $don['receipt_number'] : null]);
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
            } elseif (($issue = receipt_blocking_issue($don)) !== null) {
                $info['errors'][] = "Reçu impossible : " . $issue;
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
                    audit_log('donations', 'generate', 'donation', $id,
                        'Reçu fiscal n° ' . $receiptNumber,
                        ['montant' => round((float)$don['amount'], 2), 'regeneration' => ((string)($don['receipt_number'] ?? '') !== '')]);
                    $msg = "Reçu fiscal généré (n° {$receiptNumber}).";
                } catch (Throwable $e) {
                    $info['errors'][] = "Impossible de générer le reçu : " . $e->getMessage();
                }
            }
        }
    }
}

// 2bis) Reçu groupé : un seul reçu pour plusieurs dons du même donateur (même nature, même année)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_group_receipt'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['group_ids'] ?? [])))));
    if (!$tableOk) {
        $info['errors'][] = "Table donations inexistante : reçu groupé impossible.";
    } elseif (count($ids) < 2) {
        $info['errors'][] = "Cochez au moins deux dons à regrouper dans un même reçu.";
    } else {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM donations WHERE id IN ($in) ORDER BY donation_date ASC, id ASC");
        $stmt->execute($ids);
        $grp = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $err = null;
        if (count($grp) !== count($ids)) $err = "Un des dons sélectionnés est introuvable.";
        $donorKey = static function (array $r): string {
            if ((string)($r['donor_type'] ?? 'individual') === 'company') return 'c|' . receipt_digits((string)($r['donor_siren'] ?? ''));
            return 'p|' . mb_strtolower(trim((string)($r['donor_first_name'] ?? '')) . '|' . trim((string)($r['donor_last_name'] ?? '')) . '|' . trim((string)($r['donor_postal_code'] ?? '')), 'UTF-8');
        };
        if ($err === null) {
            $ref = $grp[0];
            foreach ($grp as $r) {
                if ((string)($r['source'] ?? '') !== 'manual') { $err = "Seuls les dons manuels peuvent être regroupés."; break; }
                if ((int)($r['receipt_eligible'] ?? 0) !== 1) { $err = "Tous les dons doivent être éligibles au reçu fiscal."; break; }
                if (!empty($r['receipt_number']) || !empty($r['receipt_group_id'])) { $err = "Un des dons a déjà un reçu."; break; }
                if (($issue = receipt_blocking_issue($r)) !== null) { $err = $issue; break; }
                if ($donorKey($r) !== $donorKey($ref)) { $err = "Les dons doivent venir du même donateur."; break; }
                if ((string)($r['donation_kind'] ?? 'cash') !== (string)($ref['donation_kind'] ?? 'cash')) { $err = "Ne regroupez que des dons de même nature (numéraire, biens ou prestations)."; break; }
                if (substr((string)$r['donation_date'], 0, 4) !== substr((string)$ref['donation_date'], 0, 4)) { $err = "Les dons doivent appartenir à la même année civile."; break; }
            }
        }
        if ($err !== null) {
            $info['errors'][] = "Reçu groupé impossible : " . $err;
        } else {
            $year = (int)substr((string)$grp[0]['donation_date'], 0, 4);
            $number = next_receipt_number($pdo, $year);
            $masterId = (int)$grp[0]['id'];
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE donations SET receipt_number = :n, receipt_date = CURDATE(), receipt_group_id = NULL WHERE id = :id")
                    ->execute(['n' => $number, 'id' => $masterId]);
                $up = $pdo->prepare("UPDATE donations SET receipt_number = :n, receipt_date = CURDATE(), receipt_group_id = :m WHERE id = :id");
                foreach (array_slice($grp, 1) as $r) $up->execute(['n' => $number, 'm' => $masterId, 'id' => (int)$r['id']]);
                $pdo->commit();
                $total = array_sum(array_map(fn($r) => (float)$r['amount'], $grp));
                audit_log('donations', 'generate', 'donation', $masterId, 'Reçu fiscal groupé n° ' . $number,
                    ['montant' => round($total, 2), 'dons' => array_map(fn($r) => (int)$r['id'], $grp), 'groupe' => true]);
                $msg = "Reçu groupé généré (n° {$number}, " . count($grp) . " dons, " . number_format($total, 2, ',', ' ') . " €).";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $info['errors'][] = "Impossible de générer le reçu groupé : " . $e->getMessage();
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

                    if (!$dryRun) {
                        $pdo->commit();
                        audit_log('donations', 'import', 'donation', null, (string)$orig, [
                            'lignes' => $rows, 'crees' => $inserted, 'mis_a_jour' => $updated, 'ignorees' => $skipped, 'invalides' => $bad,
                        ]);
                    }

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
               campaign, payment_method, status, receipt_eligible, receipt_number,
               donor_type, donor_company_name, donation_kind, receipt_group_id, proof_path
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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Dons — Touraine-Ukraine</title>
  <link rel="stylesheet" href="<?= h($base) ?>/assets/css/suite_nav.css<?= function_exists('suite_css_v') ? suite_css_v() : '' ?>"><?= function_exists('suite_pwa_head') ? suite_pwa_head() : '' ?>
  <style>
  /* Complète le design system tu-v2 : composants spécifiques à cette page
     (auto-complétion d'adresse) qui n'ont pas d'équivalent générique. */
  .addr-suggest{position:relative;}
  .addr-list{position:absolute;left:0;right:0;top:100%;z-index:20;border:1px solid var(--tu-ink-100);background:#fff;border-radius:12px;box-shadow:0 10px 20px rgba(0,0,0,.08);max-height:220px;overflow:auto;margin-top:6px;}
  .addr-item{padding:10px 12px;cursor:pointer;font-size:13px;}
  .addr-item:hover{background:var(--tu-sand-50);}
  /* Bulles d'aide « ? » du formulaire de don */
  .tu-help-wrap{position:relative;display:inline-block;margin-left:4px;vertical-align:middle;text-transform:none;letter-spacing:0;font-weight:400;}
  .tu-help{width:18px;height:18px;border-radius:50%;border:1px solid var(--tu-ink-100);background:#fff;color:var(--tu-ink-300);font:600 11px/16px system-ui,sans-serif;cursor:pointer;padding:0;}
  .tu-help:hover,.tu-help[aria-expanded="true"]{background:var(--tu-sand-50);color:var(--tu-ink-900,#111);}
  .tu-help-pop{position:absolute;left:0;top:calc(100% + 6px);z-index:40;width:min(400px,84vw);max-height:60vh;overflow:auto;background:#fff;border:1px solid var(--tu-ink-100);border-radius:12px;box-shadow:0 10px 24px rgba(0,0,0,.12);padding:12px 14px;font-size:12.5px;line-height:1.5;color:var(--tu-ink-700,#333);text-align:left;white-space:normal;}
  .tu-help-pop[hidden]{display:none;}
  .tu-help-list{margin:8px 0 0;padding-left:16px;} .tu-help-list li{margin:0 0 8px;}
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

  <form method="post" class="tu-form-bar" style="margin-top:16px;">
    <input type="hidden" name="do_helloasso_sync_now" value="1">
    <div class="tu-form-field" style="min-width:220px;">
      <span class="tu-lbl">Fenêtre</span>
      <select class="tu-input" name="sync_days">
        <option value="90">90 derniers jours</option>
        <option value="30">30 derniers jours</option>
        <option value="400">400 derniers jours (backfill initial)</option>
      </select>
    </div>
    <div class="tu-form-field" style="flex:1 1 280px;min-width:200px;max-width:520px;">
      <span class="tu-lbl">Date de début personnalisée</span>
      <input class="tu-input" type="date" name="sync_from_date" value="">
      <div class="tu-hint">Remplace la fenêtre ci-dessus si renseignée — ex: 01/01/2022 pour tout reprendre depuis la création de l'association.</div>
    </div>
    <label class="tu-form-check">
      <input type="checkbox" name="sync_dry_run" value="1" checked>
      Simulation (ne rien écrire en base)
    </label>
    <button type="submit" class="tu-btn tu-btn-p">Synchroniser maintenant</button>
  </form>

  <p style="margin-top:12px;margin-bottom:0;font-size:11px;color:var(--tu-ink-300);">Les dons HelloAsso apparaissent ci-dessous avec les mêmes règles que l'import CSV (non modifiables/supprimables ici). Nécessite le <code>.env</code> de la suite renseigné (HELLOASSO_CLIENT_ID / SECRET / ORG_SLUG).</p>
  </div><!-- /panel sync -->
  <?php endif; ?>

  <div class="tu-tab-panel" data-panel="manual" style="<?= $activeTab === 'manual' ? '' : 'display:none;' ?>">
  <p style="margin-top:0;margin-bottom:16px;font-size:12px;color:var(--tu-ink-300);">À utiliser uniquement pour les dons hors HelloAsso. Les reçus fiscaux ne pourront être générés que pour ces dons manuels.</p>

  <?php require __DIR__ . '/includes/manual_form.php'; ?>
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
      <label class="tu-form-check">
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
            $isCompanyRow = (($r['donor_type'] ?? 'individual') === 'company' && trim((string)($r['donor_company_name'] ?? '')) !== '');
            if ($isCompanyRow) { $contactRow = $name; $name = trim((string)$r['donor_company_name']); } else { $contactRow = ''; }
            $inKindRow = (($r['donation_kind'] ?? 'cash') !== 'cash');
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
              <div style="font-weight:700;font-size:13px;"><?= h($name ?: '—') ?><?php if ($isCompanyRow): ?> <span class="tu-bdg tu-bdg-teal">entreprise</span><?php endif; ?></div>
              <?php if ($contactRow !== ''): ?><div style="font-size:11px;color:var(--tu-ink-300);">Contact : <?= h($contactRow) ?></div><?php endif; ?>
              <?php if ($email): ?><div style="font-size:11px;color:var(--tu-ink-300);"><?= h($email) ?></div><?php endif; ?>
            </td>
            <td style="font-weight:700;font-size:13px;"><?= h(number_format((float)$r['amount'], 2, ',', ' ')) ?> €<?php if ($inKindRow): ?><div style="font-size:11px;font-weight:400;color:var(--tu-ink-300);">valeur du don</div><?php endif; ?></td>
            <td style="font-size:13px;"><?= $inKindRow ? '<span class="tu-bdg tu-bdg-amber">' . h(($r['donation_kind'] ?? '') === 'services' ? 'prestation' : 'en nature') . '</span>' : h($paymentLabel) ?></td>
            <td style="font-size:12px;color:var(--tu-ink-300);"><?= h($campaignLabel) ?></td>
            <td>
              <?php if (!empty($r['receipt_number'])): ?>
                <a class="tu-bdg tu-bdg-teal" target="_blank" href="<?= h($base) ?>/donations/receipt_view.php?id=<?= (int)$r['id'] ?>" style="text-decoration:none;"><?= h($r['receipt_number']) ?></a>
                <a target="_blank" href="<?= h($base) ?>/donations/receipt_pdf.php?id=<?= (int)$r['id'] ?>" style="margin-left:6px;font-size:11px;color:var(--tu-ink-300);">PDF</a>
              <?php elseif ((int)($r['receipt_eligible'] ?? 0) === 1): ?>
                <span class="tu-bdg tu-bdg-amber">éligible</span>
                <?php if ($isManual): ?><label title="Cocher plusieurs dons du même donateur pour un reçu groupé" style="margin-left:6px;"><input type="checkbox" name="group_ids[]" value="<?= (int)$r['id'] ?>" form="group_form"></label><?php endif; ?>
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
                  <?php if (!empty($r['receipt_number']) && filter_var($email, FILTER_VALIDATE_EMAIL)): ?>
                    <form method="post" action="<?= h($base) ?>/donations/receipt_send.php" style="display:inline;" data-confirm="Envoyer le reçu <?= h($r['receipt_number']) ?> par e-mail à <?= h($email) ?> ?">
                      <input type="hidden" name="donation_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="return" value="list">
                      <input type="hidden" name="year" value="<?= (int)$year ?>">
                      <button type="submit" class="tu-btn tu-btn-xs">Envoyer par email</button>
                    </form>
                  <?php endif; ?>
                  <?php if (!empty($r['proof_path'])): ?>
                    <a class="tu-btn tu-btn-xs" target="_blank" href="<?= h($base) ?>/donations/proof.php?id=<?= (int)$r['id'] ?>">Justificatif</a>
                  <?php endif; ?>
                  <form method="post" style="display:inline;" data-confirm="Supprimer ce don manuel ?">
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
    <form method="post" id="group_form" style="padding:12px 22px 18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;"
          data-confirm="Générer un seul reçu fiscal pour les dons cochés ? Ils ne pourront plus être supprimés ni modifiés séparément.">
      <input type="hidden" name="do_group_receipt" value="1">
      <button type="submit" class="tu-btn tu-btn-t tu-btn-xs">Générer un reçu groupé</button>
      <span style="font-size:11px;color:var(--tu-ink-300);">Cochez, dans la colonne « Reçu », plusieurs dons « éligible » du même donateur, de même nature et de la même année.</span>
    </form>
  <?php endif; ?>
</div>

  </div><!-- /tu-pg -->
</div><!-- /tu-main -->
<script src="<?= h($base) ?>/assets/js/tu_datepicker.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/js/tu_datepicker.js') ?>"></script>
</body>
</html>
