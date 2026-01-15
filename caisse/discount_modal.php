<?php
// discount_modal.php – modale générique pour saisir une remise (ligne ou panier)
?>

<style>
/* Backdrop */
#discount-modal-backdrop{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,0.35);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:9998;
}

/* Conteneur */
.discount-modal{
  background:#fff;
  border-radius:18px;
  box-shadow:0 20px 45px rgba(15,23,42,0.35);
  max-width:560px;
  width:min(560px, calc(100vw - 24px));
  padding:18px 20px 16px 20px;
  position:relative;
  font-family:system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.discount-modal-title{
  font-size:18px;
  font-weight:800;
  margin:0 0 4px 0;
}
.discount-modal-subtitle{
  font-size:13px;
  color:#6b7280;
  margin:0 0 14px 0;
  line-height:1.35;
}

.discount-modal-close{
  position:absolute;
  top:10px;
  right:12px;
  border:none;
  background:transparent;
  font-size:18px;
  cursor:pointer;
}

.discount-input-row{
  display:flex;
  gap:10px;
  align-items:stretch;
}

#discount-input{
  flex:1;
  border:1px solid #e5e7eb;
  border-radius:12px;
  padding:10px 12px;
  font-size:16px;
  outline:none;
}
#discount-input:focus{
  border-color:#2563eb;
  box-shadow:0 0 0 3px rgba(37,99,235,0.15);
}

.discount-hints{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  margin:12px 0 10px 0;
}
.discount-chip{
  border:1px solid #e5e7eb;
  background:#f9fafb;
  border-radius:999px;
  padding:7px 10px;
  font-size:13px;
  cursor:pointer;
  user-select:none;
}
.discount-chip:active{ transform:translateY(1px); }

#discount-error{
  display:none;
  color:#b91c1c;
  font-size:13px;
  font-weight:700;
  margin:6px 0 0 0;
}

.discount-actions{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
  margin-top:14px;
}

.discount-actions-left{
  display:flex;
  gap:8px;
  align-items:center;
}

.discount-btn{
  border-radius:999px;
  border:1px solid #e5e7eb;
  background:#f9fafb;
  padding:9px 14px;
  font-size:13px;
  font-weight:700;
  cursor:pointer;
}
.discount-btn:active{ transform:translateY(1px); }

.discount-btn-primary{
  background:#16a34a;
  color:#fff;
  border-color:#16a34a;
}

.discount-btn-danger{
  background:#fff;
  border-color:#fecaca;
  color:#b91c1c;
}

.discount-kbd-hint{
  font-size:12px;
  color:#6b7280;
}

@media (max-width:520px){
  .discount-actions{ flex-direction:column; align-items:stretch; }
  .discount-actions-left{ justify-content:space-between; }
}
</style>

<div id="discount-modal-backdrop" aria-hidden="true">
  <div class="discount-modal" role="dialog" aria-modal="true" aria-labelledby="discount-modal-title">
    <button type="button" class="discount-modal-close" onclick="hideDiscountModal()">×</button>
    <h2 id="discount-modal-title" class="discount-modal-title">Remise</h2>
    <p id="discount-modal-subtitle" class="discount-modal-subtitle"></p>

    <div class="discount-input-row">
      <input
        id="discount-input"
        type="text"
        inputmode="decimal"
        placeholder="Ex: 10% ou 2.50"
        autocomplete="off"
        spellcheck="false"
      />
    </div>

    <div class="discount-hints" id="discount-chips"></div>
    <div id="discount-error"></div>

    <div class="discount-actions">
      <div class="discount-actions-left">
        <button type="button" class="discount-btn discount-btn-danger" id="discount-clear-btn" onclick="discountModalClear()">Supprimer</button>
        <span class="discount-kbd-hint">Entrée = OK • Échap = Annuler</span>
      </div>
      <div style="display:flex; gap:8px; justify-content:flex-end">
        <button type="button" class="discount-btn" onclick="hideDiscountModal(true)">Annuler</button>
        <button type="button" class="discount-btn discount-btn-primary" onclick="discountModalApply()">Appliquer</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const backdrop = document.getElementById('discount-modal-backdrop');
  const titleEl  = document.getElementById('discount-modal-title');
  const subEl    = document.getElementById('discount-modal-subtitle');
  const inputEl  = document.getElementById('discount-input');
  const chipsEl  = document.getElementById('discount-chips');
  const errEl    = document.getElementById('discount-error');
  const clearBtn = document.getElementById('discount-clear-btn');

  let onSubmit = null;
  let onCancel = null;

  function setError(msg){
    if(!msg){ errEl.style.display='none'; errEl.textContent=''; return; }
    errEl.textContent = msg;
    errEl.style.display='block';
  }

  function setChips(chips){
    chipsEl.innerHTML = '';
    (chips || []).forEach(txt => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'discount-chip';
      b.textContent = txt;
      b.addEventListener('click', () => {
        inputEl.value = txt;
        setError('');
        inputEl.focus();
        inputEl.setSelectionRange(inputEl.value.length, inputEl.value.length);
      });
      chipsEl.appendChild(b);
    });
  }

  window.showDiscountModal = function(opts){
    const o = opts || {};
    titleEl.textContent = o.title || 'Remise';
    subEl.textContent   = o.subtitle || 'Tape 10% ou 2.50. Laisse vide pour supprimer.';
    inputEl.value       = (o.current || '').toString();
    clearBtn.style.display = (o.allowClear === false) ? 'none' : 'inline-flex';
    setChips(o.chips || ['5%','10%','20%','1','2','5']);
    setError('');

    onSubmit = typeof o.onSubmit === 'function' ? o.onSubmit : null;
    onCancel = typeof o.onCancel === 'function' ? o.onCancel : null;

    backdrop.style.display = 'flex';
    backdrop.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';

    // Focus
    setTimeout(() => {
      inputEl.focus();
      inputEl.setSelectionRange(inputEl.value.length, inputEl.value.length);
    }, 0);
  };

  window.hideDiscountModal = function(userCancel){
    backdrop.style.display = 'none';
    backdrop.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
    setError('');
    const cb = onCancel;
    onSubmit = null;
    onCancel = null;
    if(userCancel && cb) cb();
  };

  window.discountModalApply = function(){
    const raw = (inputEl.value || '').trim();
    // vide => suppression
    if(!raw){
      if(onSubmit) onSubmit('');
      hideDiscountModal(false);
      return;
    }
    // Validation déléguée à l'app si dispo
    if(typeof window.parseDiscountInput === 'function'){
      const parsed = window.parseDiscountInput(raw);
      if(!parsed){
        setError('Valeur invalide. Exemple : 10% ou 2.50');
        return;
      }
    }
    if(onSubmit) onSubmit(raw);
    hideDiscountModal(false);
  };

  window.discountModalClear = function(){
    inputEl.value = '';
    setError('');
    inputEl.focus();
  };

  // Fermer au clic backdrop
  backdrop.addEventListener('click', (e) => {
    if(e.target === backdrop){
      hideDiscountModal(true);
    }
  });

  // Clavier
  document.addEventListener('keydown', (e) => {
    if(backdrop.style.display !== 'flex') return;
    if(e.key === 'Escape'){
      e.preventDefault();
      hideDiscountModal(true);
    }
    if(e.key === 'Enter'){
      // éviter de valider si une IME est en composition
      if(e.isComposing) return;
      e.preventDefault();
      discountModalApply();
    }
  });
})();
</script>
