<?php
declare(strict_types=1);

/**
 * shared/nav.config.php
 * Source UNIQUE de vérité pour nav_visible_items()
 * - Règles:
 *   - "Gens" (Bénévoles / Familles) => Annuaire
 *   - Admin séparé visuellement (groupes dans children)
 *   - Adhésions et Subventions = ADMIN uniquement
 * - Zéro chemins inventés: on ne propose un lien que si la cible existe.
 */

require_once __DIR__ . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/* ------------------------
   Rôles
------------------------- */
if (!function_exists('current_role')) {
  function current_role(): string {
    if (!empty($_SESSION['admin_plus']) || !empty($_SESSION['is_admin_plus'])) return 'admin_plus';
    if (!empty($_SESSION['is_admin']) || !empty($_SESSION['admin_authenticated']) || !empty($_SESSION['admin'])) return 'admin';
    return 'public';
  }
}
if (!function_exists('is_admin')) {
  function is_admin(): bool {
    $r = current_role();
    return $r === 'admin' || $r === 'admin_plus';
  }
}
if (!function_exists('role_rank')) {
  function role_rank(string $role): int {
    return match ($role) {
      'super_admin' => 40,
      'admin_plus'  => 30,
      'admin'       => 20,
      default       => 10,
    };
  }
}
if (!function_exists('can_see_min_role')) {
  function can_see_min_role(string $minRole): bool {
    return role_rank(current_role()) >= role_rank($minRole);
  }
}

/* ------------------------
   Helpers chemins
------------------------- */
if (!function_exists('suite_tools_root')) {
  function suite_tools_root(): string {
    // /tools/shared => /tools
    return realpath(dirname(__DIR__)) ?: dirname(__DIR__);
  }
}
if (!function_exists('add_if_exists')) {
  /**
   * @param array<int,array<string,mixed>> $dst
   */
  function add_if_exists(array &$dst, string $label, string $absPath, string $href, string $minRole = 'public', ?string $group = null): void {
    if (is_dir($absPath) || is_file($absPath)) {
      $item = ['label' => $label, 'href' => $href];
      if ($minRole !== 'public') $item['min_role'] = $minRole;
      if ($group !== null && $group !== '') $item['group'] = $group;
      $dst[] = $item;
    }
  }
}

