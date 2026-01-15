let cart = {};
let payments = [];
let globalDiscount = null; // {type:'amount'|'percent', value:number}
let cartCollapsed = false;
let isSaving = false;
let pendingConfirm = null;
let lowStockWarned = false;

const CONFIRM_THRESHOLD = 80;
const HOLD_KEY = 'mini_caisse_hold';

const body = document.body;
const hasEvent       = body.dataset.hasEvent === "1";
const DON_PRODUCT_ID = (parseInt(body.dataset.donProduct || "0", 10) || 0) || null;
const hasLastSale    = body.dataset.hasLastSale === "1";

function notify(type, msg, timeout = 3500){
  const map = { ok:'success', success:'success', error:'error', warn:'warning', warning:'warning', info:'info' };
  const t = map[type] || 'info';

  if (typeof window.showToast === 'function') {
    window.showToast(t, msg, timeout);
    return;
  }

  // Fallback minimal si toast non chargé (évite silence)
  console[t === 'error' ? 'error' : 'log'](`[${t}] ${msg}`);
}

function showMessage(msg, type='info'){
  notify(type, msg, 3500);
}

/* =========================
   Helpers paiements (auto espèces)
   ========================= */
function getAutoCashIndex(){
  return payments.findIndex(p => p.method === 'Especes' && p.autoCash === true);
}
function getAutoCashAmount(){
  const idx = getAutoCashIndex();
  if(idx === -1) return 0;
  return Number(payments[idx].amount || 0);
}
function getPaidTotal(){
  return payments.reduce((s,p)=>s+Number(p.amount || 0),0);
}
function getPaidTotalExcludingAutoCash(){
  return payments.reduce((s,p)=>{
    if(p.method === 'Especes' && p.autoCash === true) return s;
    return s + Number(p.amount || 0);
  },0);
}

/* Totaux */
function clamp(n, min, max){
  n = Number(n);
  if(Number.isNaN(n)) n = 0;
  return Math.min(Math.max(n, min), max);
}

function getLineBaseTotal(item){
  return Number(item.price || 0) * Number(item.qty || 0);
}

function getLineDiscountAmount(item){
  const base = getLineBaseTotal(item);
  if(!item || !item.discount) return 0;
  if(item.isDonation) return 0;

  const type = item.discount.type;
  const value = Number(item.discount.value || 0);
  if(!value || value <= 0) return 0;

  let disc = 0;
  if(type === 'percent') disc = base * (value / 100);
  else if(type === 'amount') disc = value;
  disc = clamp(disc, 0, base);
  return disc;
}

function getLineNetTotal(item){
  const base = getLineBaseTotal(item);
  const disc = getLineDiscountAmount(item);
  return Math.max(base - disc, 0);
}

function getEligibleSubtotal(){
  // Pour la remise panier : on exclut les dons
  let s = 0;
  Object.values(cart).forEach(i => {
    if(i && i.isDonation) return;
    s += getLineNetTotal(i);
  });
  return s;
}

function getGlobalDiscountAmount(){
  if(!globalDiscount) return 0;
  const eligible = getEligibleSubtotal();
  if(eligible <= 0) return 0;

  const type = globalDiscount.type;
  const value = Number(globalDiscount.value || 0);
  if(!value || value <= 0) return 0;

  let d = 0;
  if(type === 'percent') d = eligible * (value / 100);
  else if(type === 'amount') d = value;
  return clamp(d, 0, eligible);
}

function getCartSubtotal(){
  let total = 0;
  Object.values(cart).forEach(i => {
    total += getLineNetTotal(i);
  });
  return total;
}

function getCartTotal(){
  const subtotal = getCartSubtotal();
  const gdisc = getGlobalDiscountAmount();
  return Math.max(subtotal - gdisc, 0);
}
function getRemainingTotal(){
  const t = getCartTotal();
  const p = getPaidTotal();
  return Math.max(t - p, 0);
}

/* Mode rapide */
function updateQuickButton(){
  const btn = document.getElementById('quick-btn');
  if(!btn) return;
  if(document.body.classList.contains('quick-mode')){
    btn.textContent = '⚡ Mode rapide actif';
  } else {
    btn.textContent = 'Afficher le mode rapide';
  }
}

function toggleQuickMode(){
  document.body.classList.toggle('quick-mode');
  const isQuick = document.body.classList.contains('quick-mode');
  localStorage.setItem('mode', isQuick ? 'quick' : 'normal');

  const lines = document.getElementById('cart');
  cartCollapsed = isQuick;
  if (lines) {
    lines.style.display = cartCollapsed ? 'none' : 'block';
  }

  document.querySelectorAll('input').forEach(i => i.blur());
  updateQuickButton();
  updateCartToggleLabel();
}

