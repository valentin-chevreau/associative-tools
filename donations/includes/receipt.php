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

    // ------------------------------------------------------------------
    //  Types de dons, valorisation, identité de l'entreprise donatrice
    // ------------------------------------------------------------------

    /** @return array<string,string> */
    function donation_kinds(): array {
        return [
            'cash'     => 'Don en numéraire',
            'in_kind'  => 'Don en nature (biens)',
            'services' => 'Prestation / mécénat de compétences',
        ];
    }

    /**
     * Bases de valorisation d'un don en nature (BOFiP BOI-BIC-RICI-20-30-10-20 et service-public F3177).
     * « for » : à quel type de donateur la base s'applique ; « kinds » : pour quels dons.
     * @return array<string,array{label:string,for:string[],kinds:string[],help:string}>
     */
    function donation_valuation_bases(): array {
        return [
            'cost_stock' => [
                'label' => "Coût de revient : prix d'achat + frais directs (transport, manutention)",
                'for' => ['company'], 'kinds' => ['in_kind'],
                'help' => "L'entreprise donne des marchandises qu'elle achète pour les revendre (stock). Valeur = ce qu'elle les a payées, plus les frais directs (transport, manutention). Ce n'est pas le prix de vente en magasin.",
            ],
            'cost_production' => [
                'label' => 'Coût de production du bien',
                'for' => ['company'], 'kinds' => ['in_kind'],
                'help' => "L'entreprise donne un produit qu'elle fabrique elle-même. Valeur = ce que sa fabrication lui a coûté (matières, main-d'œuvre, charges directes), pas son prix de vente.",
            ],
            'fixed_asset' => [
                'label' => 'Valeur vénale du bien immobilisé à la date du don',
                'for' => ['company'], 'kinds' => ['in_kind'],
                'help' => "L'entreprise donne du matériel durable inscrit à son actif (véhicule, ordinateur, mobilier). Valeur = prix qu'on en tirerait sur le marché le jour du don. Un bien déjà totalement amorti n'ouvre pas droit à réduction d'impôt.",
            ],
            'service_cost' => [
                'label' => 'Coût de revient de la prestation',
                'for' => ['company'], 'kinds' => ['services'],
                'help' => "L'entreprise réalise gratuitement un travail (transport, impression, comptabilité…). Valeur = ce que ce travail lui a coûté à réaliser, pas le prix qu'elle aurait facturé.",
            ],
            'staff_cost' => [
                'label' => 'Salaires et charges des salariés mis à disposition',
                'for' => ['company'], 'kinds' => ['services'],
                'help' => "L'entreprise met des salariés à disposition de l'association pendant leur temps de travail (mécénat de compétences). Valeur = salaires + charges sociales des heures passées, dans la limite fixée par la loi.",
            ],
            'purchase_price' => [
                'label' => "Prix d'achat du bien neuf",
                'for' => ['individual'], 'kinds' => ['in_kind'],
                'help' => "Le particulier donne un bien neuf ou quasi neuf. Valeur = le prix qu'il l'a payé (facture ou ticket de caisse à l'appui).",
            ],
            'resale_price' => [
                'label' => "Prix de revente estimé du bien d'occasion",
                'for' => ['individual'], 'kinds' => ['in_kind'],
                'help' => "Le particulier donne un bien d'occasion. Valeur = le prix auquel il aurait pu le revendre aujourd'hui (par exemple le prix de ventes comparables sur un site d'annonces).",
            ],
            'rent' => [
                'label' => "Loyer qui aurait été perçu (local mis à disposition sous contrat de location)",
                'for' => ['individual'], 'kinds' => ['in_kind'],
                'help' => "Le propriétaire met un local à disposition de l'association avec un contrat de location. Valeur = le loyer qu'il aurait perçu. Sans contrat de location, il n'y a pas de reçu possible.",
            ],
        ];
    }

    function donation_valuation_label(?string $key): string {
        $all = donation_valuation_bases();
        return isset($all[(string)$key]) ? $all[(string)$key]['label'] : '';
    }

    /** SIREN (9 chiffres) ou SIRET (14) : chiffres uniquement. */
    function receipt_digits(string $s): string { return preg_replace('/\D+/', '', $s) ?? ''; }

    /** Contrôle de Luhn d'un SIREN (9 chiffres) ou d'un SIRET (14 chiffres). */
    function receipt_siren_valid(string $s): bool {
        $d = receipt_digits($s);
        if (strlen($d) !== 9 && strlen($d) !== 14) return false;
        if (strlen($d) === 14 && strncmp($d, '356000000', 9) === 0) return true; // La Poste : SIRET hors Luhn
        $sum = 0;
        foreach (array_reverse(str_split($d)) as $i => $c) {
            $n = (int)$c * (($i % 2) ? 2 : 1);
            $sum += $n > 9 ? $n - 9 : $n;
        }
        return $sum % 10 === 0;
    }

    function receipt_siren_format(string $s): string {
        $d = receipt_digits($s);
        if (strlen($d) === 9)  return trim(chunk_split($d, 3, ' '));
        if (strlen($d) === 14) return trim(chunk_split(substr($d, 0, 9), 3, ' ')) . ' ' . substr($d, 9);
        return $d;
    }

    /** Mode de versement tel qu'attendu sur un reçu fiscal. */
    function receipt_payment_label(?string $m): string {
        $m = trim((string)$m);
        if ($m === '') return '';
        $map = ['Espèces' => "Remise d'espèces", 'CB (hors HelloAsso)' => 'Carte bancaire'];
        return $map[$m] ?? $m;
    }


    /**
     * Raison pour laquelle un reçu fiscal ne peut pas (encore) être émis pour ce don, ou null.
     * @param array<string,mixed> $don
     */
    function receipt_blocking_issue(array $don): ?string {
        $isCompany = (string)($don['donor_type'] ?? 'individual') === 'company';
        if ($isCompany) {
            if (trim((string)($don['donor_company_name'] ?? '')) === '') return "raison sociale de l'entreprise manquante.";
            if (!receipt_siren_valid((string)($don['donor_siren'] ?? ''))) return "SIREN/SIRET de l'entreprise manquant ou invalide.";
        }
        $kind = (string)($don['donation_kind'] ?? 'cash');
        if ($kind !== 'cash') {
            if (trim((string)($don['in_kind_description'] ?? '')) === '') return "description des biens ou services manquante.";
            $bases = donation_valuation_bases();
            if (!isset($bases[(string)($don['valuation_basis'] ?? '')])) return "base de valorisation manquante.";
            if (trim((string)($don['valuation_source'] ?? '')) === '') return "valorisation non communiquée par le donateur (obligatoire pour émettre le reçu).";
            if (empty($don['no_counterpart'])) return "contrepartie directe non écartée (parrainage : pas de reçu fiscal).";
        }
        return null;
    }

    /**
     * Paragraphes de mentions légales du reçu (une seule source pour le PDF et l'aperçu HTML).
     * @param array<string,mixed> $d données de receipt_build()
     * @return string[]
     */
    function receipt_legal_paragraphs(array $d): array {
        $art  = $d['is_company'] ? "l'article 238 bis du Code général des impôts" : "l'article 200 du Code général des impôts";
        $out  = [];
        if ($d['is_in_kind']) {
            $out[] = "Le présent reçu atteste que l'organisme ci-dessus a accepté, à titre gratuit, les biens ou services décrits dans la rubrique « Don » et mis à sa disposition par le donateur mentionné.";
            $out[] = "La valorisation indiquée a été déterminée et communiquée par le donateur sur la base mentionnée ; l'organisme l'enregistre sans la garantir. "
                   . "Le donateur conserve les pièces justifiant la réalité et la valorisation de ce don.";
            if (!empty($d['no_counterpart'])) {
                $out[] = "Le donateur déclare n'avoir reçu aucune contrepartie directe de l'organisme en échange de ce don.";
            }
        } else {
            $out[] = "Le présent reçu atteste que l'organisme ci-dessus a reçu un don en numéraire du donateur mentionné.";
        }
        $out[] = "Ce don ouvre droit à une réduction d'impôt au titre de {$art}, dans les conditions et limites prévues par ce texte et sous réserve de la réglementation en vigueur.";
        $out[] = "L'organisme certifie sur l'honneur que les dons et versements qu'il reçoit ouvrent droit à la réduction d'impôt prévue à {$art}.";
        return $out;
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

        // Reçu groupé : un don « maître » porte le reçu, les autres y renvoient (receipt_group_id).
        $masterId = (int)($don['receipt_group_id'] ?? 0);
        if ($masterId > 0) {
            $st->execute(['id' => $masterId]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if ($m) { $don = $m; $id = $masterId; }
        }
        $gst = $pdo->prepare('SELECT * FROM donations WHERE id = :m OR receipt_group_id = :m2 ORDER BY donation_date ASC, id ASC');
        $gst->execute(['m' => $id, 'm2' => $id]);
        $group = $gst->fetchAll(PDO::FETCH_ASSOC) ?: [$don];

        $receiptDate  = (string)($don['receipt_date'] ?? '');

        $isCompany = (string)($don['donor_type'] ?? 'individual') === 'company';
        $kind      = (string)($don['donation_kind'] ?? 'cash');
        if (!isset(donation_kinds()[$kind])) $kind = 'cash';
        $isInKind  = $kind !== 'cash';

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

        $company = trim(receipt_clean((string)($don['donor_company_name'] ?? '')));
        $contact = trim($first . ' ' . $last);
        $donorName = ($isCompany && $company !== '') ? $company : $contact;

        // Lignes du reçu (un don = une ligne ; reçu groupé = plusieurs lignes)
        $items = [];
        $total = 0.0;
        $payments = [];
        $sources = [];
        $bases = [];
        $minTs = PHP_INT_MAX; $maxTs = 0;
        foreach ($group as $g) {
            $ts = strtotime((string)($g['donation_date'] ?? '')) ?: time();
            $minTs = min($minTs, $ts); $maxTs = max($maxTs, $ts);
            $a = (float)($g['amount'] ?? 0);
            $total += $a;
            $pay = receipt_payment_label((string)($g['payment_method'] ?? ''));
            if ($pay !== '') $payments[$pay] = true;
            $src = trim((string)($g['valuation_source'] ?? ''));
            if ($src !== '') $sources[$src] = true;
            $bl = donation_valuation_label((string)($g['valuation_basis'] ?? ''));
            if ($bl !== '') $bases[$bl] = true;
            $items[] = [
                'date'        => date('d/m/Y', $ts),
                'description' => trim(receipt_clean((string)($g['in_kind_description'] ?? ''))),
                'amount'      => $a,
                'amount_fr'   => number_format($a, 2, ',', ' ') . ' €',
            ];
        }
        $donationDate = $minTs === $maxTs
            ? date('d/m/Y', $minTs)
            : 'du ' . date('d/m/Y', $minTs) . ' au ' . date('d/m/Y', $maxTs);

        $doneAt = receipt_upper((string)($config['org_receipt_city'] ?? ''));
        if ($doneAt === '') $doneAt = receipt_upper(receipt_city_name(receipt_lines((string)($config['org_city'] ?? ''))[0] ?? ''));

        $d = [
            'id'            => $id,
            'don'           => $don,
            'number'        => (string)($don['receipt_number'] ?? ''),
            'receipt_date'  => $receiptDate !== '' ? date('d/m/Y', strtotime($receiptDate)) : date('d/m/Y'),
            'donation_date' => $donationDate,
            'donation_year' => (int)date('Y', $minTs),
            'org_name'      => trim(receipt_clean((string)($config['org_name'] ?? 'ASSOCIATION'))),
            'org_rna'       => trim((string)($config['org_rna'] ?? '')),
            'org_siret'     => trim((string)($config['org_siret'] ?? '')),
            'org_object'    => trim(receipt_clean((string)($config['org_object'] ?? ''))),
            'org_status'    => trim(receipt_clean((string)($config['org_status'] ?? ''))),
            'org_lines'     => receipt_org_address_lines($config),
            'is_company'    => $isCompany,
            'donor_company' => $isCompany ? $company : '',
            'donor_siren'   => $isCompany ? receipt_siren_format((string)($don['donor_siren'] ?? '')) : '',
            'donor_contact' => $isCompany ? $contact : '',
            'donor_name'    => $donorName,
            'donor_email'   => trim((string)($don['donor_email'] ?? '')),
            'donor_lines'   => $donorLines,
            'kind'          => $kind,
            'kind_label'    => $isInKind ? ($kind === 'services' ? "Don en nature : prestation / mécénat de compétences" : 'Don en nature') : 'Don en numéraire',
            'is_in_kind'    => $isInKind,
            'items'         => $items,
            'grouped'       => count($items) > 1,
            'valuation_bases'  => array_keys($bases),
            'valuation_source' => implode(' ; ', array_keys($sources)),
            'no_counterpart'   => !empty($don['no_counterpart']),
            'payment_label' => implode(', ', array_keys($payments)),
            'amount'        => $total,
            'amount_fr'     => number_format($total, 2, ',', ' ') . ' €',
            'done_at'       => $doneAt,
            'signatory'     => receipt_signatory($pdo),
        ];
        $d['legal'] = receipt_legal_paragraphs($d);
        return $d;
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
