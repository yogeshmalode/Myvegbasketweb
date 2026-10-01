<footer class="site-footer">
  <div class="container footer-inner">
    <div>
      <img src="<?= BASE_URL ?>/assets/images/logo-horizontal-white.png" alt="MyVegBasket" style="height:34px; width:auto; margin-bottom:10px;">
      <p style="color:#B7C9AC; max-width:320px;">Fresh vegetables sourced from local farms, delivered to your doorstep the same day.</p>
    </div>
    <div>
      <h4 style="color:#fff; font-size:1rem;">Shop</h4>
      <p><a href="<?= BASE_URL ?>/index.php" style="color:#B7C9AC;">All vegetables</a></p>
      <p><a href="<?= BASE_URL ?>/cart.php" style="color:#B7C9AC;">My cart</a></p>
      <p><a href="<?= BASE_URL ?>/photo_credits.php" style="color:#B7C9AC;">Product photo credits</a></p>
    </div>
    <div>
      <h4 style="color:#fff; font-size:1rem;">Contact</h4>
      <p style="color:#B7C9AC;"><a href="mailto:myvegbasketcare@gmail.com" style="color:#B7C9AC;">myvegbasketcare@gmail.com</a></p>
      <p style="color:#B7C9AC;"><a href="tel:+917972381861" style="color:#B7C9AC;">+91 79723 81861</a></p>
    </div>
  </div>
  <small>&copy; <?= date('Y') ?> MyVegBasket. Built with PHP.</small>
</footer>

<a href="https://wa.me/<?= WHATSAPP_NUMBER ?>?text=<?= rawurlencode('Hello, I need help with my order.') ?>"
   target="_blank" rel="noopener noreferrer" title="Chat with us on WhatsApp"
   style="position:fixed; width:56px; height:56px; bottom:22px; right:22px; background-color:#25D366; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:2px 2px 10px rgba(0,0,0,0.3); z-index:1000; text-decoration:none; font-size:28px;">
  💬
</a>

<script src="<?= BASE_URL ?>/assets/js/script.js"></script>
</body>
</html>
