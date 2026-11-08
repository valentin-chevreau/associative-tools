-- Mailing V2 : le contenu d'une newsletter devient une suite de blocs (titre, texte, image, bouton, colonnes,
-- citation, chiffres clés, évènement, séparateur) stockée en JSON. Les anciennes colonnes titre / texte / bouton
-- sont converties en blocs puis supprimées. Ajoute aussi les exclusions de destinataires propres à une newsletter.

ALTER TABLE mailing_campaigns ADD COLUMN blocks MEDIUMTEXT NULL AFTER preheader;

UPDATE mailing_campaigns SET blocks = CONCAT('[', CONCAT_WS(',',
    IF(title <> '', JSON_OBJECT('type', 'title', 'text', title), NULL),
    IF(body <> '', JSON_OBJECT('type', 'text', 'text', body), NULL),
    IF(button_label <> '' AND button_url <> '', JSON_OBJECT('type', 'button', 'label', button_label, 'url', button_url), NULL)
), ']');

ALTER TABLE mailing_campaigns
  MODIFY blocks MEDIUMTEXT NOT NULL,
  DROP COLUMN title,
  DROP COLUMN body,
  DROP COLUMN button_label,
  DROP COLUMN button_url;

-- Personnes décochées pour cette newsletter seulement (elles restent dans leurs listes).
CREATE TABLE IF NOT EXISTS mailing_campaign_exclusions (
  campaign_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  PRIMARY KEY (campaign_id, email),
  CONSTRAINT fk_mailing_exclusion_campaign FOREIGN KEY (campaign_id) REFERENCES mailing_campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
