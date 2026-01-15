<?php
// login_admin.php

session_start();
require_once 'db.php';

// ⚠️ Mets ici ton vrai code admin
$ADMIN_CODE  = '21031426';
$CODE_LENGTH = strlen($ADMIN_CODE);

$error = '';

// URL de redirection souhaitée :
// - priorité au ?redirect= dans l'URL
// - sinon ce qu'on a mémorisé en session (page précédente)
// - sinon index.php
$redirect = $_GET['redirect'] ?? ($_SESSION['login_redirect'] ?? 'index.php');

// Si on arrive en GET sans redirect explicite, on essaie de mémoriser la page d'origine
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['redirect'])) {
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $ref = $_SERVER['HTTP_REFERER'];

        // On évite les boucles vers login/logout
        if (
            strpos($ref, 'login_admin.php') === false &&
            strpos($ref, 'logout_admin.php') === false
        ) {
            $_SESSION['login_redirect'] = $ref;
            $redirect = $ref;
        }
    }
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');

    if ($code === $ADMIN_CODE) {
        $_SESSION['is_admin'] = true;

        // On nettoie la valeur de redirection en session
        $target = $_POST['redirect'] ?? $_SESSION['login_redirect'] ?? 'index.php';
        unset($_SESSION['login_redirect']);

        // Sécurité minimale : si vide ou bizarre, fallback
        if (!$target || strpos($target, 'login_admin.php') !== false) {
            $target = 'index.php';
        }

        header('Location: ' . $target);
        exit;
    } else {
        $error = "Code incorrect.";
    }
}

require_once 'header.php';
?>

<style>
    .pad-card { border-radius: 1rem; }
    .dots { gap: .4rem; }
    .dot {
        width: 14px; height: 14px;
        border-radius: 999px;
        border: 2px solid #ccc;
        background: #f2f2f2;
    }
    .dot.filled { background: #198754; border-color:#198754; }
    .keypad button {
        border-radius: 999px !important;
        font-size: 1.3rem;
        padding: .6rem 0;
    }
</style>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm pad-card">
            <div class="card-body p-4">
                <h1 class="h5 text-center mb-2">Accès administration</h1>
                <p class="text-muted text-center small mb-3">
                    Saisis le code à <?= (int)$CODE_LENGTH ?> chiffres pour activer le mode admin.
                </p>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" id="adminForm">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <input type="hidden" name="code" id="codeInput">

                    <div class="d-flex justify-content-center dots mb-3" id="dots">
                        <?php for ($i=0; $i<$CODE_LENGTH; $i++): ?>
                            <span class="dot"></span>
                        <?php endfor; ?>
                    </div>

                    <div class="keypad d-grid gap-2 mb-2" style="max-width:260px;margin:auto;">
                        <?php
                        $rows = [[1,2,3],[4,5,6],[7,8,9]];
                        foreach ($rows as $r) {
                            echo '<div class="d-flex gap-2">';
                            foreach ($r as $n) {
                                echo "<button type='button' class='btn btn-light flex-fill key' data-key='$n'>$n</button>";
                            }
                            echo '</div>';
                        }
                        ?>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary flex-fill" id="clear">Effacer</button>
                            <button type="button" class="btn btn-light flex-fill key" data-key="0">0</button>
                            <button type="button" class="btn btn-outline-secondary flex-fill" id="back">&larr;</button>
                        </div>

                        <button type="submit" class="btn btn-success btn-lg">
                            Valider
                        </button>
                    </div>

                    <div class="text-center small">
                        <a href="<?= htmlspecialchars($redirect ?: 'index.php') ?>">Retour</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let buffer = "";
const maxLen = <?= (int)$CODE_LENGTH ?>;
const dots = [...document.querySelectorAll('.dot')];
const input = document.getElementById('codeInput');
const form = document.getElementById('adminForm');

function updateDots() {
    dots.forEach((d, i) => d.classList.toggle('filled', i < buffer.length));
    input.value = buffer;

    // 🔙 Auto-validation : quand on a tous les chiffres, on envoie le formulaire
    if (buffer.length === maxLen) {
        form.submit();
    }
}

document.querySelectorAll('.key').forEach(btn => {
    btn.onclick = () => {
        if (buffer.length < maxLen) {
            buffer += btn.dataset.key;
            updateDots();
        }
    };
});

document.getElementById('clear').onclick = () => {
    buffer = "";
    updateDots();
};

document.getElementById('back').onclick = () => {
    buffer = buffer.slice(0, -1);
    updateDots();
};

// Saisie clavier numérique possible aussi
document.addEventListener('keydown', (e) => {
    if (e.key >= '0' && e.key <= '9') {
        if (buffer.length < maxLen) {
            buffer += e.key;
            updateDots();
        }
    } else if (e.key === 'Backspace') {
        buffer = buffer.slice(0, -1);
        updateDots();
    } else if (e.key === 'Enter') {
        form.submit();
    }
});
</script>

<?php require 'footer.php'; ?>