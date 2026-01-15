-- Suite Touraine-Ukraine (PREPROD) - Schéma unifié v1
-- DB cible: touraineukraine_preprod_suite
-- Sources: touraineukraine_preprod_planning, touraineukraine_preprod_outilcaisse, touraineukraine_preprod_logistique

CREATE DATABASE IF NOT EXISTS `touraineukraine_preprod_suite` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `touraineukraine_preprod_suite`;

-- People (bénévoles, familles, donateurs)
CREATE TABLE IF NOT EXISTS core_people (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type ENUM('benevole','famille','donateur', volontaire) NOT NULL,
  first_name VARCHAR(120) NULL,
  last_name VARCHAR(120) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  city VARCHAR(120) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_people_email (email),
  KEY idx_people_phone (phone),
  KEY idx_people_name (last_name, first_name)
) ENGINE=InnoDB;

-- Legacy link table (pour mapping stable)
CREATE TABLE IF NOT EXISTS core_legacy_links (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity ENUM('person','event') NOT NULL,
  source_db VARCHAR(64) NOT NULL,
  source_table VARCHAR(64) NOT NULL,
  legacy_id BIGINT NOT NULL,
  core_id BIGINT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_link (entity, source_db, source_table, legacy_id),
  KEY idx_core (entity, core_id)
) ENGINE=InnoDB;

-- Events (référence unique via Planning)
CREATE TABLE IF NOT EXISTS core_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  event_type VARCHAR(50) NOT NULL DEFAULT 'permanence',
  start_datetime DATETIME NOT NULL,
  end_datetime DATETIME NOT NULL,
  location VARCHAR(255) NULL,
  is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_dates (start_datetime, end_datetime)
) ENGINE=InnoDB;

-- Planning (copie structurelle minimale pour rapports unifiés)
CREATE TABLE IF NOT EXISTS planning_slots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id INT UNSIGNED NOT NULL,
  start_time DATETIME NOT NULL,
  end_time DATETIME NOT NULL,
  min_volunteers INT NULL,
  max_volunteers INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_slots_event (event_id),
  CONSTRAINT fk_slots_event FOREIGN KEY (event_id) REFERENCES core_events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS planning_registrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(32) NOT NULL DEFAULT 'registered',
  PRIMARY KEY (id),
  KEY idx_reg_event (event_id),
  KEY idx_reg_person (person_id),
  CONSTRAINT fk_reg_event FOREIGN KEY (event_id) REFERENCES core_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_reg_person FOREIGN KEY (person_id) REFERENCES core_people(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Caisse (ventes + paiements)
CREATE TABLE IF NOT EXISTS caisse_sales (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_sale_id INT NULL,
  event_id INT UNSIGNED NULL,
  person_id INT UNSIGNED NULL,
  payment_method ENUM('CB','Especes','Cheque') NULL,
  total DECIMAL(10,2) NULL,
  sous_total DECIMAL(10,2) NULL,
  total_brut DECIMAL(10,2) NULL,
  remise_panier_type ENUM('none','percent','amount') NOT NULL DEFAULT 'none',
  remise_panier_valeur DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  remise_panier_montant DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  remise_total DECIMAL(10,2) NULL,
  date_vente DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sales_event (event_id),
  KEY idx_sales_person (person_id),
  CONSTRAINT fk_sales_event FOREIGN KEY (event_id) REFERENCES core_events(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_person FOREIGN KEY (person_id) REFERENCES core_people(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS caisse_sale_payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_id INT UNSIGNED NOT NULL,
  method ENUM('CB','Especes','Cheque') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pay_sale (sale_id),
  CONSTRAINT fk_pay_sale FOREIGN KEY (sale_id) REFERENCES caisse_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Dons (depuis Planning)
CREATE TABLE IF NOT EXISTS donations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_donation_id INT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'helloasso',
  source_ref VARCHAR(190) NULL,
  donation_date DATETIME NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  donor_person_id INT UNSIGNED NULL,
  donor_first_name VARCHAR(120) NULL,
  donor_last_name VARCHAR(120) NULL,
  donor_email VARCHAR(190) NULL,
  donor_address VARCHAR(255) NULL,
  donor_postal_code VARCHAR(24) NULL,
  donor_city VARCHAR(120) NULL,
  donor_country VARCHAR(120) NULL,
  campaign VARCHAR(190) NULL,
  payment_method VARCHAR(64) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'paid',
  receipt_eligible TINYINT(1) NOT NULL DEFAULT 0,
  receipt_number VARCHAR(64) NULL,
  receipt_date DATE NULL,
  receipt_pdf_path VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_don_date (donation_date),
  KEY idx_don_person (donor_person_id),
  CONSTRAINT fk_don_person FOREIGN KEY (donor_person_id) REFERENCES core_people(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Logistique (convois + familles + stock) - structure minimale pour évoluer
CREATE TABLE IF NOT EXISTS logistique_convoys (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_convoy_id INT NULL,
  name VARCHAR(255) NULL,
  status VARCHAR(32) NULL,
  departure_date DATE NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS logistique_families (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_family_id INT NULL,
  person_id INT UNSIGNED NOT NULL,
  public_ref VARCHAR(40) NULL,
  housing_notes VARCHAR(255) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_family_person (person_id),
  CONSTRAINT fk_family_person FOREIGN KEY (person_id) REFERENCES core_people(id) ON DELETE CASCADE
) ENGINE=InnoDB;
