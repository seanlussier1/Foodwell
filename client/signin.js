'use strict';
const form = document.getElementById('signin-form'), status = document.getElementById('signin-status');
function update() { const account = window.foodwellAuth.customer(); document.getElementById('signin-heading').textContent = account ? 'Your account' : 'Sign in'; form.hidden = !!account; document.getElementById('register-prompt').hidden = !!account; document.getElementById('signout').hidden = !account; status.textContent = account ? `Signed in as ${account.firstName} ${account.lastName} (${account.role}).` : window.foodwellAuth.notice(); }
document.getElementById('signin-api').value = window.foodwellAuth.base();
form.addEventListener('submit', async event => { event.preventDefault(); const password = document.getElementById('signin-password'); try { const account = await window.foodwellAuth.login(document.getElementById('signin-email').value.trim(), password.value, document.getElementById('signin-api').value); window.foodwellAuth.navigate(account.role === 'customer' ? 'shop' : 'customers'); } catch (error) { status.textContent = error.message; } finally { password.value=''; } });
document.getElementById('signout').addEventListener('click', async () => { try { await window.foodwellAuth.logout(); } catch (_) { status.textContent='Signed out locally.'; } update(); });
window.addEventListener('foodwell-auth-change', update); update();

document.getElementById('go-register').addEventListener('click', event => { if (event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) { event.preventDefault(); window.foodwellAuth.navigate('register'); } });
