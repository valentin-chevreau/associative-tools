<?php
declare(strict_types=1);

// mailing/cron/send_queue.php
//
// Envoie les newsletters en attente par petits lots : lance les campagnes dont l'heure est venue
// (en figeant leur liste de destinataires), puis envoie au plus MAILING_BATCH_SIZE messages.
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
require_once dirname(__DIR__) . '/includes/mailing.php';

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
    $r = mailing_send_batch($pdo, $batch);
    if ($r['sent'] || $r['failed']) {
        echo date('c') . " mailing: {$r['sent']} envoyé(s), {$r['failed']} en échec\n";
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('mailing_send_queue')");
}
