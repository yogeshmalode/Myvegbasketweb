// =========================================================
// VegBasket front-end interactivity
// =========================================================
function apiUrl(path) {
  // works whether the site lives at root or in a sub-folder
  const base = window.__VEGBASKET_BASE__ || '';
  return base + path;
}

function showToast(message) {
  const toast = document.getElementById('toast');
  if (!toast) return;
  toast.textContent = message;
  toast.classList.add('show');
  clearTimeout(showToast._t);
  showToast._t = setTimeout(() => toast.classList.remove('show'), 2200);
}

function updateCartBadges(count, total) {
  document.querySelectorAll('#cartCount').forEach(el => el.textContent = count);
  document.querySelectorAll('#cartDrawerTotal').forEach(el => el.textContent = '₹' + total);
}

function refreshCartDrawer() {
  fetch(apiUrl('/ajax/get_cart.php'))
    .then(r => r.text())
    .then(html => {
      const body = document.getElementById('cartDrawerBody');
      if (body) body.innerHTML = html;
    });
}

function openCart() {
  document.getElementById('cartDrawer')?.classList.add('open');
  document.getElementById('cartOverlay')?.classList.add('open');
  refreshCartDrawer();
}
function closeCart() {
  document.getElementById('cartDrawer')?.classList.remove('open');
  document.getElementById('cartOverlay')?.classList.remove('open');
}

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('openCartBtn')?.addEventListener('click', openCart);
  document.getElementById('closeCartBtn')?.addEventListener('click', closeCart);
  document.getElementById('cartOverlay')?.addEventListener('click', closeCart);

  // Quantity steppers on product cards
  document.querySelectorAll('.veg-card').forEach(card => {
    const input = card.querySelector('.qty-input');
    card.querySelector('.qty-plus')?.addEventListener('click', () => {
      input.value = Math.max(1, (parseInt(input.value) || 1) + 1);
    });
    card.querySelector('.qty-minus')?.addEventListener('click', () => {
      input.value = Math.max(1, (parseInt(input.value) || 1) - 1);
    });
  });

  // Add to basket (AJAX, no page reload)
  document.querySelectorAll('.add-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const card = btn.closest('.veg-card');
      const qty = card.querySelector('.qty-input')?.value || 1;
      const id = btn.dataset.id;

      btn.disabled = true;
      btn.textContent = 'Adding...';

      fetch(apiUrl('/ajax/add_to_cart.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${encodeURIComponent(id)}&qty=${encodeURIComponent(qty)}`
      })
        .then(r => r.json())
        .then(data => {
          btn.disabled = false;
          btn.textContent = 'Add to basket';
          if (data.success) {
            updateCartBadges(data.cart_count, data.cart_total);
            showToast(data.message);
          } else {
            showToast(data.message || 'Could not add item.');
          }
        })
        .catch(() => {
          btn.disabled = false;
          btn.textContent = 'Add to basket';
          showToast('Network error, please try again.');
        });
    });
  });

  // Cart drawer: quantity change / remove (event delegation, since content is AJAX-loaded)
  document.getElementById('cartDrawerBody')?.addEventListener('change', (e) => {
    if (e.target.classList.contains('cart-qty-input')) {
      const id = e.target.dataset.id;
      const qty = e.target.value;
      fetch(apiUrl('/ajax/update_cart.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${encodeURIComponent(id)}&qty=${encodeURIComponent(qty)}`
      })
        .then(r => r.json())
        .then(data => {
          updateCartBadges(data.cart_count, data.cart_total);
          refreshCartDrawer();
        });
    }
  });

  document.getElementById('cartDrawerBody')?.addEventListener('click', (e) => {
    if (e.target.classList.contains('remove-link')) {
      e.preventDefault();
      const id = e.target.dataset.id;
      fetch(apiUrl('/ajax/remove_from_cart.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${encodeURIComponent(id)}`
      })
        .then(r => r.json())
        .then(data => {
          updateCartBadges(data.cart_count, data.cart_total);
          refreshCartDrawer();
          showToast('Item removed');
        });
    }
  });

  // Initial badge sync on page load
  refreshCartDrawer();
});
