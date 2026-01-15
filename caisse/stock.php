<?php
require 'config.php';
$page = 'stock';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$adminError = '';
$adminSuccess = '';

function redirect_self_keep_query($extra = []) {
    $query = $_GET;
    foreach ($extra as $k => $v) {
        $query[$k] = $v;
    }
    $qs = $query ? ('?'.http_build_query($query)) : '';
    header("Location: stock.php".$qs);
    exit;
}

/* -------------------------
   ACTIONS (admin only)
   ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {

    // Toggle actif/inactif
    if (isset($_POST['toggle_actif'])) {
        $id = (int)($_POST['product_id'] ?? 0);

        try {
            $stmt = $pdo->prepare("
                UPDATE produits
                SET actif = CASE WHEN actif = 1 THEN 0 ELSE 1 END
                WHERE id = ?
            ");
            $stmt->execute([$id]);

            $adminSuccess = "Statut du produit mis à jour.";
            redirect_self_keep_query(['ok' => 1]);
        } catch (Exception $e) {
            $adminError = "Erreur lors du changement de statut.";
        }
    }

    // Update produit (nom/prix/stock)
    if (isset($_POST['update_product'])) {
        $id   = (int)($_POST['product_id'] ?? 0);
        $nom  = trim($_POST['nom'] ?? '');
        $prix = str_replace(',', '.', trim($_POST['prix'] ?? ''));
        $prix = ($prix === '') ? null : (float)$prix;

        $isUnlimited = isset($_POST['stock_unlimited']) && $_POST['stock_unlimited'] === '1';
        $stockRaw    = trim($_POST['stock'] ?? '');
        $stock       = null;

        if (!$isUnlimited) {
            if ($stockRaw === '') {
                $stock = 0;
            } else {
                $stock = (int)$stockRaw;
                if ($stock < 0) $stock = 0;
            }
        } // sinon stock = NULL

        if ($nom === '') {
            $adminError = "Le nom est obligatoire.";
        } elseif ($prix === null || $prix < 0) {
            $adminError = "Le prix est invalide.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE produits
                    SET nom = ?, prix = ?, stock = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nom, $prix, $stock, $id]);

                $adminSuccess = "Produit mis à jour.";
                redirect_self_keep_query(['ok' => 1]);
            } catch (Exception $e) {
                $adminError = "Erreur lors de la mise à jour du produit.";
            }
        }
    }

    // Create produit
    if (isset($_POST['create_product'])) {
        $nom  = trim($_POST['nom'] ?? '');
        $prix = str_replace(',', '.', trim($_POST['prix'] ?? ''));
        $prix = ($prix === '') ? null : (float)$prix;

        $isUnlimited = isset($_POST['stock_unlimited']) && $_POST['stock_unlimited'] === '1';
        $stockRaw    = trim($_POST['stock'] ?? '');
        $stock       = null;

        if (!$isUnlimited) {
            if ($stockRaw === '') {
                $stock = 0;
            } else {
                $stock = (int)$stockRaw;
                if ($stock < 0) $stock = 0;
            }
        }

        $actif = isset($_POST['actif']) ? 1 : 0;

        if ($nom === '') {
            $adminError = "Le nom est obligatoire.";
        } elseif ($prix === null || $prix < 0) {
            $adminError = "Le prix est invalide.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO produits (nom, prix, stock, actif)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$nom, $prix, $stock, $actif]);

                $adminSuccess = "Produit créé.";
                redirect_self_keep_query(['ok' => 1]);
            } catch (Exception $e) {
                $adminError = "Erreur lors de la création du produit.";
            }
        }
    }
}

if (isset($_GET['ok']) && !$adminSuccess) {
    $adminSuccess = "Action effectuée.";
}

/* -------------------------
   FILTRES
   ------------------------- */
$state = $_GET['state'] ?? 'all';     // all | actif | inactif
$q     = trim($_GET['q'] ?? '');      // recherche

$where = [];
$params = [];

