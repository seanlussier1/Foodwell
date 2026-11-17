'use strict';
const registerForm = document.getElementById('register-form');
const registerStatus = document.getElementById('register-status');
const registerErrors = document.getElementById('register-errors');
const registerButton = document.getElementById('register-submit');
const password = document.getElementById('register-password');
const confirmation = document.getElementById('register-confirm');
const signinLink = document.getElementById('go-signin');
signinLink.addEventListener('click', event => { if (event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) { event.preventDefault(); window.foodwellAuth.navigate('signin'); } });
registerForm.addEventListener('submit', async event => {
  event.preventDefault();
  registerStatus.textContent = '';
  registerErrors.replaceChildren();
  if (password.value !== confirmation.value) { registerStatus.textContent = 'Passwords do not match.'; confirmation.focus(); return; }
  const byteLength = new TextEncoder().encode(password.value).length;
  if (byteLength < 8 || byteLength > 72 || password.value.includes('\0')) { registerStatus.textContent = 'Password must be 8–72 bytes and cannot contain a null character.'; password.focus(); return; }
  const input = {firstName:document.getElementById('register-first').value.trim(), lastName:document.getElementById('register-last').value.trim(), email:document.getElementById('register-email').value.trim(), password:password.value};
  if (!input.firstName || !input.lastName) { registerStatus.textContent = 'Enter your first and last name.'; return; }
  registerButton.disabled = true;
  try {
    const response = await fetch(window.foodwellAuth.base() + '/customers', {method:'POST', headers:{'Accept':'application/json','Content-Type':'application/json'}, body:JSON.stringify(input)});
    const data = await response.json();
    if (!response.ok) {
      registerStatus.textContent = data.error?.message || 'Account could not be created. Please try again.';
      for (const [field, message] of Object.entries(data.error?.details || {})) { const item = document.createElement('li'); item.textContent = `${field}: ${message}`; registerErrors.append(item); }
      return;
    }
    registerForm.reset(); registerForm.hidden = true;
    registerStatus.textContent = 'Your account has been created. Sign in to continue.';
    signinLink.focus();
  } catch (_) { registerStatus.textContent = 'Could not connect to Foodwell. Please try again.'; }
  finally { registerButton.disabled = false; password.value = ''; confirmation.value = ''; input.password = ''; }
});
