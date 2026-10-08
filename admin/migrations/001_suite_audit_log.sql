-- Journal d'audit de la suite (shared/audit.php).
-- Versionne la table (jusqu'ici créée à la main), élargit quelques colonnes pour les
-- nouvelles actions, et ajoute les index utiles aux filtres de admin/audit_log.php.
-- Idempotent : peut être rejoué sans effet (MariaDB).

CREATE TABLE IF NOT EXISTS suite_audit_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    volunteer_id  INT NULL,
    actor_name    VARCHAR(150) NOT NULL,
    actor_role    VARCHAR(30)  NULL,
    module        VARCHAR(40)  NOT NULL,
    action        VARCHAR(60)  NOT NULL,
    entity_type   VARCHAR(60)  NULL,
    entity_id     INT NULL,
    entity_label  VARCHAR(255) NULL,
    details_json  MEDIUMTEXT NULL,
    ip_address    VARCHAR(45)  NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tables déjà existantes : colonnes assez larges pour les nouvelles valeurs (rôle « system », actions longues…).
ALTER TABLE suite_audit_log
    MODIFY actor_name   VARCHAR(150) NOT NULL,
    MODIFY actor_role   VARCHAR(30)  NULL,
    MODIFY module       VARCHAR(40)  NOT NULL,
    MODIFY action       VARCHAR(60)  NOT NULL,
    MODIFY entity_type  VARCHAR(60)  NULL,
    MODIFY entity_label VARCHAR(255) NULL;

CREATE INDEX IF NOT EXISTS idx_audit_created  ON suite_audit_log (created_at);
CREATE INDEX IF NOT EXISTS idx_audit_module   ON suite_audit_log (module, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_actor    ON suite_audit_log (volunteer_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_entity   ON suite_audit_log (entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_action   ON suite_audit_log (action, created_at);