/* Panier repliable */
function toggleCartView(){
  const lines = document.getElementById('cart');
  if (!lines) return;

  cartCollapsed = !cartCollapsed;
  lines.style.display = cartCollapsed ? 'none' : 'block';
  updateCartToggleLabel();
}

function updateCartToggleLabel(totalArg, countArg){
  const label = document.getElementById('cart-toggle-label');
  const chev  = document.getElementById('cart-toggle-chevron');
  if(!label || !chev) return;

  let total = totalArg;
  let count = countArg;

  if(typeof total === 'undefined' || typeof count === 'undefined'){
    total = getCartTotal();
    count = 0;
    Object.values(cart).forEach(i=>{ count += i.qty; });
  }

  if(count === 0){
    label.textContent = 'Panier vide';
  }else{
    label.textContent = '🛒 '+count+' • '+total.toFixed(2)+' €';
  }
  chev.textContent = cartCollapsed ? '▴' : '▾';
}

/* Panier */
function add(id,name,price,stock){
  let maxStock = (stock === null) ? Infinity : Number(stock);
  if(!cart[id]) cart[id]={id,name,price,qty:0,maxStock:maxStock,isDonation:false,discount:null};
  if(cart[id].qty + 1 > cart[id].maxStock){
    showMessage("Stock épuisé pour "+name,"error");
    return;
  }
  cart[id].qty++;
  render();
}

/* Don libre */
function addDonationAmount(amount){
  if (DON_PRODUCT_ID === null) {
    showMessage("Produit 'Don libre' non configuré.","error");
    return;
  }
  if (isNaN(amount) || amount <= 0){
    showMessage("Montant de don invalide.","error");
    return;
  }

  const id   = DON_PRODUCT_ID;
  const name = "Don libre";

  if (!cart[id]) {
    cart[id] = {
      id,
      name,
      price: amount,
      qty: 1,
      maxStock: Infinity,
      isDonation: true,
      discount: null
    };
  } else {
    cart[id].price += amount;
  }

  render();
}

function addDonation(){
  if(DON_PRODUCT_ID === null){
    showMessage("Produit 'Don libre' non configuré.","error");
    return;
  }

  const input = document.getElementById('don-amount');
  if(!input) return;

  let val = parseFloat((input.value || '').replace(',', '.'));
  if(isNaN(val) || val <= 0){
    showMessage("Montant de don invalide.","error");
    return;
  }

  addDonationAmount(val);

  input.value = '';
  input.blur();
}

/* Garder la monnaie comme don */
function keepChangeAsDonation(){
  const cashInput = document.getElementById('cash-given');
  if(!cashInput) return;

  const total = getCartTotal();

  // On calcule le "reste à payer" SANS compter l'espèces auto
  const paidOther       = getPaidTotalExcludingAutoCash();
  const remainingBefore = Math.max(total - paidOther, 0);

  let given = parseFloat((cashInput.value || '').replace(',', '.'));
  if(isNaN(given) || given <= 0){
    showMessage("Aucun montant espèces saisi.","info");
    return;
  }

  const amountWouldPay = Math.min(given, remainingBefore);
  const change         = given - amountWouldPay;
  const remainingAfter = Math.max(remainingBefore - amountWouldPay, 0);

  if(change <= 0 || remainingAfter !== 0){
    showMessage("La monnaie ne peut être gardée que lorsque tout est déjà payé.","info");
    return;
  }

  // On transforme la monnaie en don
  addDonationAmount(change);

  // IMPORTANT : on vide le champ espèces (sinon il recrée de l'espèces auto)
  cashInput.value = '';
  updateChange();

  showMessage("Monnaie convertie en don de "+change.toFixed(2)+" €","success");
}

function decItem(id){
  if(!cart[id]) return;
  cart[id].qty--;
  if(cart[id].qty <= 0){
    delete cart[id];
  }
  render();
}
function incItem(id){
  if(!cart[id]) return;
  if(cart[id].qty + 1 > cart[id].maxStock){
    showMessage("Stock épuisé pour "+cart[id].name,"error");
    return;
  }
  cart[id].qty++;
  render();
}
function delItem(id){
  delete cart[id];
  render();
}

