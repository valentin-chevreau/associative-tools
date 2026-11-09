-- Dernier passage de la tâche planifiée (mailing/cron/send_queue.php), pour la page « File d'envoi ».
CREATE TABLE IF NOT EXISTS mailing_cron_state (
  name VARCHAR(40) NOT NULL PRIMARY KEY,
  last_run_at DATETIME NOT NULL,
  last_sent INT UNSIGNED NOT NULL DEFAULT 0,
  last_failed INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
