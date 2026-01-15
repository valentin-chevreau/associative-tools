// ================================
// evenements.js — gestion historique + clôture de caisse
// ================================

let closingEventId = null;
let fondTheorique = 0;

// --------------------------------
// Soumission auto des filtres
// --------------------------------
function autoSubmitFilters(){
  const form = document.getElementById('history-filters-form');
  if(form){
    form.submit();
  }
}

// --------------------------------
// Ouvrir le modal de clôture
// --------------------------------
function openCloseCaisseModal(eventId, fondAttendu){
  closingEventId = eventId;
  fondTheorique = Number(fondAttendu) || 0;

  const modal = document.getElementById('close-caisse-modal');
  const input = document.getElementById('fond-reel-input');
  const prev  = document.getElementById('ecart-preview');
  const label = document.getElementById('fond-theorique-label');

  if(!modal || !input || !prev || !label){
    alert("Erreur : modal de clôture introuvable (HTML manquant ou IDs incorrects).");
    return;
  }

  // Affichage fond théorique
  label.textContent = fondTheorique.toFixed(2) + ' €';

  // Reset UI
  input.value = '';
  const retraitInput = document.getElementById('retrait-especes-input');
  const retraitNoteInput = document.getElementById('retrait-note-input');
  if (retraitInput) retraitInput.value = '';
  if (retraitNoteInput) retraitNoteInput.value = '';
  prev.textContent = '';
  prev.classList.remove('ok', 'warn');
  prev.classList.add('hidden');

  // Affiche le modal (double sécurité)
  modal.classList.remove('hidden');
  modal.style.display = 'flex';

  // Focus champ
  setTimeout(() => input.focus(), 50);
}

// --------------------------------
// Fermer le modal
// --------------------------------
function closeModal(){
  const modal = document.getElementById('close-caisse-modal');
  if(!modal) return;

  modal.classList.add('hidden');
  modal.style.display = 'none';
}

// --------------------------------
// Mise à jour preview écart
// --------------------------------
function updateEcartPreview(){
  const input = document.getElementById('fond-reel-input');
  const prev  = document.getElementById('ecart-preview');
  if(!input || !prev) return;

  // ✅ parsing robuste virgule / point
  const raw = (input.value || '').replace(',', '.');
  const fondReel = parseFloat(raw);

  if (isNaN(fondReel)) {
    prev.textContent = '';
    prev.classList.add('hidden');
    prev.classList.remove('ok', 'warn');
    return;
  }

  const ecart = fondReel - fondTheorique;
  prev.classList.remove('hidden');

  if (Math.abs(ecart) < 0.01) {
    prev.textContent = 'Caisse conforme ✔';
    prev.classList.remove('warn');
    prev.classList.add('ok');
  } else if (ecart > 0) {
    prev.textContent = `Excédent de ${ecart.toFixed(2)} €`;
    prev.classList.remove('ok');
    prev.classList.add('warn');
  } else {
    prev.textContent = `Manque de ${Math.abs(ecart).toFixed(2)} €`;
    prev.classList.remove('ok');
    prev.classList.add('warn');
  }
}

// --------------------------------
// Confirmation clôture caisse
// --------------------------------
function confirmCloseCaisse(){
  const input = document.getElementById('fond-reel-input');
  if(!input || closingEventId === null){
    alert("Erreur interne : évènement non défini.");
    return;
  }

  // ✅ parsing robuste virgule / point
  const raw = (input.value || '').replace(',', '.');
  const fondReel = parseFloat(raw);

  if (isNaN(fondReel) || fondReel < 0){
    alert("Montant invalide.");
    return;
  }

  // Retrait d'espèces (optionnel)
  const retraitInput = document.getElementById('retrait-especes-input');
  const retraitNoteInput = document.getElementById('retrait-note-input');

  let retraitEspeces = 0;
  if (retraitInput && retraitInput.value.trim() !== ''){
    const rraw = retraitInput.value.replace(',', '.').trim();
    retraitEspeces = parseFloat(rraw);
    if (isNaN(retraitEspeces) || retraitEspeces < 0){
      alert("Retrait invalide.");
      return;
    }
    if (retraitEspeces > fondReel){
      alert("Le retrait ne peut pas dépasser le fond réel compté.");
      return;
    }
  }

  const retraitNote = retraitNoteInput ? retraitNoteInput.value.trim() : '';

  fetch('close_event.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({
      event_id: closingEventId,
      fond_reel: fondReel.toFixed(2),
      retrait_especes: retraitEspeces.toFixed(2),
      retrait_note: retraitNote
    })
  })
  .then(r => r.json())
  .then(res => {
    if(!res.ok) throw new Error(res.error || 'Erreur');
    location.reload();
  })
  .catch(err => {
    alert("Erreur lors de la clôture de caisse.");
    console.error(err);
  });
}

// --------------------------------
// Exposition globale (onclick HTML)
// --------------------------------
window.autoSubmitFilters     = autoSubmitFilters;
window.openCloseCaisseModal = openCloseCaisseModal;
window.closeModal            = closeModal;
window.updateEcartPreview   = updateEcartPreview;
window.confirmCloseCaisse   = confirmCloseCaisse;