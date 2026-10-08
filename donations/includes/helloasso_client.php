<?php
declare(strict_types=1);

// donations/includes/helloasso_client.php
//
// Client HTTP minimal (cURL, sans dépendance externe / composer) pour l'API
// HelloAsso v5. Couvre uniquement ce dont on a besoin : authentification
// OAuth2 (client_credentials + cache du token en base) et la liste paginée
// des paiements d'une organisation, convertis en lignes "donations".
//
// Doc API :
// - Auth      : https://dev.helloasso.com/docs/getting-started
// - Pagination: https://dev.helloasso.com/docs/pagination (continuationToken)
//
// IMPORTANT : les noms exacts des paramètres de filtre de la liste des
// paiements (from/to, formType...) n'ont pas pu être vérifiés à 100% sur la
// doc interactive (rendue en JS, non accessible en lecture simple). On les
// envoie "au mieux" ET on refiltre systématiquement côté PHP (date + type de
// formulaire) dans helloasso_map_payment_to_donation() / le script de sync,
// pour rester correct même si un paramètre est ignoré ou mal nommé par
// l'API. Vérifie le mapping avec --dry-run avant d'activer la tâche
// planifiée (voir cron/sync_helloasso_donations.php).

class HelloAssoConfigException extends RuntimeException {}
class HelloAssoApiException extends RuntimeException {}

/**
 * Lit la config HelloAsso depuis le .env unique de la suite (voir includes/env.php).
 */
require_once dirname(__DIR__, 2) . '/shared/audit.php';

function helloasso_config(): array {
    $clientId     = (string)env('HELLOASSO_CLIENT_ID', '');
    $clientSecret = (string)env('HELLOASSO_CLIENT_SECRET', '');
    $orgSlug      = (string)env('HELLOASSO_ORG_SLUG', '');
    $apiBase      = rtrim((string)env('HELLOASSO_API_BASE', 'https://api.helloasso.com'), '/');

    if ($clientId === '' || $clientSecret === '' || $orgSlug === '') {
        throw new HelloAssoConfigException(
            "Configuration HelloAsso incomplète : vérifie HELLOASSO_CLIENT_ID, " .
            "HELLOASSO_CLIENT_SECRET et HELLOASSO_ORG_SLUG dans le .env de la suite"
        );
    }

    // Liste blanche/noire manuelle de formSlug à toujours exclure des dons —
    // filet de sécurité pour les formulaires HelloAsso mal typés côté API
    // (ex: un formulaire d'adhésion dont order.formType n'est pas toujours
    // renvoyé par l'API "liste des paiements", et dont les lignes de détail
    // sont malgré tout typées "Donation" par HelloAsso). Voir
    // HELLOASSO_EXCLUDE_FORM_SLUGS dans .env.example (racine).
    $excludeRaw = (string)env('HELLOASSO_EXCLUDE_FORM_SLUGS', '');
    $excludeFormSlugs = array_values(array_filter(array_map(
        fn($s) => mb_strtolower(trim($s)),
        explode(',', $excludeRaw)
    ), fn($s) => $s !== ''));

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'org_slug' => $orgSlug,
        'api_base' => $apiBase,
        'exclude_form_slugs' => $excludeFormSlugs,
    ];
}

/**
 * Petit wrapper cURL générique (form/JSON in, JSON out).
 */
function helloasso_http_request(string $method, string $url, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $body,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new HelloAssoApiException("Erreur réseau HelloAsso : $err");
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string)$raw, true);
    if ($status >= 400) {
        $detail = is_array($json) ? json_encode($json, JSON_UNESCAPED_UNICODE) : substr((string)$raw, 0, 500);
        throw new HelloAssoApiException("HelloAsso a répondu $status : $detail");
    }
    if (!is_array($json)) {
        throw new HelloAssoApiException("Réponse HelloAsso illisible (statut $status).");
    }
    return $json;
}

/**
 * Table d'état : un seul enregistrement (id=1) qui garde le token en cache
 * (pour éviter de redemander un token à chaque exécution du cron — l'API
 * limite les appels d'authentification) et le résumé de la dernière
 * synchro, affiché dans index.php.
 *
 * Anciennement "planning_helloasso_sync_state" — renommée en
 * "donations_helloasso_sync_state" lors de l'extraction du module hors de
 * planning (voir migrations/002_rename_helloasso_sync_state.sql).
 */
