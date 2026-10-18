-- Caisse : ventes à 0 € (remise 100 %, aucun paiement) → caisse_ventes.paiement reçoit la valeur 'Gratuit'.
-- Idempotent : n'ajoute la valeur que si elle manque, en conservant la définition actuelle de la colonne
-- (valeurs existantes, NULL / NOT NULL, valeur par défaut).
SET @def = (
  SELECT CONCAT(
           LEFT(COLUMN_TYPE, CHAR_LENGTH(COLUMN_TYPE) - 1), ',''Gratuit'')',
           IF(IS_NULLABLE = 'NO', ' NOT NULL', ' NULL'),
           CASE
             WHEN COLUMN_DEFAULT IS NULL THEN ''
             WHEN COLUMN_DEFAULT = 'NULL' THEN ' DEFAULT NULL'
             WHEN COLUMN_DEFAULT LIKE '''%' THEN CONCAT(' DEFAULT ', COLUMN_DEFAULT)
             ELSE CONCAT(' DEFAULT ', QUOTE(COLUMN_DEFAULT))
           END
         )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'caisse_ventes'
    AND COLUMN_NAME = 'paiement'
    AND COLUMN_TYPE LIKE 'enum(%'
    AND COLUMN_TYPE NOT LIKE '%''Gratuit''%'
);
SET @sql = IF(@def IS NULL, 'DO 0', CONCAT('ALTER TABLE caisse_ventes MODIFY COLUMN paiement ', @def));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
