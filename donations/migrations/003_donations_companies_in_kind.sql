-- Dons d'entreprises, dons en nature et mécénat de compétences (reçus fiscaux).
--
-- À exécuter UNE SEULE FOIS, sur la base de prod ET de préprod (ou via
-- admin/migrations.php) : une seconde exécution échouerait (colonnes déjà présentes).
-- Les colonnes sont ajoutées avec des valeurs par défaut qui reproduisent le
-- comportement actuel : tous les dons existants restent des « dons en numéraire
-- de particulier », sans aucun changement.
--
-- Compatible avec les deux structures rencontrées :
--   - « donations » est une vraie table  -> elle est modifiée directement ;
--   - « donations » est une vue de compatibilité sur « planning_donations »
--     (cas de la préprod) -> la table réelle est modifiée, puis la vue est recréée
--     avec les nouvelles colonnes.
--
--   donor_type          'individual' (particulier) | 'company' (entreprise / organisation)
--   donation_kind       'cash' (numéraire) | 'in_kind' (don en nature) | 'services' (prestation, mécénat de compétences)
--   in_kind_description description exhaustive des biens / services (nature, quantités)
--   valuation_basis     base de valorisation (clé, voir donations/includes/receipt.php)
--   valuation_source    qui a communiqué la valorisation, et comment (attestation, facture…)
--   no_counterpart      1 = le donateur n'a reçu aucune contrepartie directe (sinon : parrainage, pas de reçu)
--   proof_path          justificatif conservé (uploads/donations/…)
--   receipt_group_id    reçu groupé : id du don « maître » qui porte le reçu commun

SET @don_target = (
  SELECT IF(table_type = 'VIEW', 'planning_donations', 'donations')
  FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'donations'
  LIMIT 1
);

SET @don_alter = CONCAT('ALTER TABLE `', @don_target, '`
  ADD COLUMN donor_type          VARCHAR(16)  NOT NULL DEFAULT ''individual'' AFTER donor_country,
  ADD COLUMN donor_company_name  VARCHAR(255) NULL AFTER donor_type,
  ADD COLUMN donor_siren         VARCHAR(14)  NULL AFTER donor_company_name,
  ADD COLUMN donation_kind       VARCHAR(16)  NOT NULL DEFAULT ''cash'' AFTER donor_siren,
  ADD COLUMN in_kind_description TEXT         NULL AFTER donation_kind,
  ADD COLUMN valuation_basis     VARCHAR(32)  NULL AFTER in_kind_description,
  ADD COLUMN valuation_source    VARCHAR(255) NULL AFTER valuation_basis,
  ADD COLUMN no_counterpart      TINYINT(1)   NOT NULL DEFAULT 0 AFTER valuation_source,
  ADD COLUMN proof_path          VARCHAR(255) NULL AFTER no_counterpart,
  ADD COLUMN receipt_group_id    INT UNSIGNED NULL AFTER receipt_pdf_path,
  ADD KEY idx_don_receipt_group (receipt_group_id)');
PREPARE don_stmt FROM @don_alter;
EXECUTE don_stmt;
DEALLOCATE PREPARE don_stmt;

-- Vue de compatibilité : recréée uniquement si « donations » en est une.
SET @don_is_view = (
  SELECT COUNT(*) FROM information_schema.views
  WHERE table_schema = DATABASE() AND table_name = 'donations'
);
SET @don_view = IF(@don_is_view > 0,
  'CREATE OR REPLACE VIEW donations AS SELECT
     id, source, source_ref, donation_date, amount, currency,
     donor_first_name, donor_last_name, donor_email,
     donor_address, donor_postal_code, donor_city, donor_country,
     donor_type, donor_company_name, donor_siren, donation_kind,
     in_kind_description, valuation_basis, valuation_source, no_counterpart, proof_path,
     campaign, payment_method, status, receipt_eligible, receipt_number, receipt_date,
     receipt_pdf_path, receipt_group_id, raw_payload, created_at, updated_at
   FROM planning_donations',
  'DO 0');
PREPARE don_stmt FROM @don_view;
EXECUTE don_stmt;
DEALLOCATE PREPARE don_stmt;
