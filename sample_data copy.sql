-- ============================================
-- DONNÉES D'EXEMPLE
-- Module Adhésions & Subventions
-- Touraine-Ukraine
-- ============================================

-- Insertion d'adhérents exemples
INSERT INTO adherents (numero, nom, prenom, email, telephone, adresse, code_postal, ville, date_premiere_adhesion, origine, competences, notes, actif) VALUES
('ADH-2024-001', 'Dupont', 'Marie', 'marie.dupont@email.com', '0612345678', '15 rue de la Paix', '37000', 'Tours', '2024-01-15', 'bouche-a-oreille', 'Traduction français-ukrainien', 'Très impliquée dans les actions', 1),
('ADH-2024-002', 'Martin', 'Pierre', 'pierre.martin@email.com', '0623456789', '28 avenue de Grammont', '37000', 'Tours', '2024-02-10', 'reseaux-sociaux', 'Logistique, transport', NULL, 1),
('ADH-2024-003', 'Bernard', 'Sophie', 'sophie.bernard@email.com', '0634567890', '5 place Plumereau', '37000', 'Tours', '2024-03-05', 'evenement', 'Communication', 'Participante événement mars 2024', 1),
('ADH-2023-015', 'Petit', 'Jean', 'jean.petit@email.com', '0645678901', '42 rue Nationale', '37000', 'Tours', '2023-05-20', 'presse', NULL, 'Adhérent depuis 2023', 1),
('ADH-2024-004', 'Dubois', 'Claire', 'claire.dubois@email.com', '0656789012', '10 rue Colbert', '37100', 'Tours', '2024-04-12', 'bouche-a-oreille', 'Enseignante, aide scolaire', NULL, 1);

-- Insertion d'adhésions pour 2024
INSERT INTO adhesions (adherent_id, annee, date_adhesion, montant, mode_paiement, numero_transaction, statut) VALUES
(1, 2024, '2024-01-15', 20.00, 'virement', 'VIR-20240115-001', 'valide'),
(2, 2024, '2024-02-10', 20.00, 'cheque', 'CHQ-1234567', 'valide'),
(3, 2024, '2024-03-05', 25.00, 'especes', NULL, 'valide'),
(5, 2024, '2024-04-12', 20.00, 'cb', 'CB-20240412-789', 'valide');

-- Insertion d'adhésions pour 2023 (pour l'historique)
INSERT INTO adhesions (adherent_id, annee, date_adhesion, montant, mode_paiement, statut) VALUES
(1, 2023, '2023-01-20', 15.00, 'cheque', 'valide'),
(4, 2023, '2023-05-20', 20.00, 'virement', 'valide');

-- Insertion de subventions exemples
INSERT INTO subventions (numero, organisme, type_subvention, projet_concerne, annee, montant_demande, montant_accorde, statut, date_depot, date_limite, date_reponse, contact_referent, contact_email, notes) VALUES
('SUB-2024-001', 'Conseil Départemental 37', 'fonctionnement', NULL, 2024, 5000.00, 4000.00, 'versee', '2024-01-15', '2024-02-28', '2024-03-20', 'Mme Durand', 'associations@cd37.fr', 'Subvention fonctionnement général'),
('SUB-2024-002', 'Région Centre-Val de Loire', 'projet', 'Accueil familles réfugiées', 2024, 15000.00, 12000.00, 'accordee', '2024-02-01', '2024-03-15', '2024-04-10', 'M. Leroy', 'subventions@regioncentre.fr', 'Projet d\'accueil et d\'intégration'),
('SUB-2024-003', 'Ville de Tours', 'projet', 'Collecte humanitaire', 2024, 3000.00, NULL, 'en_cours', '2024-03-10', '2024-04-30', NULL, 'Mme Lambert', 'vie.associative@tours.fr', 'En attente de réponse'),
('SUB-2024-004', 'Fondation de France', 'equipement', 'Matériel informatique', 2024, 8000.00, NULL, 'deposee', '2024-04-05', '2024-05-31', NULL, 'Service Associations', 'contact@fdf.fr', NULL),
('SUB-2025-001', 'Conseil Départemental 37', 'fonctionnement', NULL, 2025, 5500.00, NULL, 'brouillon', NULL, '2025-02-28', NULL, 'Mme Durand', 'associations@cd37.fr', 'Dossier en préparation');

-- Insertion d'historique pour les subventions
INSERT INTO subventions_historique (subvention_id, action, auteur, commentaire) VALUES
(1, 'creation', 'Système', 'Création de la demande'),
(1, 'changement_statut', 'Trésorier', 'Changement de statut : deposee'),
(1, 'subvention_accordee', 'Système', 'Subvention accordée : 4000.00 €'),
(1, 'versement', 'Système', 'Subvention versée le 15/05/2024'),
(2, 'creation', 'Système', 'Création de la demande'),
(2, 'changement_statut', 'Trésorier', 'Changement de statut : deposee'),
(2, 'subvention_accordee', 'Système', 'Subvention accordée : 12000.00 €. Décision du conseil régional'),
(3, 'creation', 'Système', 'Création de la demande'),
(3, 'changement_statut', 'Trésorier', 'Changement de statut : deposee'),
(4, 'creation', 'Système', 'Création de la demande'),
(5, 'creation', 'Système', 'Création de la demande');

-- Insertion de documents exemples (à adapter selon vos fichiers)
-- Note: Ces enregistrements supposent que vous avez uploadé les fichiers correspondants
-- INSERT INTO subventions_documents (subvention_id, type_document, nom_fichier, chemin_fichier, taille_fichier) VALUES
-- (1, 'statuts', 'SUB-2024-001_statuts_1234567890.pdf', '/path/to/uploads/subventions/SUB-2024-001_statuts_1234567890.pdf', 125000),
-- (1, 'rapport_activite', 'SUB-2024-001_rapport_activite_1234567891.pdf', '/path/to/uploads/subventions/SUB-2024-001_rapport_activite_1234567891.pdf', 340000),
-- (1, 'budget', 'SUB-2024-001_budget_1234567892.xlsx', '/path/to/uploads/subventions/SUB-2024-001_budget_1234567892.xlsx', 45000);
