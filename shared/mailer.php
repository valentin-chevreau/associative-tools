<?php
/**
 * shared/mailer.php — Envoi d'e-mails (texte + HTML + pièces jointes), sans dépendance externe.
 *
 * Transport : fonction mail() de PHP, donc le serveur de messagerie local (Postfix) ; aucun SMTP à configurer.
 *
 * Expéditeurs (tous facultatifs, dans le .env de la suite). Un « canal » désigne une famille de messages :
 *   planning (évènements), dons (reçus fiscaux), alertes (alertes de connexion), mailing (newsletters).
 *   MAIL_FROM / MAIL_FROM_NAME / MAIL_REPLY_TO                       expéditeur par défaut
 *                                                                    (défaut : noreply@<domaine du site>)
 *   MAIL_FROM_<CANAL> / MAIL_FROM_NAME_<CANAL> / MAIL_REPLY_TO_<CANAL>   expéditeur d'un canal (repli : le défaut)
 *   Exemple : MAIL_FROM_DONS=tresorerie@asso.fr  MAIL_REPLY_TO_PLANNING=planning@asso.fr
 *
 * suite_mail_send() ne lève jamais d'exception : elle renvoie null en cas de succès, sinon
 * le message d'erreur (déjà écrit dans error_log) — un souci d'e-mail ne doit jamais
 * empêcher l'action métier qui l'a déclenché.
 */

require_once __DIR__ . '/db_credentials.php';

if (!function_exists('suite_mail_channels')) {
    /** @return array<string,string> canal => libellé */
    function suite_mail_channels(): array {
        return ['planning' => 'Planning (évènements)', 'dons' => 'Dons (reçus fiscaux)', 'alertes' => 'Alertes de connexion', 'mailing' => 'Mailing (newsletters)'];
    }
}

if (!function_exists('suite_mail_config')) {
    /**
     * Expéditeur d'un canal (repli sur l'expéditeur par défaut).
     * @return array{from:string,from_name:string,reply_to:string}
     */
    function suite_mail_config(string $channel = ''): array {
        static $cache = [];
        $channel = strtolower(trim($channel));
        if (isset($cache[$channel])) return $cache[$channel];

        $file = suite_load_env_file(suite_env_path());
        $get = static function (string $k) use ($file): string {
            $v = getenv($k);
            if ($v === false || $v === '') $v = $file[$k] ?? '';
            return trim((string)$v);
        };
        $pick = static function (string $key) use ($get, $channel): string {
            $suffix = $channel !== '' && preg_match('/^[a-z]+$/', $channel) ? '_' . strtoupper($channel) : '';
            $v = $suffix !== '' ? $get($key . $suffix) : '';
            return $v !== '' ? $v : $get($key);
        };

        $from = $pick('MAIL_FROM');
        if ($from === '') {
            $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
            $host = preg_replace('/^www\./', '', $host);
            $from = preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', (string)$host) ? 'noreply@' . $host : '';
        }
        $name = $pick('MAIL_FROM_NAME');
        return $cache[$channel] = [
            'from'      => $from,
            'from_name' => $name !== '' ? $name : 'Touraine-Ukraine',
            'reply_to'  => $pick('MAIL_REPLY_TO'),
        ];
    }
}

if (!function_exists('suite_mail_ready')) {
    /** true si une adresse d'expédition est déterminée (sans cela, aucun envoi n'est tenté). */
    function suite_mail_ready(string $channel = ''): bool {
        return suite_mail_config($channel)['from'] !== '';
    }
}

if (!function_exists('suite_mail_header_safe')) {
    function suite_mail_header_safe(string $s): string {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
    }
}

if (!function_exists('suite_mail_encode_header')) {
    function suite_mail_encode_header(string $s): string {
        $s = suite_mail_header_safe($s);
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }
}

