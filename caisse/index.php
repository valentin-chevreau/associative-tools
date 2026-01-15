<?php
require 'config.php';

// Produits
$produits = $pdo->query("SELECT * FROM produits WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Produit spécial "Don libre"
$donProductId = null;
foreach ($produits as $p) {
    if ($p['nom'] === 'Don libre') {
        $donProductId = (int)$p['id'];
        break;
    }
}

// Bénévoles
$benevoles = $pdo->query("SELECT * FROM benevoles ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Évènement actif
$event = $pdo->query("
    SELECT *
    FROM evenements
    WHERE actif = 1
    AND date_fin IS NULL
    ORDER BY id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);


// Totaux CB / Espèces / Chèque + montant gagné via vente_paiements
$totalCB = $totalCash = $totalCheque = 0;
$gagne = 0;
if ($event) {
    $stmt = $pdo->prepare("
        SELECT vp.methode, SUM(vp.montant) s
        FROM vente_paiements vp
        JOIN ventes v ON vp.vente_id = v.id
        WHERE v.evenement_id = ?
        GROUP BY vp.methode
    ");
    $stmt->execute([$event['id']]);
    foreach ($stmt as $r) {
        if ($r['methode'] === 'CB')      $totalCB     = (float)$r['s'];
        if ($r['methode'] === 'Especes') $totalCash   = (float)$r['s'];
        if ($r['methode'] === 'Cheque')  $totalCheque = (float)$r['s'];
    }
    $gagne = $totalCB + $totalCash + $totalCheque;
}

// Savoir s'il y a au moins une vente pour l'évènement (pour le bouton "Annuler")
$hasLastSale = false;
if ($event) {
    $stmt = $pdo->prepare("SELECT id FROM ventes WHERE evenement_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$event['id']]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasLastSale = (bool)$last;
}

// Dernières ventes pour la mini-timeline (avec paiements + produits)
$recentSales = [];
$benevoleIndex = [];
if ($event) {
    // Seulement les 3 dernières ventes
    $stmt = $pdo->prepare("SELECT id, total, total_brut, remise_total, benevole_id FROM ventes WHERE evenement_id = ? ORDER BY id DESC LIMIT 3");
    $stmt->execute([$event['id']]);
    $recentSales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($benevoles as $b) {
        $benevoleIndex[$b['id']] = $b['nom'];
    }

    // Ajout des paiements et des produits pour chaque vente
    $stmtPay = $pdo->prepare("
        SELECT methode, SUM(montant) s 
        FROM vente_paiements 
        WHERE vente_id = ? 
        GROUP BY methode
    ");
    $stmtDet = $pdo->prepare("
        SELECT p.nom, d.quantite
        FROM vente_details d
        JOIN produits p ON p.id = d.produit_id
        WHERE d.vente_id = ?
    ");

    foreach ($recentSales as &$vs) {
        // Paiements
        $stmtPay->execute([$vs['id']]);
        $payRows = $stmtPay->fetchAll(PDO::FETCH_ASSOC);
        $parts = [];
        foreach ($payRows as $pr) {
            $parts[] = $pr['methode']." ".number_format($pr['s'],2)." €";
        }
        $vs['paiements_label'] = $parts ? implode(" + ", $parts) : "—";

        // Produits
        $stmtDet->execute([$vs['id']]);
        $detRows = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($detRows as $dr) {
            $label = $dr['nom'];
            $qte = (int)$dr['quantite'];
            if ($qte > 1) {
                $label .= " x".$qte;
            }
            $items[] = $label;
        }
        $vs['produits_label'] = $items ? implode(", ", $items) : "—";
    }
    unset($vs);
}

$page = 'caisse';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"/>
    <title>Mini Caisse – Association</title>
    <link rel="stylesheet" href="assets/css/style.css?v=8">
    <link rel="stylesheet" href="assets/cs/products.css?v=8">
    <link rel="stylesheet" href="assets/css/toasts.css">
</head>
<body class="page-caisse" data-has-event="<?= $event ? '1' : '0' ?>

<?php require_once __DIR__ . '/../shared/suite_nav.php'; ?>" data-don-product="<?= $donProductId ?: 0 ?>" data-has-last-sale="<?= $hasLastSale ? '1' : '0' ?>">

<?php include 'nav.php'; ?>
<?php include 'admin_modal.php'; ?>
<?php include 'discount_modal.php'; ?>
<div id="quick-banner">⚡ MODE RAPIDE</div>
<div class="app">

    <div class="grid">

        <!-- COLONNE GAUCHE : PRODUITS + DON -->
        <div class="card">
            <div class="sticky-summary">
                <div class="summary-main">
                    <div style="flex:1">
                        <div class="small">Évènement actif :</div>
                        <?php if ($event): ?>
                            <div>
                                <span class="small"><strong><?= htmlspecialchars($event['nom']) ?></strong></span><br>
                                <span class="small">Fond de caisse : <?= number_format($event['fond_caisse'], 2) ?> €</span>
                            </div>
                        <?php else: ?>
                            <div class="small" style="color:#dc2626">
                                Aucun évènement actif – va sur la page "Évènements".
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="flex:1;min-width:160px">
                        <div class="small">Bénévole :</div>
                        <select id="benevole" <?= $event ? '' : 'disabled' ?>>
                            <?php if (!$event): ?>
                                <option value="">Créer d’abord un évènement</option>
                            <?php else: ?>
                                <option value="">Global / non renseigné</option>
                                <?php foreach ($benevoles as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['nom']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="quick-toggle">
                    <button type="button" id="quick-btn" onclick="toggleQuickMode()"></button>
                </div>
            </div>

            <!-- Titre + toggle tuiles/liste sur la même ligne -->
            <div class="products-header-row">
                <h3>Produits</h3>
                <div class="view-toggle">
                    <button type="button" id="view-tiles-btn" onclick="setProductView('tiles')">
                        <span class="view-icon">▦</span>
                        <span class="view-label">Tuiles</span>
                    </button>
                    <button type="button" id="view-list-btn" onclick="setProductView('list')">
                        <span class="view-icon">☰</span>
                        <span class="view-label">Liste</span>
                    </button>
                </div>
            </div>
            <div class="products">
                <?php foreach ($produits as $p): ?>
                    <?php include __DIR__ . '/components/product.php'; ?>
                <?php endforeach; ?>
            </div>

            <!-- DON LIBRE -->
            <?php if ($donProductId): ?>
                <div class="don-block">
                    <strong>Don libre</strong>
                    <div class="small">
                        Saisis le montant du don (par ex. 5 €, 10 €…). Un don libre s’enregistre comme une ligne dédiée dans l’historique.
                    </div>
                    <div class="don-row">
                        <input
                            type="number"
                            id="don-amount"
                            min="0.1"
                            step="0.1"
                            placeholder="Montant en €"
                            inputmode="decimal"
                            pattern="[0-9]*"
                            onkeydown="if(event.key==='Enter'){event.preventDefault();addDonation();}"
                        >
                        <button type="button" onclick="addDonation()" class="btn-secondary btn-don">
                          <svg class="btn-svg" viewBox="0 0 24 24" aria-hidden="true">
                              <circle cx="12" cy="12" r="8"></circle>
                              <path d="M12 9v6"></path>
                              <path d="M9 12h6"></path>
                          </svg>
                          <span class="btn-label">Ajouter le don</span>
                      </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="don-block" style="background:#fef3c7;border-color:#fed7aa">
                    <strong>Don libre non configuré</strong>
                    <div class="small">
                        Crée un produit nommé <strong>"Don libre"</strong> dans la page Stock (prix = 0, stock illimité ou très haut)
                        pour activer cette fonction.
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <!-- COLONNE DROITE : PANIER + MONNAIE + PAIEMENTS + TIMELINE -->
        <div class="card cart-card" id="cart-card">
            <div class="cart-header-row">
                <h3>Panier</h3>
                <div class="cart-toggle-bar" onclick="toggleCartView()">
                    <span class="cart-toggle-label" id="cart-toggle-label">Panier vide</span>
                    <span class="cart-toggle-chevron" id="cart-toggle-chevron">▾</span>
                </div>
            </div>

            <div class="cart-details" id="cart-details">
                <div id="cart"></div>

                <!-- Montant du panier mis en valeur -->
                <div class="cart-total-panel">
                    <div class="cart-total-label">Montant du panier</div>
                    <div class="cart-total-value"><span id="total">0.00</span> €</div>
                </div>

                <!-- Actions panier condensées -->
                <div class="cart-top-actions">
                  <button class="btn-secondary" type="button" onclick="clearCart()">
                    <svg class="btn-svg" viewBox="0 0 24 24">
                      <polyline points="3 6 5 6 21 6"></polyline>
                      <path d="M19 6l-1 14a2 2 0 0 1 -2 2H8a2 2 0 0 1 -2 -2L5 6"></path>
                      <path d="M10 11l0 6"></path>
                      <path d="M14 11l0 6"></path>
                      <path d="M9 6V4a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v2"></path>
                    </svg>
                    <span class="btn-label">Vider le panier</span>
                  </button>

                  <!-- Remise panier -->
                  <button class="btn-secondary" type="button" onclick="setCartDiscount()">
                    <svg class="btn-svg" viewBox="0 0 24 24">
                      <path d="M19 7l-7 7"></path>
                      <circle cx="7" cy="7" r="2"></circle>
                      <circle cx="17" cy="17" r="2"></circle>
                    </svg>
                    <span class="btn-label">Remise panier</span>
                  </button>

                  <!-- Mettre en attente -->
                  <button class="btn-secondary" type="button" id="hold-btn" onclick="holdCart()">
                    <svg class="btn-svg" viewBox="0 0 24 24">
                      <rect x="7" y="4" width="3" height="16" rx="1"></rect>
                      <rect x="14" y="4" width="3" height="16" rx="1"></rect>
                    </svg>
                    <span class="btn-label">Mise en attente</span>
                  </button>

                  <!-- Rappeler la vente (caché par défaut) -->
                  <button class="btn-secondary" type="button" id="resume-btn" onclick="resumeCart()" style="display:none;">
                    <svg class="btn-svg" viewBox="0 0 24 24">
                      <!-- cadre -->
                      <rect x="5" y="5" width="14" height="14" rx="2"></rect>
                      <!-- flèche de rappel -->
                      <polyline points="13 9 9 12 13 15"></polyline>
                      <path d="M9 12h6"></path>
                    </svg>
                    <span class="btn-label">Rappeler vente</span>
                  </button>
                </div>

                <!-- RENDU MONNAIE -->
                <div class="cash-block" id="cash-block">
                    <strong>Rendu monnaie (espèces)</strong>
                    <div class="cash-row">
                        <div>
                            <div class="small">Montant donné</div>
                            <input
                                type="number"
                                id="cash-given"
                                min="0"
                                step="0.1"
                                placeholder="Ex: 20"
                                inputmode="decimal"
                                pattern="[0-9]*"
                                oninput="updateChange()"
                            >
                            <div class="cash-quick">
                                <button type="button" onclick="setCashGiven('total')">= Total</button>
                                <button type="button" onclick="setCashGiven(5)">5 €</button>
                                <button type="button" onclick="setCashGiven(10)">10 €</button>
                                <button type="button" onclick="setCashGiven(20)">20 €</button>
                                <button type="button" onclick="setCashGiven(50)">50 €</button>
                            </div>
                        </div>
                        <div>
                            <div class="small">Monnaie à rendre</div>
                            <div class="cash-main-amount"><span id="cash-change">0.00</span> €</div>
                            <div class="small" id="cash-remaining-text"></div>
                            <button type="button"
                              id="keep-change-btn"
                              class="btn-keep-change"
                              onclick="keepChangeAsDonation()"
                            >
                              Garder la monnaie comme don
                          </button>
                        </div>
                    </div>
                </div>

                <!-- PAIEMENTS ENREGISTRÉS (rempli par JS, masqué tant qu'il est vide) -->
                <div id="payments-block" class="payments-empty"></div>

                <!-- Actions paiements condensées -->
                <div class="payments-actions-row">
                  <button
                    type="button"
                    id="reset-payments-btn"
                    class="btn-secondary"
                    onclick="resetPayments()"
                  >
                    <svg class="btn-svg" viewBox="0 0 24 24">
                      <!-- flèche circulaire -->
                      <path d="M4 4v5h5"></path>
                      <path d="M4.5 9A7 7 0 1 1 9 19.5"></path>
                    </svg>
                    <span class="btn-label">Réinitialiser</span>
                  </button>

                  <button
                    type="button"
                    id="finalize-btn"
                    class="btn-primary"
                    onclick="finalizeSale()"
                    disabled
                  >
                    <svg class="btn-svg" viewBox="0 0 24 24">
                        <polyline points="4 12 10 18 20 6"></polyline>
                    </svg>
                    <span class="btn-label">Valider</span>
                  </button>
                </div>

                <!-- DERNIÈRES VENTES -->
                <?php if ($event && !empty($recentSales)): ?>
                    <div class="timeline-block">
                        <strong class="timeline-title">Dernières ventes</strong>
                        <?php foreach ($recentSales as $vs): ?>
                            <div class="timeline-item">
                                <div class="timeline-products">
                                    <?= htmlspecialchars($vs['produits_label']) ?>
                                </div>
                                <div class="timeline-payments">
                                    <?= htmlspecialchars($vs['paiements_label']) ?>
                                </div>
                                <div class="timeline-total">
                                    <?php
                                      $tFinal = (float)($vs['total'] ?? 0);
                                      $tBrut  = (float)($vs['total_brut'] ?? 0);
                                      $tRem   = (float)($vs['remise_total'] ?? 0);
                                    ?>
                                    <?php if ($tRem > 0.0001 && $tBrut > $tFinal + 0.0001): ?>
                                      <span class="price-old"><?= number_format($tBrut, 2) ?> €</span>
                                      <span class="price-new"><?= number_format($tFinal, 2) ?> €</span>
                                      <span class="discount-note">(remise −<?= number_format($tRem, 2) ?> €)</span>
                                    <?php else: ?>
                                      <?= number_format($tFinal, 2) ?> €
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- BOUTON ANNULER DERNIÈRE VENTE -->
                <button
                  type="button"
                  class="btn-secondary undo-sale-btn"
                  id="undo-btn"
                  onclick="undoLastSale()"
                  <?= $hasLastSale ? '' : 'disabled' ?>
                >
                  <svg class="btn-svg" viewBox="0 0 24 24">
                    <polyline points="9 5 4 10 9 15"></polyline>
                    <path d="M4 10h8a6 6 0 1 1 0 12"></path>
                  </svg>
                  <span class="btn-label">Annuler la dernière vente</span>
              </button>
            </div>
        </div>

    </div>
</div>

<!-- RÉCAP EN BAS -->
<div class="recap-bottom">
    <div class="recap-row">
        <div class="recap-item">💳 <strong><?= number_format($totalCB, 2) ?> €</strong><br><span>CB</span></div>
        <div class="recap-item">💶 <strong><?= number_format($totalCash, 2) ?> €</strong><br><span>Espèces</span></div>
        <div class="recap-item">🧾 <strong><?= number_format($totalCheque, 2) ?> €</strong><br><span>Chèques</span></div>
        <div class="recap-item">🏁 <strong><?= number_format($gagne, 2) ?> €</strong><br><span>Gagné</span></div>
    </div>
</div>

<!-- FOOTER PAIEMENT : AJOUTE DES PAIEMENTS (NE FINALISE PLUS) -->
<div class="checkout-footer">
    <div class="checkout-total"><span id="totalFooter">0.00</span> €</div>
    <div class="checkout-actions">

        <!-- CB -->
        <button class="btn-cb" onclick="addPayment('CB')">
            <svg class="btn-svg" viewBox="0 0 24 24" aria-hidden="true">
                <!-- carte bancaire -->
                <rect x="3" y="5" width="18" height="12" rx="2" ry="2" />
                <rect x="6" y="11" width="7" height="2" />
            </svg>
            <span class="btn-label">CB</span>
        </button>

        <!-- Espèces -->
        <button class="btn-cash" onclick="addPayment('Especes')">
            <svg class="btn-svg" viewBox="0 0 24 24" aria-hidden="true">
                <!-- billet -->
                <rect x="3" y="6" width="18" height="10" rx="2" ry="2" />
                <!-- pièces / repères -->
                <circle cx="9" cy="11" r="2.2" />
                <circle cx="15" cy="11" r="2.2" />
            </svg>
            <span class="btn-label">Espèces</span>
        </button>

        <!-- Chèque -->
        <button class="btn-cheque" onclick="addPayment('Cheque')">
            <svg class="btn-svg" viewBox="0 0 24 24" aria-hidden="true">
                <!-- feuille de chèque -->
                <rect x="3" y="6" width="18" height="10" rx="2" ry="2" />
                <!-- lignes d’écriture -->
                <line x1="6" y1="10" x2="17" y2="10" />
                <line x1="6" y1="13" x2="13" y2="13" />
            </svg>
            <span class="btn-label">Chèque</span>
        </button>

    </div>
</div>
<!-- Modal Remise (ligne / panier) -->
<div class="tu-modal" id="discount-modal" aria-hidden="true" style="display:none;">
  <div class="tu-modal-backdrop" data-close="1"></div>
  <div class="tu-modal-card" role="dialog" aria-modal="true" aria-labelledby="discount-modal-title">
    <div class="tu-modal-header">
      <div>
        <div class="tu-modal-title" id="discount-modal-title">Remise</div>
        <div class="tu-modal-subtitle" id="discount-modal-subtitle"></div>
      </div>
      <button class="tu-modal-close" type="button" data-close="1" aria-label="Fermer">×</button>
    </div>
    <div class="tu-modal-body">
      <label class="tu-modal-label" for="discount-modal-input">Valeur</label>
      <input
        id="discount-modal-input"
        class="tu-modal-input"
        type="text"
        inputmode="decimal"
        placeholder="Ex: 10% ou 2.50"
        autocomplete="off"
      />
      <div class="tu-modal-help" id="discount-modal-help">Tape 10% ou 2.50. Laisse vide pour supprimer.</div>
      <div class="tu-modal-error" id="discount-modal-error" style="display:none;"></div>

      <div class="tu-modal-chips" id="discount-modal-chips" aria-label="Raccourcis"></div>

      <div class="tu-modal-actions">
        <button class="btn-secondary" type="button" data-close="1">Annuler</button>
        <button class="btn-primary" type="button" id="discount-modal-ok">OK</button>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/toasts.js?v=1" defer></script>
<script src="assets/js/app.js?v=7" defer></script>
</body>
</html>