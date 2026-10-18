-- Caisse : mouvements d'espèces en cours d'évènement (échanges CB/espèces entre associations, etc.).
-- Les lignes existantes de caisse_retraits_caisse sont des mises en banque (après comptage) : elles restent
-- de type 'banque' et n'entrent pas dans le fond théorique. Les autres types le font varier :
--   echange_sortie / autre_sortie  → espèces qui quittent la caisse (fond théorique ↓)
--   echange_entree / autre_entree  → espèces qui entrent dans la caisse sans être une vente (fond théorique ↑)
ALTER TABLE caisse_retraits_caisse
  ADD COLUMN IF NOT EXISTS type ENUM('banque','echange_sortie','echange_entree','autre_sortie','autre_entree')
      NOT NULL DEFAULT 'banque' AFTER montant;
