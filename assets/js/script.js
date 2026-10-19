// =========================================================
// VegBasket front-end interactivity
// =========================================================
function apiUrl(path) {
  // works whether the site lives at root or in a sub-folder
  const base = window.__VEGBASKET_BASE__ || '';
  return base + path;
}

function showNotifyMeButton(card, productId, csrfToken, loggedIn) {
  const footer = card.querySelector('.veg-footer');
  if (!footer || footer.querySelector('.notify-btn')) return;

  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'notify-btn';
  button.dataset.id = productId;
  button.dataset.csrf = csrfToken;
  button.dataset.loggedIn = loggedIn ? '1' : '0';
  button.textContent = 'Notify me';
  button.style.cssText = 'width:100%; border:0; border-radius:8px; padding:10px 14px; background:#1F4D36; color:#fff; font-weight:700; cursor:pointer;';
  footer.replaceChildren(button);
}

function showToast(message, duration = 2200) {
  const toast = document.getElementById('toast');
  if (!toast) return;
  toast.textContent = message;
  toast.classList.add('show');
  clearTimeout(showToast._t);
  showToast._t = setTimeout(() => toast.classList.remove('show'), duration);
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

  const locationModal = document.getElementById('deliveryLocationModal');
  const locationInput = document.getElementById('deliveryAreaInput');
  const locationValue = document.getElementById('deliveryLocationValue');
  const locationForm = document.getElementById('deliveryLocationForm');
  const locationButton = document.getElementById('deliveryLocationBtn');
  const storedAreaKey = 'myvegbasket_delivery_area';
  let locationTrigger = null;
  const setLocationModalOpen = isOpen => {
    if (!locationModal) return;
    locationModal.hidden = !isOpen;
    locationButton?.setAttribute('aria-expanded', String(isOpen));
    if (isOpen) {
      locationTrigger = document.activeElement;
      if (locationInput && locationValue?.textContent !== 'Choose your area') {
        locationInput.value = locationValue.textContent;
      }
      locationInput?.focus();
    } else {
      locationTrigger?.focus();
    }
  };

  if (locationValue) {
    try {
      locationValue.textContent = localStorage.getItem(storedAreaKey) || 'Choose your area';
    } catch (error) {
      console.warn('Delivery area preference storage is unavailable.', error);
    }
  }

  locationButton?.addEventListener('click', () => setLocationModalOpen(true));
  document.getElementById('deliveryLocationClose')?.addEventListener('click', () => setLocationModalOpen(false));
  locationModal?.addEventListener('click', event => {
    if (event.target === locationModal) setLocationModalOpen(false);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && locationModal && !locationModal.hidden) setLocationModalOpen(false);
  });
  locationForm?.addEventListener('submit', event => {
    event.preventDefault();
    const area = locationInput?.value.trim();
    if (!area || !locationValue) return;
    try {
      localStorage.setItem(storedAreaKey, area);
      locationValue.textContent = area;
      setLocationModalOpen(false);
    } catch (error) {
      console.error('Could not save the delivery area preference.', error);
      showToast('Could not save this area on your device. Please try again.');
    }
  });

  const searchForm = document.querySelector('.search-form');
  const searchInput = searchForm?.querySelector('input[name="q"]');
  document.getElementById('searchMicBtn')?.addEventListener('click', () => {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition || !searchForm || !searchInput) {
      showToast('Voice search is not supported by this browser.');
      return;
    }
    const recognition = new SpeechRecognition();
    recognition.lang = 'en-IN';
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;
    recognition.addEventListener('result', event => {
      const transcript = event.results?.[0]?.[0]?.transcript?.trim();
      if (transcript) {
        searchInput.value = transcript;
        searchForm.requestSubmit();
      }
    });
    recognition.addEventListener('error', event => {
      if (event.error !== 'no-speech' && event.error !== 'aborted') {
        showToast('Voice search could not start. Please use the search field.');
      }
    });
    try {
      recognition.start();
    } catch (error) {
      console.error('Voice search could not start.', error);
      showToast('Voice search could not start. Please use the search field.');
    }
  });

  const promoSlider = document.getElementById('promoSlider');
  if (promoSlider) {
    const slides = Array.from(promoSlider.querySelectorAll('.promo-slide'));
    const counter = document.getElementById('promoSlideCount');
    let activeSlide = Math.max(0, slides.findIndex(slide => slide.classList.contains('is-active')));
    const showSlide = index => {
      activeSlide = (index + slides.length) % slides.length;
      slides.forEach((slide, slideIndex) => {
        const active = slideIndex === activeSlide;
        slide.hidden = !active;
        slide.classList.toggle('is-active', active);
      });
      if (counter) counter.textContent = `${activeSlide + 1} / ${slides.length}`;
    };
    document.getElementById('promoPrevious')?.addEventListener('click', () => showSlide(activeSlide - 1));
    document.getElementById('promoNext')?.addEventListener('click', () => showSlide(activeSlide + 1));
    if (slides.length > 1 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      window.setInterval(() => showSlide(activeSlide + 1), 6500);
    }
  }

  let pendingNotify = null;
  const notifyOverlay = document.getElementById('notifyMeOverlay');
  const notifyForm = document.getElementById('notifyMeForm');
  const notifyEmail = document.getElementById('notifyMeEmail');
  const notifySubmit = document.getElementById('notifyMeSubmit');

  function closeNotifyDialog() {
    if (notifyOverlay) notifyOverlay.style.display = 'none';
    pendingNotify = null;
  }

  function sendNotifyRequest(productId, csrfToken, email = '') {
    return fetch(apiUrl('/ajax/notify_api.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        product_id: productId,
        csrf_token: csrfToken,
        email
      })
    }).then(async response => {
      let data;
      try {
        data = await response.json();
      } catch (error) {
        throw new Error('The server returned an invalid response. Please try again.');
      }
      if (!response.ok && !data.message) {
        throw new Error('Could not save your notification. Please try again.');
      }
      return { response, data };
    });
  }

  document.addEventListener('click', event => {
    const button = event.target.closest('.notify-btn');
    if (!button) return;
    const productId = button.dataset.id;
    const csrfToken = button.dataset.csrf;
    if (button.dataset.loggedIn === '1') {
      button.disabled = true;
      sendNotifyRequest(productId, csrfToken)
        .then(({data}) => {
          if (data.success) {
            showToast(data.confirmation_sent === false
              ? 'You are subscribed, but the confirmation email could not be sent. We will still retry your restock alert.'
              : 'Thank you! We will notify you via email when this product is back in stock.', 4500);
          } else {
            showToast(data.message || 'Could not save your notification.');
          }
        })
        .catch(error => showToast(error.message || 'Network error. Please try again.'))
        .finally(() => { button.disabled = false; });
      return;
    }

    if (!notifyOverlay || !notifyEmail) {
      showToast('The email signup form is unavailable. Please refresh and try again.');
      return;
    }
    pendingNotify = { productId, csrfToken };
    notifyOverlay.style.display = 'flex';
    notifyEmail.focus();
  });

  document.getElementById('notifyMeClose')?.addEventListener('click', closeNotifyDialog);
  notifyOverlay?.addEventListener('click', event => {
    if (event.target === notifyOverlay) closeNotifyDialog();
  });

  notifyForm?.addEventListener('submit', event => {
    event.preventDefault();
    const email = notifyEmail.value.trim();
    if (!notifyEmail.checkValidity() || !email) {
      showToast('Please enter a valid email address.');
      notifyEmail.focus();
      return;
    }
    if (!pendingNotify) return;

    notifySubmit.disabled = true;
    notifySubmit.textContent = 'Saving...';
    sendNotifyRequest(pendingNotify.productId, pendingNotify.csrfToken, email)
      .then(({data}) => {
        if (data.success) {
          closeNotifyDialog();
          showToast(data.confirmation_sent === false
            ? 'You are subscribed, but the confirmation email could not be sent. We will still retry your restock alert.'
            : 'Thank you! We will notify you via email when this product is back in stock.', 4500);
        } else {
          showToast(data.message || 'Could not save your notification.');
        }
      })
      .catch(error => showToast(error.message || 'Network error. Please try again.'))
      .finally(() => {
        notifySubmit.disabled = false;
        notifySubmit.textContent = 'Notify me';
      });
  });

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
        addButton.textContent = unavailable ? 'Price unavailable' : 'ADD';
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
          btn.textContent = 'ADD';
          if (data.success) {
            updateCartBadges(data.cart_count, data.cart_total);
            showToast(data.message);
          } else {
            if (data.out_of_stock) {
              showNotifyMeButton(card, id, btn.dataset.csrf, btn.dataset.loggedIn === '1');
            }
            showToast(data.message || 'Could not add item.');
          }
        })
        .catch(() => {
          btn.disabled = false;
          btn.textContent = 'ADD';
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
