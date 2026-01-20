<?php
declare(strict_types=1);

/**
 * shared/nav.config.php
 * Source UNIQUE de vérité pour nav_visible_items()
 * - Règles:
 *   - "Gens" (Bénévoles / Familles) => Annuaire
 *   - Admin séparé visuellement (groupes dans children)
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
      'admin_plus' => 30,
      'admin'      => 20,
      default      => 10,
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
    add_if_exists($planningChildren, 'Dons',  $root . '/planning/admin/donations.php',    $base . '/planning/admin/donations.php', 'admin', 'Admin');
    add_if_exists($planningChildren, 'Admin', $root . '/planning/admin/events_list.php',  $base . '/planning/admin/events_list.php', 'admin', 'Admin');

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
    add_if_exists($caisseChildren, 'Évènements',  $root . '/caisse/evenements.php', $base . '/caisse/evenements.php');
    add_if_exists($caisseChildren, 'Stock',           $root . '/caisse/stock.php',            $base . '/caisse/stock.php', 'admin', 'Admin');
    add_if_exists($caisseChildren, 'Dashboard',       $root . '/caisse/dashboard.php',        $base . '/caisse/dashboard.php', 'admin', 'Admin');
    add_if_exists($caisseChildren, 'Retraits caisse', $root . '/caisse/retraits_caisse.php',  $base . '/caisse/retraits_caisse.php', 'admin', 'Admin');

    // ======================
    // Annuaire (Gens)
    // ======================
    $annuaireChildren = [];

    // Familles (logistique)
    add_if_exists($annuaireChildren, 'Familles', $root . '/logistique/families/index.php', $base . '/logistique/families/index.php');

    // Bénévoles (caisse) => Annuaire
    add_if_exists($annuaireChildren, 'Bénévoles (caisse)', $root . '/caisse/benevoles.php', $base . '/caisse/benevoles.php', 'admin', 'Admin');

    // Bénévoles (planning) => Annuaire
    // (on teste plusieurs noms possibles, sans inventer)
    add_if_exists($annuaireChildren, 'Bénévoles (planning)', $root . '/planning/admin/volunteers.php', $base . '/planning/admin/volunteers.php', 'admin', 'Admin');
    add_if_exists($annuaireChildren, 'Bénévoles (planning)', $root . '/planning/admin/benevoles.php',  $base . '/planning/admin/benevoles.php',  'admin', 'Admin');
    add_if_exists($annuaireChildren, 'Bénévoles (planning)', $root . '/planning/admin/volunteers_list.php', $base . '/planning/admin/volunteers_list.php', 'admin', 'Admin');

    // ======================
    // Items top-level
    // ======================
    $items = [];

    $items[] = [
      'label' => 'Planning',
      'icon'  => 'calendar',
      'min_role' => 'public',
      'children' => $planningChildren,
    ];

    $items[] = [
      'label' => 'Convois',
      'icon'  => 'truck',
      'min_role' => 'public',
      'children' => $convoisChildren,
    ];

    $items[] = [
      'label' => 'Stock local',
      'icon'  => 'box',
      'min_role' => 'public',
      'children' => $stockChildren,
    ];

    if ($etiquettesHref !== null) {
      $items[] = [
        'label' => 'Étiquettes',
        'icon'  => 'tag',
        'min_role' => 'public',
        'href' => $etiquettesHref,
      ];
    }

    $items[] = [
      'label' => 'Caisse',
      'icon'  => 'cash',
      'min_role' => 'public',
      'children' => $caisseChildren,
    ];

    $items[] = [
      'label' => 'Annuaire',
      'icon'  => 'users',
      'min_role' => 'public',
      'children' => $annuaireChildren,
    ];

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
      if (!can_see_min_role($minRole)) continue;

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