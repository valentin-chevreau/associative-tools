<?php
declare(strict_types=1);

/**
 * reports/includes/layout.php
 * Gabarit commun du module Rapports (sidebar de la suite + zone principale).
 *
 * Avant l'include, la page positionne :
 *   $title            titre de l'onglet
 *   $content          HTML du corps (topbar + page)
 *   $suiteActiveItem  entrée de sous-menu active (reports-home, reports-cra, reports-vol, reports-don)
 */

require_once dirname(__DIR__, 2) . '/shared/bootstrap.php';
require_once dirname(__DIR__, 2) . '/shared/suite_nav.php';
require_once __DIR__ . '/metrics.php';

$title = $title ?? 'Rapports';
$content = $content ?? '';
$suiteActiveItem = $suiteActiveItem ?? 'reports-home';
$suiteBase = suite_base();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= rep_h($title) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <link rel="stylesheet" href="<?= rep_h($suiteBase) ?>/assets/css/suite_nav.css<?= function_exists('suite_css_v') ? suite_css_v() : '' ?>"><?= function_exists('suite_pwa_head') ? suite_pwa_head() : '' ?>
  <?= rep_common_css() ?>
</head>
<body class="tu-v2">
<?php suite_nav_render('reports', $suiteActiveItem); ?>
<div class="tu-main">
<?= $content ?>
</div>
</body>
</html>
