<?php
/**
 * Composant tuile produit
 * Usage: render_product_tile($p);
 */

$stock       = $p['stock'];
$isOut       = ($stock !== null && (int)$stock <= 0);
$pid         = (int)$p['id'];
$isDonation  = ($p['nom'] === 'Don libre');
$isUnlimited = ($stock === null);
$isLow       = ($stock !== null && (int)$stock > 0 && (int)$stock <= 5);
?>
<div
  class="product <?= $isOut ? 'out' : '' ?> <?= $isLow ? 'low' : '' ?> <?= $isUnlimited ? 'unlimited' : '' ?> <?= $isDonation ? 'donation' : '' ?>"
  data-id="<?= $pid ?>"
  data-name="<?= htmlspecialchars($p['nom'], ENT_QUOTES) ?>"
  data-price="<?= (float)$p['prix'] ?>"
  data-stock="<?= $stock === null ? 'null' : (int)$stock ?>"
  data-out="<?= $isOut ? '1' : '0' ?>"
  data-donation="<?= $isDonation ? '1' : '0' ?>"
>
  <!-- Badge quantité (rempli par JS) -->
  <div id="prod-qty-<?= $pid ?>" class="product-qty hidden">0</div>

  <div class="product-name"><?= htmlspecialchars($p['nom']) ?></div>

  <div class="product-price"><?= number_format((float)$p['prix'], 2) ?> €</div>

  <div class="product-stock">
    <?php if ($stock === null): ?>
      Stock : illimité
    <?php else: ?>
      Stock restant : <?= (int)$stock ?>
    <?php endif; ?>
  </div>
</div>
