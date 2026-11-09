-- Messages automatiques personnalisables : une règle = un déclencheur + un délai + une heure + des filtres + un message.
-- Les deux messages historiques (rappel la veille, bienvenue) sont repris comme premières règles.

CREATE TABLE IF NOT EXISTS mailing_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  trigger_type VARCHAR(20) NOT NULL,
  delay_days TINYINT UNSIGNED NOT NULL DEFAULT 0,
  send_hour TINYINT UNSIGNED NOT NULL DEFAULT 18,
  filters TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  subject VARCHAR(190) NULL,
  blocks MEDIUMTEXT NULL,
  site_url VARCHAR(255) NOT NULL DEFAULT '',
  enabled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mailing_rules (name, trigger_type, delay_days, send_hour, enabled, subject, blocks, site_url, enabled_at)
SELECT 'Rappel la veille d''un créneau', 'before_slot', 1, 18, COALESCE(a.enabled, 0), a.subject, a.blocks, COALESCE(a.site_url, ''), a.enabled_at
FROM (SELECT 1 AS x) d LEFT JOIN mailing_automations a ON a.type = 'reminder'
WHERE NOT EXISTS (SELECT 1 FROM mailing_rules WHERE trigger_type = 'before_slot' AND name = 'Rappel la veille d''un créneau');

INSERT INTO mailing_rules (name, trigger_type, delay_days, send_hour, enabled, subject, blocks, site_url, enabled_at)
SELECT 'Bienvenue aux nouveaux bénévoles', 'new_volunteer', 0, 0, COALESCE(a.enabled, 0), a.subject, a.blocks, COALESCE(a.site_url, ''), a.enabled_at
FROM (SELECT 1 AS x) d LEFT JOIN mailing_automations a ON a.type = 'welcome'
WHERE NOT EXISTS (SELECT 1 FROM mailing_rules WHERE trigger_type = 'new_volunteer' AND name = 'Bienvenue aux nouveaux bénévoles');

-- Le journal d'envoi passe de « reminder » / « welcome » à « r<id de la règle> » pour qu'aucun message déjà envoyé ne soit renvoyé.
UPDATE mailing_auto_log l JOIN mailing_rules r ON r.trigger_type = 'before_slot' AND r.name = 'Rappel la veille d''un créneau'
  SET l.type = CONCAT('r', r.id) WHERE l.type = 'reminder';
UPDATE mailing_auto_log l JOIN mailing_rules r ON r.trigger_type = 'new_volunteer' AND r.name = 'Bienvenue aux nouveaux bénévoles'
  SET l.type = CONCAT('r', r.id) WHERE l.type = 'welcome';
