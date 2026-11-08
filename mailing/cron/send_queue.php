<?php
declare(strict_types=1);

// mailing/cron/send_queue.php
//
// Envoie par petits lots (MAILING_BATCH_SIZE messages au plus par passage) : d'abord les messages automatiques
// dus, puis les newsletters en attente (en lançant celles dont l'heure est venue, liste de destinataires figée).
//
// Usage (CLI uniquement) :
//   php send_queue.php              # un lot (MAILING_BATCH_SIZE messages)
//   php send_queue.php --batch=50   # taille de lot personnalisée
//
// Crontab conseillé (chaque minute ; le verrou évite tout chevauchement) :
//   SUITE_ENV_FILE=/chemin/vers/secrets/tools.env
//   * * * * * /usr/bin/php /chemin/vers/mailing/cron/send_queue.php >> /var/log/mailing_queue.log 2>&1

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script ne peut être exécuté qu'en ligne de commande.\n");
}

require_once dirname(__DIR__, 2) . '/shared/bootstrap.php';
require_once dirname(__DIR__) . '/includes/automations.php';

$batch = MAILING_BATCH_SIZE;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--batch=(\d{1,3})$/', $arg, $m) && (int)$m[1] > 0) $batch = (int)$m[1];
}

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "Connexion base de données indisponible.\n");
    exit(1);
}
if (!mailing_tables_ready($pdo)) exit(0); // migration pas encore exécutée

// Un seul exécutant à la fois.
if ((int)$pdo->query("SELECT GET_LOCK('mailing_send_queue', 0)")->fetchColumn() !== 1) exit(0);

try {
    // Messages automatiques d'abord (un rappel de la veille ne doit pas attendre la fin d'une grosse newsletter).
    $a = mailing_auto_tables_ready($pdo) ? mailing_auto_run($pdo, $batch) : ['sent' => 0, 'failed' => 0];
    if ($a['sent'] || $a['failed']) {
        echo date('c') . " automatiques: {$a['sent']} envoyé(s), {$a['failed']} en échec\n";
    }
    $left = $batch - $a['sent'] - $a['failed'];
    $r = $left > 0 ? mailing_send_batch($pdo, $left) : ['sent' => 0, 'failed' => 0];
    if ($r['sent'] || $r['failed']) {
        echo date('c') . " mailing: {$r['sent']} envoyé(s), {$r['failed']} en échec\n";
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('mailing_send_queue')");
}
