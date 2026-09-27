#!/usr/bin/env bash
#
# Déploiement de la Suite Touraine-Ukraine en production.
#
# Ce script vit dans le dépôt (deploy/deploy-prod.sh) pour être versionné,
# mais DOIT être installé/copié une fois à un emplacement STABLE, en dehors
# de l'arborescence déployée (/opt/touraine-ukraine-deploy/deploy-prod.sh) :
# un rollback (git reset --hard) dans le dossier prod ne doit jamais pouvoir
# faire disparaître le script en train de s'exécuter.
#
#   sudo mkdir -p /opt/touraine-ukraine-deploy
#   sudo cp deploy/deploy-prod.sh /opt/touraine-ukraine-deploy/deploy-prod.sh
#   sudo chmod +x /opt/touraine-ukraine-deploy/deploy-prod.sh
#
# Après toute modification de ce fichier dans le dépôt, il faut donc recopier
# la nouvelle version à cet emplacement (le workflow GitHub Actions appelle
# UNIQUEMENT la copie dans /opt, jamais celle du dépôt).

set -euo pipefail

# ---- Configuration -------------------------------------------------------
PROD_DIR="/var/www/html/touraine-ukraine.fr/public/tools"
BACKUP_DIR="/var/backups/touraine-ukraine-tools"
KEEP_BACKUPS=10
# Fichier NON versionné contenant les identifiants MySQL de prod, format :
#   DB_HOST=localhost
#   DB_NAME=touraineukraine_tools
#   DB_USER=...
#   DB_PASS=...
ENV_FILE="/etc/touraine-ukraine-deploy.env"
# Nom du service PHP-FPM à recharger après déploiement (évite tout souci de
# cache OPcache servant encore l'ancien code) — adapte à ta version PHP.
PHP_FPM_SERVICE="php8.2-fpm"

log() { echo "[deploy $(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# ---- 1) Sauvegarde de la base avant toute modification --------------------
mkdir -p "$BACKUP_DIR"
if [ -f "$ENV_FILE" ]; then
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    timestamp=$(date +%Y%m%d-%H%M%S)
    backup_file="$BACKUP_DIR/${DB_NAME}-${timestamp}.sql.gz"
    log "Sauvegarde de la base $DB_NAME vers $backup_file"
    MYSQL_PWD="$DB_PASS" mysqldump -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" | gzip > "$backup_file"
    # Ne garde que les KEEP_BACKUPS sauvegardes les plus récentes.
    ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm --
else
    log "ATTENTION : $ENV_FILE introuvable — sauvegarde DB ignorée (voir deploy/README.md pour l'activer)"
fi

# ---- 2) Récupération du code ----------------------------------------------
cd "$PROD_DIR"
prev_commit=$(git rev-parse HEAD)
log "Commit actuel : $prev_commit"

log "git fetch + fast-forward sur main"
git fetch origin main
git merge --ff-only origin/main

log "Mise à jour des submodules"
git submodule sync --recursive
git submodule update --init --recursive

new_commit=$(git rev-parse HEAD)
log "Nouveau commit : $new_commit"

# ---- 3) Vérification avant de considérer le déploiement comme valide ------
log "Vérification syntaxique (php -l) de tous les fichiers PHP"
lint_failed=0
while IFS= read -r -d '' f; do
    if ! php -l "$f" > /tmp/deploy-php-lint.log 2>&1; then
        log "ERREUR DE SYNTAXE dans $f :"
        cat /tmp/deploy-php-lint.log
        lint_failed=1
    fi
done < <(find . -name '*.php' -not -path '*/vendor/*' -print0)

if [ "$lint_failed" -ne 0 ]; then
    log "Vérification échouée — rollback vers $prev_commit"
    git reset --hard "$prev_commit"
    git submodule update --init --recursive
    log "Rollback effectué. Déploiement ABANDONNÉ."
    exit 1
fi

# ---- 4) Rechargement du cache PHP -----------------------------------------
log "Rechargement de $PHP_FPM_SERVICE (vide l'OPcache)"
sudo -n systemctl reload "$PHP_FPM_SERVICE" || log "ATTENTION : rechargement de $PHP_FPM_SERVICE impossible (droits sudo ? voir deploy/README.md)"

log "Déploiement terminé avec succès : $new_commit"
