-- Messages automatiques (rappel la veille d'un créneau, bienvenue aux nouveaux bénévoles).
-- Les textes par défaut vivent dans le code : cette table ne garde que les réglages modifiés.

CREATE TABLE IF NOT EXISTS mailing_automations (
  type VARCHAR(30) NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  subject VARCHAR(190) NULL,
  blocks MEDIUMTEXT NULL,
  site_url VARCHAR(255) NOT NULL DEFAULT '',
  enabled_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journal des envois automatiques : garantit qu'un message n'est jamais envoyé deux fois pour le même fait
-- (même inscription à un créneau, même nouveau bénévole), et porte le jeton de désinscription du message.
CREATE TABLE IF NOT EXISTS mailing_auto_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(30) NOT NULL,
  ref VARCHAR(80) NOT NULL,
  email VARCHAR(190) NOT NULL,
  token CHAR(32) NULL,
  status ENUM('sent','retry','failed','skipped') NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  UNIQUE KEY uq_type_ref (type, ref),
  UNIQUE KEY uq_token (token),
  KEY idx_type_sent (type, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
