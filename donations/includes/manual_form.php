<?php
declare(strict_types=1);

// donations/includes/manual_form.php
// Formulaire « Ajouter un don manuel » (particulier / entreprise, numéraire / nature / prestation).
// Inclus par donations/index.php : utilise h(), $base et les fonctions de receipt.php.

// Après une erreur de validation, on ré-affiche ce que l'utilisateur avait saisi (seul le justificatif,
// que le navigateur interdit de pré-remplir, est à re-sélectionner).
$old = (isset($_POST['do_manual_add']) && !empty($info['errors'])) ? $_POST : [];
$ov  = static fn(string $k, string $d = ''): string => h((string)($old[$k] ?? $d));
$sel = static fn(string $k, string $v, string $d = ''): string => ((string)($old[$k] ?? $d) === $v) ? ' selected' : '';
$oldDate = '';
if ($old && isset($old['donation_date']) && function_exists('parse_fr_date') && ($__d = parse_fr_date((string)$old['donation_date']))) $oldDate = $__d->format('Y-m-d');

/** Bouton « ? » + bulle d'aide (contenu HTML maîtrisé, jamais saisi par un utilisateur). */
$tuHelp = static function (string $id, string $html): string {
    return '<span class="tu-help-wrap"><button type="button" class="tu-help" aria-expanded="false" aria-controls="' . h($id) . '" title="Aide">?</button>'
         . '<span class="tu-help-pop" id="' . h($id) . '" role="note" hidden>' . $html . '</span></span>';
};
$helpKinds = '<strong>Don en numéraire</strong> : de l\'argent (espèces, chèque, virement, carte bancaire). Le reçu porte sur la somme reçue.<br>'
    . '<em>Ex. : un commerçant remet un chèque de 200 €.</em><br><br>'
    . '<strong>Don en nature (biens)</strong> : des objets ou marchandises donnés à l\'association à la place d\'argent, qu\'elle garde, distribue ou envoie par convoi. Le reçu porte sur leur <em>valeur</em>, estimée par le donateur.<br>'
    . '<em>Ex. : 120 peluches neuves, des palettes de conserves, des vêtements chauds, un ordinateur.</em><br><br>'
    . '<strong>Prestation / mécénat de compétences</strong> : un travail ou un service offert gratuitement. Le montant est ce que ce service a coûté au donateur, pas le prix qu\'il aurait facturé.<br>'
    . '<em>Ex. : une entreprise transporte un convoi sans facturer, un graphiste crée les affiches gratuitement, un salarié est mis à disposition quelques jours par son employeur.</em>';
$helpDonor = '<strong>Particulier</strong> : une personne qui donne en son nom. Le reçu ouvre droit à une réduction de l\'impôt sur le revenu.<br><br>'
    . '<strong>Entreprise / organisation</strong> : société, commerce, artisan, autre association… Le reçu est émis à son nom avec son SIREN, et la réduction se fait sur l\'impôt de l\'entreprise.';
$helpSiren = 'Le <strong>SIREN</strong> compte 9 chiffres et identifie l\'entreprise. Le <strong>SIRET</strong> en compte 14 : le SIREN suivi de 5 chiffres propres à l\'établissement.<br><br>'
    . 'On les trouve sur un extrait Kbis, l\'avis de situation INSEE, une facture ou les mentions légales du site.<br><br>'
    . 'Astuce : tapez le <em>nom</em> de l\'entreprise dans « Raison sociale » ou le numéro ici, la recherche remplit le reste (adresse comprise).';