if (!function_exists('suite_mail_send')) {
    /** @return ?string null = envoyé ; sinon message d'erreur */
    function suite_mail_send(string $to, string $subject, string $body, ?string $html = null, array $attachments = [], string $channel = '', array $extraHeaders = []): ?string {
        $cfg = suite_mail_config($channel);
        $to = suite_mail_header_safe($to);
        if ($cfg['from'] === '') return "Adresse d'expédition indéterminée : définissez MAIL_FROM dans le .env de la suite.";
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return "Adresse destinataire invalide.";
        if (!filter_var($cfg['from'], FILTER_VALIDATE_EMAIL)) return "MAIL_FROM n'est pas une adresse valide.";

        $from    = suite_mail_header_safe($cfg['from']);
        $fromHdr = suite_mail_encode_header($cfg['from_name']) . ' <' . $from . '>';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromHdr,
            'To: ' . $to,
            'Subject: ' . suite_mail_encode_header($subject),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . (substr(strrchr($from, '@') ?: '@localhost', 1)) . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
        ];
        // En-têtes supplémentaires (ex. List-Unsubscribe d'une newsletter), nettoyés contre l'injection.
        foreach ($extraHeaders as $name => $value) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', (string)$name)) continue;
            $headers[] = $name . ': ' . suite_mail_header_safe((string)$value);
        }
        if ($cfg['reply_to'] !== '' && filter_var($cfg['reply_to'], FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . suite_mail_header_safe($cfg['reply_to']);
        }
        $b64 = static fn(string $t): string => chunk_split(base64_encode($t), 76, "\r\n");
        if ($html === null) {
            $inner = ["Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($body)];
        } else {
            // multipart/alternative : version texte (repli) puis version HTML (préférée par les messageries)
            $alt = 'tu_' . bin2hex(random_bytes(12));
            $inner = ["Content-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n"
                    . "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($body)
                    . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html)
                    . "--$alt--\r\n"];
        }
        if (!$attachments) {
            [$ctype, $rest] = explode("\r\n\r\n", $inner[0], 2);
            $headers[] = $ctype;
            $payload = $rest;
        } else {
            // multipart/mixed : corps puis pièces jointes ['filename'=>..., 'content'=>binaire, 'type'=>mime]
            $mx = 'tu_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mx . '"';
            $payload = "--$mx\r\n" . $inner[0];
            foreach ($attachments as $a) {
                $fn = str_replace(['"', "\r", "\n", '\\'], '_', (string)($a['filename'] ?? 'piece-jointe'));
                $payload .= "--$mx\r\nContent-Type: " . ($a['type'] ?? 'application/octet-stream') . "; name=\"$fn\"\r\n"
                          . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fn\"\r\n\r\n"
                          . $b64((string)$a['content']);
            }
            $payload .= "--$mx--\r\n";
        }

        try {
            $err = suite_mail_via_php($to, $subject, $headers, $payload, $from);
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
        if ($err !== null) error_log('suite_mail_send: ' . $err);
        return $err;
    }
}

if (!function_exists('suite_mail_abs_url')) {
    /** URL absolue d'une page de la suite (liens des e-mails), ex. suite_mail_abs_url('/planning/events.php'). */
    function suite_mail_abs_url(string $path): string {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $base  = function_exists('suite_base') ? suite_base() : '';
        return ($https ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? '') . $base . $path;
    }
}

if (!function_exists('suite_mail_html')) {
    /**
     * Gabarit HTML commun des e-mails de la suite (mise en page en tableaux, styles en ligne :
     * seule méthode fiable dans Gmail / Outlook).
     * @param array<int,array{0:string,1:string}> $rows  lignes « libellé » / « valeur »
     * @param array{0:string,1:string}|null $button       [texte, url]
     */
    function suite_mail_html(string $title, string $lead, array $rows = [], ?array $button = null, string $footer = '', string $badge = '', string $preheader = ''): string {
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $rowsHtml = '';
        foreach ($rows as $i => [$k, $v]) {
            $bt = $i === 0 ? '' : 'border-top:1px solid #e8ddd0;';
            $rowsHtml .= '<tr><td style="' . $bt . 'padding:10px 0;width:112px;font-size:13px;color:#a89880;vertical-align:top;">' . $e($k)
                       . '</td><td style="' . $bt . 'padding:10px 0;font-size:14px;color:#1a1510;font-weight:600;word-break:break-word;">' . $e($v) . '</td></tr>';
        }
        $btn = $button ? '<p style="margin:26px 0 4px;"><a href="' . $e($button[1]) . '" style="display:inline-block;background:#d4732a;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 22px;border-radius:10px;">' . $e($button[0]) . '</a></p>' : '';
        $bdg = $badge !== '' ? '<span style="display:inline-block;background:#fdf0e3;color:#b85e1e;font-size:12px;font-weight:700;padding:4px 10px;border-radius:20px;margin-bottom:12px;">' . $e($badge) . '</span><br>' : '';
        $foot = $footer !== '' ? '<p style="margin:0;font-size:12px;line-height:1.5;color:#a89880;">' . $e($footer) . '</p>' : '';
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f5f0e8;">'
            . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $e($preheader) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f0e8;"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;">'
            . '<tr><td style="padding:0 4px 14px;font-size:15px;font-weight:800;color:#3d3428;letter-spacing:.2px;"><span style="color:#d4732a;">&#9679;</span>&nbsp; Touraine-Ukraine</td></tr>'
            . '<tr><td style="background:#ffffff;border-radius:16px;border:1px solid #e8ddd0;border-top:4px solid #d4732a;padding:28px 28px 24px;">'
            . $bdg
            . '<h1 style="margin:0 0 8px;font-size:20px;line-height:1.3;color:#1a1510;">' . $e($title) . '</h1>'
            . '<p style="margin:0 0 20px;font-size:14px;line-height:1.55;color:#6b5c47;">' . $e($lead) . '</p>'
            . ($rowsHtml !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e8ddd0;border-bottom:1px solid #e8ddd0;">' . $rowsHtml . '</table>' : '')
            . $btn
            . '</td></tr>'
            . '<tr><td style="padding:16px 8px 0;">' . $foot . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}

if (!function_exists('suite_mail_via_php')) {
    function suite_mail_via_php(string $to, string $subject, array $headers, string $payload, string $from): ?string {
        $hdr = array_values(array_filter($headers, static fn($h) => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:')));
        $ok = @mail($to, suite_mail_encode_header($subject), $payload, implode("\r\n", $hdr), '-f' . escapeshellarg($from));
        return $ok ? null : "mail() a échoué (le serveur de messagerie local n'a pas accepté le message).";
    }
}
