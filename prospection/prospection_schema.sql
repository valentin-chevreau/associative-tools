-- Module Prospection (démarchage matériel/dons) — Touraine-Ukraine
-- Harmonise les 9 onglets Excel du VP (JOUETS_*, EPI, MEDIC_*, REEDUC_*, FILETS ANTI-DRONES)
-- en un modèle unique : catégories (= les anciens onglets, groupées par famille),
-- fiches structure (contacts), et un journal de suivi historisé (multi-années).

CREATE TABLE IF NOT EXISTS prospection_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) UNIQUE NOT NULL,
    label VARCHAR(150) NOT NULL,
    famille_label VARCHAR(100) NOT NULL DEFAULT 'Autres',
    famille_sort INT NOT NULL DEFAULT 100,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_famille (famille_sort, famille_label),
    INDEX idx_actif (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospection_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    categorie_id INT NOT NULL,
    nom VARCHAR(255) NOT NULL,             -- Structure / Société / Enseigne / Établissement / Organisme / Service...
    commune VARCHAR(150),
    adresse VARCHAR(255),
    telephone VARCHAR(60),
    email VARCHAR(190),
    site_web VARCHAR(255),
    contact_referent VARCHAR(190),         -- Contact RSE/dons, Gestionnaire, Contact à demander...
    priorite VARCHAR(60),                  -- texte libre : les échelles diffèrent selon l'onglet d'origine
    statut ENUM('a_contacter','contacte','a_rappeler','accorde','refuse') NOT NULL DEFAULT 'a_contacter',
    extra_json TEXT,                       -- colonnes source non harmonisées (JSON), pour ne rien perdre à l'import
    actif BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (categorie_id) REFERENCES prospection_categories(id) ON DELETE RESTRICT,
    INDEX idx_categorie (categorie_id),
    INDEX idx_statut (statut),
    INDEX idx_nom (nom),
    INDEX idx_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal de suivi : une ligne par relance/contact/note, datée — permet de retrouver
-- l'historique année par année (2026, 2027, ...) sans jamais écraser les entrées précédentes.
CREATE TABLE IF NOT EXISTS prospection_suivi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contact_id INT NOT NULL,
    type ENUM('contact','rappel','note') NOT NULL DEFAULT 'contact',
    date_suivi DATE NOT NULL,
    annee INT NOT NULL,                    -- dénormalisé depuis date_suivi pour filtrer/grouper vite
    auteur VARCHAR(150),                    -- nom du bénévole (texte libre)
    commentaire TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES prospection_contacts(id) ON DELETE CASCADE,
    INDEX idx_contact (contact_id),
    INDEX idx_annee (annee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catégories initiales, reprises des 9 onglets du fichier Excel
INSERT INTO prospection_categories (code, label, famille_label, famille_sort, sort_order) VALUES
('jouets_magasins_fr',              'Magasins - FR',              'Jouets',                    10, 10),
('jouets_grandes_surfaces',         'Grandes surfaces',           'Jouets',                    10, 20),
('jouets_ludotheques_ecoles_ville', 'Ludothèques - écoles - ville','Jouets',                    10, 30),
('epi',                             'EPI',                        'EPI',                        20, 10),
('medic_ephad',                     'EHPAD',                      'Médical / Rééducation',     30, 10),
('medic_ssiad',                     'SSIAD',                      'Médical / Rééducation',     30, 20),
('medic_fr_materiel',               'Matériel médical - FR',      'Médical / Rééducation',     30, 30),
('reeduc_salles_sport_muscu',       'Salles de sport - musculation','Médical / Rééducation',    30, 40),
('filets_anti_drones',              'Filets anti-drones',         'Filets anti-drones',        40, 10)
ON DUPLICATE KEY UPDATE label = VALUES(label);