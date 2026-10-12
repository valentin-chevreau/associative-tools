<?php
/**
 * shared/login_alerts.php — Alertes e-mail à chaque connexion de certains utilisateurs.
 *
 * Réglage : admin/login_alerts.php (super admin uniquement). Tous les utilisateurs sont surveillés ;
 * la table suite_login_alert_exclusions (admin/migrations/003_suite_login_alerts_exclusions.sql)
 * ne liste que les exceptions. Transport : shared/mailer.php.
 *
 * Règles :
 *  - chaque connexion d'un utilisateur non exclu prévient les super admins ;
 *  - seuls les super admins actifs ayant une adresse e-mail reçoivent l'alerte ;
 *  - un super admin ne reçoit jamais l'alerte de sa propre connexion ;
 *  - l'envoi part APRÈS la réponse au navigateur : la connexion n'attend jamais le serveur SMTP ;
 *  - aucune exception : une panne d'e-mail ne doit pas empêcher de se connecter.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

if (!function_exists('suite_login_alert_excluded_ids')) {
    /** @return int[] identifiants des utilisateurs exclus de la surveillance (vide si la table n'existe pas encore) */
    function suite_login_alert_excluded_ids(?PDO $pdo = null): array {
        try {
            $pdo ??= suite_pdo();
            return array_map('intval', $pdo->query("SELECT user_id FROM suite_login_alert_exclusions")->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('suite_login_alert_recipients')) {
    /**
     * Super admins à prévenir, hors la personne qui vient de se connecter.
     * @return array<int,array{id:int,name:string,email:string}>
     */
    function suite_login_alert_recipients(PDO $pdo, int $excludeUserId = 0): array {
        $q = $pdo->prepare("
            SELECT id, first_name, last_name, email
            FROM users
            WHERE role = 'super_admin' AND is_active = 1 AND deleted_at IS NULL
              AND email IS NOT NULL AND email <> '' AND id <> ?
        ");
        $q->execute([$excludeUserId]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL)) continue;
            $out[] = ['id' => (int)$r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'email' => $r['email']];
        }
        return $out;
    }
}

if (!function_exists('suite_login_alert_after_login')) {
    /** À appeler une fois la connexion réussie (admin_login_with_code). */
    function suite_login_alert_after_login(int $userId, string $name, string $role): void {
        try {
            $pdo = suite_pdo();
            if (in_array($userId, suite_login_alert_excluded_ids($pdo), true)) return;
            $to = suite_login_alert_recipients($pdo, $userId);
            if (!$to || !suite_mail_ready()) return;

            $labels = ['super_admin' => 'Super admin', 'admin_plus' => 'Admin+', 'admin' => 'Admin'];
            $when = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('d/m/Y à H:i');
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 200);
            $site = (string)($_SERVER['HTTP_HOST'] ?? '') . (function_exists('suite_base') ? suite_base() : '');

            $roleLabel = $labels[$role] ?? $role;
            $subject = 'Connexion de ' . $name . ' — Touraine-Ukraine';
            $rows = [['Rôle', $roleLabel], ['Date', $when], ['Site', $site], ['Adresse IP', $ip !== '' ? $ip : 'inconnue']];
            if ($ua !== '') $rows[] = ['Navigateur', $ua];
            $note = 'Vous recevez ce message car vous êtes super administrateur. Les alertes se règlent dans Administration › Alertes de connexion.';

            $body = "$name vient de se connecter à la suite.\n\n";
            foreach ($rows as [$k, $v]) $body .= "$k : $v\n";
            $body .= "\n$note\n";

            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $auditUrl = ($https ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? '') . (function_exists('suite_base') ? suite_base() : '') . '/admin/audit_log.php';
            $html = suite_mail_html($name . ' vient de se connecter', 'Une connexion à la suite Touraine-Ukraine vient d\'être enregistrée.',
                $rows, ['Ouvrir le journal d\'audit', $auditUrl], $note, 'Alerte de connexion');

            $send = static function () use ($to, $subject, $body, $html, $userId): void {
                foreach ($to as $r) {
                    $err = suite_mail_send($r['email'], $subject, $body, $html);
                    if ($err !== null && function_exists('audit_log')) {
                        audit_log('auth', 'login_alert_failed', 'volunteer', $userId, null, ['destinataire' => $r['name'], 'erreur' => $err]);
                    }
                }
            };
            // Après la réponse : la page de connexion redirige aussitôt, sans attendre le serveur SMTP.
            register_shutdown_function(static function () use ($send): void {
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                $send();
            });
        } catch (Throwable $e) {
            error_log('suite_login_alert_after_login: ' . $e->getMessage());
        }
    }
}