if ($state === 'actif') {
    $where[] = "actif = 1";
} elseif ($state === 'inactif') {
    $where[] = "actif = 0";
}

if ($q !== '') {
    $where[] = "nom LIKE ?";
    $params[] = '%'.$q.'%';
}

$sql = "SELECT id, nom, prix, stock, actif FROM produits";
if ($where) {
    $sql .= " WHERE ".implode(" AND ", $where);
}
$sql .= " ORDER BY actif DESC, nom ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stat global
$counts = $pdo->query("
    SELECT
      SUM(CASE WHEN actif=1 THEN 1 ELSE 0 END) AS n_actifs,
      SUM(CASE WHEN actif=0 THEN 1 ELSE 0 END) AS n_inactifs,
      COUNT(*) AS n_total
    FROM produits
")->fetch(PDO::FETCH_ASSOC);

$nTotal    = (int)($counts['n_total'] ?? 0);
$nActifs   = (int)($counts['n_actifs'] ?? 0);
$nInactifs = (int)($counts['n_inactifs'] ?? 0);

?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Stock – Caisse Associative</title>

  <!-- (option) tu peux garder ton style global si tu en as un -->
  <link rel="stylesheet" href="assets/css/stock.css?v=2">
</head>
<body>

<?php require_once __DIR__ . '/../shared/suite_nav.php'; ?>

<?php include 'nav.php'; ?>
<?php include 'admin_modal.php'; ?>

<div class="app">
  <div class="card">
    <div class="header-row">
      <div>
        <h2>📦 Stock</h2>
        <div class="small">
          Total : <strong><?= $nTotal ?></strong> •
          Actifs : <strong><?= $nActifs ?></strong> •
          Inactifs : <strong><?= $nInactifs ?></strong>
        </div>
      </div>

      <a class="link-back" href="index.php">← Retour à la caisse</a>
    </div>

    <?php if($adminError): ?>
      <div class="alert error"><?= htmlspecialchars($adminError) ?></div>
    <?php endif; ?>

    <?php if($adminSuccess): ?>
      <div class="alert success"><?= htmlspecialchars($adminSuccess) ?></div>
    <?php endif; ?>

    <form class="filters" method="get">
      <select name="state" onchange="this.form.submit()">
        <option value="all"    <?= $state==='all'?'selected':'' ?>>Tous</option>
        <option value="actif"  <?= $state==='actif'?'selected':'' ?>>Actifs</option>
        <option value="inactif"<?= $state==='inactif'?'selected':'' ?>>Inactifs</option>
      </select>

      <input
        type="search"
        name="q"
        value="<?= htmlspecialchars($q) ?>"
        placeholder="Rechercher un produit…"
      />

      <button type="submit" class="btn-primary">Filtrer</button>

      <?php if($q !== '' || $state !== 'all'): ?>
        <a class="btn-secondary" href="stock.php">Réinitialiser</a>
      <?php endif; ?>
    </form>

    <?php if (!is_admin()): ?>
      <div class="alert info">
        Pour modifier le stock / activer / désactiver, active le <strong>mode administrateur</strong>.
      </div>
    <?php endif; ?>
  </div>

  <?php if (is_admin()): ?>
    <div class="card">
      <h3>➕ Ajouter un produit</h3>

      <form method="post" class="product-form create-form">
        <input type="hidden" name="create_product" value="1"/>

        <div class="form-grid">
          <label>
            <span class="label">Nom</span>
            <input type="text" name="nom" required placeholder="Ex: Bouteille d’eau">
          </label>

          <label>
            <span class="label">Prix (€)</span>
            <input type="number" step="0.01" min="0" name="prix" required placeholder="Ex: 2.00">
          </label>

          <label class="stock-wrap">
            <span class="label">Stock</span>
            <input type="number" min="0" name="stock" placeholder="Ex: 12">
            <div class="hint">Laisse vide = 0</div>
          </label>

          <label class="switch">
            <input type="checkbox" name="stock_unlimited" value="1">
            <span>Stock illimité</span>
          </label>

          <label class="switch">
            <input type="checkbox" name="actif" checked>
            <span>Produit actif</span>
          </label>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn-primary">Créer</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div class="card">
    <h3>Liste des produits</h3>

    <div class="table-wrapper">
      <table class="products-table">
        <thead>
          <tr>
            <th>Produit</th>
            <th>Prix</th>
            <th>Stock</th>
            <th>Statut</th>
            <?php if(is_admin()): ?>
              <th>Actions</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>

        <?php foreach($produits as $p):
          $id    = (int)$p['id'];
          $nom   = $p['nom'];
          $prix  = (float)$p['prix'];
          $stock = $p['stock']; // null = illimité
          $actif = (int)$p['actif'] === 1;
          $isOut = ($stock !== null && (int)$stock <= 0);
        ?>
          <tr class="<?= $actif ? '' : 'row-inactive' ?>">
            <td>
              <div class="prod-name">
                <?= htmlspecialchars($nom) ?>
                <?php if($isOut && $actif): ?>
                  <span class="chip chip-out">Stock à 0</span>
                <?php endif; ?>
              </div>
              <div class="small">ID: <?= $id ?></div>
            </td>

            <td><?= number_format($prix, 2) ?> €</td>

            <td>
              <?php if($stock === null): ?>
                <span class="chip chip-unlimited">Illimité</span>
              <?php else: ?>
                <?= (int)$stock ?>
              <?php endif; ?>
            </td>

            <td>
              <?php if($actif): ?>
                <span class="badge badge-on">Actif</span>
              <?php else: ?>
                <span class="badge badge-off">Inactif</span>
              <?php endif; ?>
            </td>

            <?php if(is_admin()): ?>
              <td class="actions">
                <div class="actions-row">
                  <form method="post" class="inline">
                    <input type="hidden" name="toggle_actif" value="1">
                    <input type="hidden" name="product_id" value="<?= $id ?>">
                    <button type="submit" class="btn-secondary">
                      <?= $actif ? 'Désactiver' : 'Activer' ?>
                    </button>
                  </form>

                  <button type="button" class="btn-secondary js-edit-btn" data-edit="<?= $id ?>">
                    Modifier
                  </button>
                </div>

                <!-- Form d'édition (collapsé) -->
                <div class="edit-panel" id="edit-<?= $id ?>" hidden>
                  <form method="post" class="product-form">
                    <input type="hidden" name="update_product" value="1">
                    <input type="hidden" name="product_id" value="<?= $id ?>">

                    <div class="form-grid">
                      <label>
                        <span class="label">Nom</span>
                        <input type="text" name="nom" required value="<?= htmlspecialchars($nom, ENT_QUOTES) ?>">
                      </label>

                      <label>
                        <span class="label">Prix (€)</span>
                        <input type="number" step="0.01" min="0" name="prix" required value="<?= number_format($prix,2,'.','') ?>">
                      </label>

                      <label class="stock-wrap">
                        <span class="label">Stock</span>
                        <input
                          type="number"
                          min="0"
                          name="stock"
                          value="<?= $stock === null ? '' : (int)$stock ?>"
                          placeholder="Ex: 12"
                        >
                        <div class="hint">Laisse vide = 0</div>
                      </label>

                      <label class="switch">
                        <input type="checkbox" name="stock_unlimited" value="1" <?= $stock === null ? 'checked' : '' ?>>
                        <span>Stock illimité</span>
                      </label>
                    </div>

                    <div class="form-actions">
                      <button type="submit" class="btn-primary">Enregistrer</button>
                      <button type="button" class="btn-secondary js-cancel-btn" data-cancel="<?= $id ?>">Fermer</button>
                    </div>
                  </form>
                </div>
              </td>
            <?php endif; ?>

          </tr>
        <?php endforeach; ?>

        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="assets/js/stock.js?v=1"></script>
</body>
</html>