// assets/toast.js
(function () {
  function ensureWrap() {
    let wrap = document.querySelector(".toast-wrap");
    if (!wrap) {
    wrap = document.createElement("div");
    wrap.className = "toast-wrap";
    document.body.appendChild(wrap);
    }
    return wrap;
  }

  function titleFor(type) {
    switch (type) {
      case "success": return "OK";
      case "error": return "Erreur";
      case "warning": return "Attention";
      default: return "Info";
    }
  }

  window.showToast = function (type, message, timeoutMs = 4500) {
    const wrap = ensureWrap();
    const safeType = ["success", "error", "warning", "info"].includes(type) ? type : "info";

    const toast = document.createElement("div");
    toast.className = `toast ${safeType}`;
    toast.innerHTML = `
    <div class="bar"></div>
    <div>
    <div class="title">${titleFor(safeType)}</div>
    <p class="msg"></p>
    </div>
    <button class="close" type="button" aria-label="Fermer">×</button>
    `;
    toast.querySelector(".msg").textContent = String(message ?? "");
    toast.querySelector(".close").addEventListener("click", () => toast.remove());

    wrap.appendChild(toast);

    const t = Number(timeoutMs);
    if (!Number.isNaN(t) && t > 0) {
      setTimeout(() => toast.remove(), t);
    }
  };

  // Flash “data-attributes” (optionnel)
  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-toast-type][data-toast-msg]").forEach(el => {
    showToast(el.getAttribute("data-toast-type"), el.getAttribute("data-toast-msg"), 4500);
    el.remove();
    });
  });
})();