function helloasso_ensure_state_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS donations_helloasso_sync_state (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
            access_token TEXT NULL,
            token_expires_at DATETIME NULL,
            last_sync_started_at DATETIME NULL,
            last_sync_finished_at DATETIME NULL,
            last_sync_ok TINYINT(1) NULL,
            last_sync_inserted INT NULL,
            last_sync_updated INT NULL,
            last_sync_errors INT NULL,
            last_error TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("INSERT IGNORE INTO donations_helloasso_sync_state (id) VALUES (1)");
}

function helloasso_get_access_token(PDO $pdo, array $cfg): string {
    helloasso_ensure_state_table($pdo);

    $row = $pdo->query("SELECT access_token, token_expires_at FROM donations_helloasso_sync_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['access_token']) && !empty($row['token_expires_at'])) {
        $expiresAt = strtotime((string)$row['token_expires_at']);
        // Marge de sécurité de 60s avant expiration.
        if ($expiresAt !== false && $expiresAt > time() + 60) {
            return (string)$row['access_token'];
        }
    }

    $body = http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
    ]);

    $json = helloasso_http_request('POST', $cfg['api_base'] . '/oauth2/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], $body);

    $token = (string)($json['access_token'] ?? '');
    $expiresIn = (int)($json['expires_in'] ?? 1800);
    if ($token === '') {
        throw new HelloAssoApiException("Réponse token HelloAsso sans access_token : " . json_encode($json));
    }

    $expiresAt = (new DateTimeImmutable("+{$expiresIn} seconds"))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE donations_helloasso_sync_state
        SET access_token = :token, token_expires_at = :exp
        WHERE id = 1
    ");
    $stmt->execute(['token' => $token, 'exp' => $expiresAt]);

    return $token;
}

/**
 * Récupère TOUS les paiements de l'organisation entre $from et $to (bornes
 * incluses), toutes pages confondues (pagination par continuationToken).
 *
 * @return array<int, array> liste brute des paiements (objets décodés du JSON)
 */
function helloasso_fetch_payments(PDO $pdo, array $cfg, DateTimeImmutable $from, DateTimeImmutable $to): array {
    $token = helloasso_get_access_token($pdo, $cfg);

    $all = [];
    $continuationToken = null;
    $page = 0;
    $maxPages = 100; // garde-fou anti-boucle infinie si l'API se comporte mal

    do {
        $page++;
        if ($page > $maxPages) {
            throw new HelloAssoApiException("Trop de pages HelloAsso (>{$maxPages}) — arrêt de sécurité.");
        }

        $query = [
            'from' => $from->format('Y-m-d\TH:i:sP'),
            'to'   => $to->format('Y-m-d\TH:i:sP'),
            'pageSize' => 100,
        ];
        if ($continuationToken) $query['continuationToken'] = $continuationToken;

        $url = $cfg['api_base'] . '/v5/organizations/' . rawurlencode($cfg['org_slug']) . '/payments?' . http_build_query($query);

        $json = helloasso_http_request('GET', $url, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);

        $data = $json['data'] ?? [];
        if (!is_array($data)) $data = [];
        foreach ($data as $item) $all[] = $item;

        $continuationToken = $json['pagination']['continuationToken'] ?? null;

        // Arrêt : plus de données OU pas de token de continuation.
        if (empty($data) || !$continuationToken) break;

    } while (true);

    return $all;
}

/**
 * Convertit un paiement brut de l'API HelloAsso en tableau prêt pour un
 * INSERT/UPDATE dans "donations". Retourne null si ce paiement n'est pas un
 * don (ex: billetterie, adhésion via HelloAsso Boutique/Formulaire).
 *
 * Champs API constatés (voir commentaire d'en-tête) :
 *   id, amount (entier, en centimes), date (ISO8601), paymentMeans, state,
 *   payer{email,firstName,lastName,address,city,zipCode,country,company},
 *   order{id,date,formSlug,formType,organizationName,organizationSlug},
 *   items[]{id,amount,type,state}
 */
