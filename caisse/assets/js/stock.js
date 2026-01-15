document.addEventListener('DOMContentLoaded', () => {
  // Ouvrir/fermer les panels d'édition
  document.querySelectorAll('.js-edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-edit');
      const panel = document.getElementById('edit-' + id);
      if (!panel) return;

      const isHidden = panel.hasAttribute('hidden');
      if (isHidden) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', 'hidden');
    });
  });

  document.querySelectorAll('.js-cancel-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-cancel');
      const panel = document.getElementById('edit-' + id);
      if (!panel) return;
      panel.setAttribute('hidden', 'hidden');
    });
  });

  // UX: si "stock illimité" est coché, on grise le champ stock associé
  document.querySelectorAll('form').forEach(form => {
    const unlimited = form.querySelector('input[name="stock_unlimited"]');
    const stock = form.querySelector('input[name="stock"]');
    if (!unlimited || !stock) return;

    const sync = () => {
      const on = unlimited.checked;
      stock.disabled = on;
      if (on) stock.value = '';
    };

    unlimited.addEventListener('change', sync);
    sync();
  });
});