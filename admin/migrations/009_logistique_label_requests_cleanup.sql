-- Logistique : supprime les colonnes inutilisées de logistique_label_requests (parent_id, requester, contact),
-- présentes uniquement si la migration 007 a été exécutée dans sa première version.
-- Sans effet sur une base où elles n'existent pas. Idempotent.
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logistique_label_requests' AND COLUMN_NAME = 'parent_id');
SET @sql = IF(@c > 0, 'ALTER TABLE logistique_label_requests DROP COLUMN parent_id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logistique_label_requests' AND COLUMN_NAME = 'requester');
SET @sql = IF(@c > 0, 'ALTER TABLE logistique_label_requests DROP COLUMN requester', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logistique_label_requests' AND COLUMN_NAME = 'contact');
SET @sql = IF(@c > 0, 'ALTER TABLE logistique_label_requests DROP COLUMN contact', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
