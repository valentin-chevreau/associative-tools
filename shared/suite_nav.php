<?php
declare(strict_types=1);
/**
 * shared/suite_nav.php
 * Sidebar unifiée Touraine-Ukraine
 */

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('suite_nav_render')) {

function suite_nav_render(string $activeModule = '', string $activeItem = ''): void {
    $base        = function_exists('suite_base') ? suite_base() : '';
    $role        = function_exists('current_role') ? current_role() : 'public';
    $isAdmin     = in_array($role, ['admin', 'admin_plus'], true);
    $isAdminPlus = ($role === 'admin_plus');

    $roleLabel = match($role) {
        'admin_plus' => 'Admin+',
        'admin'      => 'Admin',
        default      => 'Bénévole',
    };
    $roleDotClass = match($role) {
        'admin_plus' => 'admin-plus',
        'admin'      => '',
        default      => 'public',
    };

    $volunteerName = function_exists('current_volunteer_name') ? current_volunteer_name() : '';
    $displayName   = ($isAdmin && $volunteerName !== '' && $volunteerName !== 'Inconnu') ? $volunteerName : null;

    $loginUrl  = function_exists('suite_login_url')  ? suite_login_url()  : ($base . '/admin/login.php');
    $logoutUrl = function_exists('suite_logout_url') ? suite_logout_url() : ($base . '/admin/logout.php');

    function _nav_active(string $key, string $activeItem, string $activeModule, string $module): string {
        if ($key === $activeItem) return 'tu-active';
        if ($activeItem === '' && $module === $activeModule) return 'tu-active';
        return '';
    }
    function _mod_active(string $module, string $activeModule): string {
        return $module === $activeModule ? 'tu-active' : '';
    }
    function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
    ?>
<aside class="tu-sb" id="tu-sidebar">

  <a href="<?= h2($base . '/index.php') ?>" class="tu-sb-brand">
    <div class="tu-sb-logo">TU</div>
    <div>
      <div class="tu-sb-name">Touraine-Ukraine</div>
      <div class="tu-sb-sub">Suite associative</div>
    </div>
  </a>

  <nav class="tu-sb-nav">
    <span class="tu-sb-sec">Modules</span>

    <!-- PLANNING -->
    <a href="<?= h2($base . '/planning/events.php') ?>"
       class="tu-sb-item <?= _mod_active('planning', $activeModule) ?>"
       id="snav-planning">
      <svg class="tu-sb-ico" viewBox="0 0 24 24">
        <rect x="3" y="4" width="18" height="18" rx="2"/>
        <path d="M16 2v4M8 2v4M3 10h18"/>
      </svg>
      Planning
    </a>
    <?php if ($activeModule === 'planning'): ?>
    <div class="tu-sb-sub-nav">
      <a href="<?= h2($base . '/planning/events.php') ?>"
         class="tu-sb-item <?= _nav_active('planning-vol', $activeItem, $activeModule, 'planning') ?>">
        Vue bénévoles
      </a>
      <?php if ($isAdmin): ?>
      <a href="<?= h2($base . '/planning/admin/events_list.php') ?>"
         class="tu-sb-item <?= _nav_active('planning-admin', $activeItem, $activeModule, 'planning') ?>">
        Gestion événements
      </a>
      <a href="<?= h2($base . '/planning/admin/event_edit.php') ?>"
         class="tu-sb-item <?= _nav_active('planning-create', $activeItem, $activeModule, 'planning') ?>">
        Créer un événement
      </a>
      <a href="<?= h2($base . '/planning/admin/donations.php') ?>"
         class="tu-sb-item <?= _nav_active('planning-dons', $activeItem, $activeModule, 'planning') ?>">
        Dons
      </a>
      <a href="<?= h2($base . '/planning/admin/report_activity.php') ?>"
         class="tu-sb-item <?= _nav_active('planning-cra', $activeItem, $activeModule, 'planning') ?>">
        Compte-rendu d'activité
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- CAISSE -->
    <a href="<?= h2($base . '/caisse/index.php') ?>"
       class="tu-sb-item <?= _mod_active('caisse', $activeModule) ?>"
       id="snav-caisse">
      <svg class="tu-sb-ico" viewBox="0 0 24 24">
        <rect x="3" y="7" width="18" height="10" rx="1"/>
        <circle cx="8" cy="12" r="2"/>
        <path d="M13 10h4M13 12h3"/>
      </svg>
      Caisse
    </a>
    <?php if ($activeModule === 'caisse'): ?>
    <div class="tu-sb-sub-nav">
      <a href="<?= h2($base . '/caisse/index.php') ?>"
         class="tu-sb-item <?= _nav_active('caisse-pdv', $activeItem, $activeModule, 'caisse') ?>">
        Point de vente
      </a>
      <a href="<?= h2($base . '/caisse/evenements.php') ?>"
         class="tu-sb-item <?= _nav_active('caisse-events', $activeItem, $activeModule, 'caisse') ?>">
        Événements
      </a>
      <?php if ($isAdmin): ?>
      <a href="<?= h2($base . '/caisse/dashboard.php') ?>"
         class="tu-sb-item <?= _nav_active('caisse-dash', $activeItem, $activeModule, 'caisse') ?>">
        Dashboard
      </a>
      <a href="<?= h2($base . '/caisse/stock.php') ?>"
         class="tu-sb-item <?= _nav_active('caisse-stock', $activeItem, $activeModule, 'caisse') ?>">
        Stock produits
      </a>
      <a href="<?= h2($base . '/caisse/retraits_caisse.php') ?>"
         class="tu-sb-item <?= _nav_active('caisse-retraits', $activeItem, $activeModule, 'caisse') ?>">
        Retraits
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- LOGISTIQUE -->
    <a href="<?= h2($base . '/logistique/index.php') ?>"
       class="tu-sb-item <?= _mod_active('logistique', $activeModule) ?>"
       id="snav-logistique">
      <svg class="tu-sb-ico" viewBox="0 0 24 24">
        <path d="M3 7h11v10H3zM14 10h4l2 3v4h-6z"/>
        <circle cx="7" cy="17" r="2"/>
        <circle cx="17" cy="17" r="2"/>
      </svg>
      Convois
    </a>
    <?php if ($activeModule === 'logistique'): ?>
    <div class="tu-sb-sub-nav">
      <a href="<?= h2($base . '/logistique/index.php') ?>"
         class="tu-sb-item <?= _nav_active('logistique-convois', $activeItem, $activeModule, 'logistique') ?>">
        Convois
      </a>
      <a href="<?= h2($base . '/logistique/categories/view.php') ?>"
         class="tu-sb-item <?= _nav_active('logistique-categories', $activeItem, $activeModule, 'logistique') ?>">
        Catégories
      </a>
      <a href="<?= h2($base . '/logistique/stock/index.php') ?>"
         class="tu-sb-item <?= _nav_active('logistique-stock', $activeItem, $activeModule, 'logistique') ?>">
        Stock local
      </a>
      <a href="<?= h2($base . '/logistique/stock/categories/index.php') ?>"
         class="tu-sb-item <?= _nav_active('logistique-stock-categories', $activeItem, $activeModule, 'logistique') ?>">
        Catégories stock
      </a>
      <a href="<?= h2($base . '/logistique/families/index.php') ?>"
         class="tu-sb-item <?= _nav_active('logistique-families', $activeItem, $activeModule, 'logistique') ?>">
        Familles
      </a>
      <a href="<?= h2($base . '/logistique/labels/labels.php') ?>"
         class="tu-sb-item <?= _nav_active('logistique-labels', $activeItem, $activeModule, 'logistique') ?>">
        Étiquettes
      </a>
      <a href="<?= h2($base . '/logistique/stats.php') ?>"
         class="tu-sb-item <?= _nav_active('logistique-stats', $activeItem, $activeModule, 'logistique') ?>">
        Statistiques
      </a>
    </div>
    <?php endif; ?>

    <!-- ANNUAIRE -->
    <a href="<?= h2($base . '/logistique/families/index.php') ?>"
       class="tu-sb-item <?= _mod_active('annuaire', $activeModule) ?>"
       id="snav-annuaire">
      <svg class="tu-sb-ico" viewBox="0 0 24 24">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
      </svg>
      Annuaire
    </a>

    <div class="tu-sb-div"></div>
    <span class="tu-sb-sec">Administration</span>

    <?php if ($isAdmin): ?>
    <!-- UTILISATEURS -->
    <a href="<?= h2($base . '/admin/users.php') ?>"
       class="tu-sb-item <?= _mod_active('users', $activeModule) ?>"
       id="snav-users">
      <svg class="tu-sb-ico" viewBox="0 0 24 24">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
      </svg>
      Utilisateurs
    </a>

    <!-- JOURNAL D'AUDIT -->
    <a href="<?= h2($base . '/admin/audit_log.php') ?>"
       class="tu-sb-item <?= _mod_active('audit', $activeModule) ?>"
       id="snav-audit">
      <svg class="tu-sb-ico" viewBox="0 0 24 24">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/>
        <line x1="16" y1="17" x2="8" y2="17"/>
      </svg>
      Journal d'audit
    </a>

    <!-- ADHÉSIONS -->
    <a href="<?= h2($base . '/adhesions/index.php') ?>"
       class="tu-sb-item <?= _mod_active('adhesions', $activeModule) ?>"
       id="snav-adhesions">
      <svg class="tu-sb-ico" viewBox="0 0 24 24">
        <rect x="3" y="6" width="18" height="12" rx="2"/>
        <circle cx="9" cy="11" r="2"/>
        <path d="M14 10h4M14 12h4"/>
      </svg>
      Adhésions
    </a>
    <?php if ($activeModule === 'adhesions'): ?>
    <div class="tu-sb-sub-nav">
      <a href="<?= h2($base . '/adhesions/index.php') ?>"
         class="tu-sb-item <?= _nav_active('adhesions-list', $activeItem, $activeModule, 'adhesions') ?>">
        Liste membres
      </a>
      <?php if ($isAdminPlus): ?>
      <a href="<?= h2($base . '/adhesions/statistiques.php') ?>"
         class="tu-sb-item <?= _nav_active('adhesions-stats', $activeItem, $activeModule, 'adhesions') ?>">
        Statistiques
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- SUBVENTIONS -->
    <a href="<?= h2($base . '/subventions/index.php') ?>"
       class="tu-sb-item <?= _mod_active('subventions', $activeModule) ?>"
       id="snav-subventions">
      <svg class="tu-sb-ico" viewBox="0 0 24 24">
        <rect x="4" y="7" width="16" height="13" rx="2"/>
        <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M4 13h16"/>
      </svg>
      Subventions
    </a>
    <?php if ($activeModule === 'subventions'): ?>
    <div class="tu-sb-sub-nav">
      <a href="<?= h2($base . '/subventions/index.php') ?>"
         class="tu-sb-item <?= _nav_active('subventions-list', $activeItem, $activeModule, 'subventions') ?>">
        Liste demandes
      </a>
      <?php if ($isAdminPlus): ?>
      <a href="<?= h2($base . '/subventions/stats.php') ?>"
         class="tu-sb-item <?= _nav_active('subventions-stats', $activeItem, $activeModule, 'subventions') ?>">
        Statistiques
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

  </nav>

  <div class="tu-sb-foot">
    <div class="tu-role-row">
      <div class="tu-role-dot <?= h2($roleDotClass) ?>"></div>
      <span class="tu-role-lbl">
        <?php if ($displayName): ?>
          <?= h2($displayName) ?>
          <span style="opacity:.6;font-weight:400;">· <?= h2($roleLabel) ?></span>
        <?php else: ?>
          <?= h2($roleLabel) ?>
        <?php endif; ?>
      </span>
      <?php if ($isAdmin): ?>
        <a href="<?= h2($logoutUrl) ?>" class="tu-logout-btn">Déco</a>
      <?php else: ?>
        <a href="<?= h2($loginUrl) ?>" class="tu-logout-btn" style="color:rgba(255,255,255,.5);">Connexion</a>
      <?php endif; ?>
    </div>
  </div>

</aside>

<!-- Hamburger : APRÈS l'aside (pas d'ancêtre avec transform) -->
<button class="tu-hamburger" id="tu-hamburger" onclick="tuSidebarOpen()" aria-label="Ouvrir le menu" type="button">
  <span></span><span></span><span></span>
</button>

<!-- Overlay -->
<div class="tu-overlay" id="tu-overlay" onclick="tuSidebarClose()"></div>

<script>
(function() {
  var sidebar   = document.getElementById('tu-sidebar');
  var overlay   = document.getElementById('tu-overlay');

  var closeBtn = document.createElement('button');
  closeBtn.className = 'tu-sb-close';
  closeBtn.setAttribute('aria-label', 'Fermer le menu');
  closeBtn.setAttribute('type', 'button');
  closeBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>';
  closeBtn.onclick = function() { tuSidebarClose(); };
  document.body.appendChild(closeBtn);

  window.tuSidebarOpen = function() {
    sidebar.classList.add('tu-open');
    overlay.classList.add('tu-open');
    document.body.classList.add('tu-sb-open');
  };
  window.tuSidebarClose = function() {
    sidebar.classList.remove('tu-open');
    overlay.classList.remove('tu-open');
    document.body.classList.remove('tu-sb-open');
  };

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') tuSidebarClose();
  });

  sidebar.querySelectorAll('a').forEach(function(link) {
    link.addEventListener('click', function() {
      if (window.innerWidth <= 900) tuSidebarClose();
    });
  });
})();
</script>
<?php
} // end suite_nav_render

} // end if !function_exists

// Alias rétrocompatibilité
if (!function_exists('suite_nav_v2_render')) {
    function suite_nav_v2_render(string $activeModule = '', string $activeItem = ''): void {
        suite_nav_render($activeModule, $activeItem);
    }
}
