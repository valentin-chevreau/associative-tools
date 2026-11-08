<?php
declare(strict_types=1);

/**
 * mailing/includes/blocks.php — Contenu d'une newsletter sous forme de blocs.
 *
 * Un message est une liste de blocs (JSON). Ce fichier est la seule source de vérité du rendu :
 * l'éditeur affiche l'aperçu de chaque bloc via block_preview.php, qui appelle mailing_block_html().
 *
 * Blocs : title, text, image, button, columns, quote, stats, event, divider.
 */

const MAILING_MAX_BLOCKS = 60;
const MAILING_IMAGE_DIR_NAME = 'mailing';

/** @return array<string,string> type => libellé */
function mailing_block_types(): array {
    return [
        'title'   => 'Titre',
        'text'    => 'Texte',
        'image'   => 'Image',
        'button'  => 'Bouton',
        'columns' => 'Colonnes',
        'quote'   => 'Citation',
        'stats'   => 'Chiffres clés',
        'event'   => 'Évènement',
        'divider' => 'Séparateur',
    ];
}

function mailing_image_dir(): string {
    return dirname(__DIR__, 2) . '/uploads/' . MAILING_IMAGE_DIR_NAME;
}

/** Adresse publique d'une image téléversée ($base : URL du dossier de la suite, sans barre finale). */
function mailing_image_url(string $base, string $file): string {
    return rtrim($base, '/') . '/mailing/image.php?f=' . rawurlencode($file);
}

function mailing_valid_url(string $u): bool {
    return (bool)preg_match('~^https?://[^\s<>"]+$~i', $u) && strlen($u) <= 500;
}

/** Texte → HTML en ligne : échappement, **gras**, *italique*, adresses web cliquables. */
function mailing_inline(string $text): string {
    $e = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $e = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $e) ?? $e;
    $e = preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/su', '<em>$1</em>', $e) ?? $e;
    return preg_replace_callback('~https?://[^\s<]+~u', static function (array $m): string {
        $url = rtrim($m[0], '.,;:!?)');
        return '<a href="' . $url . '" style="color:#b85e1e;">' . $url . '</a>' . substr($m[0], strlen($url));
    }, $e) ?? $e;
}

/** Paragraphes séparés par une ligne vide ; retours à la ligne conservés. */
function mailing_paragraphs(string $text, string $style = 'margin:0 0 14px;font-size:15px;line-height:1.6;color:#3d3428;'): string {
    $out = '';
    foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $p) {
        $p = trim($p);
        if ($p !== '') $out .= '<p style="' . $style . '">' . nl2br(mailing_inline($p), false) . '</p>';
    }
    return $out;
}

/** @return array{0:string,1:string} [jour de la semaine, « 21 novembre »] */
function mailing_fr_date(int $ts): array {
    $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return [$days[(int)date('w', $ts)], (int)date('j', $ts) . ($ts && date('j', $ts) === '1' ? 'er' : '') . ' ' . $months[(int)date('n', $ts) - 1]];
}

/** « de 9h à 12h » / « à 9h30 ». */
function mailing_fr_hours(int $start, ?int $end): string {
    $f = static fn(int $t): string => date('i', $t) === '00' ? date('G', $t) . 'h' : date('G\hi', $t);
    return $end && $end > $start ? 'de ' . $f($start) . ' à ' . $f($end) : 'à ' . $f($start);
}

/** Instantané d'un évènement du planning, ou null s'il n'existe plus. */
function mailing_event_snapshot(PDO $pdo, int $id): ?array {
    try {
        $st = $pdo->prepare("SELECT title, description, start_datetime, end_datetime FROM planning_events WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    if (!$r) return null;
    $desc = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($r['description'] ?? ''))) ?? '');
    return [
        'title' => (string)$r['title'],
        'start' => (string)$r['start_datetime'],
        'end'   => (string)($r['end_datetime'] ?? ''),
        'desc'  => mb_strlen($desc) > 300 ? rtrim(mb_substr($desc, 0, 297)) . '…' : $desc,
    ];
}

/**
 * Nettoie les blocs reçus du navigateur : types connus, champs bornés, adresses web valides,
 * images existantes, évènements relus dans le planning.
 * $allowVars : accepte une variable à la place d'une adresse web (messages automatiques, ex. {planning_url}).
 * @param mixed $raw
 * @return list<array<string,mixed>>
 */
