// public/assets/app.js
// Tiny UX helpers shared across pages. Keep it lightweight.

/** Auto-focus the first text input on the page (nice on forms) */
(function () {
    const first = document.querySelector('input[type="text"], input[type="email"], input[type="number"], input:not([type]), textarea, select');
    if (first) {
      try { first.focus(); } catch (_) {}
    }
  })();
  
  /** Delegate confirm dialogs for links/buttons with [data-confirm] */
  document.addEventListener('click', function (e) {
    const t = e.target.closest('[data-confirm]');
    if (!t) return;
    const msg = t.getAttribute('data-confirm') || 'Are you sure?';
    if (!confirm(msg)) {
      e.preventDefault();
      e.stopPropagation();
    }
  });
  
  /** Simple helper: format number inputs with step=0.01 on blur to 2dp */
  document.addEventListener('blur', function (e) {
    const el = e.target;
    if (!(el instanceof HTMLInputElement)) return;
    if (el.type === 'number' && (el.step === '0.01' || el.step === 'any')) {
      const v = el.value.trim();
      if (v !== '' && !isNaN(+v)) {
        el.value = (+v).toFixed(2);
      }
    }
  }, true);
  
  /** Prevent multiple submits on forms (adds disabled to the clicked submit) */
  document.addEventListener('submit', function (e) {
    const form = e.target;
    const btn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (btn) {
      btn.setAttribute('disabled', 'disabled');
      setTimeout(() => btn.removeAttribute('disabled'), 3000); // safety re-enable
    }
  });
  