$helpBasis = '<strong>La valeur du don est celle que le donateur vous communique.</strong> Voici les bases possibles pour le type de donateur et de don choisis :<ul class="tu-help-list">';
foreach (donation_valuation_bases() as $__b) $helpBasis .= '<li data-for="' . h(implode(',', $__b['for'])) . '" data-kinds="' . h(implode(',', $__b['kinds'])) . '"><strong>' . h($__b['label']) . '</strong><br>' . h($__b['help']) . '</li>';
$helpBasis .= '</ul>';
$helpSource = 'Le reçu ne peut être émis que si le <strong>donateur vous a communiqué la valeur</strong> de son don. Notez qui l\'a donnée et sur quel document : attestation de l\'entreprise, facture d\'achat, ticket de caisse, devis, estimation écrite du donateur.<br><br>'
    . 'Joignez ce document en justificatif : en cas de contrôle, l\'association doit pouvoir le présenter.';
$helpCounter = 'Une <strong>contrepartie directe</strong> est ce que le donateur obtient en échange : un produit, un service de l\'association, une publicité importante.<br><br>'
    . 'Remercier le donateur ou afficher son nom ou son logo, sans mise en avant disproportionnée, reste du mécénat. En revanche, une vraie contrepartie fait du don un <strong>parrainage</strong> : il n\'ouvre pas droit à un reçu fiscal. En cas de doute, demandez à votre expert-comptable.';
