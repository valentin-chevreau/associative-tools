-- Alertes e-mail de connexion (admin/login_alerts.php, shared/login_alerts.php).
-- Une ligne = un utilisateur dont chaque connexion déclenche un e-mail aux super admins.
-- Idempotent : peut être rejoué sans effet.

CREATE TABLE IF NOT EXISTS suite_login_alerts (
    user_id     INT NOT NULL PRIMARY KEY,
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
