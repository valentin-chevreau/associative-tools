-- Renomme la table d'état de la synchro HelloAsso pour suivre l'extraction
-- du module hors de planning (planning_helloasso_sync_state -> donations_helloasso_sync_state).
--
-- Si tu n'as pas encore activé la synchronisation automatique HelloAsso
-- (pas de .env HelloAsso, tâche planifiée jamais lancée), cette table
-- n'existe probablement pas encore chez toi : dans ce cas, IGNORE ce
-- fichier — la table "donations_helloasso_sync_state" sera créée
-- automatiquement, avec le bon nom, au premier lancement de
-- cron/sync_helloasso_donations.php.
--
-- Vérifie d'abord si elle existe :
--   SHOW TABLES LIKE 'planning_helloasso_sync_state';
-- Si oui, exécute :

RENAME TABLE planning_helloasso_sync_state TO donations_helloasso_sync_state;
