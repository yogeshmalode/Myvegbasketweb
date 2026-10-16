// =========================================================
// VegBasket front-end interactivity
// =========================================================
function apiUrl(path) {
  // works whether the site lives at root or in a sub-folder
  const base = window.__VEGBASKET_BASE__ || '';
  return base + path;
}

function showNotifyMeForm(card, productId, csrfToken) {
  const footer = card.querySelector('.veg-footer');
  if (!footer || footer.querySelector('.notify-form')) return;

  const form = document.createElement('form');
  form.className = 'notify-form';
  form.dataset.id = productId;
  form.style.cssText = 'width:100%; display:flex; gap:8px; flex-wrap:wrap;';

  const input = document.createElement('input');
  input.type = 'email';
  input.name = 'email';
  input.required = true;
  input.autocomplete = 'email';
  input.placeholder = 'Your email address';
  input.setAttribute('aria-label', 'Email address for back-in-stock alert');
  input.style.cssText = 'flex:1; min-width:150px; padding:9px 10px; border:1px solid #D9E0CD; border-radius:8px;';

  const button = document.createElement('button');
  button.type = 'submit';
  button.className = 'notify-btn';
  button.dataset.csrf = csrfToken;
  button.textContent = 'Notify me';
  button.style.cssText = 'border:0; border-radius:8px; padding:9px 14px; background:#1F4D36; color:#fff; font-weight:700; cursor:pointer;';

  const message = document.createElement('span');
  message.className = 'notify-message';
  message.setAttribute('role', 'status');
  message.style.cssText = 'width:100%; font-size:0.82rem; color:#5B6656;';

  form.append(input, button, message);
  footer.replaceChildren(form);
  input.focus();
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

  // Size/weight variant picker (250 g, 500 g, 1 kg, etc.) — update the
  // displayed price on the card whenever the shopper picks a different size.
  document.querySelectorAll('.variant-select').forEach(select => {
    select.addEventListener('change', () => {
      const card = select.closest('.veg-card');
      const option = select.selectedOptions[0];
      const amountEl = card.querySelector('.price-tag .amount');
      if (amountEl && option) {
        amountEl.textContent = '₹' + parseFloat(option.dataset.price).toFixed(2);
      }
      const addButton = card.querySelector('.add-btn');
      if (addButton && option) {
        const price = parseFloat(option.dataset.price);
        const unavailable = !Number.isFinite(price) || price <= 0;
        addButton.disabled = unavailable;
        addButton.textContent = unavailable ? 'Price unavailable' : 'Add to basket';
      }
    });
  });

  // Add to basket (AJAX, no page reload)
  document.querySelectorAll('.add-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const card = btn.closest('.veg-card');
      const qty = card.querySelector('.qty-input')?.value || 1;
      const id = btn.dataset.id;
      const variantSelect = card.querySelector('.variant-select');
      const variantId = variantSelect ? variantSelect.value : '';

      btn.disabled = true;
      btn.textContent = 'Adding...';

      fetch(apiUrl('/ajax/add_to_cart.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${encodeURIComponent(id)}&qty=${encodeURIComponent(qty)}&variant_id=${encodeURIComponent(variantId)}`
      })
        .then(r => r.json())
        .then(data => {
          btn.disabled = false;
          btn.textContent = 'Add to basket';
          if (data.success) {
            updateCartBadges(data.cart_count, data.cart_total);
            showToast(data.message);
          } else {
            if (data.out_of_stock) {
              showNotifyMeForm(card, id, btn.dataset.csrf);
            }
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

  document.addEventListener('submit', (event) => {
    const form = event.target.closest('.notify-form');
    if (!form) return;
    event.preventDefault();
    const button = form.querySelector('.notify-btn');
    const input = form.querySelector('input[name="email"]');
    const message = form.querySelector('.notify-message');
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Saving...';
    message.textContent = '';

    fetch(apiUrl('/ajax/notify_when_available.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        product_id: form.dataset.id,
        email: input.value,
        csrf_token: button.dataset.csrf
      })
    })
      .then(response => response.json())
      .then(data => {
        message.textContent = data.message || 'Could not save your request.';
        if (data.success) {
          input.disabled = true;
          button.textContent = "You're on the list";
          showToast(data.message);
        } else {
          button.disabled = false;
          button.textContent = originalText;
        }
      })
      .catch(() => {
        button.disabled = false;
        button.textContent = originalText;
        message.textContent = 'Network error. Please try again.';
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