function clearCart(){
  cart = {};
  payments = [];
  globalDiscount = null;
  const cashInput = document.getElementById('cash-given');
  if(cashInput){
    cashInput.value = '';
  }
  render();
}

/* =========================
   Remises
   ========================= */
function parseDiscountInput(raw){
  const s = String(raw || '').trim().replace(',', '.');
  if(!s) return null;
  if(s.endsWith('%')){
    const v = parseFloat(s.slice(0,-1));
    if(!isFinite(v) || v <= 0) return null;
    return {type:'percent', value: clamp(v, 0, 100)};
  }
  const v = parseFloat(s);
  if(!isFinite(v) || v <= 0) return null;
  return {type:'amount', value: v};
}

// Modal UI pour saisir une remise (ligne/panier)
// Expose window.showDiscountModal({title, subtitle, current, chips, onSubmit})
(function(){
  let state = { open:false, onSubmit:null };

  function qs(id){ return document.getElementById(id); }

  function close(){
    const modal = qs('discount-modal');
    if(!modal) return;
    modal.classList.remove('is-open');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden','true');
    state.open = false;
    state.onSubmit = null;
  }

  function setError(msg){
    const err = qs('discount-modal-error');
    if(!err) return;
    if(!msg){
      err.style.display = 'none';
      err.textContent = '';
      return;
    }
    err.textContent = msg;
    err.style.display = 'block';
  }

  function open(opts){
    const modal = qs('discount-modal');
    if(!modal){
      // fallback extrême : prompt
      const raw = prompt(opts?.subtitle || opts?.title || 'Remise', opts?.current || '');
      if(raw === null) return;
      if(typeof opts?.onSubmit === 'function') opts.onSubmit(raw);
      return;
    }

    qs('discount-modal-title').textContent = opts?.title || 'Remise';
    qs('discount-modal-subtitle').textContent = opts?.subtitle || '';
    const input = qs('discount-modal-input');
    input.value = opts?.current || '';
    setError('');

    // chips
    const chipsWrap = qs('discount-modal-chips');
    chipsWrap.innerHTML = '';
    (opts?.chips || []).forEach(label => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'tu-modal-chip';
      b.textContent = label;
      b.addEventListener('click', () => {
        input.value = label;
        input.focus();
        input.select();
      });
      chipsWrap.appendChild(b);
    });

    state.onSubmit = typeof opts?.onSubmit === 'function' ? opts.onSubmit : null;
    modal.style.display = 'flex';
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');
    state.open = true;

    // focus
    setTimeout(() => { input.focus(); input.select(); }, 0);
  }

  function submit(){
    const input = qs('discount-modal-input');
    const raw = input ? input.value : '';

    // validation UX : si non vide mais invalide -> message + on ne ferme pas
    const trimmed = String(raw || '').trim();
    if(trimmed && !parseDiscountInput(trimmed)){
      setError('Format invalide. Exemple : 10% ou 2.50');
      if(input){ input.focus(); input.select(); }
      return;
    }

    if(state.onSubmit) state.onSubmit(raw);
    close();
  }

  function wireOnce(){
    const modal = qs('discount-modal');
    if(!modal || modal.__wired) return;
    modal.__wired = true;

    // close on backdrop / data-close
    modal.addEventListener('click', (e) => {
      const t = e.target;
      if(t && t.getAttribute && t.getAttribute('data-close') === '1') close();
    });

    // buttons
    const ok = qs('discount-modal-ok');
    if(ok) ok.addEventListener('click', submit);

    // Enter/Escape
    const input = qs('discount-modal-input');
    if(input){
      input.addEventListener('keydown', (e) => {
        if(e.key === 'Enter'){ e.preventDefault(); submit(); }
        if(e.key === 'Escape'){ e.preventDefault(); close(); }
      });
    }
    document.addEventListener('keydown', (e) => {
      if(!state.open) return;
      if(e.key === 'Escape') close();
    });
  }

  // expose
  window.showDiscountModal = function(opts){
    wireOnce();
    open(opts);
  };
  window.closeDiscountModal = close;
})();

