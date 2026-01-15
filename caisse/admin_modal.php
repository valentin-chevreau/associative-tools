<?php
// admin_modal.php – modale d'accès admin pour la mini caisse
?>
<style>
/* Backdrop */
#admin-modal-backdrop{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,0.35);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:9999;
}

/* Conteneur */
.admin-modal{
  background:#fff;
  border-radius:18px;
  box-shadow:0 20px 45px rgba(15,23,42,0.35);
  max-width:560px;
  width:100%;
  padding:20px 24px 18px 24px;
  position:relative;
  font-family:system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

/* Header */
.admin-modal-title{
  font-size:20px;
  font-weight:700;
  margin:0 0 4px 0;
}
.admin-modal-subtitle{
  font-size:13px;
  color:#6b7280;
  margin-bottom:16px;
}

/* Close */
.admin-modal-close{
  position:absolute;
  top:10px;
  right:12px;
  border:none;
  background:transparent;
  font-size:18px;
  cursor:pointer;
}

/* Dots */
#admin-code-dots{
  display:flex;
  gap:8px;
  margin-bottom:16px;
}
.admin-code-dot{
  width:18px;
  height:18px;
  border-radius:999px;
  border:2px solid #e5e7eb;
  background:#f9fafb;
}
.admin-code-dot.filled{
  background:#2563eb;
  border-color:#2563eb;
}

/* Clavier numérique */
.admin-keypad{
  display:grid;
  grid-template-columns:repeat(3, minmax(0,1fr));
  gap:10px;
  margin:16px 0 12px 0;
}

.admin-key-btn{
  border-radius:999px;
  border:1px solid #e5e7eb;
  background:#f9fafb;
  padding:10px 0;
  font-size:18px;
  font-weight:600;
  cursor:pointer;
  color:#111827;
  transition:background .1s, transform .05s, box-shadow .1s;
}
.admin-key-btn:active{
  background:#e5e7eb;
  transform:translateY(1px);
  box-shadow:inset 0 2px 4px rgba(15,23,42,0.2);
}
.admin-key-btn.admin-key-secondary{
  font-size:13px;
}

/* Bouton valider + message clavier */
.admin-footer-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
#admin-submit-btn{
  border-radius:999px;
  background:#16a34a;
  border:none;
  color:#fff;
  font-weight:600;
  font-size:14px;
  padding:8px 18px;
  cursor:pointer;
}
.admin-footer-hint{
  font-size:12px;
  color:#6b7280;
}

/* Erreur */
#admin-error{
  display:none;
  color:#b91c1c;
  font-size:13px;
  font-weight:600;
  margin-bottom:8px;
}
</style>

<div id="admin-modal-backdrop" aria-hidden="true">
  <div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="admin-modal-title">
    <button type="button" class="admin-modal-close" onclick="hideAdminModal()">×</button>
    <h2 id="admin-modal-title" class="admin-modal-title">Accès administration</h2>
    <p class="admin-modal-subtitle">
      Saisis ton <strong>code d’accès à 8 chiffres</strong> pour entrer dans l’administration.
    </p>

    <div id="admin-error">Code incorrect.</div>

    <!-- Dots -->
    <div id="admin-code-dots">
      <div class="admin-code-dot"></div>
      <div class="admin-code-dot"></div>
      <div class="admin-code-dot"></div>
      <div class="admin-code-dot"></div>
      <div class="admin-code-dot"></div>
      <div class="admin-code-dot"></div>
      <div class="admin-code-dot"></div>
      <div class="admin-code-dot"></div>
    </div>

    <!-- Champ caché -->
    <input type="password" id="admin_code_input" style="position:absolute;opacity:0;pointer-events:none" autocomplete="one-time-code"/>

    <!-- Pavé numérique -->
    <div class="admin-keypad">
      <button type="button" class="admin-key-btn" onclick="adminPushDigit(1)">1</button>
      <button type="button" class="admin-key-btn" onclick="adminPushDigit(2)">2</button>
      <button type="button" class="admin-key-btn" onclick="adminPushDigit(3)">3</button>

      <button type="button" class="admin-key-btn" onclick="adminPushDigit(4)">4</button>
      <button type="button" class="admin-key-btn" onclick="adminPushDigit(5)">5</button>
      <button type="button" class="admin-key-btn" onclick="adminPushDigit(6)">6</button>

      <button type="button" class="admin-key-btn" onclick="adminPushDigit(7)">7</button>
      <button type="button" class="admin-key-btn" onclick="adminPushDigit(8)">8</button>
      <button type="button" class="admin-key-btn" onclick="adminPushDigit(9)">9</button>

      <button type="button" class="admin-key-btn admin-key-secondary" onclick="adminClearCode()">Effacer</button>
      <button type="button" class="admin-key-btn" onclick="adminPushDigit(0)">0</button>
      <button type="button" class="admin-key-btn admin-key-secondary" onclick="adminBackspace()">←</button>
    </div>

    <div class="admin-footer-row">
      <span class="admin-footer-hint">Tu peux aussi taper directement au clavier.</span>
      <button type="button" id="admin-submit-btn" onclick="adminSubmitCode()">Valider</button>
    </div>
  </div>
