<?php
declare(strict_types=1);
/**
 * shared/deepl.php — Traduction automatique via l'API DeepL.
 *
 * Configuration dans le .env de la suite (voir shared/db_credentials.php) :
 *   DEEPL_API_KEY   clé d'API DeepL (une clé gratuite se termine par « :fx » : le point d'accès gratuit est alors utilisé)
 *
 * La clé ne quitte jamais le serveur : les pages appellent un point d'entrée PHP, jamais DeepL directement.
 */

require_once __DIR__ . '/db_credentials.php';

if (!function_exists('suite_deepl_key')) {
    function suite_deepl_key(): string {
        $v = getenv('DEEPL_API_KEY');
        if ($v === false || trim($v) === '') {
            $file = suite_load_env_file(suite_env_path());
            $v = $file['DEEPL_API_KEY'] ?? '';
        }
        return trim((string)$v);
    }
}

if (!function_exists('suite_deepl_ready')) {
    function suite_deepl_ready(): bool { return suite_deepl_key() !== ''; }
}

if (!function_exists('suite_deepl_parse')) {
    /**
     * Interprète une réponse DeepL.
     * @return array{ok:bool,text:string,error:?string}
     */
    function suite_deepl_parse(int $status, string $body): array {
        $json = json_decode($body, true);
        if ($status === 200 && is_array($json) && isset($json['translations'][0]['text'])) {
            return ['ok' => true, 'text' => trim((string)$json['translations'][0]['text']), 'error' => null];
        }
        $msg = match (true) {
            $status === 403 => 'Clé DeepL refusée (vérifiez DEEPL_API_KEY).',
            $status === 456 => 'Quota DeepL épuisé pour ce mois.',
            $status === 429 => 'Trop de requêtes vers DeepL, réessayez dans un instant.',
            $status === 0   => 'DeepL est injoignable depuis le serveur.',
            default         => 'Réponse inattendue de DeepL (code ' . $status . ').',
        };
        return ['ok' => false, 'text' => '', 'error' => $msg];
    }
}

if (!function_exists('suite_deepl_translate')) {
    /**
     * Traduit un court texte.
     * @param string $source  FR | UK | EN
     * @param string $target  FR | UK | EN
     * @return array{ok:bool,text:string,error:?string}
     */
    function suite_deepl_translate(string $text, string $source, string $target, string $context = ''): array {
        $key = suite_deepl_key();
        if ($key === '') return ['ok' => false, 'text' => '', 'error' => "DeepL n'est pas configuré (DEEPL_API_KEY dans le .env de la suite)."];
        $text = trim($text);
        if ($text === '') return ['ok' => true, 'text' => '', 'error' => null];

        $codes = ['FR' => 'FR', 'UK' => 'UK', 'EN' => 'EN-US'];
        $srcCodes = ['FR' => 'FR', 'UK' => 'UK', 'EN' => 'EN'];
        if (!isset($srcCodes[$source], $codes[$target]) || $source === $target) {
            return ['ok' => false, 'text' => '', 'error' => 'Langue non prise en charge.'];
        }

        $host = str_ends_with($key, ':fx') ? 'api-free.deepl.com' : 'api.deepl.com';
        $payload = ['text' => [$text], 'source_lang' => $srcCodes[$source], 'target_lang' => $codes[$target]];
        if ($context !== '') $payload['context'] = $context;

        $ch = curl_init('https://' . $host . '/v2/translate');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Authorization: DeepL-Auth-Key ' . $key, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return suite_deepl_parse($status, $body === false ? '' : (string)$body);
    }
}