function setLineDiscount(id){
  const it = cart[id];
  if(!it){
    showMessage('Ligne introuvable.','error');
    return;
  }
  if(it.isDonation){
    showMessage("Pas de remise sur un don.","info");
    return;
  }

  const current = it.discount
    ? (it.discount.type === 'percent' ? `${it.discount.value}%` : `${Number(it.discount.value).toFixed(2)}`)
    : '';

  if(typeof window.showDiscountModal !== 'function'){
    // fallback ultra-safe
    const raw = prompt(`Remise sur « ${it.name} »\n- Tape 10% ou 2.50\n- Vide pour supprimer`, current);
    if(raw === null) return;
    it.discount = parseDiscountInput(raw);
    render();
    return;
  }

  window.showDiscountModal({
    title: 'Remise ligne',
    subtitle: `Produit : « ${it.name} » — tape 10% ou 2.50. Vide = supprimer.`,
    current,
    chips: ['5%','10%','20%','1','2','5'],
    onSubmit: (raw) => {
      const parsed = parseDiscountInput(raw);
      it.discount = parsed; // null si vide
      render();
    }
  });
}

function clearLineDiscount(id){
  const it = cart[id];
  if(!it) return;
  it.discount = null;
  render();
}

function setCartDiscount(){
  const eligible = getEligibleSubtotal();
  if(eligible <= 0){
    showMessage('Aucun article éligible à la remise panier (les dons sont exclus).','info');
    return;
  }

  const current = globalDiscount
    ? (globalDiscount.type === 'percent' ? `${globalDiscount.value}%` : `${Number(globalDiscount.value).toFixed(2)}`)
    : '';

  if(typeof window.showDiscountModal !== 'function'){
    const raw = prompt(`Remise sur le panier (hors dons)\n- Tape 10% ou 5\n- Vide pour supprimer`, current);
    if(raw === null) return;
    globalDiscount = parseDiscountInput(raw);
    render();
    return;
  }

  window.showDiscountModal({
    title: 'Remise panier',
    subtitle: `S’applique au panier (hors dons). Sous-total éligible : ${eligible.toFixed(2)} €`,
    current,
    chips: ['5%','10%','15%','20%','1','2','5'],
    onSubmit: (raw) => {
      globalDiscount = parseDiscountInput(raw);
      render();
    }
  });
}

function clearCartDiscount(){
  globalDiscount = null;
  render();
}

/* =========================
   Paiements : UI + état bouton
   ========================= */
function updateFinalizeButton(){
  const btn = document.getElementById('finalize-btn');
  if (!btn) return;

  const total = getCartTotal();
  const paid  = getPaidTotal();

  const canFinalize =
    hasEvent &&
    total > 0 &&
    payments.length > 0 &&
    (paid + 0.01 >= total);

  btn.disabled = !canFinalize;
}

function renderPayments() {
  const block = document.getElementById('payments-block');
  if (!block) {
    updateFinalizeButton();
    return;
  }

  if (!payments.length) {
    block.innerHTML = '';
    block.classList.add('payments-empty');
    updateFinalizeButton();
    return;
  }

  const totalCart = getCartTotal();
  let totalPaid = 0;
  payments.forEach(p => totalPaid += Number(p.amount || 0));
  const remaining = Math.max(totalCart - totalPaid, 0);

  const rowsHtml = payments.map(p => `
    <div class="payment-row">
      <span class="payment-method">${p.method}</span>
      <span class="payment-amount">${Number(p.amount || 0).toFixed(2)} €</span>
    </div>
  `).join('');

  block.innerHTML = `
    <div class="payments-header">Paiements enregistrés</div>
    <div class="payments-rows">
      ${rowsHtml}
    </div>
    <div class="payments-summary">
      <div class="summary-item">
        <span class="summary-label">Total payé</span>
        <span class="summary-value">${totalPaid.toFixed(2)} €</span>
      </div>
      <div class="summary-item">
        <span class="summary-label">Reste à payer</span>
        <span class="summary-value ${remaining > 0 ? 'summary-warning' : ''}">
          ${remaining.toFixed(2)} €
        </span>
      </div>
    </div>
  `;

  block.classList.remove('payments-empty');
  updateFinalizeButton();
}

function resetPayments(){
  payments = [];
  const cashInput = document.getElementById('cash-given');
  if(cashInput){
    cashInput.value = '';
  }
  renderPayments();
  updateChange();
}

/* =========================
   AUTO-ESPÈCES depuis "Montant donné"
   ========================= */
