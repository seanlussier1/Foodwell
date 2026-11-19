'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function setup() {
  const elements = Object.fromEntries(['page', 'account-link', 'shell-status'].map(id => [id, { textContent: '', addEventListener() {} }]));
  elements.page.contentWindow = { dispatchEvent() {} };
  const location = { pathname: '/client/', origin: 'http://localhost:8080', port: '8080', href: 'http://localhost:8080/client/?page=shop' };
  let timer;
  let nextToken = 'first';
  const context = {
    location, URL, Date, Event: class {}, ResizeObserver: class {},
    history: { pushState() {} },
    document: { getElementById: id => elements[id], querySelectorAll: () => [] },
    window: { addEventListener() {} },
    setTimeout(fn) { timer = fn; return 1; }, clearTimeout() { timer = null; },
    fetch: async () => ({ ok: true, json: async () => ({ accessToken: nextToken, customer: { firstName: 'Alex', lastName: 'Example', role: 'customer' }, expiresAt: new Date(Date.now() + 7200000).toISOString() }) }),
  };
  vm.runInNewContext(fs.readFileSync('client/shell.js', 'utf8'), context);
  return { auth: context.window.foodwellAuth, elements, expire: () => timer(), setToken: value => { nextToken = value; } };
}

test('expiry clears the account and displays a sign-in notice', async () => {
  const { auth, elements, expire } = setup();
  await auth.login('alex@example.test', 'password', 'http://localhost:8080/api/v1');
  assert.equal(auth.customer().firstName, 'Alex');
  expire();
  assert.equal(auth.customer(), null);
  assert.deepEqual(Object.keys(auth.headers('http://localhost:8080/api/v1/customers')), []);
  assert.match(elements['shell-status'].textContent, /sign in again/i);
  assert.equal(elements['account-link'].textContent, 'Sign in');
});

test('a rejected current session clears it, but an old response cannot clear a new login', async () => {
  const { auth, setToken } = setup();
  await auth.login('alex@example.test', 'password', 'http://localhost:8080/api/v1');
  const oldAuthorization = auth.headers('http://localhost:8080/api/v1/customers').Authorization;
  setToken('second');
  await auth.login('alex@example.test', 'password', 'http://localhost:8080/api/v1');
  auth.checkResponse({ status: 401 }, oldAuthorization);
  assert.equal(auth.customer().firstName, 'Alex');
  auth.checkResponse({ status: 401 }, 'Bearer second');
  assert.equal(auth.customer(), null);
  assert.match(auth.notice(), /sign in again/i);
});
