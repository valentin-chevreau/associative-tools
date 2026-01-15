# Suite Touraine-Ukraine — PREPROD Fusion (v1)

Cette archive contient:
- une **nav Suite** unifiée + portail modules (avec icônes)
- une **auth globale** par codes (admin / admin+), indépendante du Planning
- des scripts SQL pour **base unifiée** + **migration depuis les DB préprod existantes**

## 1) Config
Copier `.env.example` en `.env` à la racine de `preprod-fusion/` puis adapter:
- `SUITE_BASE=/preprod-fusion`
- `ADMIN_CODES` / `ADMIN_PLUS_CODES`

## 2) Créer la base unifiée + migrer (depuis les DB préprod)
Sources attendues:
- `touraineukraine_preprod_planning`
- `touraineukraine_preprod_outilcaisse`
- `touraineukraine_preprod_logistique`

Cible:
- `touraineukraine_preprod_suite`

Exécuter (via phpMyAdmin ou CLI MySQL):
1. `migrations/suite_schema.sql`
2. `migrations/migrate_from_legacy.sql`

## 3) URLs utiles
- Portail: `/preprod-fusion/`
- Login admin: `/preprod-fusion/admin/login.php`
- Logout admin: `/preprod-fusion/admin/logout.php`

## Notes
- Les événements caisse sont rattachés aux événements Planning via `evenements.planning_event_id`.
- Si `planning_event_id` est NULL, les ventes sont migrées avec `event_id=NULL` (à traiter au cas par cas).