function syncAutoCashPaymentFromInput(){
  const cashInput = document.getElementById('cash-given');
  if(!cashInput) return;

  const raw = (cashInput.value || '').replace(',', '.');
  const given = parseFloat(raw);

  const idxAuto = getAutoCashIndex();

  // si vide/0 -> supprime l'espèces auto
  if(isNaN(given) || given <= 0){
    if(idxAuto !== -1){
      payments.splice(idxAuto, 1);
    }
    renderPayments();
    updateFinalizeButton();
    return;
  }

  // si une espèces MANUELLE existe, on ne touche pas
  const hasManualCash = payments.some(p => p.method === 'Especes' && !p.autoCash);
  if(hasManualCash){
    renderPayments();
    updateFinalizeButton();
    return;
  }

  // On calcule le "reste" sans compter l'espèces auto (sinon boucle)
  const total = getCartTotal();
  const paidOther = getPaidTotalExcludingAutoCash();
  const remaining = Math.max(total - paidOther, 0);

  const amount = Math.min(given, remaining);

  // si ça ne sert à rien (reste=0) -> pas d'espèces auto
  if(amount <= 0){
    if(idxAuto !== -1) payments.splice(idxAuto, 1);
    renderPayments();
    updateFinalizeButton();
    return;
  }

  const obj = { method:'Especes', amount:amount, label:'Espèces', autoCash:true };

  if(idxAuto === -1){
    payments.push(obj);
  } else {
    payments[idxAuto] = obj;
  }

  renderPayments();
  updateFinalizeButton();
}

/* =========================
   Ajout d'un paiement via les boutons du footer
   ========================= */
function addPayment(method){
  if(!hasEvent){
    showMessage("Aucun évènement actif.","error");
    return;
  }
  if(Object.keys(cart).length === 0){
    showMessage("Panier vide.","error");
    return;
  }

  let total = getCartTotal();
  let paid  = getPaidTotal();
  let remainingBefore = Math.max(total - paid, 0);

  if(remainingBefore <= 0){
    showMessage("Le total est déjà entièrement encaissé. Réinitialise ou annule un paiement pour modifier la répartition.","info");
    return;
  }

  if(method === 'Especes'){
    // Si une espèces auto existe, on la "fige" en manuel (pas de doublon)
    const idxAuto = getAutoCashIndex();
    if(idxAuto !== -1){
      payments[idxAuto].autoCash = false;
      renderPayments();
      updateChange();
      return;
    }

    // sinon : on utilise le champ cash-given comme avant
    const cashInput = document.getElementById('cash-given');
    let given = 0;
    if(cashInput && cashInput.value.trim() !== ''){
      given = parseFloat(cashInput.value.replace(',', '.')) || 0;
    }
    if(given <= 0){
      showMessage("Montant espèces invalide.","error");
      return;
    }

    const amount = Math.min(given, remainingBefore);
    const change = given - amount;

    payments.push({method:'Especes', amount:amount, label:'Espèces', autoCash:false});
    renderPayments();

    if(change > 0){
      showMessage("Monnaie à rendre : "+change.toFixed(2)+" €","info");
    }

    if(cashInput){
      cashInput.value = '';
    }
    updateChange();
    return;
  }

  // CB / Chèque : on couvre tout le reste
  const amount = remainingBefore;
  payments.push({method, amount, label:method});
  renderPayments();

  // ✅ Règle demandée :
  // - CB/Chèque complète des espèces => auto-validation
  // - CB/Chèque en premier (et couvre tout) => auto-validation
  const remainingAfter = getRemainingTotal();
  if(remainingAfter === 0){
    finalizeSale();
    return;
  }

  updateChange();
}

/* =========================
   Finalisation de la vente
   ========================= */
function finalizeSale(){
  if(!hasEvent){
    showMessage("Aucun évènement actif.","error");
    return;
  }
  if(Object.keys(cart).length === 0){
    showMessage("Panier vide.","error");
    return;
  }
  if(payments.length === 0){
    showMessage("Aucun paiement enregistré.","error");
    return;
  }

  let total = getCartTotal();
  let paid  = getPaidTotal();

  if(paid + 0.01 < total){
    showMessage("Les paiements ne couvrent pas le total.","error");
    return;
  }

  if(total >= CONFIRM_THRESHOLD && !pendingConfirm){
    pendingConfirm = true;
    showMessage(
      "Montant élevé ("+total.toFixed(2)+" €). Clique encore sur 'Valider' pour confirmer.",
      "info"
    );
    setTimeout(()=>{ pendingConfirm = null; }, 5000);
    return;
  }
  pendingConfirm = null;

  if(isSaving){
    showMessage("Vente déjà en cours d'enregistrement…","info");
    return;
  }

  isSaving = true;
  document.querySelectorAll('input').forEach(i => i.blur());

  fetch("save_sale.php",{
    method:"POST",
    headers:{"Content-Type":"application/json"},
    body: JSON.stringify({
      cart:cart,
      total:total.toFixed(2),
      globalDiscount: globalDiscount,
      payments:payments,
      benevole:document.getElementById("benevole")
        ? document.getElementById("benevole").value
        : ''
    })
  }).then(r=>{
    if(!r.ok) return r.text().then(t=>{throw new Error(t || "Erreur serveur");});
    return r.text();
  }).then(()=>{
    cart = {};
    payments = [];
    const cashInput = document.getElementById('cash-given');
    if(cashInput){
      cashInput.value = '';
    }
    render();
    localStorage.removeItem(HOLD_KEY);
    showMessage("Vente enregistrée.","success");
    location.reload();
  }).catch(e=>{
    showMessage("Erreur : "+e.message,"error");
  }).finally(()=>{
    isSaving = false;
  });
}

