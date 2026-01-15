<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$title = "Admin – Bénévoles";
ob_start();

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';

if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_authenticated'])) {
    header('Location: ' . $config['base_url'] . '/admin/login.php');
    exit;
}

global $pdo;

// Liste des bénévoles actifs
$stmt = $pdo->query("SELECT * FROM volunteers WHERE is_active = 1 ORDER BY last_name, first_name");
$actifs = $stmt->fetchAll();

// Liste des bénévoles inactifs (archives)
$stmt2 = $pdo->query("SELECT * FROM volunteers WHERE is_active = 0 ORDER BY last_name, first_name");
$archives = $stmt2->fetchAll();
?>
<div class="card">
  <h2>Gestion des bénévoles</h2>
  <p class="muted">
    Ajoute, modifie ou archive des bénévoles.  
    Les bénévoles archivés restent visibles pour l’historique, mais ne sont plus proposés aux inscriptions.
  </p>

  <div style="margin-top:10px;">
    <a href="<?= $config['base_url'] ?>/admin/volunteer_edit.php">
      <button type="button" class="primary">+ Ajouter un bénévole</button>
    </a>
    <a href="<?= $config['base_url'] ?>/admin/events_list.php">
      <button type="button">← Retour aux événements</button>
    </a>
  </div>
</div>

<div class="card">
  <h3>Bénévoles actifs</h3>
  <?php if (empty($actifs)): ?>
    <p class="muted">Aucun bénévole actif pour le moment.</p>
  <?php else: ?>
    <?php foreach ($actifs as $v): ?>
      <?php
        $fullName = trim(($v['last_name'] ?? '') . ' ' . ($v['first_name'] ?? ''));
        if ($fullName === '') $fullName = $v['first_name'] ?: 'Bénévole';
        $initials = '';
        if (!empty($v['first_name'])) $initials .= mb_substr($v['first_name'], 0, 1);
        if (!empty($v['last_name']))  $initials .= mb_substr($v['last_name'], 0, 1);
        if ($initials === '' && $fullName !== '') $initials = mb_substr($fullName, 0, 1);
      ?>
      <div style="display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid #e5e7eb;">
        <div style="display:flex; align-items:center; gap:8px;">
          <div class="attendee-avatar"><?= htmlspecialchars(mb_strtoupper($initials)) ?></div>
          <div>
            <strong><?= htmlspecialchars($fullName) ?></strong>
            <?php if (!empty($v['email'])): ?>
              <div class="muted"><?= htmlspecialchars($v['email']) ?></div>
            <?php endif; ?>
            <?php if (!empty($v['phone'])): ?>
              <div class="muted"><?= htmlspecialchars($v['phone']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <a href="<?= $config['base_url'] ?>/admin/volunteer_edit.php?id=<?= (int)$v['id'] ?>">
          <button type="button">Modifier</button>
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Archives</h3>
  <?php if (empty($archives)): ?>
    <p class="muted">Aucun bénévole archivé.</p>
  <?php else: ?>
    <?php foreach ($archives as $v): ?>
      <?php
        $fullName = trim(($v['last_name'] ?? '') . ' ' . ($v['first_name'] ?? ''));
        if ($fullName === '') $fullName = $v['first_name'] ?: 'Bénévole';
        $initials = '';
        if (!empty($v['first_name'])) $initials .= mb_substr($v['first_name'], 0, 1);
        if (!empty($v['last_name']))  $initials .= mb_substr($v['last_name'], 0, 1);
        if ($initials === '' && $fullName !== '') $initials = mb_substr($fullName, 0, 1);
      ?>
      <div style="display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid #e5e7eb;">
        <div style="display:flex; align-items:center; gap:8px; opacity:0.7;">
          <div class="attendee-avatar"><?= htmlspecialchars(mb_strtoupper($initials)) ?></div>
          <div>
            <strong><?= htmlspecialchars($fullName) ?></strong>
          </div>
        </div>
        <a href="<?= $config['base_url'] ?>/admin/volunteer_edit.php?id=<?= (int)$v['id'] ?>">
          <button type="button">Modifier</button>
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';