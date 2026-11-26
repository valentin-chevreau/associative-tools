-- Logistique : demandes d'étiquettes manquantes, déposées depuis la page publique des étiquettes
-- (logistique/labels/public.php) et traitées par les membres (logistique/labels/requests.php).
-- Idempotent : peut être rejoué sans effet.
--
--   status       'pending' (à traiter) | 'done' (étiquette créée ou déjà existante) | 'rejected' (refusée)
--   name_key     libellé normalisé (minuscules, espaces simples) : regroupe les demandes identiques
--   votes        nombre de personnes ayant demandé la même étiquette
--   ip_hash      empreinte de l'adresse IP (jamais l'IP en clair) : limite anti-abus
--   category_id  catégorie créée à partir de la demande
CREATE TABLE IF NOT EXISTS logistique_label_requests (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(160) NOT NULL,
  name_key     VARCHAR(160) NOT NULL,
  details      TEXT NULL,
  votes        INT UNSIGNED NOT NULL DEFAULT 1,
  status       VARCHAR(12) NOT NULL DEFAULT 'pending',
  ip_hash      CHAR(64) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  handled_at   DATETIME NULL,
  handled_by   VARCHAR(120) NULL,
  handled_note VARCHAR(255) NULL,
  category_id  INT UNSIGNED NULL,
  KEY idx_llr_status (status, created_at),
  KEY idx_llr_ip (ip_hash, created_at),
  KEY idx_llr_key (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