function mailing_blocks_sanitize(PDO $pdo, $raw, bool $allowVars = false): array {
    if (!is_array($raw)) return [];
    $str = static fn($v, int $max): string => mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max);
    $url = static fn($v): string => (mailing_valid_url($str($v, 500)) || ($allowVars && preg_match('/^\{[a-z_]+\}$/', $str($v, 500)))) ? $str($v, 500) : '';
    $out = [];
    foreach (array_slice($raw, 0, MAILING_MAX_BLOCKS) as $b) {
        if (!is_array($b) || !isset(mailing_block_types()[$b['type'] ?? ''])) continue;
        switch ($b['type']) {
            case 'title':
                $out[] = ['type' => 'title', 'text' => $str($b['text'] ?? '', 190)];
                break;
            case 'text':
                $out[] = ['type' => 'text', 'text' => $str($b['text'] ?? '', 8000)];
                break;
            case 'image':
                $file = $str($b['file'] ?? '', 40);
                if (!preg_match('/^[a-f0-9]{32}\.(jpg|png)$/', $file) || !is_file(mailing_image_dir() . '/' . $file)) $file = '';
                $out[] = ['type' => 'image', 'file' => $file, 'alt' => $str($b['alt'] ?? '', 190), 'caption' => $str($b['caption'] ?? '', 190), 'url' => $url($b['url'] ?? '')];
                break;
            case 'button':
                $out[] = ['type' => 'button', 'label' => $str($b['label'] ?? '', 60), 'url' => $url($b['url'] ?? '')];
                break;
            case 'columns':
                $out[] = ['type' => 'columns', 'left' => $str($b['left'] ?? '', 3000), 'right' => $str($b['right'] ?? '', 3000)];
                break;
            case 'quote':
                $out[] = ['type' => 'quote', 'text' => $str($b['text'] ?? '', 1000), 'author' => $str($b['author'] ?? '', 120)];
                break;
            case 'stats':
                $items = [];
                foreach (array_slice(is_array($b['items'] ?? null) ? $b['items'] : [], 0, 3) as $it) {
                    $items[] = ['value' => $str($it['value'] ?? '', 20), 'label' => $str($it['label'] ?? '', 60)];
                }
                while (count($items) < 3) $items[] = ['value' => '', 'label' => ''];
                $out[] = ['type' => 'stats', 'items' => $items];
                break;
            case 'event':
                $id = (int)($b['event_id'] ?? 0);
                $snap = $id > 0 ? mailing_event_snapshot($pdo, $id) : null;
                $out[] = ['type' => 'event', 'event_id' => $snap ? $id : 0, 'snapshot' => $snap,
                          'show_time' => !empty($b['show_time']), 'show_desc' => !empty($b['show_desc']),
                          'label' => $str($b['label'] ?? '', 60), 'url' => $url($b['url'] ?? '')];
                break;
            case 'divider':
                $out[] = ['type' => 'divider'];
                break;
        }
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function mailing_blocks_decode(string $json): array {
    $d = json_decode($json, true);
    return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
}

function mailing_button_html(string $label, string $url, string $align = 'center'): string {
    $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="' . $align . '" style="padding:8px 0 4px;">'
        . '<a href="' . $e($url) . '" style="display:inline-block;background:#d4732a;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 22px;border-radius:10px;">' . $e($label) . '</a></td></tr></table>';
}

/**
 * HTML d'un bloc (styles en ligne, mise en page en tableaux).
 * @param array<string,mixed> $b bloc nettoyé
 * @param string $base URL du dossier de la suite (images)
 */
function mailing_block_html(array $b, string $base): string {
    $e = static fn($t): string => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    switch ($b['type']) {
        case 'title':
            return trim((string)$b['text']) === '' ? '' : '<h2 style="margin:6px 0 12px;font-size:22px;line-height:1.3;color:#1a1510;">' . $e($b['text']) . '</h2>';
        case 'text':
            return mailing_paragraphs((string)$b['text']);
        case 'image':
            if ($b['file'] === '') return '';
            $img = '<img src="' . $e(mailing_image_url($base, (string)$b['file'])) . '" alt="' . $e($b['alt']) . '" width="512" style="display:block;width:100%;max-width:512px;height:auto;border:0;border-radius:8px;">';
            if ($b['url'] !== '') $img = '<a href="' . $e($b['url']) . '">' . $img . '</a>';
            $cap = $b['caption'] !== '' ? '<div style="margin-top:6px;font-size:12px;color:#a89880;text-align:center;">' . $e($b['caption']) . '</div>' : '';
            return '<div style="margin:0 0 14px;">' . $img . $cap . '</div>';
        case 'button':
            return ($b['label'] === '' || $b['url'] === '') ? '' : mailing_button_html((string)$b['label'], (string)$b['url']);
        case 'columns':
            $small = 'margin:0 0 10px;font-size:14px;line-height:1.55;color:#3d3428;';
            return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px;"><tr>'
                . '<td width="50%" valign="top" style="padding-right:8px;">' . mailing_paragraphs((string)$b['left'], $small) . '</td>'
                . '<td width="50%" valign="top" style="padding-left:8px;">' . mailing_paragraphs((string)$b['right'], $small) . '</td></tr></table>';
        case 'quote':
            if ($b['text'] === '') return '';
            return '<div style="margin:6px 0 16px;border-left:4px solid #f6d046;padding:4px 0 4px 14px;font-size:15px;font-style:italic;line-height:1.55;color:#3d3428;">'
                . nl2br($e($b['text']), false)
                . ($b['author'] !== '' ? '<div style="margin-top:4px;font-size:12px;font-style:normal;color:#a89880;">' . $e($b['author']) . '</div>' : '') . '</div>';
        case 'stats':
            $cells = '';
            foreach ($b['items'] as $it) {
                if ($it['value'] === '' && $it['label'] === '') continue;
                $cells .= '<td align="center" valign="top" style="padding:0 4px;"><div style="background:#f5f8fc;border-radius:10px;padding:14px 6px;">'
                    . '<div style="font-size:26px;font-weight:800;color:#1d4f9f;">' . $e($it['value']) . '</div>'
                    . '<div style="font-size:12px;color:#6b5c47;">' . $e($it['label']) . '</div></div></td>';
            }
            return $cells === '' ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 16px;"><tr>' . $cells . '</tr></table>';
        case 'event':
            $s = $b['snapshot'] ?? null;
            if (!$s) return '';
            $ts = strtotime((string)$s['start']) ?: 0;
            $te = $s['end'] !== '' ? (strtotime((string)$s['end']) ?: null) : null;
            [$dow, $dm] = mailing_fr_date($ts);
            $months = ['JAN', 'FÉV', 'MARS', 'AVR', 'MAI', 'JUIN', 'JUIL', 'AOÛT', 'SEPT', 'OCT', 'NOV', 'DÉC'];
            $when = ucfirst($dow) . ' ' . $dm . (!empty($b['show_time']) ? ', ' . mailing_fr_hours($ts, $te) : '');
            $desc = (!empty($b['show_desc']) && $s['desc'] !== '') ? '<div style="margin-top:6px;font-size:13px;line-height:1.5;color:#6b5c47;">' . $e($s['desc']) . '</div>' : '';
            $btn = ($b['label'] !== '' && $b['url'] !== '') ? mailing_button_html((string)$b['label'], (string)$b['url'], 'left') : '';
            return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 16px;border:1px solid #e8ddd0;border-left:5px solid #d4732a;border-radius:10px;background:#fffaf4;"><tr>'
                . '<td width="64" valign="top" style="padding:14px 0 14px 14px;"><div style="background:#d4732a;color:#ffffff;border-radius:8px 8px 0 0;text-align:center;font-size:11px;font-weight:700;padding:3px 0;">' . $months[(int)date('n', $ts) - 1] . '</div>'
                . '<div style="border:1px solid #e8ddd0;border-top:0;border-radius:0 0 8px 8px;background:#ffffff;text-align:center;font-size:24px;font-weight:800;color:#1a1510;padding:2px 0 4px;">' . date('j', $ts) . '</div></td>'
                . '<td valign="top" style="padding:14px 16px;font-size:14px;line-height:1.5;color:#3d3428;"><div style="font-size:15px;font-weight:700;color:#1a1510;">' . $e($s['title']) . '</div><div>' . $e($when) . '</div>' . $desc . $btn . '</td></tr></table>';
        case 'divider':
            return '<div style="margin:12px 0;height:1px;line-height:1px;background:#e8ddd0;">&nbsp;</div>';
    }
    return '';
}

/**
 * Message complet en HTML.
 * @param array<string,mixed> $c campagne (subject, preheader, blocks)
 */
function mailing_render_html(array $c, string $unsubUrl, string $base): string {
    $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $body = '';
    foreach (mailing_blocks_decode((string)$c['blocks']) as $b) $body .= mailing_block_html($b, $base);
    $pre = trim((string)$c['preheader']) !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $e((string)$c['preheader']) . '</div>' : '';
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e((string)$c['subject']) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f5f0e8;">' . $pre
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f0e8;"><tr><td align="center" style="padding:28px 12px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;">'
        . '<tr><td style="padding:0 4px 14px;font-size:15px;font-weight:800;color:#3d3428;letter-spacing:.2px;"><span style="color:#d4732a;">&#9679;</span>&nbsp; Touraine-Ukraine</td></tr>'
        . '<tr><td style="background:#ffffff;border-radius:16px;border:1px solid #e8ddd0;border-top:4px solid #d4732a;padding:24px 24px 14px;">' . $body . '</td></tr>'
        . '<tr><td style="padding:16px 8px 0;font-size:12px;line-height:1.55;color:#a89880;">'
        . 'Vous recevez ce message de la part de l\'association Touraine-Ukraine.<br>'
        . '<a href="' . $e($unsubUrl) . '" style="color:#a89880;">Se désinscrire des e-mails de l\'association</a>'
        . '</td></tr></table></td></tr></table></body></html>';
}

/** Version texte brut du message (repli des messageries sans HTML). */
function mailing_render_text(array $c, string $unsubUrl): string {
    $strip = static fn(string $t): string => preg_replace('/\*\*(.+?)\*\*/su', '$1', $t) ?? $t;
    $parts = [];
    foreach (mailing_blocks_decode((string)$c['blocks']) as $b) {
        switch ($b['type']) {
            case 'title':   if ($b['text'] !== '') $parts[] = mb_strtoupper((string)$b['text']); break;
            case 'text':    $parts[] = $strip((string)$b['text']); break;
            case 'image':   if (($b['caption'] ?? '') !== '') $parts[] = '[' . $b['caption'] . ']'; break;
            case 'button':  if ($b['label'] !== '' && $b['url'] !== '') $parts[] = $b['label'] . ' : ' . $b['url']; break;
            case 'columns': $parts[] = $strip((string)$b['left']) . "\n\n" . $strip((string)$b['right']); break;
            case 'quote':   $parts[] = '« ' . $b['text'] . ' »' . ($b['author'] !== '' ? ' — ' . $b['author'] : ''); break;
            case 'stats':
                $parts[] = implode(' · ', array_map(static fn(array $i): string => trim($i['value'] . ' ' . $i['label']), array_filter($b['items'], static fn(array $i): bool => $i['value'] . $i['label'] !== '')));
                break;
            case 'event':
                $s = $b['snapshot'] ?? null;
                if ($s) {
                    $ts = strtotime((string)$s['start']) ?: 0;
                    [$dow, $dm] = mailing_fr_date($ts);
                    $parts[] = $s['title'] . "\n" . ucfirst($dow) . ' ' . $dm . (!empty($b['show_time']) ? ', ' . mailing_fr_hours($ts, $s['end'] !== '' ? (strtotime((string)$s['end']) ?: null) : null) : '')
                        . (!empty($b['show_desc']) && $s['desc'] !== '' ? "\n" . $s['desc'] : '')
                        . ($b['label'] !== '' && $b['url'] !== '' ? "\n" . $b['label'] . ' : ' . $b['url'] : '');
                }
                break;
            case 'divider': $parts[] = '--------'; break;
        }
    }
    $parts = array_values(array_filter($parts, static fn(string $p): bool => trim($p) !== ''));
    return implode("\n\n", $parts) . "\n\n--\nVous recevez ce message de la part de l'association Touraine-Ukraine.\nSe désinscrire : " . $unsubUrl . "\n";
}
