<?php
declare(strict_types=1);

/**
 * mailing/includes/mailing.php — Cœur du module Mailing (newsletters).
 *
 * - Les listes de diffusion ne sont jamais ressaisies : elles sont calculées à la demande à partir des
 *   données existantes (planning, adhésions, dons, prospection).
 * - Une campagne lancée fige sa liste de destinataires (mailing_recipients), puis le cron
 *   (mailing/cron/send_queue.php) les envoie par petits lots.
 * - Chaque message porte un lien de désinscription personnel ; les désinscrits sont exclus de tout envoi.
 */

require_once dirname(__DIR__, 2) . '/shared/mailer.php';
require_once __DIR__ . '/blocks.php';

/** Nombre de messages envoyés à chaque passage du cron (1 passage par minute conseillé). */
const MAILING_BATCH_SIZE = 20;
/** Nombre maximal de tentatives d'envoi par destinataire. */
const MAILING_MAX_ATTEMPTS = 3;

/** @return array<string,array{label:string,desc:string,source:string}> */
function mailing_audiences(): array {
    return [
        'benevoles' => ['label' => 'Bénévoles du planning', 'desc' => 'Utilisateurs actifs du planning ayant une adresse e-mail.', 'source' => 'Planning'],
        'adherents' => ['label' => 'Adhérents à jour',      'desc' => 'Adhérents dont la cotisation de l\'année en cours est validée.', 'source' => 'Adhésions'],
        'donateurs' => ['label' => 'Donateurs',             'desc' => 'Personnes ayant fait un don payé au cours des 24 derniers mois.', 'source' => 'Dons'],
        'contacts'  => ['label' => 'Contacts externes',     'desc' => 'Fiches actives de Prospection ayant une adresse e-mail.', 'source' => 'Prospection'],
        'manuel'    => ['label' => 'Ajouts manuels',        'desc' => 'Personnes saisies à la main, qui ne figurent dans aucune autre liste.', 'source' => 'Saisie ici'],
    ];
}

/**
 * Membres de départ d'une liste, issus des données existantes, sans les ajouts ni retraits manuels.
 * @return list<array{0:string,1:string}> couples [adresse brute, nom] — non filtrés, non dédoublonnés
 */
