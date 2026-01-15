<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$title = "Admin – Générer des samedis";
ob_start();

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';

if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_authenticated'])) {
    header('Location: ' . $config['base_url'] . '/admin/login.php');
    exit;
}

global $pdo;

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startDate = $_POST['start_date'] ?? '';
    $weeks     = (int)($_POST['weeks'] ?? 4);

    if (!$startDate) {
        $error = "Merci de choisir une date de départ.";
    } elseif ($weeks <= 0) {
        $error = "Merci de choisir un nombre de semaines valide.";
    } else {
        $start = DateTime::createFromFormat('Y-m-d', $startDate);
        if (!$start) {
            $error = "Date invalide.";
        } else {
            $created = 0;
            $date = clone $start;

            for ($i = 0; $i < $weeks; $i++) {
                // Aller au samedi de la semaine correspondante
                if ((int)$date->format('N') !== 6) {
                    $date->modify('Saturday');
                }

                $startDT = (clone $date)->setTime(9, 30, 0);
                $endDT   = (clone $date)->setTime(12, 0, 0);

                // Vérifier si un événement existe déjà à cette date
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM events
                                       WHERE start_datetime = :start AND end_datetime = :end");
                $stmt->execute([
                    'start' => $startDT->format('Y-m-d H:i:s'),
                    'end'   => $endDT->format('Y-m-d H:i:s'),
                ]);
                $exists = (int)$stmt->fetchColumn();

                if (!$exists) {
                    $insert = $pdo->prepare("INSERT INTO events
                        (title, description, event_type, start_datetime, end_datetime,
                         min_volunteers, max_volunteers, is_cancelled)
                        VALUES
                        (:title, '', 'permanence', :start, :end, 0, NULL, 0)");
                    $insert->execute([
                        'title' => 'Permanence du samedi',
                        'start' => $startDT->format('Y-m-d H:i:s'),
                        'end'   => $endDT->format('Y-m-d H:i:s'),
                    ]);
                    $created++;
                }

                // Semaine suivante
                $date->modify('+7 days');
            }

            $message = "$created permanence(s) créée(s).";
        }
    }
}
?>
<div class="card">
  <h2>Générer des permanences du samedi</h2>
  <p class="muted">
    Crée automatiquement des permanences le samedi matin (9h30–12h00) sur plusieurs semaines.
  </p>

  <?php if (!empty($error)): ?>
    <p style="color:#b91c1c; margin-top:8px; font-weight:600;">
      <?= htmlspecialchars($error) ?>
    </p>
  <?php endif; ?>

  <?php if (!empty($message)): ?>
    <p style="color:#166534; margin-top:8px; font-weight:600;">
      <?= htmlspecialchars($message) ?>
    </p>
  <?php endif; ?>

  <form method="post" class="form" style="margin-top:10px;">
    <div class="form-section">
      <div class="form-section-title">Paramètres</div>
      <div class="form-grid form-grid--2">
        <div class="field">
          <label for="start_date" class="field-label">Date de départ</label>
          <input id="start_date" type="date" name="start_date"
                 value="<?= htmlspecialchars($_POST['start_date'] ?? date('Y-m-d')) ?>">
          <div class="field-hint">La génération commencera à partir de cette semaine.</div>
        </div>
        <div class="field">
          <label for="weeks" class="field-label">Nombre de semaines</label>
          <input id="weeks" type="number" name="weeks" min="1" max="26"
                 value="<?= htmlspecialchars($_POST['weeks'] ?? 4) ?>">
        </div>
      </div>
    </div>

    <div style="margin-top:4px;">
      <button type="submit" class="primary">Générer</button>
      <a href="<?= $config['base_url'] ?>/admin/events_list.php">
        <button type="button">Annuler</button>
      </a>
    </div>
  </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';