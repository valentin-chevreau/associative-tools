# Déploiement continu — mise en place (une seule fois)

Objectif : quand tu pousses sur `main` (dépôt principal), le VPS se met à
jour tout seul en prod, avec une vérification avant que ça remplace le code
en place, et un rollback automatique si la vérification échoue.

Tout tourne **sur le VPS lui-même** via un "runner" GitHub Actions
auto-hébergé : pas de secret SSH à stocker sur GitHub, pas de port à ouvrir.

## 1. Installer le runner GitHub Actions sur le VPS

Sur GitHub : dépôt principal → **Settings → Actions → Runners → New
self-hosted runner** → choisis Linux/x64. GitHub affiche une série de
commandes à copier-coller **sur le VPS** (dans un dossier dédié, ex.
`/opt/actions-runner`) : téléchargement de l'archive, `./config.sh` avec le
token fourni par GitHub, puis :

```bash
sudo ./svc.sh install
sudo ./svc.sh start
```

Ça installe le runner comme service systemd : il tourne en permanence,
interroge GitHub tout seul, et exécute les workflows dès qu'un push arrive.

## 2. Installer le script de déploiement à un emplacement stable

Le script est versionné dans le dépôt (`deploy/deploy-prod.sh`) mais doit
être copié une fois en dehors du dossier prod, pour qu'un rollback ne puisse
jamais le supprimer pendant qu'il tourne :

```bash
sudo mkdir -p /opt/touraine-ukraine-deploy
sudo cp deploy/deploy-prod.sh /opt/touraine-ukraine-deploy/deploy-prod.sh
sudo chmod +x /opt/touraine-ukraine-deploy/deploy-prod.sh
```

**Important** : à chaque fois que `deploy/deploy-prod.sh` est modifié dans
le dépôt, il faut recopier la nouvelle version à cet emplacement (le
workflow n'exécute que la copie dans `/opt`, jamais celle du dépôt).

## 3. Adapter la configuration en tête du script

Ouvre `/opt/touraine-ukraine-deploy/deploy-prod.sh` et vérifie/adapte :

- `PROD_DIR` — déjà réglé sur `/var/www/html/touraine-ukraine.fr/public/tools`
- `PHP_FPM_SERVICE` — le nom exact du service PHP-FPM (`systemctl status
  'php*-fpm'` pour le trouver si besoin)

## 4. Identifiants MySQL pour la sauvegarde avant déploiement

Le script ne possède plus de fichier d'identifiants propre : il lit `DB_HOST`,
`DB_NAME`, `DB_USER` et `DB_PASS` dans le fichier d'environnement de l'application,
`/var/www/html/touraine-ukraine.fr/secrets/tools.env`. Une rotation du mot de
passe n'a donc qu'un seul endroit à modifier.

Ce fichier est en `root:www-data` `640` : l'utilisateur du runner (`vchevreau`)
doit appartenir au groupe `www-data` pour le lire.

```bash
sudo usermod -aG www-data vchevreau
sudo systemctl restart "actions.runner.*"   # le service doit être relancé pour prendre le groupe
```

Si la sauvegarde échoue (fichier illisible, mot de passe refusé), le déploiement
est abandonné avant toute modification du code.

## 5. Autoriser le rechargement de PHP-FPM sans mot de passe

Le compte qui exécute le runner a besoin de recharger PHP-FPM sans
interaction. Sur ce VPS, le runner tourne sous l'utilisateur `vchevreau` et
le site utilise `php8.3-fpm` (identifiés via `/etc/nginx/sites-available/touraine-ukraine.fr` → `fastcgi_pass unix:/run/php/php8.3-fpm.sock;`) :

```bash
echo 'vchevreau ALL=(ALL) NOPASSWD: /bin/systemctl reload php8.3-fpm' | sudo tee /etc/sudoers.d/touraine-ukraine-deploy
sudo chmod 440 /etc/sudoers.d/touraine-ukraine-deploy
```

## 6. Vérifier les droits d'écriture

L'utilisateur du runner doit pouvoir écrire dans `PROD_DIR` (le même
utilisateur qui fait `git pull` à la main aujourd'hui, ou un utilisateur du
même groupe Unix que les fichiers du site).

## Une fois en place

Le workflow `.github/workflows/deploy-prod.yml` se déclenche automatiquement
à chaque push sur `main`. Tu peux aussi le relancer manuellement depuis
GitHub (onglet **Actions** → *Déploiement continu — Prod* → **Run
workflow**) — utile pour rejouer un déploiement sans repousser de commit.

En cas d'échec (erreur de syntaxe PHP détectée après le pull), le script
revient automatiquement au commit précédent et le déploiement est marqué en
échec sur GitHub (tu reçois un e-mail de notification par défaut).

## Étendre à la préprod (optionnel)

Le même principe peut s'appliquer à `preprod-tools` avec un second script
(`deploy-preprod.sh`, même structure, `PROD_DIR` pointant vers
`preprod-tools`) déclenché par exemple sur push vers une branche `develop`
ou `preprod`, si tu veux formaliser aussi ce déploiement plutôt que de le
faire à la main.
