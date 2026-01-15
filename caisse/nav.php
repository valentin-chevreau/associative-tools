<?php
// nav.php – barre de navigation + gestion globale du mode admin
// Suppose que $page est défini avant l'include
?>
<nav id="main-nav" style="
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    margin:0 auto 12px auto;
    max-width:1100px;
    font-family:system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
">
    <!-- Zone gauche : logo + liens -->
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;">
        <div style="
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:4px 10px;
            border-radius:999px;
            background:linear-gradient(90deg,#1d4ed8,#facc15);
            color:#f9fafb;
            font-weight:700;
            font-size:13px;
        ">
            <span>🇺🇦</span>
            <span>Touraine-Ukraine</span>
        </div>

        <?php
        $currentPage = $page ?? '';

        // lien standard
        function nav_link($href, $label, $key, $currentPage) {
            $isActive = ($currentPage === $key);
            $base = "text-decoration:none;padding:4px 10px;border-radius:999px;font-size:13px;border:1px solid transparent;";
            if ($isActive) {
                $style = $base . "background:#2563eb;color:#fff;border-color:#2563eb;font-weight:600;";
            } else {
                $style = $base . "background:#e5e7eb;color:#111827;";
            }
            echo '<a href="'.$href.'" style="'.$style.'">'.htmlspecialchars($label).'</a>';
        }

        // lien réservé admin
        function nav_link_admin($href, $label, $key, $currentPage) {
            if (!function_exists('is_admin') || !is_admin()) {
                return; // rien à afficher si pas admin
            }
            nav_link($href, $label, $key, $currentPage);
        }
        ?>

        <?php nav_link('index.php',      'Caisse',     'caisse',     $currentPage); ?>
        <?php nav_link_admin('stock.php','Stock',      'stock',      $currentPage); ?>
        <?php nav_link('evenements.php', 'Évènements', 'evenements', $currentPage); ?>
        <?php nav_link_admin('benevoles.php','Bénévoles','benevoles', $currentPage); ?>
        <?php nav_link_admin('dashboard.php','Dashboard','dashboard', $currentPage); ?>
        <?php nav_link_admin('retraits_caisse.php','Retraits caisse','retraits_caisse', $currentPage); ?>
    </div>

    <!-- Zone droite : statut admin global -->
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end;min-width:0;">
        <?php if (function_exists('is_admin') && is_admin()): ?>
            <span style="
                display:inline-flex;
                align-items:center;
                gap:6px;
                padding:4px 10px;
                border-radius:999px;
                background:#dcfce7;
                color:#166534;
                font-size:13px;
                font-weight:600;
                white-space:nowrap;
            ">
                <span>🔓</span>
                <span>Mode admin actif</span>
            </span>
            <a href="logout.php" style="
                text-decoration:none;
                padding:4px 10px;
                border-radius:999px;
                background:#fee2e2;
                color:#b91c1c;
                font-size:13px;
                font-weight:600;
                border:1px solid #fecaca;
                white-space:nowrap;
            ">
                Se déconnecter
            </a>
        <?php else: ?>
            <span style="
                display:inline-flex;
                align-items:center;
                gap:6px;
                padding:4px 10px;
                border-radius:999px;
                background:#e5e7eb;
                color:#374151;
                font-size:13px;
                font-weight:500;
                white-space:nowrap;
            ">
                <span>🔒</span>
                <span>Mode admin inactif</span>
            </span>
            <button type="button" onclick="showAdminModal()" style="
                border:0;
                padding:4px 10px;
                border-radius:999px;
                background:#2563eb;
                color:#fff;
                font-size:13px;
                font-weight:600;
                cursor:pointer;
                white-space:nowrap;
            ">
                Saisir le code admin
            </button>
        <?php endif; ?>
    </div>
</nav>