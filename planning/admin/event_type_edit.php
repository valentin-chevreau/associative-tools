<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$title = "Admin – Éditer un type d’événement";
ob_start();

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';

if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_authenticated'])) {
    header('Location: ' . $config['base_url'] . '/admin/login.php');
    exit;
}

global $pdo;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

$hasCategory = true;
try {
    $pdo->query("SELECT category_label, category_sort FROM event_types LIMIT 1");
} catch (Throwable $e) {
    $hasCategory = false;
}

$errors = [];
$success = null;

// defaults
$data = [
    'code' => '',
    'label' => '',
    'is_active' => 1,
    'sort_order' => 0,
    'category_label' => 'Autres',
    'category_sort' => 100,
];

if ($isEdit) {
    $cols = $hasCategory
        ? "id, code, label, is_active, sort_order, category_label, category_sort"
        : "id, code, label, is_active, sort_order";

    $stmt = $pdo->prepare("SELECT {$cols} FROM event_types WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        header('Location: ' . $config['base_url'] . '/admin/event_types_list.php?msg=' . urlencode("Type introuvable."));
        exit;
    }

    $data['code'] = (string)$row['code'];
    $data['label'] = (string)$row['label'];
    $data['is_active'] = (int)$row['is_active'];
    $data['sort_order'] = (int)$row['sort_order'];
    if ($hasCategory) {
        $data['category_label'] = (string)($row['category_label'] ?? 'Autres');
        $data['category_sort']  = (int)($row['category_sort'] ?? 100);
    }
}

