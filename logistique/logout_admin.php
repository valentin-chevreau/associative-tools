<?php
// logout_admin.php

session_start();

// On ne touche qu'au flag admin, on laisse le reste des sessions si tu les utilises ailleurs
unset($_SESSION['is_admin']);

// On peut éventuellement nettoyer aussi la redirection de login
unset($_SESSION['login_redirect']);

$redirect = $_GET['redirect'] ?? 'index.php';

header('Location: ' . $redirect);
exit;