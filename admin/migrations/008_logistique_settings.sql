-- Logistique : petits réglages modifiables depuis l'interface (clé / valeur).
-- Premier usage : jeton d'accès de la page publique des étiquettes (clé 'public_labels_token'),
-- régénérable depuis Étiquettes → Demandes. Idempotent.
--   Pas de ligne            -> on retombe sur PUBLIC_LABELS_TOKEN du .env (s'il existe)
--   valeur vide             -> page publique volontairement ouverte à tous
--   valeur non vide         -> page accessible uniquement avec ?k=<valeur>
CREATE TABLE IF NOT EXISTS logistique_settings (
  name       VARCHAR(64) NOT NULL PRIMARY KEY,
  value      TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(120) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