// Suggestions de catégories (UX)
$categorySuggestions = [];
if ($hasCategory) {
    try {
        $stmt = $pdo->query("SELECT DISTINCT category_label FROM event_types WHERE category_label IS NOT NULL AND category_label <> '' ORDER BY category_label ASC");
        $categorySuggestions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $categorySuggestions = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim((string)($_POST['code'] ?? ''));
    $label = trim((string)($_POST['label'] ?? ''));
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $sort_order = (int)($_POST['sort_order'] ?? 0);

    $category_label = $data['category_label'];
    $category_sort  = $data['category_sort'];

    if ($hasCategory) {
        $category_label = trim((string)($_POST['category_label'] ?? 'Autres'));
        if ($category_label === '') $category_label = 'Autres';
        $category_sort  = (int)($_POST['category_sort'] ?? 100);
    }

    // Règles : code required en création, sinon non modifiable
    if (!$isEdit) {
        if ($code === '') {
            $errors[] = "Le code est obligatoire (ex : manutention).";
        } else {
            $code = strtolower($code);
            if (!preg_match('/^[a-z0-9_]+$/', $code)) {
                $errors[] = "Le code doit contenir uniquement des minuscules/numéros/underscore (a-z 0-9 _).";
            }
        }
    } else {
        // En édition : on force le code existant
        $code = $data['code'];
    }

    if ($label === '') {
        $errors[] = "Le libellé est obligatoire.";
    }

    if (empty($errors)) {
        if ($isEdit) {
            if ($hasCategory) {
                $stmt = $pdo->prepare("
                    UPDATE event_types
                    SET label = :label,
                        is_active = :is_active,
                        sort_order = :sort_order,
                        category_label = :category_label,
                        category_sort = :category_sort
                    WHERE id = :id
                ");
                $stmt->execute([
                    'label' => $label,
                    'is_active' => $is_active,
                    'sort_order' => $sort_order,
                    'category_label' => $category_label,
                    'category_sort' => $category_sort,
                    'id' => $id,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE event_types
                    SET label = :label,
                        is_active = :is_active,
                        sort_order = :sort_order
                    WHERE id = :id
                ");
                $stmt->execute([
                    'label' => $label,
                    'is_active' => $is_active,
                    'sort_order' => $sort_order,
                    'id' => $id,
                ]);
            }

            header('Location: ' . $config['base_url'] . '/admin/event_types_list.php?msg=' . urlencode("Type mis à jour."));
            exit;
        } else {
            // création : vérifier unicité code
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_types WHERE code = :code");
            $stmt->execute(['code' => $code]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = "Ce code existe déjà. Choisis-en un autre.";
            } else {
                if ($hasCategory) {
                    $stmt = $pdo->prepare("
                        INSERT INTO event_types (code, label, is_active, sort_order, category_label, category_sort)
                        VALUES (:code, :label, :is_active, :sort_order, :category_label, :category_sort)
                    ");
                    $stmt->execute([
                        'code' => $code,
                        'label' => $label,
                        'is_active' => $is_active,
                        'sort_order' => $sort_order,
                        'category_label' => $category_label,
                        'category_sort' => $category_sort,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO event_types (code, label, is_active, sort_order)
                        VALUES (:code, :label, :is_active, :sort_order)
                    ");
                    $stmt->execute([
                        'code' => $code,
                        'label' => $label,
                        'is_active' => $is_active,
                        'sort_order' => $sort_order,
                    ]);
                }

                header('Location: ' . $config['base_url'] . '/admin/event_types_list.php?msg=' . urlencode("Type créé."));
                exit;
            }
        }
    }

    // Remplir data pour ré-affichage
    $data['label'] = $label;
    $data['is_active'] = $is_active;
    $data['sort_order'] = $sort_order;
    if ($hasCategory) {
        $data['category_label'] = $category_label;
        $data['category_sort'] = $category_sort;
    }
}

?>
<style>
.form-grid {
  display:grid;
  grid-template-columns: 1fr;
  gap:12px;
}
@media (min-width: 820px) {
  .form-grid { grid-template-columns: 1fr 1fr; }
}
.field { display:flex; flex-direction:column; gap:6px; }
.field label { font-weight:700; font-size:13px; }
.hint { font-size:12px; color:#6b7280; margin:0; }
.kbd { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12px; background:#f3f4f6; border:1px solid #e5e7eb; padding:2px 6px; border-radius:8px; }
.pill {
  display:inline-flex; align-items:center; gap:6px;
  padding:6px 10px; border-radius:999px; border:1px solid #e5e7eb;
  background:#fff; font-size:12px; color:#111827; text-decoration:none;
}
.pill:hover { background:#f9fafb; }
.pill.primary { border-color:transparent; background:linear-gradient(135deg,#2563eb,#3b82f6); color:#fff; font-weight:700; }
.actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:12px; }
</style>

<div class="card">
  <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
    <div>
      <h2 style="margin:0 0 4px;"><?= $isEdit ? "Modifier un type" : "Ajouter un type" ?></h2>
      <p class="muted" style="margin:0;">
        Les types alimentent la création d’événements et le CRA. Les catégories servent au regroupement.
      </p>
    </div>
    <div class="actions" style="margin-top:0;">
      <a class="pill" href="<?= h($config['base_url']) ?>/admin/event_types_list.php">← Retour</a>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert" style="margin-top:12px;">
      <strong>Oups :</strong>
      <ul style="margin:6px 0 0; padding-left:18px;">
        <?php foreach ($errors as $e): ?>
          <li><?= h($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <form method="post" class="form-grid" autocomplete="off">
    <div class="field">
      <label>Code (technique)</label>
      <?php if ($isEdit): ?>
        <input type="text" value="<?= h($data['code']) ?>" disabled>
        <p class="hint">Le code est figé après création (stabilité des événements existants).</p>
      <?php else: ?>
        <input type="text" name="code" value="<?= h($data['code']) ?>" placeholder="ex: manutention" required>
        <p class="hint">Minuscules + underscore uniquement (ex: <span class="kbd">demenagement</span>).</p>
      <?php endif; ?>
    </div>

    <div class="field">
      <label>Libellé</label>
      <input type="text" name="label" value="<?= h($data['label']) ?>" placeholder="ex: Manutention" required>
      <p class="hint">Ce qui apparaît dans les listes.</p>
    </div>

    <?php if ($hasCategory): ?>
      <div class="field">
        <label>Catégorie (pour le CRA)</label>
        <input list="cats" type="text" name="category_label" value="<?= h($data['category_label']) ?>" placeholder="ex: Logistique">
        <datalist id="cats">
          <?php foreach ($categorySuggestions as $c): ?>
            <option value="<?= h($c) ?>"></option>
          <?php endforeach; ?>
        </datalist>
        <p class="hint">Regroupe plusieurs types ensemble (ex: Logistique = Manutention + Déménagement).</p>
      </div>

      <div class="field">
        <label>Ordre catégorie</label>
        <input type="number" name="category_sort" value="<?= (int)$data['category_sort'] ?>" min="0" step="1">
        <p class="hint">10 = en haut, 30 = après, etc.</p>
      </div>
    <?php endif; ?>

    <div class="field">
      <label>Ordre du type (dans sa catégorie)</label>
      <input type="number" name="sort_order" value="<?= (int)$data['sort_order'] ?>" min="0" step="1">
      <p class="hint">0 = premier, 10 = après, etc.</p>
    </div>

    <div class="field" style="align-self:end;">
      <label style="display:flex; gap:10px; align-items:center; font-weight:700;">
        <input type="checkbox" name="is_active" value="1" <?= ((int)$data['is_active'] === 1) ? 'checked' : '' ?>>
        Actif (disponible à la création)
      </label>
      <p class="hint">Tu peux désactiver sans perdre l’historique.</p>
    </div>

    <div style="grid-column: 1 / -1;">
      <div class="actions">
        <button type="submit" class="primary"><?= $isEdit ? "Enregistrer" : "Créer le type" ?></button>
        <a class="pill" href="<?= h($config['base_url']) ?>/admin/event_types_list.php">Annuler</a>
      </div>
    </div>
  </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';