function helloasso_map_payment_to_donation(array $payment): ?array {
    $formType = (string)($payment['order']['formType'] ?? '');
    if ($formType !== '' && strcasecmp($formType, 'Donation') !== 0) {
        return null;
    }
    // Si formType absent (selon version/endpoint), on se rabat sur les items.
    if ($formType === '') {
        $items = $payment['items'] ?? [];
        $hasDonationItem = false;
        if (is_array($items)) {
            foreach ($items as $it) {
                if (strcasecmp((string)($it['type'] ?? ''), 'Donation') === 0) { $hasDonationItem = true; break; }
            }
        }
        if (!$hasDonationItem) return null;
    }

    $id = (string)($payment['id'] ?? '');
    if ($id === '') return null;

    $rawDate = (string)($payment['date'] ?? '');
    $date = null;
    try {
        $date = $rawDate !== '' ? (new DateTimeImmutable($rawDate))->format('Y-m-d H:i:s') : null;
    } catch (Throwable $e) {
        $date = null;
    }
    if ($date === null) return null;

    $amountCents = $payment['amount'] ?? null;
    if (!is_numeric($amountCents)) return null;
    $amount = number_format(((float)$amountCents) / 100, 2, '.', '');

    $stateRaw = strtolower((string)($payment['state'] ?? ''));
    $status = 'unknown';
    if (in_array($stateRaw, ['authorized', 'registered', 'processed'], true)) $status = 'paid';
    elseif ($stateRaw === 'pending') $status = 'pending';
    elseif (in_array($stateRaw, ['refunded', 'contested'], true)) $status = 'refunded';
    elseif (in_array($stateRaw, ['refused', 'cancelled', 'canceled'], true)) $status = 'cancelled';

    $payer = $payment['payer'] ?? [];
    // L'API renvoie un code pays ISO3 (ex: "FRA") ; on convertit les cas les
    // plus fréquents pour rester cohérent avec les dons manuels (qui stockent
    // "France" en toutes lettres). Code inconnu -> on garde le code brut.
    $countryCodeMap = ['FRA' => 'France', 'BEL' => 'Belgique', 'CHE' => 'Suisse', 'LUX' => 'Luxembourg', 'CAN' => 'Canada'];
    $countryRaw = (string)($payer['country'] ?? '');
    $country = $countryCodeMap[strtoupper($countryRaw)] ?? ($countryRaw !== '' ? $countryRaw : null);

    $paymentMeansRaw = (string)($payment['paymentMeans'] ?? '');
    $paymentMethod = $paymentMeansRaw !== '' ? "{$paymentMeansRaw} (HelloAsso)" : 'HelloAsso';

    return [
        'source' => 'helloasso',
        'source_ref' => $id,
        'donation_date' => $date,
        'amount' => $amount,
        'currency' => 'EUR',
        'donor_first_name' => ($payer['firstName'] ?? null) ?: null,
        'donor_last_name' => ($payer['lastName'] ?? null) ?: null,
        'donor_email' => ($payer['email'] ?? null) ?: null,
        'donor_address' => ($payer['address'] ?? null) ?: null,
        'donor_postal_code' => ($payer['zipCode'] ?? null) ?: null,
        'donor_city' => ($payer['city'] ?? null) ?: null,
        'donor_country' => $country,
        'campaign' => ($payment['order']['formSlug'] ?? null) ?: null,
        'payment_method' => $paymentMethod,
        'status' => $status,
        'receipt_eligible' => ($status === 'paid' && (float)$amount > 0.0) ? 1 : 0,
        'raw_payload' => json_encode($payment, JSON_UNESCAPED_UNICODE),
    ];
}

/**
 * Lance une synchronisation HelloAsso complète (fetch + upsert) sur une
 * fenêtre glissante de $days jours, et met à jour donations_helloasso_sync_state.
 *
 * Factorisé ici pour être appelé aussi bien par le cron
 * (cron/sync_helloasso_donations.php, en CLI) que par le bouton "Synchroniser
 * maintenant" de l'interface (index.php, en HTTP) — même logique, mêmes
 * garanties d'idempotence (UPSERT sur source_ref), un seul endroit à
 * maintenir.
 *
 * $fromDate (optionnel, format "Y-m-d") permet de fixer une date de début
 * explicite — utile pour un backfill ponctuel remontant à la création de
 * l'association (ex: "2022-01-01") — plutôt que de dépendre uniquement
 * d'un nombre de jours glissants. Quand elle est fournie, elle prime sur
 * $days.
 *
 * @return array{
 *   ok: bool, dry_run: bool, from: string, to: string,
 *   payments_fetched: int, inserted: int, updated: int,
 *   skipped_not_donation: int, errors: int, last_error: ?string,
 *   fatal_error: ?string
 * }
 */