?>
  <form method="post" autocomplete="off" enctype="multipart/form-data" id="manual_form">
    <input type="hidden" name="do_manual_add" value="1">

    <div class="tu-form-grid tu-mb4">
      <div class="tu-form-field">
        <span class="tu-lbl">Nature du don <?= $tuHelp('help_kind', $helpKinds) ?></span>
        <select class="tu-input" name="donation_kind" id="f_kind">
          <?php foreach (donation_kinds() as $k => $lbl): ?>
            <option value="<?= h($k) ?>"<?= $sel('donation_kind', (string)$k, 'cash') ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="tu-form-field">
        <span class="tu-lbl">Donateur <?= $tuHelp('help_donor', $helpDonor) ?></span>
        <select class="tu-input" name="donor_type" id="f_donor">
          <option value="individual"<?= $sel('donor_type', 'individual', 'individual') ?>>Particulier</option>
          <option value="company"<?= $sel('donor_type', 'company') ?>>Entreprise / organisation</option>
        </select>
      </div>
    </div>

    <div class="tu-form-grid tu-mb4" id="blk_company" style="display:none;">
      <div class="tu-form-field addr-suggest">
        <span class="tu-lbl">Raison sociale * <span class="tu-hint" style="text-transform:none;letter-spacing:0;font-weight:400;">— tapez le nom pour rechercher</span></span>
        <input class="tu-input" type="text" name="donor_company_name" id="f_company" autocomplete="off" value="<?= $ov('donor_company_name') ?>" placeholder="Société Exemple SARL">
        <div id="company_list" class="addr-list" style="display:none;"></div>
      </div>
      <div class="tu-form-field">
        <span class="tu-lbl">SIREN ou SIRET * <?= $tuHelp('help_siren', $helpSiren) ?></span>
        <input class="tu-input" type="text" name="donor_siren" id="f_siren" inputmode="numeric" autocomplete="off" value="<?= $ov('donor_siren') ?>" placeholder="123 456 789  ou  123 456 789 00012">
        <div id="siren_status" class="tu-hint" style="margin-top:4px;min-height:16px;">9 chiffres (SIREN) ou 14 chiffres (SIRET).</div>
      </div>
    </div>
    <div id="company_notice" class="tu-hint" style="display:none;margin:-6px 0 14px;"></div>

    <div class="tu-form-grid tu-form-grid-3 tu-mb4" id="grid_main">
      <div class="tu-form-field">
        <span class="tu-lbl">Date du don *</span>
        <input class="tu-input" type="date" name="donation_date" value="<?= h($oldDate) ?>" required>
      </div>
      <div class="tu-form-field">
        <span class="tu-lbl" id="lbl_amount">Montant (€) *</span>
        <input class="tu-input" type="number" name="amount" step="0.01" min="0" placeholder="0,00" value="<?= $ov('amount') ?>" required>
      </div>
      <div class="tu-form-field" id="fld_payment">
        <span class="tu-lbl">Mode de paiement</span>
        <select class="tu-input" name="payment_method">
          <option value="">Choisir…</option>
          <option value="Espèces"<?= $sel('payment_method', 'Espèces') ?>>Espèces</option>
          <option value="Chèque"<?= $sel('payment_method', 'Chèque') ?>>Chèque</option>
          <option value="Virement"<?= $sel('payment_method', 'Virement') ?>>Virement</option>
          <option value="CB (hors HelloAsso)"<?= $sel('payment_method', 'CB (hors HelloAsso)') ?>>CB (hors HelloAsso)</option>
        </select>
      </div>
    </div>

    <div class="tu-form-grid tu-mb4">
      <div class="tu-form-field">
        <span class="tu-lbl"><span class="lbl_contact" style="display:none;">Contact : </span>Prénom</span>
        <input class="tu-input" type="text" name="donor_first_name" placeholder="Prénom" value="<?= $ov('donor_first_name') ?>">
      </div>
      <div class="tu-form-field">
        <span class="tu-lbl"><span class="lbl_contact" style="display:none;">Contact : </span>Nom</span>
        <input class="tu-input" type="text" name="donor_last_name" placeholder="Nom" value="<?= $ov('donor_last_name') ?>">
      </div>
    </div>

    <div id="blk_inkind" style="display:none;">
      <div class="tu-form-field tu-mb4">
        <span class="tu-lbl">Description des biens ou services *</span>
        <textarea class="tu-input" name="in_kind_description" rows="3" placeholder="Nature et quantités, une ligne par article. Ex. : 120 peluches neuves&#10;40 puzzles 500 pièces" style="resize:vertical;"><?= $ov('in_kind_description') ?></textarea>
      </div>
      <div class="tu-form-grid tu-mb4">
        <div class="tu-form-field">
          <span class="tu-lbl">Base de valorisation * <?= $tuHelp('help_basis', $helpBasis) ?></span>
          <select class="tu-input" name="valuation_basis" id="f_basis">
            <option value="">Choisir…</option>
            <?php foreach (donation_valuation_bases() as $k => $b): ?>
              <option value="<?= h($k) ?>"<?= $sel('valuation_basis', (string)$k) ?> data-for="<?= h(implode(',', $b['for'])) ?>" data-kinds="<?= h(implode(',', $b['kinds'])) ?>" data-help="<?= h($b['help']) ?>"><?= h($b['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <div id="basis_hint" class="tu-hint" style="margin-top:4px;"></div>
        </div>
        <div class="tu-form-field">
          <span class="tu-lbl">Valorisation communiquée par * <?= $tuHelp('help_source', $helpSource) ?></span>
          <input class="tu-input" type="text" name="valuation_source" maxlength="255" value="<?= $ov('valuation_source') ?>" placeholder="Ex. : attestation de l'entreprise du 04/10/2026">
        </div>
      </div>
      <div class="tu-form-field tu-mb4">
        <span class="tu-lbl">Justificatif (PDF, JPEG ou PNG, 5 Mo max)</span>
        <input class="tu-input" type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
      </div>
      <label class="tu-form-check tu-mb4" style="display:flex;">
        <input type="checkbox" name="no_counterpart" value="1"<?= !empty($old['no_counterpart']) ? ' checked' : '' ?>>
        Le donateur ne reçoit aucune contrepartie directe (sinon : parrainage, pas de reçu fiscal)
      </label>
      <div style="margin:-8px 0 14px;"><span class="tu-hint">Qu'est-ce qu'une contrepartie ? <?= $tuHelp('help_counter', $helpCounter) ?></span></div>
      <p class="tu-hint" style="margin-top:0;">La valeur est celle que le donateur communique : coût de revient pour une entreprise, prix d'achat ou de revente pour un particulier. Elle est reprise telle quelle sur le reçu.</p>
    </div>

    <div class="tu-form-field tu-mb4">
      <span class="tu-lbl">Email (optionnel)</span>
      <input class="tu-input" type="email" name="donor_email" placeholder="email@exemple.com" value="<?= $ov('donor_email') ?>">
    </div>

    <div class="tu-form-field tu-mb4 addr-suggest">
      <span class="tu-lbl">Adresse (optionnel)</span>
      <input class="tu-input" id="addr_input" type="text" name="donor_address" placeholder="N° et rue" value="<?= $ov('donor_address') ?>">
      <div id="addr_list" class="addr-list" style="display:none;"></div>
    </div>

    <div class="tu-form-grid tu-form-grid-3 tu-mb4">
      <div class="tu-form-field">
        <span class="tu-lbl">Code postal</span>
        <input class="tu-input" id="addr_postal" type="text" name="donor_postal_code" placeholder="37000" value="<?= $ov('donor_postal_code') ?>">
      </div>
      <div class="tu-form-field">
        <span class="tu-lbl">Ville</span>
        <input class="tu-input" id="addr_city" type="text" name="donor_city" placeholder="Tours" value="<?= $ov('donor_city') ?>">
      </div>
      <div class="tu-form-field">
        <span class="tu-lbl">Pays</span>
        <input class="tu-input" type="text" name="donor_country" value="<?= $ov('donor_country', 'France') ?>">
      </div>
    </div>

    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-top:6px;">
      <label class="tu-form-check">
        <input type="checkbox" name="receipt_eligible" value="1"<?= ($old ? !empty($old['receipt_eligible']) : true) ? ' checked' : '' ?>>
        Don éligible au reçu fiscal
      </label>
      <button class="tu-btn tu-btn-p" type="submit">Ajouter le don</button>
    </div>

    <p class="tu-hint" style="margin-top:12px;margin-bottom:0;">Le don sera enregistré avec <code>source=manual</code> et <code>status=paid</code>. L’heure est enregistrée automatiquement (heure courante) pour harmoniser avec HelloAsso.</p>
  </form>

  <script>
  (function(){
    // ---- Affichage selon le type de don / de donateur --------------------------------
    const kind = document.getElementById("f_kind"), donor = document.getElementById("f_donor");
    const blkCompany = document.getElementById("blk_company"), blkInKind = document.getElementById("blk_inkind");
    const fldPayment = document.getElementById("fld_payment"), lblAmount = document.getElementById("lbl_amount");
    const grid = document.getElementById("grid_main");
    const basis = document.getElementById("f_basis"), basisHint = document.getElementById("basis_hint");
    if(!kind || !donor) return;
    const basisAll = Array.from(basis.options).map(function(o){ return o.cloneNode(true); });   // liste complète d'origine
    function sync(){
      const k = kind.value, d = donor.value, inKind = (k !== "cash");
      blkCompany.style.display = (d === "company") ? "" : "none";
      blkInKind.style.display = inKind ? "" : "none";
      fldPayment.style.display = inKind ? "none" : "";
      grid.style.gridTemplateColumns = inKind ? "1fr 1fr" : "";   // pas de colonne vide : la valeur reste alignée
      lblAmount.textContent = inKind ? "Valeur déclarée (€) *" : "Montant (€) *";
      document.querySelectorAll(".lbl_contact").forEach(function(e){ e.style.display = (d === "company") ? "" : "none"; });
      // Bases de valorisation : on ne garde dans la liste que celles qui correspondent au cas choisi.
      const keep = basis.value;
      while(basis.options.length) basis.remove(0);
      basisAll.forEach(function(o){
        if(!o.value || (o.dataset["for"].split(",").indexOf(d) >= 0 && o.dataset.kinds.split(",").indexOf(k) >= 0)) basis.add(o.cloneNode(true));
      });
      basis.value = keep;
      if(basis.selectedIndex < 0) basis.selectedIndex = 0;
      if(basis._rebuildCustomSelect) basis._rebuildCustomSelect();
      document.querySelectorAll("#help_basis li").forEach(function(li){
        li.style.display = (li.dataset["for"].split(",").indexOf(d) >= 0 && li.dataset.kinds.split(",").indexOf(k) >= 0) ? "" : "none";
      });
      showBasisHint();
    }
    function showBasisHint(){
      const o = basis.options[basis.selectedIndex];
      basisHint.textContent = (o && o.dataset.help) ? o.dataset.help : "";
    }
    kind.addEventListener("change", sync); donor.addEventListener("change", sync); basis.addEventListener("change", showBasisHint);
    sync();

    // ---- Bulles d'aide « ? » -----------------------------------------------------------
    document.querySelectorAll(".tu-help").forEach(function(btn){
      btn.addEventListener("click", function(e){
        e.preventDefault(); e.stopPropagation();
        const pop = document.getElementById(btn.getAttribute("aria-controls"));
        const open = pop.hidden;
        closeHelp();
        if(open){ pop.hidden = false; btn.setAttribute("aria-expanded", "true"); }
      });
    });
    function closeHelp(){
      document.querySelectorAll(".tu-help-pop").forEach(function(p){ p.hidden = true; });
      document.querySelectorAll(".tu-help").forEach(function(b){ b.setAttribute("aria-expanded", "false"); });
    }
    document.addEventListener("click", function(e){ if(!e.target.closest(".tu-help-wrap")) closeHelp(); });
    document.addEventListener("keydown", function(e){ if(e.key === "Escape") closeHelp(); });

    // ---- SIREN / SIRET : mise en forme, contrôle de cohérence, recherche ----------------
    const siren = document.getElementById("f_siren"), status = document.getElementById("siren_status");
    const company = document.getElementById("f_company"), clist = document.getElementById("company_list");
    const notice = document.getElementById("company_notice");
    const addr = document.getElementById("addr_input"), zip = document.getElementById("addr_postal"), city = document.getElementById("addr_city");
    const lookupUrl = <?= json_encode(rtrim((string)$base, '/') . '/donations/company_lookup.php') ?>;
    if(!siren || !company) return;

    function fmt(d){
      d = d.replace(/\D/g, "").slice(0, 14);
      let out = d.slice(0,3);
      if(d.length > 3) out += " " + d.slice(3,6);
      if(d.length > 6) out += " " + d.slice(6,9);
      if(d.length > 9) out += " " + d.slice(9);
      return out;
    }
    function luhn(d){
      if(d.length === 14 && d.indexOf("356000000") === 0) return true;   // La Poste : SIRET hors clé de Luhn
      let sum = 0;
      for(let i = 0; i < d.length; i++){
        let n = parseInt(d[d.length - 1 - i], 10) * (i % 2 ? 2 : 1);
        sum += n > 9 ? n - 9 : n;
      }
      return sum % 10 === 0;
    }
    function setStatus(txt, tone){
      status.textContent = txt;
      status.style.color = tone === "ko" ? "var(--tu-red-main, #c0432a)" : (tone === "ok" ? "var(--tu-green-main, #3a9e8e)" : "");
    }
    function checkSiren(){
      const d = siren.value.replace(/\D/g, "");
      siren.setCustomValidity("");
      if(d.length === 0){ setStatus("9 chiffres (SIREN) ou 14 chiffres (SIRET).", ""); return null; }
      if(d.length < 9){ setStatus(d.length + " / 9 chiffres pour un SIREN", ""); return null; }
      if(d.length > 9 && d.length < 14){ setStatus(d.length + " chiffres : un SIRET en compte 14 (ou 9 pour un SIREN)", ""); return null; }
      if(!luhn(d)){
        const m = "Numéro incohérent : la clé de contrôle ne correspond pas, vérifiez la saisie.";
        setStatus("✗ " + m, "ko"); siren.setCustomValidity(m); return false;
      }
      setStatus("✓ " + (d.length === 9 ? "SIREN" : "SIRET") + " valide", "ok");
      return true;
    }

    // Les champs remplis par la recherche restent écrasables par une nouvelle recherche, jamais ceux saisis à la main.
    const auto = new WeakSet();
    function fill(el, val){
      if(!el || val === undefined || val === null || val === "") return;
      if(el.value.trim() === "" || auto.has(el)){ el.value = val; auto.add(el); }
    }
    ["input"].forEach(function(ev){
      [addr, zip, city].forEach(function(el){ if(el) el.addEventListener(ev, function(e){ if(e.isTrusted) auto.delete(el); }); });
    });
    function streetCase(s){
      s = (s || "").toLowerCase().replace(/(^|[\s\-’'])\p{L}/gu, function(m){ return m.toUpperCase(); });
      s = s.replace(/\b(De|Du|Des|La|Le|Les|Et|Au|Aux|À)\b/g, function(m){ return m.toLowerCase(); });
      s = s.replace(/\b(Rue|Avenue|Boulevard|Impasse|Chemin|Route|Allée|Place|Quai|Square|Cours|Voie|Résidence)\b/g, function(m){ return m.toLowerCase(); });
      return s.charAt(0).toUpperCase() + s.slice(1);
    }
    function apply(c, keepNumber){
      company.value = c.name; auto.add(company);
      if(!keepNumber) siren.value = fmt(c.siren);   // choix dans la liste : on inscrit le SIREN ; sinon on garde le numéro saisi
      checkSiren();
      fill(addr, streetCase(c.street)); fill(zip, c.postal_code); fill(city, c.city);
      let msg = "Données récupérées dans l'annuaire officiel des entreprises : vérifiez-les avant d'enregistrer.";
      if(!c.diffusible) msg = "Cette entreprise a demandé que son adresse ne soit pas diffusée : saisissez l'adresse à la main.";
      if(!c.active) msg = "⚠ Cette entreprise est signalée comme cessée dans l'annuaire. " + msg;
      else if(c.etab_active === false){
        msg = "⚠ L'établissement de ce SIRET est fermé" + (c.address_from_siege ? " : l'adresse reprise est celle du siège actuel (SIRET " + fmt(c.siege_siret) + ")" : "")
            + ". L'entreprise, elle, est toujours en activité : vérifiez le SIRET, ou indiquez seulement le SIREN (9 chiffres). " + msg;
      }
      notice.textContent = msg; notice.style.display = "";
    }

    let seq = 0;
    async function lookup(q){
      const my = ++seq;
      try{
        const r = await fetch(lookupUrl + "?q=" + encodeURIComponent(q), {credentials: "same-origin"});
        const j = await r.json();
        if(my !== seq) return null;
        if(j.error){ notice.textContent = j.error; notice.style.display = ""; return []; }
        return j.results || [];
      }catch(err){ return my === seq ? [] : null; }
    }

    let sT = null;
    siren.addEventListener("input", function(){
      const pos = siren.selectionStart === siren.value.length;
      siren.value = fmt(siren.value);
      if(pos) siren.setSelectionRange(siren.value.length, siren.value.length);
      const ok = checkSiren();
      clearTimeout(sT);
      if(ok === true){
        setStatus(status.textContent + " — recherche…", "ok");
        sT = setTimeout(async function(){
          const res = await lookup(siren.value.replace(/\D/g, ""));
          if(res === null) return;
          checkSiren();
          if(res.length) apply(res[0], true);
          else { notice.textContent = "Numéro valide mais introuvable dans l'annuaire : saisissez les informations à la main."; notice.style.display = ""; }
        }, 250);
      }
    });
    siren.addEventListener("blur", function(){ siren.value = fmt(siren.value); checkSiren(); });
    if(siren.value){ siren.value = fmt(siren.value); checkSiren(); }   // valeur ré-affichée après une erreur

    function hideList(){ clist.style.display = "none"; clist.innerHTML = ""; }
    function esc(t){ return String(t).replace(/[&<>"]/g, function(c){ return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]; }); }
    let cT = null;
    company.addEventListener("input", function(e){
      if(e.isTrusted) auto.delete(company);
      const q = company.value.trim();
      clearTimeout(cT);
      if(q.length < 3){ hideList(); return; }
      cT = setTimeout(async function(){
        const res = await lookup(q);
        if(res === null) return;
        if(!res.length){ hideList(); return; }
        clist.innerHTML = res.map(function(c, i){
          const where = [c.postal_code, c.city].filter(Boolean).join(" ");
          return '<div class="addr-item" data-idx="' + i + '"><strong>' + esc(c.name) + '</strong><br><span style="color:var(--tu-ink-300);font-size:12px;">SIREN ' + esc(fmt(c.siren)) + (where ? " — " + esc(where) : "") + '</span></div>';
        }).join("");
        clist.style.display = "block";
        clist.onclick = function(ev){
          const el = ev.target.closest(".addr-item"); if(!el) return;
          apply(res[parseInt(el.getAttribute("data-idx"), 10)]); hideList();
        };
      }, 300);
    });
    document.addEventListener("click", function(e){ if(e.target !== company && !clist.contains(e.target)) hideList(); });
  })();
  </script>

  <script>
  (function(){
    const input = document.getElementById("addr_input");
    const list  = document.getElementById("addr_list");
    const cp    = document.getElementById("addr_postal");
    const city  = document.getElementById("addr_city");
    if(!input || !list) return;

    let t = null;
    function hide(){ list.style.display="none"; list.innerHTML=""; }
    function show(items){
      list.innerHTML = items.map((it, idx) => `<div class="addr-item" data-idx="${idx}">${it.label}</div>`).join("");
      list.style.display = items.length ? "block" : "none";
      list.onclick = (e) => {
        const el = e.target.closest(".addr-item");
        if(!el) return;
        const item = items[parseInt(el.getAttribute("data-idx"),10)];
        input.value = item.street || item.label;
        if(item.postcode && cp) cp.value = item.postcode;
        if(item.city && city) city.value = item.city;
        hide();
      };
    }

    input.addEventListener("input", () => {
      const q = input.value.trim();
      if(t) clearTimeout(t);
      if(q.length < 4){ hide(); return; }
      t = setTimeout(async () => {
        try{
          const url = "https://api-adresse.data.gouv.fr/search/?q=" + encodeURIComponent(q) + "&limit=6";
          const res = await fetch(url);
          const json = await res.json();
          const feats = (json.features || []).map(f => {
            const p = (f && f.properties) ? f.properties : {};
            const street = [p.housenumber, p.street].filter(Boolean).join(' ').trim()
              || (p.name || p.label || '');
            return {
              street,
              postcode: p.postcode || '',
              city: p.city || ''
            };
          });

          // Normalisation "rue de la" côté front (confort) – le back renormalise aussi
          function normalizeStreetFr(s){
            s = (s||'').trim();
            if(!s) return s;
            s = s.toLowerCase();
            // remonte la 1ère lettre de chaque mot
            s = s.replace(/(^|[\s\-’'])\p{L}/gu, m => m.toUpperCase());
            // particules en minuscules
            s = s.replace(/\b(De|Du|Des|La|Le|Les|Et|Au|Aux|A|À)\b/g, m => m.toLowerCase());
            s = s.replace(/\b(d'|d’|l'|l’)\b/gi, m => m.toLowerCase());
            // types de voie en minuscules
            s = s.replace(/\b(Rue|Avenue|Boulevard|Impasse|Chemin|Route|Allée|Place|Quai|Square|Cours|Voie|Résidence|Residence)\b/g, m => m.toLowerCase());
            return s;
          }

          const items = feats.map(it => ({
            label: [normalizeStreetFr(it.street), (it.postcode && it.city ? `${it.postcode} ${it.city}` : '')].filter(Boolean).join(' — '),
            street: normalizeStreetFr(it.street),
            postcode: it.postcode,
            city: it.city
          }));
          show(items);
        }catch(err){ hide(); }
      }, 250);
    });

    document.addEventListener("click", (e) => {
      if(e.target === input || list.contains(e.target)) return;
      hide();
    });
  })();
  </script>
