-- Module Mailing : newsletters ponctuelles envoyées par le serveur de messagerie de la suite.
-- Les destinataires ne sont pas stockés tant que la campagne n'est pas lancée : les listes sont calculées
-- à partir des données existantes (planning, adhésions, dons, prospection) au moment de l'envoi.

CREATE TABLE IF NOT EXISTS mailing_campaigns (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subject VARCHAR(190) NOT NULL,
  preheader VARCHAR(190) NOT NULL DEFAULT '',
  title VARCHAR(190) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  button_label VARCHAR(60) NOT NULL DEFAULT '',
  button_url VARCHAR(500) NOT NULL DEFAULT '',
  audiences VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('draft','queued','sending','sent','cancelled') NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  site_url VARCHAR(255) NOT NULL DEFAULT '',
  recipients_total INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  KEY idx_status_sched (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mailing_recipients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(190) NOT NULL DEFAULT '',
  token CHAR(32) NOT NULL,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  UNIQUE KEY uq_campaign_email (campaign_id, email),
  UNIQUE KEY uq_token (token),
  KEY idx_campaign_status (campaign_id, status),
  CONSTRAINT fk_mailing_recipient_campaign FOREIGN KEY (campaign_id) REFERENCES mailing_campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Personnes ajoutées à la main à une liste (en plus de celles qui viennent des données existantes).
CREATE TABLE IF NOT EXISTS mailing_manual_contacts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  list_key VARCHAR(20) NOT NULL,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(190) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_list_email (list_key, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Personnes retirées d'une liste donnée (elles restent dans les autres listes et gardent leur choix de newsletter).
CREATE TABLE IF NOT EXISTS mailing_exclusions (
  list_key VARCHAR(20) NOT NULL,
  email VARCHAR(190) NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (list_key, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Liste globale des désinscrits (choix de la personne, via le lien de l'e-mail ou saisi par un admin sur sa demande) :
-- aucune newsletter (ni message automatique) ne leur est plus envoyée tant qu'elle n'est pas réactivée.
CREATE TABLE IF NOT EXISTS mailing_suppressions (
  email VARCHAR(190) NOT NULL PRIMARY KEY,
  campaign_id INT UNSIGNED NULL,
  source ENUM('link','admin') NOT NULL DEFAULT 'link',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
