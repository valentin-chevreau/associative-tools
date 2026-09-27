<?php
declare(strict_types=1);

// donations/cron/sync_helloasso_donations.php
//
// Synchronise automatiquement les dons HelloAsso (formType=Donation) dans
// la table "donations", via l'API v5 (voir includes/helloasso_client.php).
// La logique de synchro elle-même (fetch + upsert) vit dans
// helloasso_run_sync() — partagée avec le bouton "Synchroniser maintenant"
// de l'interface (donations/index.php) pour n'avoir qu'un seul endroit à
// maintenir.
//
// Usage (CLI uniquement — ce script refuse d'être appelé depuis le web) :
//   php sync_helloasso_donations.php                  # fenêtre glissante par défaut (90 jours)
//   php sync_helloasso_donations.php --days=400        # backfill plus large (première synchro)
//   php sync_helloasso_donations.php --from=2022-01-01 # backfill depuis une date fixe (prime sur --days)
//   php sync_helloasso_donations.php --dry-run         # récupère et affiche, n'écrit rien en base
//
// Pas d'état "dernière date synchronisée" à maintenir : on re-synchronise à
// chaque fois une fenêtre glissante (90 jours par défaut) et on s'appuie sur
// l'UPSERT (ON DUPLICATE KEY sur source_ref) pour rester idempotent — ça
// capture aussi les changements de statut tardifs (remboursement...) sans
// logique de "curseur" fragile.
//
// Crontab conseillé (toutes les heures, décalé pour éviter les pics :00) :
//   17 * * * * /usr/bin/php /chemin/vers/donations/cron/sync_helloasso_donations.php >> /var/log/helloasso_sync.log 2>&1

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script ne peut être exécuté qu'en ligne de commande.\n");
}

require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/helloasso_client.php';

// Connexion DB : on réutilise _bootstrap_get_pdo() de shared/bootstrap.php,
// exactement comme les pages web du module — un seul endroit où les
// identifiants de connexion sont définis (caisse/config.php,
// logistique/config.php...), pas de duplication dans donations/.env.
//
// bootstrap.php a été corrigé pour détecter le CLI (PHP_SAPI === 'cli') et
// sauter dans ce cas la session HTTP et la protection globale par
// redirection (qui n'ont pas de sens hors requête web et coupaient
// auparavant ce script en plein milieu) — voir le haut de bootstrap.php.
require_once dirname(__DIR__, 2) . '/shared/bootstrap.php';

/** @param array<int,string> $argv */
function ha_cli_opt(array $argv, string $name, $default = null) {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) return substr($arg, strlen("--{$name}="));
        if ($arg === "--{$name}") return true;
    }
    return $default;
}

$days   = (int)ha_cli_opt($argv, 'days', 90);
$dryRun = (bool)ha_cli_opt($argv, 'dry-run', false);
$from   = ha_cli_opt($argv, 'from', null);
if ($from !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from)) {
    echo "[helloasso-sync] --from invalide (attendu AAAA-MM-JJ), ignoré.\n";
    $from = null;
}

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) {
    echo "[helloasso-sync] ÉCHEC : connexion base de données indisponible.\n";
    exit(1);
}

echo "[helloasso-sync] Lancement (dry-run: " . ($dryRun ? 'oui' : 'non') . ", "
   . ($from !== null ? "depuis: {$from}" : "fenêtre: {$days} jours") . ")\n";

$r = helloasso_run_sync($pdo, $days, $dryRun, $from);

if ($r['fatal_error'] !== null) {
    echo "[helloasso-sync] ÉCHEC : {$r['fatal_error']}\n";
    exit(1);
}

echo "[helloasso-sync] Fenêtre : {$r['from']} → {$r['to']}\n";
echo "[helloasso-sync] " . $r['payments_fetched'] . " paiement(s) reçu(s) de l'API.\n";
echo "[helloasso-sync] Terminé — insérés: {$r['inserted']}, mis à jour: {$r['updated']}, ignorés (hors don): {$r['skipped_not_donation']}, erreurs: {$r['errors']}\n";
if ($r['last_error'] !== null) {
    echo "[helloasso-sync] Dernière erreur : {$r['last_error']}\n";
}

exit($r['errors'] > 0 ? 1 : 0);