function mailing_source_rows(PDO $pdo, string $key): array {
    switch ($key) {
        case 'benevoles':
            $sql = "SELECT email, TRIM(CONCAT(first_name, ' ', last_name)) AS name FROM users
                    WHERE is_active = 1 AND deleted_at IS NULL AND email IS NOT NULL AND email <> ''";
            $st = $pdo->query($sql);
            break;
        case 'adherents':
            $st = $pdo->prepare("SELECT a.email, TRIM(CONCAT(a.prenom, ' ', a.nom)) AS name FROM adherents a
                    JOIN adhesions ad ON ad.adherent_id = a.id AND ad.annee = ? AND ad.statut = 'valide'
                    WHERE a.actif = 1 AND a.email IS NOT NULL AND a.email <> ''");
            $st->execute([(int)date('Y')]);
            break;
        case 'donateurs':
            $st = $pdo->query("SELECT donor_email AS email, TRIM(CONCAT(COALESCE(donor_first_name, ''), ' ', COALESCE(donor_last_name, ''))) AS name FROM donations
                    WHERE status = 'paid' AND donation_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                      AND donor_email IS NOT NULL AND donor_email <> ''");
            break;
        case 'contacts':
            $st = $pdo->query("SELECT email, nom AS name FROM prospection_contacts
                    WHERE actif = 1 AND email IS NOT NULL AND email <> ''");
            break;
        default:
            return [];
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Un champ e-mail peut contenir plusieurs adresses (« a@x.fr ; b@y.fr »).
        foreach (preg_split('/[;,\s]+/', (string)$r['email'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $addr) {
            $out[] = [$addr, (string)$r['name']];
        }
    }
    return $out;
}

/**
 * Membres d'une liste : données existantes + ajouts manuels, hors personnes retirées de cette liste.
 * @return list<array{0:string,1:string,2:string}> triplets [adresse brute, nom, origine : 'source'|'manuel']
 */
function mailing_audience_rows(PDO $pdo, string $key): array {
    $excluded = [];
    $st = $pdo->prepare("SELECT email FROM mailing_exclusions WHERE list_key = ?");
    $st->execute([$key]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) $excluded[(string)$e] = true;

    $out = [];
    foreach (mailing_source_rows($pdo, $key) as [$addr, $name]) {
        if (isset($excluded[mb_strtolower(trim($addr))])) continue;
        $out[] = [$addr, $name, 'source'];
    }
    $st = $pdo->prepare("SELECT email, name FROM mailing_manual_contacts WHERE list_key = ?");
    $st->execute([$key]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($excluded[(string)$r['email']])) continue;
        $out[] = [(string)$r['email'], (string)$r['name'], 'manuel'];
    }
    return $out;
}

/** @return array<string,true> adresses (minuscules) désinscrites */
function mailing_suppressed(PDO $pdo): array {
    $set = [];
    foreach ($pdo->query("SELECT email FROM mailing_suppressions")->fetchAll(PDO::FETCH_COLUMN) as $e) $set[(string)$e] = true;
    return $set;
}

/**
 * Calcule les destinataires d'un ensemble de listes.
 * @param list<string> $keys
 * @param array<string,true> $skip adresses (minuscules) à ignorer : exclues de cette newsletter seulement
 * @return array{recipients:array<string,string>,per:array<string,int>,invalid:int,duplicates:int,suppressed:int}
 */
function mailing_resolve(PDO $pdo, array $keys, array $skip = []): array {
    $audiences = mailing_audiences();
    $suppressed = mailing_suppressed($pdo);
    $recipients = [];
    $per = [];
    $invalid = $duplicates = $suppressedN = 0;
    $seenSuppressed = [];

    foreach ($keys as $key) {
        if (!isset($audiences[$key])) continue;
        $per[$key] = 0;
        $inList = [];
        foreach (mailing_audience_rows($pdo, $key) as [$addr, $name]) {
            $addr = mb_strtolower(trim($addr));
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || strlen($addr) > 190) { $invalid++; continue; }
            if (isset($skip[$addr])) continue;
            if (isset($suppressed[$addr])) {
                if (!isset($seenSuppressed[$addr])) { $seenSuppressed[$addr] = true; $suppressedN++; }
                continue;
            }
            if (isset($inList[$addr])) continue;
            $inList[$addr] = true;
            $per[$key]++;
            if (isset($recipients[$addr])) { $duplicates++; continue; }
            $recipients[$addr] = $name;
        }
    }
    return ['recipients' => $recipients, 'per' => $per, 'invalid' => $invalid, 'duplicates' => $duplicates, 'suppressed' => $suppressedN];
}

/** @return array<string,true> adresses décochées pour cette newsletter */
function mailing_campaign_exclusions(PDO $pdo, int $campaignId): array {
    $st = $pdo->prepare("SELECT email FROM mailing_campaign_exclusions WHERE campaign_id = ?");
    $st->execute([$campaignId]);
    $set = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) $set[(string)$e] = true;
    return $set;
}

/** Remplace les exclusions d'une newsletter (adresses invalides ignorées). */
function mailing_save_campaign_exclusions(PDO $pdo, int $campaignId, array $emails): void {
    $pdo->prepare("DELETE FROM mailing_campaign_exclusions WHERE campaign_id = ?")->execute([$campaignId]);
    $ins = $pdo->prepare("INSERT IGNORE INTO mailing_campaign_exclusions (campaign_id, email) VALUES (?, ?)");
    foreach (array_slice($emails, 0, 5000) as $e) {
        $e = mb_strtolower(trim((string)$e));
        if (filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 190) $ins->execute([$campaignId, $e]);
    }
}

/**
 * Annuaire des destinataires possibles : une ligne par adresse, avec ses listes d'origine.
 * @return array<string,array{name:string,lists:list<string>}>
 */
function mailing_directory(PDO $pdo): array {
    $dir = [];
    foreach (array_keys(mailing_audiences()) as $key) {
        foreach (mailing_resolve($pdo, [$key])['recipients'] as $email => $name) {
            $dir[$email]['name'] = ($dir[$email]['name'] ?? '') !== '' ? $dir[$email]['name'] : $name;
            $dir[$email]['lists'][] = $key;
        }
    }
    return $dir;
}

/** @return list<string> clés de listes valides extraites de la valeur stockée « a,b,c » */
function mailing_parse_audiences(string $stored): array {
    $valid = array_keys(mailing_audiences());
    return array_values(array_intersect($valid, array_filter(explode(',', $stored))));
}

function mailing_status_label(string $s): array {
    return [
        'draft'     => ['Brouillon', 'sand'],
        'queued'    => ['Planifié', 'blue'],
        'sending'   => ['En cours', 'amber'],
        'sent'      => ['Envoyé', 'green'],
        'cancelled' => ['Annulé', 'red'],
    ][$s] ?? [$s, 'sand'];
}

/* ---------- Contenu du message ---------- */

function mailing_unsubscribe_url(string $siteUrl, string $token): string {
    return rtrim($siteUrl, '/') . '/mailing/unsubscribe.php?t=' . $token;
}

/* ---------- Envoi ---------- */

/** Fige la liste des destinataires d'une campagne due et la passe « en cours ». Renvoie leur nombre. */
function mailing_start(PDO $pdo, int $campaignId): int {
    $st = $pdo->prepare("SELECT audiences FROM mailing_campaigns WHERE id = ? AND status = 'queued'");
    $st->execute([$campaignId]);
    $stored = $st->fetchColumn();
    if ($stored === false) return 0;

    $res = mailing_resolve($pdo, mailing_parse_audiences((string)$stored), mailing_campaign_exclusions($pdo, $campaignId));
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO mailing_recipients (campaign_id, email, name, token) VALUES (?, ?, ?, ?)");
        foreach ($res['recipients'] as $email => $name) {
            $ins->execute([$campaignId, $email, mb_substr($name, 0, 190), bin2hex(random_bytes(16))]);
        }
        $n = count($res['recipients']);
        $pdo->prepare("UPDATE mailing_campaigns SET status = 'sending', recipients_total = ?, started_at = NOW() WHERE id = ?")->execute([$n, $campaignId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $n;
}

/**
 * Envoie au plus $limit messages en attente. Clôt les campagnes terminées.
 * @return array{sent:int,failed:int}
 */
function mailing_send_batch(PDO $pdo, int $limit = MAILING_BATCH_SIZE): array {
    $due = $pdo->query("SELECT id FROM mailing_campaigns WHERE status = 'queued' AND (scheduled_at IS NULL OR scheduled_at <= NOW()) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($due as $id) mailing_start($pdo, (int)$id);

    $sent = $failed = 0;
    $st = $pdo->prepare("SELECT r.id, r.email, r.token, r.attempts, c.subject, c.preheader, c.blocks, c.site_url
        FROM mailing_recipients r JOIN mailing_campaigns c ON c.id = r.campaign_id
        WHERE r.status = 'pending' AND c.status = 'sending'
        ORDER BY r.id LIMIT " . max(1, $limit));
    $st->execute();
    $suppressed = mailing_suppressed($pdo);
    $ok  = $pdo->prepare("UPDATE mailing_recipients SET status = 'sent', attempts = attempts + 1, sent_at = NOW(), error = NULL WHERE id = ?");
    $ko  = $pdo->prepare("UPDATE mailing_recipients SET status = IF(attempts + 1 >= ?, 'failed', 'pending'), attempts = attempts + 1, error = ? WHERE id = ?");

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Désinscrit depuis le lancement de la campagne : on n'envoie plus rien.
        if (isset($suppressed[$r['email']])) {
            $ko->execute([1, 'Désinscrit', $r['id']]);
            $failed++;
            continue;
        }
        $url = mailing_unsubscribe_url((string)$r['site_url'], (string)$r['token']);
        $err = suite_mail_send(
            (string)$r['email'], (string)$r['subject'],
            mailing_render_text($r, $url), mailing_render_html($r, $url, (string)$r['site_url']), [], 'mailing',
            ['List-Unsubscribe' => '<' . $url . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click', 'Precedence' => 'bulk']
        );
        if ($err === null) {
            $ok->execute([$r['id']]);
            $sent++;
        } else {
            $ko->execute([MAILING_MAX_ATTEMPTS, mb_substr($err, 0, 255), $r['id']]);
            $failed++;
        }
    }

    $pdo->exec("UPDATE mailing_campaigns c SET status = 'sent', finished_at = NOW()
        WHERE c.status = 'sending' AND NOT EXISTS (SELECT 1 FROM mailing_recipients r WHERE r.campaign_id = c.id AND r.status = 'pending')");
    return ['sent' => $sent, 'failed' => $failed];
}

/** Désinscrit l'adresse liée à un jeton. Renvoie l'adresse, ou null si le jeton est inconnu. */
function mailing_unsubscribe(PDO $pdo, string $token): ?string {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $st = $pdo->prepare("SELECT email, campaign_id FROM mailing_recipients WHERE token = ?");
    $st->execute([$token]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $pdo->prepare("INSERT IGNORE INTO mailing_suppressions (email, campaign_id) VALUES (?, ?)")->execute([$r['email'], $r['campaign_id']]);
    return (string)$r['email'];
}

/* ---------- Aides pour les pages ---------- */

function mailing_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function mailing_pdo(): PDO {
    $pdo = _bootstrap_get_pdo();
    if (!($pdo instanceof PDO)) {
        http_response_code(500);
        echo "Connexion base de données indisponible.";
        exit;
    }
    return $pdo;
}

/** Vrai si les tables du module existent (migration exécutée). */
function mailing_tables_ready(PDO $pdo): bool {
    try {
        $pdo->query("SELECT 1 FROM mailing_campaigns LIMIT 1");
        $pdo->query("SELECT 1 FROM mailing_suppressions LIMIT 1");
        $pdo->query("SELECT 1 FROM mailing_manual_contacts LIMIT 1");
        $pdo->query("SELECT 1 FROM mailing_exclusions LIMIT 1");
        return true;
    } catch (Throwable) {
        return false;
    }
}

function mailing_csrf(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['mailing_csrf'])) $_SESSION['mailing_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['mailing_csrf'];
}

function mailing_csrf_ok(): bool {
    return hash_equals(mailing_csrf(), (string)($_POST['csrf'] ?? ''));
}

function mailing_flash(?string $msg = null, string $type = 'ok'): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if ($msg !== null) { $_SESSION['mailing_flash'] = [$type, $msg]; return null; }
    $f = $_SESSION['mailing_flash'] ?? null;
    unset($_SESSION['mailing_flash']);
    return $f;
}

function mailing_redirect(string $page): never {
    header('Location: ' . suite_base() . '/mailing/' . $page);
    exit;
}

/** Début de page commun : en-tête HTML, barre latérale, fil d'Ariane. */
function mailing_page_start(string $title, string $activeItem, string $crumb, string $actions = ''): void {
    $h = 'mailing_h';
    ?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title><?= $h($title) ?> — Touraine-Ukraine</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <link rel="stylesheet" href="<?= $h(suite_base()) ?>/assets/css/suite_nav.css<?= suite_css_v() ?>"><?= function_exists('suite_pwa_head') ? suite_pwa_head() : '' ?>
  <style>
    body.tu-v2 { display: block; }
    body.tu-v2 .tu-main { margin-left: var(--tu-sw); padding: 24px; }
    @media (max-width: 900px) { body.tu-v2 .tu-main { margin-left: 0; padding: 16px; padding-top: 70px; } }
    .ml-card-h { padding: 14px 18px; border-bottom: 1px solid var(--tu-ink-100); }
    .ml-card-h b { font-family: var(--tu-font-d); font-size: 14px; }
    .ml-card-h p { margin: 2px 0 0; font-size: 12px; color: var(--tu-ink-300); }
    .ml-card-b { padding: 16px 18px; }
    .ml-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
    .ml-pill { display: inline-block; font-size: 11.5px; font-weight: 700; padding: 3px 9px; border-radius: 20px; background: var(--tu-sand-100, #f5ead8); color: var(--tu-ink-500); }
    .ml-pill-green { background: var(--tu-green-soft); color: var(--tu-green-main); }
    .ml-pill-red { background: var(--tu-red-soft); color: var(--tu-red-main); }
    .ml-pill-amber, .ml-pill-blue { background: #fdf0e3; color: #b85e1e; }
    .ml-pill-blue { background: #e4ecf5; color: #2a5fa0; }
    .ml-bar { height: 8px; border-radius: 5px; background: var(--tu-ink-100); overflow: hidden; }
    .ml-bar > i { display: block; height: 100%; background: var(--tu-amber-500); }
    .ml-muted { color: var(--tu-ink-300); font-size: 12px; }
    .ml-lbl { display: block; font-size: 12px; font-weight: 700; color: var(--tu-ink-500); margin: 0 0 6px; }
    .ml-field { margin-bottom: 16px; }
    .ml-field .tu-input { width: 100%; box-sizing: border-box; }
  </style>
</head>
<body class="tu-v2">
<?php
    require_once dirname(__DIR__, 2) . '/shared/suite_nav.php';
    suite_nav_render('mailing', $activeItem);
    $flash = mailing_flash();
?>
<div class="tu-main">
  <div class="tu-topbar">
    <div class="tu-bc">
      <a href="<?= $h(suite_base()) ?>/index.php" style="color:inherit;text-decoration:none;">Accueil</a>
      <span class="tu-bc-sep">›</span>
      <a href="<?= $h(suite_base()) ?>/mailing/index.php" style="color:inherit;text-decoration:none;">Mailing</a>
      <span class="tu-bc-sep">›</span>
      <span class="tu-bc-cur"><?= $h($crumb) ?></span>
    </div>
    <?php if ($actions !== ''): ?><div class="tu-topbar-acts"><?= $actions ?></div><?php endif; ?>
  </div>
  <div class="tu-pg">
  <?php if ($flash): ?>
    <?php [$ft, $fm] = $flash; ?>
    <div style="background:<?= $ft === 'ok' ? 'var(--tu-green-soft)' : 'var(--tu-red-soft)' ?>;border-radius:12px;padding:12px 16px;margin-bottom:16px;color:<?= $ft === 'ok' ? 'var(--tu-green-main)' : 'var(--tu-red-main)' ?>;font-size:13px;"><?= $h($fm) ?></div>
  <?php endif; ?>
<?php
}

function mailing_page_end(): void {
    ?>
  </div>
</div><!-- /tu-main -->
</body>
</html>
<?php
}

/** Bloc « migration à exécuter » quand les tables n'existent pas encore. */
function mailing_migration_notice(): void {
    ?>
    <div class="tu-card" style="padding:20px;">
      <div style="font-weight:800;margin-bottom:6px;">Migration SQL à exécuter</div>
      <div style="font-size:13px;color:var(--tu-ink-400);">
        Les tables du module Mailing n'existent pas encore. Exécutez la migration
        <code>mailing / 001_mailing.sql</code> depuis
        <a href="<?= mailing_h(suite_base()) ?>/admin/migrations.php" style="color:var(--tu-amber-600);font-weight:700;">Migrations SQL</a>.
      </div>
    </div>
    <?php
}

/* ---------- Gestion des listes (pages admin) ---------- */

/**
 * Membres d'une liste, dédoublonnés, avec leur choix de newsletter.
 * @return list<array{email:string,name:string,origin:string,suppressed:bool}>
 */
function mailing_members(PDO $pdo, string $key): array {
    $suppressed = mailing_suppressed($pdo);
    $seen = [];
    foreach (mailing_audience_rows($pdo, $key) as [$addr, $name, $origin]) {
        $addr = mb_strtolower(trim($addr));
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || strlen($addr) > 190) continue;
        // Un ajout manuel prime sur la source : on le garde pour pouvoir le supprimer.
        if (isset($seen[$addr]) && $origin !== 'manuel') continue;
        $seen[$addr] = ['email' => $addr, 'name' => trim($name), 'origin' => $origin, 'suppressed' => isset($suppressed[$addr])];
    }
    $members = array_values($seen);
    usort($members, static fn(array $a, array $b): int => strcasecmp($a['name'] !== '' ? $a['name'] : $a['email'], $b['name'] !== '' ? $b['name'] : $b['email']));
    return $members;
}

/** @return list<array{email:string,name:string}> personnes retirées de la liste (encore présentes dans les données existantes ou non) */
function mailing_excluded_members(PDO $pdo, string $key): array {
    $st = $pdo->prepare("SELECT email FROM mailing_exclusions WHERE list_key = ? ORDER BY email");
    $st->execute([$key]);
    $names = [];
    foreach (mailing_source_rows($pdo, $key) as [$addr, $name]) $names[mb_strtolower(trim($addr))] = trim($name);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) $out[] = ['email' => (string)$e, 'name' => $names[(string)$e] ?? ''];
    return $out;
}

/** Retire une personne d'une liste (ajout manuel supprimé, ou exclusion d'une personne issue des données existantes). */
function mailing_remove_from_list(PDO $pdo, string $key, string $email, ?int $by): void {
    $pdo->prepare("DELETE FROM mailing_manual_contacts WHERE list_key = ? AND email = ?")->execute([$key, $email]);
    $pdo->prepare("INSERT IGNORE INTO mailing_exclusions (list_key, email, created_by) VALUES (?, ?, ?)")->execute([$key, $email, $by]);
}

/** Ajoute une personne à une liste. Renvoie un message d'erreur ou null. */
function mailing_add_to_list(PDO $pdo, string $key, string $email, string $name, ?int $by): ?string {
    $email = mb_strtolower(trim($email));
    if (!isset(mailing_audiences()[$key])) return "Liste inconnue.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return "Adresse e-mail invalide.";
    $pdo->prepare("DELETE FROM mailing_exclusions WHERE list_key = ? AND email = ?")->execute([$key, $email]);
    $pdo->prepare("INSERT INTO mailing_manual_contacts (list_key, email, name, created_by) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name)")->execute([$key, $email, mb_substr(trim($name), 0, 190), $by]);
    return null;
}

/** Enregistre le refus de newsletter d'une personne, à sa demande (le lien de l'e-mail fait la même chose lui-même). */
function mailing_suppress(PDO $pdo, string $email): void {
    $pdo->prepare("INSERT IGNORE INTO mailing_suppressions (email, source) VALUES (?, 'admin')")->execute([mb_strtolower(trim($email))]);
}

function mailing_unsuppress(PDO $pdo, string $email): void {
    $pdo->prepare("DELETE FROM mailing_suppressions WHERE email = ?")->execute([mb_strtolower(trim($email))]);
}
