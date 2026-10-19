(() => {
  const config = document.getElementById('orderLiveRefresh');
  if (!config) return;

  const scope = config.dataset.scope;
  const currentVersion = config.dataset.version;
  const page = config.dataset.page;
  const orderId = config.dataset.orderId;
  if (!['admin', 'customer', 'rider', 'single'].includes(scope) || !currentVersion) return;

  const base = (window.__VEGBASKET_BASE__ || '').replace(/\/$/, '');
  const query = new URLSearchParams({ scope });
  if (scope === 'admin' && page) query.set('page', page);
  if (scope === 'single' && orderId) query.set('order_id', orderId);
  const updatesUrl = `${base}/ajax/order_updates.php?${query}`;
  let requestInFlight = false;
  let refreshPending = false;
  let requestErrorLogged = false;

  function pageIsBeingEdited() {
    const activeElement = document.activeElement;
    if (activeElement instanceof HTMLElement && (
      activeElement.matches('input, select, textarea, [contenteditable="true"]')
    )) {
      return true;
    }

    if (document.querySelector('dialog[open]')) return true;

    return Array.from(document.forms).some(form => Array.from(form.elements).some(element => {
      if (element instanceof HTMLInputElement) {
        return element.type === 'checkbox' || element.type === 'radio'
          ? element.checked !== element.defaultChecked
          : element.value !== element.defaultValue;
      }
      if (element instanceof HTMLTextAreaElement) return element.value !== element.defaultValue;
      if (element instanceof HTMLSelectElement) {
        return Array.from(element.options).some(option => option.selected !== option.defaultSelected);
      }
      return false;
    }));
  }

  function refreshWhenSafe() {
    if (!refreshPending || document.visibilityState !== 'visible' || pageIsBeingEdited()) return;
    const activeOrderTab = document.querySelector('.order-tab-btn.active');
    if (scope === 'admin' && activeOrderTab?.dataset.tab) {
      try {
        sessionStorage.setItem('myvegbasket_admin_order_tab', activeOrderTab.dataset.tab);
      } catch (error) {
        console.warn('Could not preserve the selected orders tab.', error);
      }
    }
    refreshPending = false;
    window.location.reload();
  }

  async function checkForOrderChanges() {
    if (requestInFlight || document.visibilityState !== 'visible') return;
    requestInFlight = true;

    try {
      const response = await fetch(updatesUrl, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });

      if (response.status === 401 || response.status === 403) return;
      if (!response.ok) throw new Error(`Order update check failed with HTTP ${response.status}.`);

      const data = await response.json();
      if (data.success && typeof data.version === 'string' && data.version !== currentVersion) {
        refreshPending = true;
        refreshWhenSafe();
      }
      requestErrorLogged = false;
    } catch (error) {
      if (!requestErrorLogged) {
        console.warn('Could not check for order updates. Automatic checks will retry.', error);
        requestErrorLogged = true;
      }
    } finally {
      requestInFlight = false;
    }
  }

  window.setInterval(checkForOrderChanges, 5000);
  window.setInterval(refreshWhenSafe, 1500);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') checkForOrderChanges();
  });
  window.addEventListener('focus', refreshWhenSafe);
})();
