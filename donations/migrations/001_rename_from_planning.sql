-- Extraction du module "donations" hors de planning (base unifiée).
-- RENAME TABLE est atomique et ne déplace aucune ligne physiquement : zéro
-- risque de perte ou de doublon sur l'historique déjà importé (CSV +
-- éventuels dons manuels déjà enregistrés).
--
-- À exécuter UNE SEULE FOIS, sur la base de prod ET de préprod, après avoir
-- déployé le nouveau dossier donations/ (qui lit désormais la table
-- "donations" au lieu de "planning_donations").

RENAME TABLE planning_donations TO donations;
