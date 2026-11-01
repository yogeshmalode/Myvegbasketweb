document.addEventListener('DOMContentLoaded', () => {
  const revealTargets = document.querySelectorAll(
    '.promo-hero, .partner-spotlight-section, .catalog-sidebar, .deal-section, .product-section'
  );
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (reduceMotion || !('IntersectionObserver' in window)) {
    revealTargets.forEach(target => target.classList.add('is-visible'));
  } else {
    const revealObserver = new IntersectionObserver((entries, observer) => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.08 });

    revealTargets.forEach(target => {
      target.classList.add('ui-reveal');
      revealObserver.observe(target);
    });
  }

  document.querySelectorAll('.cart-count').forEach(badge => {
    const badgeObserver = new MutationObserver(() => {
      badge.classList.remove('ui-bounce');
      window.requestAnimationFrame(() => badge.classList.add('ui-bounce'));
    });
    badgeObserver.observe(badge, { childList: true, characterData: true, subtree: true });
  });
});