</div>

<script>
(function(){
  let adminCode = '';
  const maxLen = 8;

  const backdrop    = document.getElementById('admin-modal-backdrop');
  const inputHidden = document.getElementById('admin_code_input');
  const dots        = document.querySelectorAll('#admin-code-dots .admin-code-dot');
  const errorBox    = document.getElementById('admin-error');

  function refreshDots() {
    dots.forEach((dot, idx) => {
      if (idx < adminCode.length) {
        dot.classList.add('filled');
      } else {
        dot.classList.remove('filled');
      }
    });
  }

  function syncHidden() {
    if (inputHidden) inputHidden.value = adminCode;
  }

  function showError(msg) {
    errorBox.textContent = msg || "Code incorrect.";
    errorBox.style.display = 'block';
  }

  function hideError() {
    errorBox.textContent = '';
    errorBox.style.display = 'none';
  }

  function adminClearCode() {
    adminCode = '';
    syncHidden();
    refreshDots();
    // NE PAS masquer l'erreur ici, sinon elle disparaît immédiatement
  }

  function adminSubmitCodeInternal() {
    if (adminCode.length !== maxLen) return;

    const fd = new FormData();
    fd.append('admin_code', adminCode);

    hideError();

    fetch('admin_login.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
    .then(resp => resp.text())
    .then(text => {
      const t = text.trim();
      if (t === 'OK') {
        hideAdminModal();
        window.location.reload();
        return;
      }
      if (t === 'CONFIG_MISSING') {
        showError("Code admin non configuré (ADMIN_CODE dans config.php).");
      } else {
        showError("Code incorrect.");
      }
      adminClearCode(); // on garde l'erreur visible
    })
    .catch(() => {
      showError("Erreur réseau lors de la vérification du code admin.");
      adminClearCode();
    });
  }

  function maybeAutoSubmit() {
    if (adminCode.length === maxLen) {
      adminSubmitCodeInternal();
    }
  }

  window.showAdminModal = function() {
    adminClearCode();
    hideError();
    backdrop.style.display = 'flex';
    backdrop.setAttribute('aria-hidden', 'false');
    if (inputHidden) inputHidden.focus();
  };

  window.hideAdminModal = function() {
    backdrop.style.display = 'none';
    backdrop.setAttribute('aria-hidden', 'true');
  };

  window.adminPushDigit = function(d) {
    if (adminCode.length >= maxLen) return;
    adminCode += String(d);
    syncHidden();
    refreshDots();
    maybeAutoSubmit();
  };

  window.adminBackspace = function() {
    if (!adminCode.length) return;
    adminCode = adminCode.slice(0, -1);
    syncHidden();
    refreshDots();
  };

  window.adminClearCode = adminClearCode;
  window.adminSubmitCode = adminSubmitCodeInternal;

  document.addEventListener('keydown', function(e){
    if (backdrop.style.display !== 'flex') return;

    if (e.key >= '0' && e.key <= '9') {
      e.preventDefault();
      if (adminCode.length < maxLen) {
        adminCode += e.key;
        syncHidden();
        refreshDots();
        maybeAutoSubmit();
      }
    } else if (e.key === 'Backspace') {
      e.preventDefault();
      adminBackspace();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      hideAdminModal();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (adminCode.length === maxLen) {
        adminSubmitCodeInternal();
      }
    }
  });

  backdrop.addEventListener('click', function(e){
    if (e.target === backdrop) {
      hideAdminModal();
    }
  });
})();
</script>