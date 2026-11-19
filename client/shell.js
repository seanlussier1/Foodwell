'use strict';
(() => {
  let token = null, account = null, boundBase = null, lastBase = null, expiryTimer = null, notice = '';
  const prefix = location.pathname.includes('/client') ? location.pathname.split('/client')[0] : '';
  const defaultBase = location.port === '8081' ? 'http://localhost/foodwell-api/api/v1' : `${location.origin}${prefix}/api/v1`;
  const pages = {shop:'shop.html', delivery:'delivery.html', customers:'customers.html', products:'products.html', signin:'signin.html', register:'register.html'};
  const frame = document.getElementById('page');
  const normalize = value => { const u = new URL(value); if (!['http:','https:'].includes(u.protocol) || u.username || u.password || u.search || u.hash) throw new Error('Invalid API address.'); return u.href.replace(/\/$/, ''); };
  const notify = () => { for (const link of document.querySelectorAll('[data-page=customers], [data-page=products]')) link.hidden = !account || !['staff','admin'].includes(account.role); document.getElementById('account-link').textContent = account ? 'Account' : 'Sign in'; document.getElementById('shell-status').textContent = account ? `Signed in as ${account.firstName} ${account.lastName}` : notice; try { frame.contentWindow.dispatchEvent(new Event('foodwell-auth-change')); } catch (_) {} };
  function clear(message = '') { clearTimeout(expiryTimer); expiryTimer = null; token = null; account = null; boundBase = null; notice = message; notify(); }
  function expire() {
    if (!token) return;
    clear('Your session has expired or is no longer valid. Please sign in again.');
    const page = new URL(location.href).searchParams.get('page');
    if (page === 'customers' || page === 'products') navigate('signin');
  }
  window.foodwellAuth = {
    base: () => boundBase || lastBase || defaultBase,
    customer: () => account,
    notice: () => notice,
    checkResponse(response, authorization) { if (response.status === 401 && token && authorization === `Bearer ${token}`) expire(); },
    headers(target) { if (!token) return {}; const u = new URL(target, boundBase + '/'); const base = new URL(boundBase); if (u.origin !== base.origin || !u.pathname.startsWith(base.pathname.replace(/\/$/, '') + '/')) throw new Error('Refusing to send a session to another API.'); return {Authorization:`Bearer ${token}`}; },
    async login(email, password, address) { const base = normalize(address || defaultBase); const response = await fetch(base+'/auth/sessions',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({email,password})}); const data = await response.json(); if (!response.ok) throw new Error(data.error?.message || 'Sign in failed.'); clearTimeout(expiryTimer); token=data.accessToken; account=data.customer; boundBase=base; lastBase=base; notice=''; const expires = Date.parse(data.expiresAt); if (Number.isFinite(expires)) expiryTimer=setTimeout(expire, Math.max(0, expires-Date.now())); notify(); return account; },
    async logout() { try { if (token) await fetch(boundBase+'/auth/sessions/current',{method:'DELETE',headers:{Authorization:`Bearer ${token}`}}); } finally { clear(); } },
    navigate,
  };
  function render() {
    const candidate = new URL(location.href).searchParams.get('page') || 'shop';
    const page = Object.hasOwn ? (Object.hasOwn(pages,candidate) ? candidate : 'shop') : (Object.prototype.hasOwnProperty.call(pages,candidate) ? candidate : 'shop');
    for (const link of document.querySelectorAll('[data-page]')) { if (link.dataset.page === page || (page === 'register' && link.dataset.page === 'signin')) link.setAttribute('aria-current','page'); else link.removeAttribute('aria-current'); }
    frame.src = pages[page] + '?embedded=1';
    document.title = `Foodwell | ${page === 'register' ? 'Create account' : page === 'signin' ? 'Account' : page.charAt(0).toUpperCase()+page.slice(1)}`;
  }
  function navigate(page) { if (!Object.prototype.hasOwnProperty.call(pages,page)) return; history.pushState({},'',`?page=${encodeURIComponent(page)}`); render(); }
  document.querySelectorAll('a[data-page], a.brand').forEach(link => link.addEventListener('click', e => { if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return; e.preventDefault(); navigate(link.dataset.page || 'shop'); }));
  frame.addEventListener('load', () => { try { const doc=frame.contentDocument; const resize=()=>{ frame.style.height=Math.max(500,doc.documentElement.scrollHeight)+'px'; }; resize(); new ResizeObserver(resize).observe(doc.body); } catch (_) {} });
  window.addEventListener('popstate',render); notify(); render();
})();
