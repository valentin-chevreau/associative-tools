-- Suite Touraine-Ukraine (PREPROD) - Migration v1 (ULTIMATE: CONVERT + COLLATE)
-- Cible: touraineukraine_preprod_suite
-- Sources: touraineukraine_preprod_planning, touraineukraine_preprod_outilcaisse, touraineukraine_preprod_logistique
-- Règles:
-- - événements: Planning = source de vérité. Caisse se rattache via evenements.planning_event_id.
-- - auth: codes via .env (pas en DB)
-- - personnes: email > téléphone > (nom+prénom)

USE `touraineukraine_preprod_suite`;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = 'utf8mb4_unicode_ci';

SET FOREIGN_KEY_CHECKS=0;

-- =========================
-- 1) PEOPLE: bénévoles depuis Planning.volunteers
-- =========================
INSERT INTO core_people (type, first_name, last_name, email, phone, created_at)
SELECT
  'benevole' AS type,
  v.first_name,
  v.last_name,
  NULLIF(v.email,'') AS email,
  NULLIF(v.phone,'') AS phone,
  COALESCE(v.created_at, CURRENT_TIMESTAMP)
FROM `touraineukraine_preprod_planning`.volunteers v;

-- Lier legacy volunteers -> core_people (match sur email, sinon phone, sinon nom/prénom)
INSERT IGNORE INTO core_legacy_links (entity, source_db, source_table, legacy_id, core_id)
SELECT
  'person', 'touraineukraine_preprod_planning', 'volunteers', v.id,
  p.id
