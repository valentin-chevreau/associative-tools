-- Logistique : journal minimal des planches d'étiquettes envoyées par e-mail depuis la page publique.
-- Sert uniquement aux limites anti-abus (par visiteur et par adresse). Aucune adresse n'est stockée en clair :
-- seulement des empreintes. Les lignes de plus de 2 jours sont supprimées automatiquement. Idempotent.
CREATE TABLE IF NOT EXISTS logistique_label_mail_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip_hash    CHAR(64) NOT NULL,
  to_hash    CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_llml_ip (ip_hash, created_at),
  KEY idx_llml_to (to_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