/* Mise en attente / rappel */
function syncHoldButtons(){
  const raw      = localStorage.getItem(HOLD_KEY);
  const hasHold  = !!raw;

  const holdBtn   = document.getElementById('hold-btn');
  const resumeBtn = document.getElementById('resume-btn');

  if (holdBtn) {
    holdBtn.style.display = hasHold ? 'none' : 'block';
  }
  if (resumeBtn) {
    resumeBtn.style.display = hasHold ? 'block' : 'none';
  }
}

function holdCart(){
  if(Object.keys(cart).length === 0){
    showMessage("Panier vide, rien à mettre en attente.","error");
    return;
  }
  const bene = document.getElementById('benevole') ? document.getElementById('benevole').value : '';
  const payload = {cart:cart, benevole:bene, payments:payments, globalDiscount:globalDiscount};
  localStorage.setItem(HOLD_KEY, JSON.stringify(payload));
  cart = {};
  payments = [];
  globalDiscount = null;
  const cashInput = document.getElementById('cash-given');
  if(cashInput){
    cashInput.value = '';
  }
  render();
  syncHoldButtons();
  showMessage("Vente mise en attente.","success");
}

function resumeCart(){
  const raw = localStorage.getItem(HOLD_KEY);
  if(!raw){
    showMessage("Aucune vente en attente.","error");
    syncHoldButtons();
    return;
  }
  if(Object.keys(cart).length > 0 || payments.length > 0){
    if(!confirm("Remplacer le panier actuel et les paiements par la vente en attente ?")) return;
  }
  try{
    const data = JSON.parse(raw);
    cart = data.cart || {};
    payments = data.payments || [];
    globalDiscount = data.globalDiscount || null;
    const bene = document.getElementById('benevole');
    if(bene && data.benevole !== undefined){
      bene.value = data.benevole;
    }
    render();
    localStorage.removeItem(HOLD_KEY);
    syncHoldButtons();
    showMessage("Vente en attente restaurée.","success");
  }catch(e){
    showMessage("Erreur lors de la récupération de la vente en attente.","error");
  }
}

/* =========================
   Rendu monnaie (corrigé)
   ========================= */
function updateChange(){
  const cashInput     = document.getElementById('cash-given');
  const changeEl      = document.getElementById('cash-change');
  const cashBlock     = document.getElementById('cash-block');
  const remainingText = document.getElementById('cash-remaining-text');
  const keepBtn       = document.getElementById('keep-change-btn');
  if(!cashInput || !changeEl || !cashBlock) return;

  const total = getCartTotal();

  // ✅ IMPORTANT : on calcule le "reste" SANS compter l'espèces auto
  const paidOther       = getPaidTotalExcludingAutoCash();
  const remainingBefore = Math.max(total - paidOther, 0);

  let given = parseFloat((cashInput.value || '').replace(',', '.'));

  if(isNaN(given) || given <= 0){
    changeEl.innerText = '0.00';
    cashBlock.classList.remove('highlight');
    if(remainingText) remainingText.textContent = '';
    if(keepBtn) keepBtn.style.display = 'none';

    syncAutoCashPaymentFromInput();
    updateFinalizeButton();
    return;
  }

  const amountWouldPay = Math.min(given, remainingBefore);
  const change         = given - amountWouldPay;
  const remainingAfter = Math.max(remainingBefore - amountWouldPay, 0);

  changeEl.innerText = change.toFixed(2);

  if(remainingText){
    if(remainingBefore === 0){
      remainingText.textContent =
        "Le total est déjà encaissé. Réinitialise les paiements pour modifier la répartition.";
    } else {
      remainingText.textContent =
        "Après ces espèces : reste " + remainingAfter.toFixed(2) + " € à encaisser.";
    }
  }

  if(change > 0){
    cashBlock.classList.add('highlight');
  } else {
    cashBlock.classList.remove('highlight');
  }

  if(keepBtn){
    if(change > 0 && remainingAfter === 0){
      keepBtn.style.display = 'inline-flex';
    } else {
      keepBtn.style.display = 'none';
    }
  }

  // ✅ met à jour / crée l'espèces auto (et donc le bloc paiements)
  syncAutoCashPaymentFromInput();
  updateFinalizeButton();
}

