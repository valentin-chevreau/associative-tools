<?php
declare(strict_types=1);

// donations/includes/receipt.php
// Données du reçu fiscal (organisme, donateur, signature du trésorier) partagées par
// la vue HTML, le PDF et l'envoi par e-mail : un seul endroit pour la mise en forme.

if (!function_exists('receipt_clean')) {

    /** Nettoie un texte saisi : espaces insécables, et séquences littérales « \n » héritées du .env. */
    function receipt_clean(string $s): string {
        $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $s);
        $s = preg_replace('/\\\\r\\\\n|\\\\n|\\\\r/', "\n", $s) ?? $s;   // \n écrit en toutes lettres
        return trim(str_replace("\r", '', $s));
    }

    /** @return string[] lignes non vides */
    function receipt_lines(string $s): array {
        $out = [];
        foreach (explode("\n", receipt_clean($s)) as $l) {
            $l = trim(preg_replace('/\s{2,}/u', ' ', $l) ?? $l);
            if ($l !== '') $out[] = $l;
        }
        return $out;
    }

    function receipt_upper(string $s): string { return function_exists('mb_strtoupper') ? mb_strtoupper(trim($s), 'UTF-8') : strtoupper(trim($s)); }
    function receipt_title(string $s): string {
        $s = trim($s);
        return function_exists('mb_convert_case') ? mb_convert_case($s, MB_CASE_TITLE, 'UTF-8') : ucwords(strtolower($s));
    }

    /** Retire d'une ligne de rue le code postal / la ville qu'elle répète déjà. */
    function receipt_strip_zip_city(string $street, string $zip, string $city): string {
        $s = trim($street);
        if ($zip !== '')  $s = preg_replace('/\b' . preg_quote($zip, '/') . '\b/u', '', $s) ?? $s;
        if ($city !== '') $s = preg_replace('/\b' . preg_quote($city, '/') . '\b/iu', '', $s) ?? $s;
        $s = preg_replace('/\s{2,}/', ' ', $s) ?? $s;
        return trim($s, " \t\n\r\0\x0B,;-");
    }

    /** Nom de ville sans code postal ("37300 Joué-lès-Tours" → "Joué-lès-Tours"). */
    function receipt_city_name(string $s): string {
        return trim(preg_replace('/^\d{4,5}\s+/u', '', trim($s)) ?? $s);
    }

    /**
     * Adresse de l'organisme : lignes de ORG_ADDRESS + ORG_CITY, sans doublon
     * (la ville est souvent déjà présente en dernière ligne de l'adresse).
     * @return string[]
     */
    function receipt_org_address_lines(array $config): array {
        $lines = receipt_lines((string)($config['org_address'] ?? ''));
        foreach (receipt_lines((string)($config['org_city'] ?? '')) as $city) {
            $known = false;
            foreach ($lines as $l) {
                if (mb_stripos($l, receipt_city_name($city)) !== false) { $known = true; break; }
            }
            if (!$known) $lines[] = $city;
        }
        // ville en majuscules sur la ligne « CP Ville »
        foreach ($lines as $i => $l) {
            if (preg_match('/^(\d{4,5})\s+(.+)$/u', $l, $m)) $lines[$i] = $m[1] . ' ' . receipt_upper($m[2]);
        }
        return $lines;
    }

    /**
     * Charge le don et prépare toutes les valeurs d'affichage du reçu.
     * @return array{error:string}|array<string,mixed>
     */
    function receipt_build(PDO $pdo, int $id, array $config, bool $needNumber = true): array {
        if ($id <= 0) return ['error' => 'Reçu invalide.', 'code' => 400];
        $st = $pdo->prepare('SELECT * FROM donations WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        $don = $st->fetch(PDO::FETCH_ASSOC);
        if (!$don) return ['error' => 'Don introuvable.', 'code' => 404];
        if ((string)($don['source'] ?? '') !== 'manual') return ['error' => 'Reçu fiscal disponible uniquement pour les dons manuels.', 'code' => 403];
        if ($needNumber && empty($don['receipt_number'])) return ['error' => 'Reçu non généré (numéro manquant).', 'code' => 400];

        $receiptDate  = (string)($don['receipt_date'] ?? '');
        $donationDate = (string)($don['donation_date'] ?? '');

        $first = receipt_title((string)($don['donor_first_name'] ?? ''));
        $last  = receipt_upper((string)($don['donor_last_name'] ?? ''));
        $zip   = trim((string)($don['donor_postal_code'] ?? ''));
        $city  = receipt_upper((string)($don['donor_city'] ?? ''));
        $addr  = trim(receipt_clean((string)($don['donor_address'] ?? '')));
        $street = receipt_strip_zip_city(str_replace("\n", ', ', $addr), $zip, $city);
        if ($street === '') $street = str_replace("\n", ', ', $addr);
        $donorLines = array_values(array_filter([
            $street,
            trim($zip . ' ' . $city),
            receipt_title((string)($don['donor_country'] ?? '')),
        ], fn($v) => $v !== ''));

        $doneAt = receipt_upper((string)($config['org_receipt_city'] ?? ''));
        if ($doneAt === '') $doneAt = receipt_upper(receipt_city_name(receipt_lines((string)($config['org_city'] ?? ''))[0] ?? ''));

        $amount = (float)($don['amount'] ?? 0);
        return [
            'id'            => $id,
            'don'           => $don,
            'number'        => (string)($don['receipt_number'] ?? ''),
            'receipt_date'  => $receiptDate !== '' ? date('d/m/Y', strtotime($receiptDate)) : date('d/m/Y'),
            'donation_date' => $donationDate !== '' ? date('d/m/Y', strtotime($donationDate)) : '—',
            'donation_year' => (int)date('Y', strtotime($donationDate ?: 'now')),
            'org_name'      => trim(receipt_clean((string)($config['org_name'] ?? 'ASSOCIATION'))),
            'org_rna'       => trim((string)($config['org_rna'] ?? '')),
            'org_siret'     => trim((string)($config['org_siret'] ?? '')),
            'org_lines'     => receipt_org_address_lines($config),
            'donor_name'    => trim($first . ' ' . $last),
            'donor_email'   => trim((string)($don['donor_email'] ?? '')),
            'donor_lines'   => $donorLines,
            'amount'        => $amount,
            'amount_fr'     => number_format($amount, 2, ',', ' ') . ' €',
            'done_at'       => $doneAt,
            'signatory'     => receipt_signatory($pdo),
        ];
    }

    /**
     * Signataire « Trésorier » actif ayant une image de signature (module Documents).
     * @return array{name:string,role:string,path:string}|null  path = fichier image absolu (PNG/JPG)
     */
    function receipt_signatory(PDO $pdo): ?array {
        try {
            $rows = $pdo->query('SELECT full_name, role, signature_path FROM document_signatories
                                 WHERE is_active = 1 AND signature_path IS NOT NULL AND signature_path <> ""
                                 ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null; // module Documents non installé
        }
        $root = realpath(dirname(__DIR__, 2) . '/documents');
        if ($root === false) return null;
        foreach ($rows as $r) {
            $role = strtr(mb_strtolower((string)$r['role'], 'UTF-8'), ['é' => 'e', 'è' => 'e', 'ê' => 'e']);
            if (strpos($role, 'tresorier') === false) continue;
            $abs = realpath($root . '/' . ltrim((string)$r['signature_path'], '/'));
            if ($abs === false || strncmp($abs, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0 || !is_file($abs)) continue;
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) continue; // webp non intégrable au PDF
            return ['name' => (string)$r['full_name'], 'role' => (string)$r['role'], 'path' => $abs];
        }
        return null;
    }

    /** Dernier envoi par e-mail de ce reçu (journal d'audit), ou null. */
    function receipt_last_email(PDO $pdo, int $id): ?array {
        try {
            $st = $pdo->prepare("SELECT created_at, actor_name, details_json FROM suite_audit_log
                                 WHERE module = 'donations' AND action = 'receipt_email_sent' AND entity_type = 'donation' AND entity_id = :id
                                 ORDER BY id DESC LIMIT 1");
            $st->execute(['id' => $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
