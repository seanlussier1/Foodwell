'use strict';
(() => {
  const page = document.body.dataset.page;
  if (window.top === window.self) { location.replace(`./?page=${encodeURIComponent(page || 'shop')}`); return; }
  try {
    if (window.parent.location.origin !== location.origin || !window.parent.foodwellAuth) throw new Error('Invalid container');
    window.foodwellAuth = window.parent.foodwellAuth;
    document.documentElement.classList.add('embedded');
  } catch (_) { location.replace(`./?page=${encodeURIComponent(page || 'shop')}`); }
})();
