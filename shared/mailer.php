<?php
/**
 * shared/mailer.php — Envoi d'e-mails texte, sans dépendance externe.
 *
 * Configuration dans le .env de la suite (voir shared/db_credentials.php) :
 *   MAIL_HOST         serveur SMTP. Vide = fonction mail() de PHP (MTA local du serveur).
 *   MAIL_PORT         465 (ssl), 587 (tls) ou 25. Défaut : 465 si ssl, 587 si tls.
 *   MAIL_ENCRYPTION   ssl | tls | none   (défaut : tls)
 *   MAIL_USER / MAIL_PASS   authentification SMTP (facultative)
 *   MAIL_FROM         adresse d'expédition (obligatoire)
 *   MAIL_FROM_NAME    nom affiché (défaut : « Touraine-Ukraine »)
 *
 * suite_mail_send() ne lève jamais d'exception : elle renvoie null en cas de succès, sinon
 * le message d'erreur (déjà écrit dans error_log) — un souci d'e-mail ne doit jamais
 * empêcher l'action métier qui l'a déclenché.
 */

require_once __DIR__ . '/db_credentials.php';

if (!function_exists('suite_mail_config')) {
    /** @return array{host:string,port:int,enc:string,user:string,pass:string,from:string,from_name:string} */
    function suite_mail_config(): array {
        static $cfg = null;
        if ($cfg !== null) return $cfg;
        $file = suite_load_env_file(suite_env_path());
        $get = static function (string $k, string $d = '') use ($file): string {
            $v = getenv($k);
            if ($v === false || $v === '') $v = $file[$k] ?? '';
            return $v === '' ? $d : (string)$v;
        };
        $enc = strtolower($get('MAIL_ENCRYPTION', 'tls'));
        if (!in_array($enc, ['ssl', 'tls', 'none'], true)) $enc = 'tls';
        $cfg = [
            'host'      => $get('MAIL_HOST'),
            'port'      => (int)$get('MAIL_PORT', $enc === 'ssl' ? '465' : ($enc === 'tls' ? '587' : '25')),
            'enc'       => $enc,
            'user'      => $get('MAIL_USER'),
            'pass'      => $get('MAIL_PASS'),
            'from'      => $get('MAIL_FROM'),
            'from_name' => $get('MAIL_FROM_NAME', 'Touraine-Ukraine'),
        ];
        return $cfg;
    }
}

if (!function_exists('suite_mail_ready')) {
    /** true si l'expéditeur est configuré (sans cela, aucun envoi n'est tenté). */
    function suite_mail_ready(): bool {
        return suite_mail_config()['from'] !== '';
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
    function suite_mail_send(string $to, string $subject, string $body, ?string $html = null): ?string {
        $cfg = suite_mail_config();
        $to = suite_mail_header_safe($to);
        if ($cfg['from'] === '') return "MAIL_FROM n'est pas configuré dans le .env de la suite.";
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
        $b64 = static fn(string $t): string => chunk_split(base64_encode($t), 76, "\r\n");
        if ($html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $payload = $b64($body);
        } else {
            // multipart/alternative : version texte (repli) puis version HTML (préférée par les messageries)
            $bd = 'tu_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $bd . '"';
            $payload = "--$bd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($body)
                     . "--$bd\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html)
                     . "--$bd--\r\n";
        }

        try {
            $err = $cfg['host'] === ''
                ? suite_mail_via_php($to, $subject, $headers, $payload, $from)
                : suite_mail_via_smtp($cfg, $from, $to, $headers, $payload);
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
        if ($err !== null) error_log('suite_mail_send: ' . $err);
        return $err;
    }
}

if (!function_exists('suite_mail_html')) {
    /**
     * Gabarit HTML commun des e-mails de la suite (mise en page en tableaux, styles en ligne :
     * seule méthode fiable dans Gmail / Outlook).
     * @param array<int,array{0:string,1:string}> $rows  lignes « libellé » / « valeur »
     * @param array{0:string,1:string}|null $button       [texte, url]
     */
    function suite_mail_html(string $title, string $lead, array $rows = [], ?array $button = null, string $footer = '', string $badge = ''): string {
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
        return $ok ? null : "mail() a échoué (aucun MTA local ? définissez MAIL_HOST pour utiliser un serveur SMTP).";
    }
}

if (!function_exists('suite_mail_via_smtp')) {
    function suite_mail_via_smtp(array $cfg, string $from, string $to, array $headers, string $payload): ?string {
        $scheme = $cfg['enc'] === 'ssl' ? 'ssl' : 'tcp';
        $fp = @stream_socket_client($scheme . '://' . $cfg['host'] . ':' . $cfg['port'], $errno, $errstr, 8);
        if (!$fp) return "Connexion SMTP impossible ($errstr)";
        stream_set_timeout($fp, 8);

        $read = static function () use ($fp): array {
            $code = 0; $text = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $text .= $line;
                $code = (int)substr($line, 0, 3);
                if (!isset($line[3]) || $line[3] !== '-') break;
            }
            return [$code, trim($text)];
        };
        $cmd = static function (string $c, array $ok) use ($fp, $read): ?string {
            fwrite($fp, $c . "\r\n");
            [$code, $text] = $read();
            return in_array($code, $ok, true) ? null : "SMTP : réponse inattendue ($text)";
        };

        [$code, $text] = $read();
        if ($code !== 220) { fclose($fp); return "SMTP : bannière inattendue ($text)"; }
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        if ($e = $cmd($ehlo, [250])) { fclose($fp); return $e; }

        if ($cfg['enc'] === 'tls') {
            if ($e = $cmd('STARTTLS', [220])) { fclose($fp); return $e; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return 'SMTP : négociation TLS impossible'; }
            if ($e = $cmd($ehlo, [250])) { fclose($fp); return $e; }
        }
        if ($cfg['user'] !== '') {
            if ($e = $cmd('AUTH LOGIN', [334]))                              { fclose($fp); return $e; }
            if ($e = $cmd(base64_encode($cfg['user']), [334]))               { fclose($fp); return $e; }
            if ($e = $cmd(base64_encode($cfg['pass']), [235]))               { fclose($fp); return 'SMTP : identifiants refusés'; }
        }
        if ($e = $cmd('MAIL FROM:<' . $from . '>', [250])) { fclose($fp); return $e; }
        if ($e = $cmd('RCPT TO:<' . $to . '>', [250, 251])) { fclose($fp); return $e; }
        if ($e = $cmd('DATA', [354])) { fclose($fp); return $e; }

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $payload;
        $data = preg_replace('/^\./m', '..', $data);
        fwrite($fp, $data . "\r\n.\r\n");
        [$code, $text] = $read();
        $cmd('QUIT', [221]);
        fclose($fp);
        return $code === 250 ? null : "SMTP : message refusé ($text)";
    }
}
