<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';

$isAdmin    = !empty($_SESSION['is_admin']);
$simpleMode = !empty($_SESSION['simple_mode']);

$path = (string)($_SERVER['PHP_SELF'] ?? '');

function nav_is_active(string $needle, string $haystack): bool {
    return $needle !== '' && strpos($haystack, $needle) !== false;
}

$isConvoisActive = nav_is_active('/convoys', $path) || nav_is_active('/boxes', $path) || nav_is_active('/pallets', $path) || nav_is_active('/labels', $path) || nav_is_active('/categories', $path) || nav_is_active('/index.php', $path);
$isStockActive   = nav_is_active('/stock/', $path);
$isFamiliesActive= nav_is_active('/families/', $path);
$isStatsActive   = nav_is_active('/stats.php', $path);

?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Touraine-Ukraine – Convois</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        :root {
            --tu-blue: #0057b7;
            --tu-yellow: #ffd54f;
        }

        body { background: #f5f5f7; }

        .navbar-tua { background: linear-gradient(135deg, var(--tu-blue), #003c87); }

        .navbar-brand span {
            font-weight: 600;
            letter-spacing: .02em;
        }

        .badge-role {
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .btn { border-radius: 999px; }

        .btn-primary { background-color: var(--tu-blue); border-color: var(--tu-blue); }
        .btn-primary:hover, .btn-primary:focus { background-color: #004399; border-color: #004399; }

        .btn-outline-primary { color: var(--tu-blue); border-color: var(--tu-blue); }
        .btn-outline-primary:hover { background-color: var(--tu-blue); color: #fff; }

        .btn-warning { background-color: var(--tu-yellow); border-color: var(--tu-yellow); color: #333; }
        .btn-warning:hover { background-color: #ffc93b; border-color: #ffc93b; color: #111; }

        .card { border-radius: 1rem; border: none; }
        .card.shadow-sm { box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08) !important; }

        .badge.bg-primary, .badge.bg-info { background-color: var(--tu-blue) !important; }

        main.app-main { max-width: 1000px; }

        /* dropdown un peu plus lisible sur fond bleu */
        .navbar .dropdown-menu {
            border-radius: 0.9rem;
            border: none;
            box-shadow: 0 10px 30px rgba(15,23,42,.18);
            padding: .5rem;
        }
        .navbar .dropdown-item {
            border-radius: .7rem;
            padding: .55rem .75rem;
        }
        .navbar .dropdown-item:active {
            background: var(--tu-blue);
        }

        @media (max-width: 575.98px) {
            .navbar-brand small { display: none; }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . "/../shared/suite_nav.php"; ?>

<nav class="navbar navbar-expand-md navbar-dark navbar-tua shadow-sm mb-3 no-print">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= APP_BASE ?>/index.php">
            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center"
                 style="width:32px;height:32px;">
                <span class="fw-bold" style="color:var(--tu-blue);">TU</span>
            </div>
            <div class="d-flex flex-column">
                <span>Touraine-Ukraine</span>
                <small class="text-white-50">Outil logistique</small>
            </div>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#mainNav" aria-controls="mainNav"
                aria-expanded="false" aria-label="Menu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse mt-2 mt-md-0" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-md-0">

                <!-- CONVOIS (dropdown) -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?= $isConvoisActive ? ' active' : '' ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-box-seam"></i> Convois
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="<?= APP_BASE ?>/index.php">
                                <i class="bi bi-list-ul me-1"></i> Tous les convois
                            </a>
                        </li>
                        <?php if ($isAdmin): ?>
                            <li>
                                <a class="dropdown-item" href="<?= APP_BASE ?>/convoys/edit.php">
                                    <i class="bi bi-plus-circle me-1"></i> Nouveau convoi
                                </a>
                            </li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?= APP_BASE ?>/categories/view.php">
                                <i class="bi bi-tags me-1"></i> Catégories (convois)
                            </a>
                        </li>
                        <?php if (is_file(__DIR__ . '/pallets/index.php')): ?>
                            <li>
                                <a class="dropdown-item" href="<?= APP_BASE ?>/pallets/index.php">
                                    <i class="bi bi-boxes me-1"></i> Palettes
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (is_file(__DIR__ . '/labels/index.php')): ?>
                            <li>
                                <a class="dropdown-item" href="<?= APP_BASE ?>/labels/index.php">
                                    <i class="bi bi-upc-scan me-1"></i> Étiquettes
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>

                <!-- STOCK LOCAL (dropdown) -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?= $isStockActive ? ' active' : '' ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-house-heart"></i> Stock local
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="<?= APP_BASE ?>/stock/index.php">
                                <i class="bi bi-archive me-1"></i> Objets en stock
                            </a>
                        </li>
                        <?php if ($isAdmin): ?>
                            <li>
                                <a class="dropdown-item" href="<?= APP_BASE ?>/stock/item_edit.php">
                                    <i class="bi bi-plus-circle me-1"></i> Ajouter un objet
                                </a>
                            </li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?= APP_BASE ?>/stock/categories/index.php">
                                <i class="bi bi-tags me-1"></i> Catégories
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?= APP_BASE ?>/stock/locations/index.php">
                                <i class="bi bi-geo-alt me-1"></i> Lieux
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- FAMILLES (dropdown) -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?= $isFamiliesActive ? ' active' : '' ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-people"></i> Familles
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="<?= APP_BASE ?>/families/index.php">
                                <i class="bi bi-list-ul me-1"></i> Liste des familles
                            </a>
                        </li>
                        <?php if ($isAdmin): ?>
                            <li>
                                <a class="dropdown-item" href="<?= APP_BASE ?>/families/edit.php">
                                    <i class="bi bi-plus-circle me-1"></i> Nouvelle famille
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>

                <!-- STATS -->
                <li class="nav-item">
                    <a class="nav-link<?= $isStatsActive ? ' active' : '' ?>"
                       href="<?= APP_BASE ?>/stats.php">
                        <i class="bi bi-graph-up"></i> Stats
                    </a>
                </li>

            </ul>

            <div class="d-flex align-items-center gap-2">
                <?php if ($simpleMode): ?>
                    <span class="badge bg-light text-dark badge-role">
                        Mode bénévole
                    </span>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                    <span class="badge bg-warning text-dark badge-role">
                        Admin
                    </span>
                    <a href="<?= APP_BASE ?>/logout_admin.php" class="btn btn-sm btn-outline-light">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                <?php else: ?>
                    <a href="<?= APP_BASE ?>/login_admin.php" class="btn btn-sm btn-outline-light">
                        <i class="bi bi-shield-lock"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<main class="app-main container py-3 py-md-4">