/* Alerte stock faible */
function checkLowStockAlert(){
  const low = [];
  document.querySelectorAll('.product').forEach(p=>{
    const stockRaw = p.dataset.stock;
    if(stockRaw && stockRaw !== 'null'){
      const s = parseInt(stockRaw,10);
      if(s > 0 && s <= 2){
        low.push(p.dataset.name + " ("+s+")");
      }
    }
  });
  if(low.length){
    showMessage("Stock faible : " + low.join(", "), "info");
    lowStockWarned = true;
  }
}

function setCashGiven(val){
  const input = document.getElementById('cash-given');
  if(!input) return;
  let amount = 0;
  if(val === 'total'){
    const total = getCartTotal();
    const paidOther = getPaidTotalExcludingAutoCash();
    const remaining = Math.max(total - paidOther, 0);
    amount = remaining;
  } else {
    amount = Number(val) || 0;
  }
  input.value = amount.toFixed(2);
  updateChange();
}

/* Vue produits */
function setProductView(mode){
  if(mode === 'list'){
    document.body.classList.add('view-list');
    localStorage.setItem('productView','list');
  } else {
    document.body.classList.remove('view-list');
    localStorage.setItem('productView','tiles');
  }
  updateViewButtons();
}
function updateViewButtons(){
  const tilesBtn = document.getElementById('view-tiles-btn');
  const listBtn  = document.getElementById('view-list-btn');
  if(!tilesBtn || !listBtn) return;
  const isList = document.body.classList.contains('view-list');
  if(isList){
    listBtn.classList.add('active');
    tilesBtn.classList.remove('active');
  } else {
    tilesBtn.classList.add('active');
    listBtn.classList.remove('active');
  }
}

/* Annulation dernière vente */
function undoLastSale(){
  if(!hasEvent){
    showMessage("Aucun évènement actif.","error");
    return;
  }
  if(!hasLastSale){
    showMessage("Aucune vente à annuler pour cet évènement.","error");
    return;
  }
  if(!confirm("Annuler la dernière vente et remettre le stock ?")) return;

  fetch("undo_last_sale.php",{ method:"POST" })
    .then(r=>{
      if(!r.ok) return r.text().then(t=>{throw new Error(t || "Erreur serveur");});
      return r.text();
    })
    .then((txt)=>{
      if(txt && txt.indexOf("Aucune vente") !== -1){
        showMessage("Aucune vente à annuler.","error");
        return;
      }
      showMessage("Dernière vente annulée.","success");
      location.reload();
    })
    .catch(e=>{
      showMessage("Erreur annulation : "+e.message,"error");
    });
}

document.addEventListener('DOMContentLoaded', () => {
  const savedMode = localStorage.getItem('mode');
  if(savedMode === 'normal'){
    document.body.classList.remove('quick-mode');
    cartCollapsed = false;
  } else {
    document.body.classList.add('quick-mode');
    cartCollapsed = true;
  }
  updateQuickButton();

  const lines = document.getElementById('cart');
  if (lines) {
    lines.style.display = cartCollapsed ? 'none' : 'block';
  }
  updateCartToggleLabel();

  const savedView = localStorage.getItem('productView');
  if(savedView === 'list'){
    document.body.classList.add('view-list');
  }
  updateViewButtons();

  const undoBtn = document.getElementById('undo-btn');
  if(undoBtn && !hasLastSale){
    undoBtn.disabled = true;
  }

  syncHoldButtons();

  document.querySelectorAll('.product').forEach(el => {
    el.addEventListener('click', () => {
      const isDonation = el.dataset.donation === '1';
      const out = el.dataset.out === '1';
      const name = el.dataset.name || '';

      if(isDonation){
        const donInput = document.getElementById('don-amount');
        if(donInput){
          donInput.focus();
          showMessage("Saisis le montant du don ci-dessous.","info");
        }
        return;
      }

      if (out) {
        showMessage("Stock épuisé pour "+name, "error");
        return;
      }
      const id = parseInt(el.dataset.id, 10);
      const price = parseFloat(el.dataset.price);
      const stockRaw = el.dataset.stock;
      const stock = (stockRaw === 'null') ? null : parseInt(stockRaw, 10);
      add(id, name, price, stock);
    });
  });

  const isMobileLike = () => window.innerWidth <= 768;
  document.querySelectorAll('input[type="number"]').forEach(inp => {
    inp.addEventListener('focus', () => {
      if(isMobileLike()){
        document.body.classList.add('keyboard-open');
      }
    });
    inp.addEventListener('blur', () => {
      if(isMobileLike()){
        document.body.classList.remove('keyboard-open');
      }
    });
  });

  render();
  syncHoldButtons();

  setTimeout(() => {
    window.scrollTo(0,0);
    if(document.activeElement && document.activeElement.tagName === 'INPUT'){
      document.activeElement.blur();
    }
  }, 50);
});

