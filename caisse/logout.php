<?php
require 'config.php';

unset($_SESSION['is_admin']);
session_regenerate_id(true);

// Redirection après déconnexion
header('Location: index.php?logout=1');
exit;