FROM `touraineukraine_preprod_planning`.volunteers v
JOIN core_people p ON p.type='benevole'
 AND (
      (p.email IS NOT NULL AND p.email =
        (CONVERT(NULLIF(v.email,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
      )
   OR (p.email IS NULL AND p.phone IS NOT NULL AND p.phone =
        (CONVERT(NULLIF(v.phone,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
      )
   OR (p.email IS NULL AND p.phone IS NULL
       AND p.last_name  = (CONVERT(v.last_name  USING utf8mb4) COLLATE utf8mb4_unicode_ci)
       AND p.first_name = (CONVERT(v.first_name USING utf8mb4) COLLATE utf8mb4_unicode_ci)
   )
 );

-- =========================
-- 2) PEOPLE: familles depuis Logistique.families (réutilise personne existante si match)
-- =========================
INSERT INTO core_people (type, first_name, last_name, email, phone, city, notes, created_at)
SELECT
  'famille',
  NULLIF(f.firstname,''),
  NULLIF(f.lastname,''),
  NULLIF(f.email,''),
  NULLIF(f.phone,''),
  NULLIF(f.city,''),
  f.notes,
  COALESCE(f.created_at, CURRENT_TIMESTAMP)
FROM `touraineukraine_preprod_logistique`.families f
WHERE NOT EXISTS (
  SELECT 1
  FROM core_people p
  WHERE (p.email IS NOT NULL AND p.email =
        (CONVERT(NULLIF(f.email,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
        )
     OR (p.email IS NULL AND p.phone IS NOT NULL AND p.phone =
        (CONVERT(NULLIF(f.phone,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
        )
     OR (p.email IS NULL AND p.phone IS NULL
         AND p.last_name  = (CONVERT(NULLIF(f.lastname,'')  USING utf8mb4) COLLATE utf8mb4_unicode_ci)
         AND p.first_name = (CONVERT(NULLIF(f.firstname,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
     )
);

-- Lier legacy families -> core_people (match mêmes règles)
INSERT IGNORE INTO core_legacy_links (entity, source_db, source_table, legacy_id, core_id)
SELECT
  'person', 'touraineukraine_preprod_logistique', 'families', f.id,
  p.id
FROM `touraineukraine_preprod_logistique`.families f
JOIN core_people p ON p.type IN ('famille','benevole','donateur')
 AND (
      (p.email IS NOT NULL AND p.email =
        (CONVERT(NULLIF(f.email,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
      )
   OR (p.email IS NULL AND p.phone IS NOT NULL AND p.phone =
        (CONVERT(NULLIF(f.phone,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
      )
   OR (p.email IS NULL AND p.phone IS NULL
       AND p.last_name  = (CONVERT(NULLIF(f.lastname,'')  USING utf8mb4) COLLATE utf8mb4_unicode_ci)
       AND p.first_name = (CONVERT(NULLIF(f.firstname,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
   )
 );

-- =========================
-- 3) PEOPLE: donateurs depuis Planning.donations (par email si possible)
-- =========================
INSERT INTO core_people (type, first_name, last_name, email, created_at)
SELECT
  'donateur',
  NULLIF(d.donor_first_name,''),
  NULLIF(d.donor_last_name,''),
  NULLIF(d.donor_email,''),
  CURRENT_TIMESTAMP
FROM `touraineukraine_preprod_planning`.donations d
WHERE NULLIF(d.donor_email,'') IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM core_people p
    WHERE p.email IS NOT NULL
      AND p.email = (CONVERT(NULLIF(d.donor_email,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
  );

INSERT IGNORE INTO core_legacy_links (entity, source_db, source_table, legacy_id, core_id)
SELECT
  'person', 'touraineukraine_preprod_planning', 'donations_donor', d.id,
  p.id
FROM `touraineukraine_preprod_planning`.donations d
JOIN core_people p
  ON p.email IS NOT NULL
 AND p.email = (CONVERT(NULLIF(d.donor_email,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci);

-- =========================
-- 4) EVENTS: depuis Planning.events (source de vérité)
-- =========================
INSERT INTO core_events (title, description, event_type, start_datetime, end_datetime, is_cancelled, created_at)
SELECT
  e.title,
  e.description,
  e.event_type,
  e.start_datetime,
  e.end_datetime,
  COALESCE(e.is_cancelled,0),
  COALESCE(e.created_at, CURRENT_TIMESTAMP)
FROM `touraineukraine_preprod_planning`.events e;

-- Link planning.events -> core_events
INSERT IGNORE INTO core_legacy_links (entity, source_db, source_table, legacy_id, core_id)
SELECT
  'event', 'touraineukraine_preprod_planning', 'events', e.id, ce.id
FROM `touraineukraine_preprod_planning`.events e
JOIN core_events ce
  ON ce.title = (CONVERT(e.title USING utf8mb4) COLLATE utf8mb4_unicode_ci)
 AND ce.start_datetime = e.start_datetime
 AND ce.end_datetime = e.end_datetime;

-- =========================
-- 5) CAISSE: liaison evenements -> core_events via planning_event_id
-- =========================
DROP TEMPORARY TABLE IF EXISTS tmp_caisse_event_map;
CREATE TEMPORARY TABLE tmp_caisse_event_map AS
SELECT
  ev.id AS caisse_evenement_id,
  ev.planning_event_id,
  (SELECT core_id
     FROM core_legacy_links l
    WHERE l.entity='event'
      AND l.source_db='touraineukraine_preprod_planning'
      AND l.source_table='events'
      AND l.legacy_id=ev.planning_event_id
    LIMIT 1
  ) AS core_event_id
FROM `touraineukraine_preprod_outilcaisse`.evenements ev;

-- =========================
-- 6) CAISSE: bénévoles (noms seuls) -> core_people type benevole (si pas match)
-- =========================
INSERT INTO core_people (type, last_name, created_at)
SELECT 'benevole', NULLIF(b.nom,''), CURRENT_TIMESTAMP
FROM `touraineukraine_preprod_outilcaisse`.benevoles b
WHERE NULLIF(b.nom,'') IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM core_people p
    WHERE p.type='benevole'
      AND p.last_name = (CONVERT(NULLIF(b.nom,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
  );

INSERT IGNORE INTO core_legacy_links (entity, source_db, source_table, legacy_id, core_id)
SELECT
  'person', 'touraineukraine_preprod_outilcaisse', 'benevoles', b.id, p.id
FROM `touraineukraine_preprod_outilcaisse`.benevoles b
JOIN core_people p
  ON p.type='benevole'
 AND p.last_name = (CONVERT(NULLIF(b.nom,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci);

-- =========================
-- 7) CAISSE: ventes (event_id via tmp map, person_id via links)
-- =========================
INSERT INTO caisse_sales (
  legacy_sale_id, event_id, person_id, payment_method,
  total, sous_total, total_brut,
  remise_panier_type, remise_panier_valeur, remise_panier_montant, remise_total,
  date_vente
)
SELECT
  v.id,
  m.core_event_id,
  (SELECT core_id
     FROM core_legacy_links l
    WHERE l.entity='person'
      AND l.source_db='touraineukraine_preprod_outilcaisse'
      AND l.source_table='benevoles'
      AND l.legacy_id=v.benevole_id
    LIMIT 1
  ) AS person_id,
  v.paiement,
  v.total, v.sous_total, v.total_brut,
  v.remise_panier_type, v.remise_panier_valeur, v.remise_panier_montant, v.remise_total,
  v.date_vente
FROM `touraineukraine_preprod_outilcaisse`.ventes v
LEFT JOIN tmp_caisse_event_map m ON m.caisse_evenement_id = v.evenement_id;

-- =========================
-- 8) CAISSE: paiements (rattachés aux ventes migrées)
-- =========================
INSERT INTO caisse_sale_payments (sale_id, method, amount)
SELECT
  s.id AS sale_id,
  p.methode,
  p.montant
FROM `touraineukraine_preprod_outilcaisse`.vente_paiements p
JOIN caisse_sales s ON s.legacy_sale_id = p.vente_id;

-- =========================
-- 9) DONATIONS: migration depuis Planning.donations (donor_person_id via email)
-- =========================
INSERT INTO donations (
  legacy_donation_id, source, source_ref, donation_date, amount, currency,
  donor_person_id, donor_first_name, donor_last_name, donor_email,
  donor_address, donor_postal_code, donor_city, donor_country,
  campaign, payment_method, status,
  receipt_eligible, receipt_number, receipt_date, receipt_pdf_path,
  created_at, updated_at
)
SELECT
  d.id, d.source, d.source_ref, d.donation_date, d.amount, d.currency,
  (SELECT p.id
     FROM core_people p
    WHERE p.email IS NOT NULL
      AND p.email = (CONVERT(NULLIF(d.donor_email,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
    LIMIT 1
  ),
  d.donor_first_name, d.donor_last_name, d.donor_email,
  d.donor_address, d.donor_postal_code, d.donor_city, d.donor_country,
  d.campaign, d.payment_method, d.status,
  d.receipt_eligible, d.receipt_number, d.receipt_date, d.receipt_pdf_path,
  d.created_at, d.updated_at
FROM `touraineukraine_preprod_planning`.donations d;

-- =========================
-- 10) LOGISTIQUE: familles (détails) -> logistique_families (person_id via link families)
-- =========================
INSERT INTO logistique_families (legacy_family_id, person_id, public_ref, housing_notes, status, created_at, updated_at)
SELECT
  f.id,
  (SELECT core_id
     FROM core_legacy_links l
    WHERE l.entity='person'
      AND l.source_db='touraineukraine_preprod_logistique'
      AND l.source_table='families'
      AND l.legacy_id=f.id
    LIMIT 1
  ) AS person_id,
  f.public_ref,
  f.housing_notes,
  f.status,
  f.created_at,
  f.updated_at
FROM `touraineukraine_preprod_logistique`.families f;

-- =========================
-- 11) LOGISTIQUE: convois (migration minimale)
-- =========================
INSERT INTO logistique_convoys (legacy_convoy_id, name, status, departure_date, notes, created_at)
SELECT
  c.id,
  c.name,
  c.status,
  c.departure_date,
  c.notes,
  COALESCE(c.created_at, CURRENT_TIMESTAMP)
FROM `touraineukraine_preprod_logistique`.convoys c;

SET FOREIGN_KEY_CHECKS=1;