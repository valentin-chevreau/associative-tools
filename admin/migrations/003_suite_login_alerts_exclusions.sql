-- Alertes de connexion : tous les utilisateurs sont surveillés par défaut ;
-- cette table liste uniquement les EXCEPTIONS (utilisateurs dont les connexions ne déclenchent pas d'e-mail).
CREATE TABLE IF NOT EXISTS suite_login_alert_exclusions (
  user_id INT NOT NULL PRIMARY KEY,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- L'ancienne liste « utilisateurs surveillés » (migration 002) n'a plus de sens.
DROP TABLE IF EXISTS suite_login_alerts;
