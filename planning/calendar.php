<?php
// Compat : redirige vers la vue unifiée du planning
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = 'events.php' . ($qs ? ('?' . $qs) : '');
header('Location: ' . $target);
exit;