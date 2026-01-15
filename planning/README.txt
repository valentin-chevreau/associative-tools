Outil de gestion des permanences - Version simplifiée (code admin, pas de comptes)

1. Copiez le dossier `planning` sur votre hébergement (par ex. à la racine du site).
   L'application sera accessible via: https://monsite.org/planning/
2. Créez une base de données MySQL vide.
3. Importez le fichier `schema.sql` dans cette base.
4. Copiez `config/config.example.php` en `config/config.php` et remplissez les paramètres (hôte, base, user, mot de passe).
   - Vous pouvez aussi changer le code admin (PIN numérique ou mot de passe simple).
5. Allez sur: https://monsite.org/planning/admin/ (ou chemin équivalent)
   - On vous demandera le code admin pour accéder à la partie gestion.
6. Dans l'admin:
   - Ajoutez les bénévoles dans "Bénévoles".
   - Créez des permanences ou utilisez "Générer des samedis".
7. Sur la page principale (planning/index.php):
   - Le bénévole choisit son nom dans la liste déroulante.
   - Puis il/elle s'inscrit ou se désinscrit pour chaque permanence.