function helloasso_run_sync(PDO $pdo, int $days = 90, bool $dryRun = false, ?string $fromDate = null): array {
    if ($days < 1) $days = 90;

    $startedAt = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
    $to   = $startedAt;
    $from = $startedAt->modify("-{$days} days")->setTime(0, 0, 0);

    if ($fromDate !== null && $fromDate !== '') {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $fromDate, new DateTimeZone('Europe/Paris'));
        if ($parsed !== false) {
            $from = $parsed->setTime(0, 0, 0);
        }
    }

    $result = [
        'ok' => false,
        'dry_run' => $dryRun,
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'payments_fetched' => 0,
        'inserted' => 0,
        'updated' => 0,
        'skipped_not_donation' => 0,
        'errors' => 0,
        'last_error' => null,
        'fatal_error' => null,
        // Récapitulatif des campagnes HelloAsso rencontrées (slug => nom
        // résolu + nombre de paiements comptés comme dons) — sert à
        // identifier facilement le formSlug à ajouter à
        // HELLOASSO_EXCLUDE_FORM_SLUGS si un formulaire d'adhésion s'y
        // glisse malgré le filtre par type (voir donations/.env.example).
        'campaigns' => [],
    ];

    helloasso_ensure_state_table($pdo);

    if (!$dryRun) {
        $stmt = $pdo->prepare("UPDATE donations_helloasso_sync_state SET last_sync_started_at = :now WHERE id = 1");
        $stmt->execute(['now' => $startedAt->format('Y-m-d H:i:s')]);
    }

    try {
        $cfg = helloasso_config();
        $payments = helloasso_fetch_payments($pdo, $cfg, $from, $to);
        $result['payments_fetched'] = count($payments);

        $sqlUpsert = "
            INSERT INTO donations
              (source, source_ref, donation_date, amount, currency,
               donor_first_name, donor_last_name, donor_email,
               donor_address, donor_postal_code, donor_city, donor_country,
               campaign, payment_method, status, receipt_eligible, raw_payload)
            VALUES
              (:source, :source_ref, :donation_date, :amount, :currency,
               :first, :last, :email,
               :addr, :zip, :city, :country,
               :campaign, :payment_method, :status, :receipt_eligible, :raw_payload)
            ON DUPLICATE KEY UPDATE
              donation_date = VALUES(donation_date),
              amount = VALUES(amount),
              currency = VALUES(currency),
              donor_first_name = VALUES(donor_first_name),
              donor_last_name = VALUES(donor_last_name),
              donor_email = VALUES(donor_email),
              donor_address = VALUES(donor_address),
              donor_postal_code = VALUES(donor_postal_code),
              donor_city = VALUES(donor_city),
              donor_country = VALUES(donor_country),
              campaign = VALUES(campaign),
              payment_method = VALUES(payment_method),
              status = VALUES(status),
              receipt_eligible = VALUES(receipt_eligible),
              raw_payload = VALUES(raw_payload)
        ";
        $stmtIns = $pdo->prepare($sqlUpsert);

        if (!$dryRun) $pdo->beginTransaction();

        foreach ($payments as $payment) {
            try {
                // Filet de sécurité "côté front" (notre code, pas l'API) :
                // certains formulaires d'adhésion HelloAsso ne renvoient pas
                // order.formType et ont pourtant des lignes de détail typées
                // "Donation" par HelloAsso lui-même — le filtre par type ne
                // peut alors pas les distinguer d'un vrai don. On exclut donc
                // explicitement les formSlug listés dans
                // HELLOASSO_EXCLUDE_FORM_SLUGS (donations/.env), quel que
                // soit ce que l'API prétend par ailleurs.
                $rawFormSlug = mb_strtolower(trim((string)($payment['order']['formSlug'] ?? '')));
                if ($rawFormSlug !== '' && in_array($rawFormSlug, $cfg['exclude_form_slugs'], true)) {
                    $result['skipped_not_donation']++;
                    continue;
                }

                $mapped = helloasso_map_payment_to_donation($payment);
                if ($mapped === null) { $result['skipped_not_donation']++; continue; }

                // NB : la récupération du nom lisible de la campagne via
                // l'API "fiche formulaire" (GET .../forms/{formType}/{slug})
                // a été désactivée : cet endpoint répond 403 "This endpoint
                // is only accessible to authenticated users" avec un jeton
                // d'organisation (client_credentials) — il exige un jeton
                // utilisateur connecté, que l'on n'a pas en synchro
                // automatique. On garde donc le formSlug brut tel quel
                // (affiché "Formulaire n°X" côté interface pour les slugs
                // purement numériques — voir donations/index.php), et on
                // compte simplement les paiements par slug ci-dessous pour
                // t'aider à repérer l'identifiant à ajouter à
                // HELLOASSO_EXCLUDE_FORM_SLUGS si besoin.
                $formSlug = (string)($payment['order']['formSlug'] ?? '');
                if ($formSlug !== '') {
                    if (!isset($result['campaigns'][$formSlug])) {
                        $result['campaigns'][$formSlug] = ['count' => 0];
                    }
                    $result['campaigns'][$formSlug]['count']++;
                }

                $paymentDate = strtotime($mapped['donation_date']);
                if ($paymentDate === false || $paymentDate < $from->getTimestamp() || $paymentDate > $to->getTimestamp()) {
                    continue;
                }

                if ($dryRun) {
                    $result['inserted']++; // juste pour le compte affiché en simulation
                    continue;
                }

                $stmtIns->execute([
                    'source' => $mapped['source'],
                    'source_ref' => $mapped['source_ref'],
                    'donation_date' => $mapped['donation_date'],
                    'amount' => $mapped['amount'],
                    'currency' => $mapped['currency'],
                    'first' => $mapped['donor_first_name'],
                    'last' => $mapped['donor_last_name'],
                    'email' => $mapped['donor_email'],
                    'addr' => $mapped['donor_address'],
                    'zip' => $mapped['donor_postal_code'],
                    'city' => $mapped['donor_city'],
                    'country' => $mapped['donor_country'],
                    'campaign' => $mapped['campaign'],
                    'payment_method' => $mapped['payment_method'],
                    'status' => $mapped['status'],
                    'receipt_eligible' => $mapped['receipt_eligible'],
                    'raw_payload' => $mapped['raw_payload'],
                ]);
                $rc = $stmtIns->rowCount();
                if ($rc === 1) $result['inserted']++; else $result['updated']++;
            } catch (Throwable $e) {
                $result['errors']++;
                $result['last_error'] = $e->getMessage();
            }
        }

        if (!$dryRun) $pdo->commit();

        $result['ok'] = ($result['errors'] === 0);
        if (!$dryRun) {
            audit_log('donations', 'sync', 'donation', null, 'Synchronisation HelloAsso', [
                'paiements_lus' => $result['payments_fetched'],
                'crees'         => $result['inserted'],
                'mis_a_jour'    => $result['updated'],
                'ignores'       => $result['skipped_not_donation'],
                'erreurs'       => $result['errors'],
                'derniere_erreur' => $result['last_error'],
            ]);
        }

        if (!$dryRun) {
            $stmt = $pdo->prepare("
                UPDATE donations_helloasso_sync_state
                SET last_sync_finished_at = :now, last_sync_ok = :ok,
                    last_sync_inserted = :ins, last_sync_updated = :upd, last_sync_errors = :err,
                    last_error = :lasterr
                WHERE id = 1
            ");
            $stmt->execute([
                'now' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s'),
                'ok' => $result['ok'] ? 1 : 0,
                'ins' => $result['inserted'],
                'upd' => $result['updated'],
                'err' => $result['errors'],
                'lasterr' => $result['last_error'],
            ]);
        }
    } catch (Throwable $e) {
        if (!$dryRun && $pdo->inTransaction()) $pdo->rollBack();
        $result['fatal_error'] = $e->getMessage();

        if (!$dryRun) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE donations_helloasso_sync_state
                    SET last_sync_finished_at = :now, last_sync_ok = 0, last_error = :err
                    WHERE id = 1
                ");
                $stmt->execute([
                    'now' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s'),
                    'err' => $e->getMessage(),
                ]);
            } catch (Throwable $ignored) {}
        }
    }

    return $result;
}
