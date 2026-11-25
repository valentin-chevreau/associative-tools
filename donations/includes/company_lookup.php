<?php
declare(strict_types=1);

// donations/includes/company_lookup.php
// Recherche d'entreprise dans l'annuaire officiel (API « Recherche d'entreprises », data.gouv.fr :
// gratuite, sans clé). Appelée côté serveur par donations/company_lookup.php.

require_once __DIR__ . '/receipt.php';

if (!function_exists('company_lookup_map')) {

    /** Adresse de rue d'un établissement de l'API (sans code postal ni ville). */
    function company_lookup_street(array $e): string {
        $parts = array_filter([
            trim((string)($e['numero_voie'] ?? '')),
            trim((string)($e['indice_repetition'] ?? '')),
            trim((string)($e['type_voie'] ?? '')),
            trim((string)($e['libelle_voie'] ?? '')),
        ], fn($v) => $v !== '');
        $street = implode(' ', $parts);
        if ($street === '') {
            // repli : « adresse » complète sans le code postal ni la commune
            $street = trim((string)($e['adresse'] ?? ''));
            foreach ([(string)($e['code_postal'] ?? ''), (string)($e['libelle_commune'] ?? '')] as $rm) {
                if ($rm !== '') $street = trim(str_ireplace($rm, '', $street));
            }
        }
        $compl = trim((string)($e['complement_adresse'] ?? ''));
        return trim($compl !== '' ? $compl . ', ' . $street : $street, " ,");
    }

    /**
     * Transforme un résultat de l'API en ligne utilisable par le formulaire.
     * @param array<string,mixed> $r      résultat (unité légale)
     * @param string              $siret  SIRET recherché (14 chiffres) : l'adresse de cet établissement est alors utilisée
     * @return array<string,mixed>
     */
    function company_lookup_map(array $r, string $siret = ''): array {
        $siege = is_array($r['siege'] ?? null) ? $r['siege'] : [];
        $etab = $siege;
        if ($siret !== '') {
            foreach ((array)($r['matching_etablissements'] ?? []) as $m) {
                if (is_array($m) && (string)($m['siret'] ?? '') === $siret) { $etab = $m; break; }
            }
        }
        $name = trim((string)($r['nom_raison_sociale'] ?? ''));
        if ($name === '') $name = trim((string)($r['nom_complet'] ?? ''));
        $diffusible = (string)($r['statut_diffusion'] ?? 'O') === 'O';

        $companyActive = (string)($r['etat_administratif'] ?? 'A') === 'A';
        $etabActive    = (string)($etab['etat_administratif'] ?? 'A') === 'A';
        $siegeActive   = (string)($siege['etat_administratif'] ?? 'A') === 'A';

        // Établissement fermé alors que le siège est ouvert : on reprend l'adresse actuelle (celle du siège).
        $addrFrom = $etab;
        $etabClosedUsingSiege = false;
        if (!$etabActive && $siegeActive && $companyActive) { $addrFrom = $siege; $etabClosedUsingSiege = true; }

        return [
            'siren'       => (string)($r['siren'] ?? ''),
            'siret'       => (string)($etab['siret'] ?? $siege['siret'] ?? ''),
            'name'        => $name,
            'street'      => $diffusible ? company_lookup_street($addrFrom) : '',
            'postal_code' => $diffusible ? (string)($addrFrom['code_postal'] ?? '') : '',
            'city'        => $diffusible ? (string)($addrFrom['libelle_commune'] ?? '') : '',
            'active'      => $companyActive,                 // l'entreprise (unité légale) est en activité
            'etab_active' => $etabActive,                    // l'établissement du SIRET saisi est ouvert
            'address_from_siege' => $etabClosedUsingSiege,   // adresse reprise du siège car l'établissement est fermé
            'siege_siret' => (string)($siege['siret'] ?? ''),
            'diffusible'  => $diffusible,
        ];
    }

    /**
     * Interroge l'API. Renvoie ['results' => [...]] ou ['error' => '...'].
     * @return array<string,mixed>
     */
    function company_lookup(string $q): array {
        $q = trim($q);
        $digits = receipt_digits($q);
        $numeric = $digits !== '' && preg_match('/^[\d\s.]+$/', $q) === 1;
        if ($numeric && !in_array(strlen($digits), [9, 14], true)) return ['results' => []];
        if (!$numeric && mb_strlen($q) < 3) return ['results' => []];

        $params = ['q' => $numeric ? $digits : $q, 'per_page' => $numeric ? 1 : 6];
        if (!$numeric) $params['etat_administratif'] = 'A';
        $url = 'https://recherche-entreprises.api.gouv.fr/search?' . http_build_query($params);

        $body = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'Suite-Touraine-Ukraine/1.0']);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($body !== false && $code >= 400) $body = false;
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 6, 'header' => "Accept: application/json\r\nUser-Agent: Suite-Touraine-Ukraine/1.0\r\n"]]);
            $body = @file_get_contents($url, false, $ctx);
        }
        if ($body === false) return ['error' => "L'annuaire des entreprises ne répond pas pour le moment. Saisissez les informations à la main."];
        $json = json_decode((string)$body, true);
        if (!is_array($json) || !isset($json['results']) || !is_array($json['results'])) return ['error' => "Réponse inattendue de l'annuaire des entreprises."];

        $siret = ($numeric && strlen($digits) === 14) ? $digits : '';
        $out = [];
        foreach ($json['results'] as $r) {
            if (!is_array($r)) continue;
            $row = company_lookup_map($r, $siret);
            if ($row['siren'] !== '') $out[] = $row;
        }
        return ['results' => $out];
    }
}