/* ------------------------
   Définition items (non filtrés)
------------------------- */
if (!function_exists('nav_items')) {
  function nav_items(): array {
    $base = suite_base();
    $root = suite_tools_root();

    // ======================
    // Planning
    // ======================
    $planningChildren = [];
    add_if_exists($planningChildren, 'Planning', $root . '/planning/index.php', $base . '/planning/index.php');

    // Planning admin (reste sous Planning, groupé "Admin")
    // NB : la gestion des dons a été extraite dans son propre module
    // top-level "donations" (base unifiée, ne dépend plus de planning) —
    // voir plus bas, section "Dons".
    add_if_exists($planningChildren, 'Gestion du planning', $root . '/planning/admin/events_list.php',  $base . '/planning/admin/events_list.php', 'admin_plus', 'Admin');

    // ======================
    // Logistique / Convois
    // ======================
    $convoisChildren = [];
    add_if_exists($convoisChildren, 'Tous les convois', $root . '/logistique/index.php', $base . '/logistique/index.php');
    add_if_exists($convoisChildren, 'Catégories (convois)', $root . '/logistique/categories/view.php', $base . '/logistique/categories/view.php');
    add_if_exists($convoisChildren, 'Palettes',   $root . '/logistique/pallets/index.php', $base . '/logistique/pallets/index.php');
    add_if_exists($convoisChildren, 'Étiquettes', $root . '/logistique/labels/index.php',  $base . '/logistique/labels/index.php');

    // ======================
    // Stock local (logistique/stock)
    // ======================
    $stockChildren = [];
    add_if_exists($stockChildren, 'Objets en stock', $root . '/logistique/stock/index.php', $base . '/logistique/stock/index.php');
    add_if_exists($stockChildren, 'Ajouter un objet', $root . '/logistique/stock/item_edit.php', $base . '/logistique/stock/item_edit.php', 'admin', 'Admin');
    add_if_exists($stockChildren, 'Catégories', $root . '/logistique/stock/categories/index.php', $base . '/logistique/stock/categories/index.php');
    add_if_exists($stockChildren, 'Lieux',      $root . '/logistique/stock/locations/index.php',  $base . '/logistique/stock/locations/index.php');

    // ======================
    // Étiquettes (module global éventuel)
    // ======================
    $etiquettesHref = null;
    if (is_dir($root . '/labels')) $etiquettesHref = $base . '/labels/';
    if (is_file($root . '/labels/index.php')) $etiquettesHref = $base . '/labels/index.php';

    // ======================
    // Caisse (sous-menus alignés sur caisse/nav.php)
    // ======================
    $caisseChildren = [];
    add_if_exists($caisseChildren, 'Caisse',      $root . '/caisse/index.php',      $base . '/caisse/index.php');
    add_if_exists($caisseChildren, 'Évènements',  $root . '/caisse/evenements.php', $base . '/caisse/evenements.php', 'admin_plus', 'Admin');
    add_if_exists($caisseChildren, 'Stock',           $root . '/caisse/stock.php',            $base . '/caisse/stock.php', 'admin', 'Admin');
    add_if_exists($caisseChildren, 'Dashboard',       $root . '/caisse/dashboard.php',        $base . '/caisse/dashboard.php', 'admin', 'Admin');
    add_if_exists($caisseChildren, 'Retraits caisse', $root . '/caisse/retraits_caisse.php',  $base . '/caisse/retraits_caisse.php', 'admin_plus', 'Admin');

    // ======================
    // Annuaire (Gens)
    // ======================
    $annuaireChildren = [];

    // Familles (logistique)
    add_if_exists($annuaireChildren, 'Familles', $root . '/logistique/families/index.php', $base . '/logistique/families/index.php');

    // Bénévoles (planning) => Annuaire
    add_if_exists($annuaireChildren, 'Bénévoles (planning)', $root . '/planning/admin/volunteers.php', $base . '/planning/admin/volunteers.php', 'admin', 'Admin');
    add_if_exists($annuaireChildren, 'Bénévoles (planning)', $root . '/planning/admin/benevoles.php',  $base . '/planning/admin/benevoles.php',  'admin', 'Admin');
    add_if_exists($annuaireChildren, 'Bénévoles (planning)', $root . '/planning/admin/volunteers_list.php', $base . '/planning/admin/volunteers_list.php', 'admin', 'Admin');

    // ======================
    // Adhésions (ADMIN uniquement)
    // ======================
    $adhesionsChildren = [];
    add_if_exists($adhesionsChildren, 'Membres',      $root . '/adhesions/index.php',              $base . '/adhesions/index.php', 'admin');
    add_if_exists($adhesionsChildren, 'Cotisations',  $root . '/adhesions/cotisations/index.php',  $base . '/adhesions/cotisations/index.php', 'admin');
    add_if_exists($adhesionsChildren, 'Documents',    $root . '/adhesions/documents/index.php',    $base . '/adhesions/documents/index.php', 'admin');
    add_if_exists($adhesionsChildren, 'Nouveau membre',  $root . '/adhesions/membres/add.php',     $base . '/adhesions/membres/add.php', 'admin', 'Admin');
    add_if_exists($adhesionsChildren, 'Statistiques',    $root . '/adhesions/stats.php',           $base . '/adhesions/stats.php', 'admin_plus', 'Admin');

    // ======================
    // Subventions (ADMIN uniquement)
    // ======================
    $subventionsChildren = [];
    add_if_exists($subventionsChildren, 'Demandes',       $root . '/subventions/index.php',              $base . '/subventions/index.php', 'admin');
    add_if_exists($subventionsChildren, 'Organismes',     $root . '/subventions/organismes/index.php',   $base . '/subventions/organismes/index.php', 'admin');
    add_if_exists($subventionsChildren, 'Versements',     $root . '/subventions/versements/index.php',   $base . '/subventions/versements/index.php', 'admin');
    add_if_exists($subventionsChildren, 'Documents',      $root . '/subventions/documents/index.php',    $base . '/subventions/documents/index.php', 'admin');
    add_if_exists($subventionsChildren, 'Nouvelle demande', $root . '/subventions/demandes/add.php',    $base . '/subventions/demandes/add.php', 'admin', 'Admin');
    add_if_exists($subventionsChildren, 'Statistiques',     $root . '/subventions/stats.php',           $base . '/subventions/stats.php', 'admin_plus', 'Admin');

    // ======================
    // Dons (HelloAsso + manuel — module autonome, ADMIN+ uniquement)
    // ======================
    $donationsChildren = [];
    add_if_exists($donationsChildren, 'Gestion des dons', $root . '/donations/index.php', $base . '/donations/index.php');

    // ======================
    // Prospection (démarchage matériel/dons — ADMIN+ uniquement)
    // Catégories listées en dur (comme les autres sections de ce fichier) plutôt
    // qu'interrogées en base à chaque chargement de page : si une catégorie est
    // ajoutée dans prospection/schema.sql, ajoute la ligne correspondante ici.
    // ======================
    $prospectionCategoriesNav = [
        'jouets_magasins_fr'              => 'Jouets — Magasins',
        'jouets_grandes_surfaces'         => 'Jouets — Grandes surfaces',
        'jouets_ludotheques_ecoles_ville' => 'Jouets — Ludothèques - écoles - ville',
        'epi'                             => 'EPI',
        'medic_ephad'                     => 'Médical — EHPAD',
        'medic_ssiad'                     => 'Médical — SSIAD',
        'medic_fr_materiel'               => 'Médical — Matériel médical - FR',
        'reeduc_salles_sport_muscu'       => 'Médical — Salles de sport - musculation',
        'filets_anti_drones'              => 'Filets anti-drones',
    ];
    $prospectionChildren = [];
    add_if_exists($prospectionChildren, 'Toutes les fiches', $root . '/prospection/index.php', $base . '/prospection/index.php');
    foreach ($prospectionCategoriesNav as $pCode => $pLabel) {
        add_if_exists($prospectionChildren, $pLabel, $root . '/prospection/index.php', $base . '/prospection/index.php?categorie=' . urlencode($pCode));
    }
    add_if_exists($prospectionChildren, 'Importer un CSV', $root . '/prospection/import.php', $base . '/prospection/import.php', 'admin', 'Admin');
    add_if_exists($prospectionChildren, 'Nouvelle fiche',  $root . '/prospection/contact_form.php', $base . '/prospection/contact_form.php', 'admin', 'Admin');

    // ======================
    // Items top-level
    // ======================
    $items = [];

    $items[] = [
      'label' => 'Planning',
      'icon'  => 'calendar',
      'module' => 'planning', 'group' => 'terrain',
      'min_role' => 'public',
      'children' => $planningChildren,
    ];

    $items[] = [
      'label' => 'Convois',
      'icon'  => 'truck',
      'module' => 'logistique', 'group' => 'terrain',
      'min_role' => 'public',
      'children' => $convoisChildren,
    ];

    $items[] = [
      'label' => 'Stock local',
      'icon'  => 'box',
      'module' => 'logistique', 'group' => 'terrain',
      'min_role' => 'public',
      'children' => $stockChildren,
    ];

    if ($etiquettesHref !== null) {
      $items[] = [
        'label' => 'Étiquettes',
        'icon'  => 'tag',
        'module' => 'logistique', 'group' => 'terrain',
        'min_role' => 'public',
        'href' => $etiquettesHref,
      ];
    }

    $items[] = [
      'label' => 'Caisse',
      'icon'  => 'cash',
      'module' => 'caisse', 'group' => 'terrain',
      'min_role' => 'public',
      'children' => $caisseChildren,
    ];

    $items[] = [
      'label' => 'Annuaire',
      'icon'  => 'users',
      'module' => 'annuaire', 'group' => 'terrain',
      'min_role' => 'public',
      'children' => $annuaireChildren,
    ];

    // Adhésions (apparaît seulement pour admin+)
    if (!empty($adhesionsChildren)) {
      $items[] = [
        'label' => 'Adhésions',
        'icon'  => 'id-card',
        'module' => 'adhesions', 'group' => 'gestion',
        'min_role' => 'admin_plus',
        'children' => $adhesionsChildren,
      ];
    }

    // Subventions (apparaît seulement pour admin+)
    if (!empty($subventionsChildren)) {
      $items[] = [
        'label' => 'Subventions',
        'icon'  => 'briefcase',
        'module' => 'subventions', 'group' => 'gestion',
        'min_role' => 'admin_plus',
        'children' => $subventionsChildren,
      ];
    }

    // Dons (apparaît seulement pour admin+) — module autonome, plus nested
    // sous Planning (voir commentaire ci-dessus).
    if (!empty($donationsChildren)) {
      $items[] = [
        'label' => 'Dons',
        'icon'  => 'gift',
        'module' => 'donations', 'group' => 'gestion',
        'min_role' => 'admin_plus',
        'children' => $donationsChildren,
      ];
    }

    // Prospection (apparaît seulement pour admin+)
    if (!empty($prospectionChildren)) {
      $items[] = [
        'label' => 'Prospection',
        'icon'  => 'briefcase',
        'module' => 'prospection', 'group' => 'gestion',
        'min_role' => 'admin_plus',
        'children' => $prospectionChildren,
      ];
    }

    // Documents (attestations, PV, listes de présence…)
    $documentsChildren = [];
    add_if_exists($documentsChildren, 'Tous les documents', $root . '/documents/index.php', $base . '/documents/index.php');
    if (!empty($documentsChildren)) {
      $items[] = [
        'label' => 'Documents',
        'icon'  => 'file',
        'module' => 'documents', 'group' => 'gestion',
        'min_role' => 'admin',
        'children' => $documentsChildren,
      ];
    }

    // Rapports (CRA, bilan bénévoles, bilan dons) — droit « reports », Admin+ par défaut
    $reportsChildren = [];
    add_if_exists($reportsChildren, "Vue d'ensemble",         $root . '/reports/index.php',      $base . '/reports/index.php');
    add_if_exists($reportsChildren, "Compte-rendu d'activité", $root . '/reports/activity.php',  $base . '/reports/activity.php');
    add_if_exists($reportsChildren, 'Bilan bénévoles',        $root . '/reports/volunteers.php', $base . '/reports/volunteers.php');
    add_if_exists($reportsChildren, 'Bilan dons',             $root . '/reports/donations.php',  $base . '/reports/donations.php');
    if (!empty($reportsChildren)) {
      $items[] = [
        'label' => 'Rapports',
        'icon'  => 'chart',
        'module' => 'reports', 'group' => 'gestion',
        'min_role' => 'admin_plus',
        'children' => $reportsChildren,
      ];
    }

    return $items;
  }
}