/* Render global */
function render(){
  const c = document.getElementById("cart");
  if (!c) return;
  c.innerHTML = "";
  let subtotal = 0;

  document.querySelectorAll('.product-qty-badge').forEach(badge => {
    badge.classList.add('hidden');
    badge.textContent = '0';
  });
  // reset highlight on all tiles
  document.querySelectorAll('.products .product').forEach(tile => tile.classList.remove('has-qty'));

  Object.values(cart).forEach(i => {
    const base = getLineBaseTotal(i);
    const disc = getLineDiscountAmount(i);
    const net  = getLineNetTotal(i);
    subtotal += net;

    const hasDisc = disc > 0.00001;

    const discBtn = (!i.isDonation)
      ? `<button class="btn-secondary btn-discount" title="Remise" aria-label="Remise" style="padding:4px 8px" onclick="setLineDiscount(${i.id})">🏷</button>`
      : '';

    const lineHtml = `
      <div class="cart-line">
        <div class="cart-name">
          <div class="cart-title">
            <strong>${i.name}</strong>
            <span class="small cart-qty">x${i.qty}</span>
          </div>
          <div class="cart-prices">
            ${hasDisc ? `<span class="price-old">${base.toFixed(2)} €</span>` : ``}
            <span class="price-new">${net.toFixed(2)} €</span>
            ${hasDisc ? `<span class="discount-note">−${disc.toFixed(2)} €</span>` : ``}
          </div>
        </div>
        <div class="cart-controls">
          ${discBtn}
          <button class="btn-minus" onclick="decItem(${i.id})">−</button>
          <button class="btn-plus" onclick="incItem(${i.id})">+</button>
          <button class="btn-trash" onclick="delItem(${i.id})">🗑</button>
        </div>
      </div>`;

    c.innerHTML += lineHtml;

const badge = document.getElementById('prod-qty-' + i.id);
    if (badge) {
      badge.textContent = String(i.qty);
      badge.classList.remove('hidden');

      // highlight tile when qty > 0
      const tile = document.querySelector('.products .product[data-id="' + i.id + '"]');
      if (tile) tile.classList.add('has-qty');
    }
  });

  // Ligne remise panier (visuel)
  const gdisc = getGlobalDiscountAmount();
  if(gdisc > 0.00001){
    c.innerHTML += `
      <div class="cart-line" style="border-top:1px solid #e5e7eb;margin-top:6px;padding-top:8px">
        <div class="cart-name"><strong>Remise panier</strong> <span class="small" style="color:#6b7280">(hors dons)</span></div>
        <div class="cart-controls">
          <span style="font-weight:700;color:#16a34a">−${gdisc.toFixed(2)} €</span>
          <button class="btn-secondary" style="padding:4px 8px" onclick="setCartDiscount()">Modifier</button>
          <button class="btn-secondary" style="padding:4px 8px" onclick="clearCartDiscount()">✕</button>
        </div>
      </div>`;
  }

  const total = Math.max(subtotal - gdisc, 0);

  const totalEl = document.getElementById("total");
  const totalFooterEl = document.getElementById("totalFooter");
  if (totalEl)       totalEl.innerText       = total.toFixed(2);
  if (totalFooterEl) totalFooterEl.innerText = total.toFixed(2);

  updateChange();
  updateCartToggleLabel(
    total,
    Object.values(cart).reduce((s, i) => s + i.qty, 0)
  );

  renderPayments();
  updateFinalizeButton();

  if (!lowStockWarned) {
    checkLowStockAlert();
  }
}