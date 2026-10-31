-- Logistique : drapeau « médical » sur les catégories (porté par la racine, hérité par les sous-catégories).
-- Les étiquettes des catégories concernées reçoivent automatiquement un repère rouge. Idempotent.
SET @sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logistique_categories' AND COLUMN_NAME = 'is_medical'),
  'DO 0',
  'ALTER TABLE logistique_categories ADD COLUMN is_medical TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