/* ------------------------
   Filtrage final (LA fonction stable)
------------------------- */
if (!function_exists('nav_visible_items')) {
  function nav_visible_items(): array {
    $items = nav_items();

    $out = [];
    foreach ($items as $it) {
      if (!is_array($it)) continue;

      $minRole = (string)($it['min_role'] ?? 'public');
      $module  = (string)($it['module'] ?? '');
      if ($module !== '' && is_admin()) {
        // Droits par module (gérés par le super admin) : remplacent le rôle minimum.
        if (!module_access($module)) continue;
        $minRole = 'public'; // les sous-liens sans rôle propre héritent de l'accès au module
      } elseif (!can_see_min_role($minRole)) {
        continue;
      }

      // Filtre children
      if (!empty($it['children']) && is_array($it['children'])) {
        $children = [];
        foreach ($it['children'] as $ch) {
          if (!is_array($ch)) continue;
          $chMin = (string)($ch['min_role'] ?? $minRole);
          if (!can_see_min_role($chMin)) continue;

          $href = (string)($ch['href'] ?? '');
          if ($href === '') continue;

          $children[] = $ch;
        }
        $it['children'] = $children;
      }

      $href = (string)($it['href'] ?? '');
      $hasChildren = !empty($it['children']) && is_array($it['children']) && count($it['children']) > 0;

      if ($href === '' && !$hasChildren) continue;

      $out[] = $it;
    }

    return $out;
  }
}
