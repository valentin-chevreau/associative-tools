-- ============================================
-- SCHEMA BASE DE DONNÉES
-- Module Adhésions & Subventions
-- Touraine-Ukraine
-- ============================================

-- Table des adhérents
CREATE TABLE IF NOT EXISTS adherents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(20) UNIQUE NOT NULL,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(150),
    telephone VARCHAR(20),
    adresse TEXT,
    code_postal VARCHAR(10),
    ville VARCHAR(100),
    date_premiere_adhesion DATE,
    origine VARCHAR(50),
    competences TEXT,
    notes TEXT,
    actif BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nom (nom, prenom),
    INDEX idx_email (email),
    INDEX idx_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des adhésions annuelles
CREATE TABLE IF NOT EXISTS adhesions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    adherent_id INT NOT NULL,
    annee YEAR NOT NULL,
    date_adhesion DATE NOT NULL,
    montant DECIMAL(10,2) NOT NULL,
    mode_paiement ENUM('especes', 'cheque', 'virement', 'cb') NOT NULL,
    numero_transaction VARCHAR(50),
    statut ENUM('en_attente', 'valide', 'annule') DEFAULT 'valide',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (adherent_id) REFERENCES adherents(id) ON DELETE CASCADE,
    UNIQUE KEY adhesion_unique (adherent_id, annee),
    INDEX idx_annee (annee),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des subventions
CREATE TABLE IF NOT EXISTS subventions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(20) UNIQUE NOT NULL,
    organisme VARCHAR(150) NOT NULL,
    type_subvention ENUM('fonctionnement', 'projet', 'equipement', 'autre') NOT NULL,
    projet_concerne VARCHAR(200),
    annee YEAR NOT NULL,
    montant_demande DECIMAL(10,2) NOT NULL,
    montant_accorde DECIMAL(10,2),
    statut ENUM('brouillon', 'deposee', 'en_cours', 'accordee', 'refusee', 'versee', 'annulee') DEFAULT 'brouillon',
    date_depot DATE,
    date_limite DATE,
    date_reponse DATE,
    date_versement DATE,
    contact_referent VARCHAR(100),
    contact_email VARCHAR(150),
    contact_telephone VARCHAR(20),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_annee (annee),
    INDEX idx_statut (statut),
    INDEX idx_organisme (organisme)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des documents liés aux subventions
CREATE TABLE IF NOT EXISTS subventions_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subvention_id INT NOT NULL,
    type_document ENUM('demande', 'statuts', 'rapport_activite', 'budget', 'rib', 'deliberation', 'courrier_reponse', 'autre') NOT NULL,
    nom_fichier VARCHAR(255) NOT NULL,
    chemin_fichier VARCHAR(500) NOT NULL,
    taille_fichier INT,
    date_upload TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subvention_id) REFERENCES subventions(id) ON DELETE CASCADE,
    INDEX idx_subvention (subvention_id),
    INDEX idx_type (type_document)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table de l'historique des subventions
CREATE TABLE IF NOT EXISTS subventions_historique (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subvention_id INT NOT NULL,
    date_action DATETIME DEFAULT CURRENT_TIMESTAMP,
    action VARCHAR(100) NOT NULL,
    auteur VARCHAR(100),
    commentaire TEXT,
    FOREIGN KEY (subvention_id) REFERENCES subventions(id) ON DELETE CASCADE,
    INDEX idx_subvention (subvention_id),
    INDEX idx_date (date_action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
