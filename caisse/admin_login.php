<?php
require 'config.php';

// On renvoie juste du texte simple
header('Content-Type: text/plain; charset=utf-8');

// Code pas configuré
if (!defined('ADMIN_CODE') || ADMIN_CODE === '') {
    echo 'CONFIG_MISSING';
    exit;
}

// On récupère le code envoyé par la modale
$code = $_POST['admin_code'] ?? $_POST['code'] ?? '';
$code = trim($code);

// Vérification
if ($code === ADMIN_CODE) {
    // On force toutes les clés possibles à TRUE pour rester compatible
    $_SESSION['is_admin']        = true;
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_caisse']    = true;

    echo 'OK';
    exit;
}

// Mauvais code : on nettoie
$_SESSION['is_admin']        = false;
$_SESSION['admin_logged_in'] = false;
$_SESSION['admin_caisse']    = false;

echo 'KO';
exit;