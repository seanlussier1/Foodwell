'use strict';
const $ = (id) => document.getElementById(id);
const state = { page: 1, limit: 2, search: '', active: '', editing: null, pages: 0, loading: false };
let pendingList = 0;
let listAbort;
const apachePrefix = location.pathname.includes('/client') ? location.pathname.split('/client')[0] : '';
const defaultApi = location.port === '8081' ? 'http://localhost/foodwell-api/api/v1' : `${location.origin}${apachePrefix}/api/v1`;
$('api-url').value = defaultApi;

function feedback(title, error = false, details = {}) {
  $('feedback').hidden = false;
  $('feedback').classList.toggle('error', error);
  $('feedback-title').textContent = title;
  $('feedback-details').replaceChildren();
  for (const [field, message] of Object.entries(details || {})) {
    const li = document.createElement('li');
    li.textContent = `${field}: ${message}`;
    $('feedback-details').append(li);
  }
}
function base() {
  const url = new URL($('api-url').value.trim());
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) throw new Error('Use an HTTP API base URL without credentials, query or fragment.');
  return url.href.replace(/\/$/, '');
}
async function request(path, { method = 'GET', body, signal } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  let response;
  try { response = await fetch(`${base()}${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), signal }); }
  catch (error) { if (error.name === 'AbortError') throw error; throw new Error('Cannot reach the API. Check the API URL, Wamp/server and CORS settings.'); }
  let result;
  try { result = await response.json(); } catch { throw new Error(`HTTP ${response.status}: the API did not return JSON. Check the API URL and server.`); }
  if (!response.ok) {
    const error = new Error(`Server error (${response.status}): ${result.error?.message || 'Request failed.'}`);
    error.details = result.error?.details || {};
    throw error;
  }
  return { result, status: response.status };
}
function pagination() {
  $('previous').disabled = state.loading || state.page <= 1 || state.pages === 0;
  $('next').disabled = state.loading || state.page >= state.pages;
}
async function loadCustomers(lastPage = false) {
  const current = ++pendingList;
  listAbort?.abort(); listAbort = new AbortController();
  state.loading = true; pagination();
  $('list-summary').textContent = 'Loading customers…';
  const query = new URLSearchParams({ page: state.page, limit: state.limit });
  if (state.search) query.set('search', state.search);
  if (state.active !== '') query.set('isActive', state.active);
  try {
    const { result } = await request(`/customers?${query}`, { signal: listAbort.signal });
    if (current !== pendingList) return;
    state.pages = result.metadata.totalPages;
    if (lastPage && state.pages > 1) { state.page = state.pages; return await loadCustomers(); }
    if (result.data.length === 0 && state.page > 1 && state.pages > 0) { state.page = state.pages; return await loadCustomers(); }
    $('list-summary').textContent = `${result.metadata.total} matching customer${result.metadata.total === 1 ? '' : 's'} · ${result.data.length} shown`;
    $('page-label').textContent = state.pages ? `Page ${state.page} of ${state.pages}` : 'No results';
    $('customers').replaceChildren();
    for (const customer of result.data) {
      const row = document.createElement('tr');
      const nameCell = row.insertCell();
      const name = document.createElement('span'); name.className = 'customer-name'; name.textContent = `${customer.firstName} ${customer.lastName}`;
      const email = document.createElement('span'); email.className = 'customer-email'; email.textContent = `#${customer.id} · ${customer.email}`;
      nameCell.append(name, email);
      const pill = document.createElement('span'); pill.className = `status-pill${customer.isActive ? '' : ' inactive'}`; pill.textContent = customer.isActive ? 'Active' : 'Inactive'; row.insertCell().append(pill);
      const actions = document.createElement('div'); actions.className = 'row-actions';
      const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'secondary'; edit.textContent = 'Edit'; edit.setAttribute('aria-label', `Edit ${customer.firstName} ${customer.lastName}`); edit.onclick = () => editCustomer(customer);
      const deactivate = document.createElement('button'); deactivate.type = 'button'; deactivate.className = 'deactivate'; deactivate.textContent = customer.isActive ? 'Deactivate' : 'Deactivated'; deactivate.disabled = !customer.isActive; deactivate.setAttribute('aria-label', `Deactivate ${customer.firstName} ${customer.lastName}`); deactivate.onclick = () => deactivateCustomer(customer, deactivate);
      actions.append(edit, deactivate); row.insertCell().append(actions); $('customers').append(row);
    }
    if (!result.data.length) { const row = document.createElement('tr'); const cell = row.insertCell(); cell.colSpan = 3; cell.className = 'empty-state'; cell.textContent = 'No customers match these filters.'; $('customers').append(row); }
    return true;
  } catch (error) {
    if (error.name === 'AbortError' || current !== pendingList) return false;
    $('customers').replaceChildren(); state.pages = 0;
    $('list-summary').textContent = 'Customers could not be loaded.'; $('page-label').textContent = 'Unavailable';
    feedback(error.message, true, error.details); return false;
  } finally { if (current === pendingList) { state.loading = false; pagination(); } }
}
function resetForm() {
  state.editing = null; $('customer-form').reset();
  $('form-mode').textContent = 'NEW ACCOUNT'; $('editor-title').textContent = 'Register a customer';
  $('editor-help').textContent = 'Create a synthetic account to try the complete workflow.';
  $('password-label').hidden = false; $('password').disabled = false; $('cancel-edit').hidden = true; $('save').textContent = 'Create customer';
}
function editCustomer(customer) {
  state.editing = customer.id;
  for (const [id, field] of [['first-name','firstName'], ['last-name','lastName'], ['email','email'], ['address','address'], ['phone','phone']]) $(id).value = customer[field] || '';
  $('password').value = ''; $('password').disabled = true; $('password-label').hidden = true;
  $('form-mode').textContent = `EDIT ACCOUNT #${customer.id}`; $('editor-title').textContent = 'Update customer profile';
  $('editor-help').textContent = 'Profile updates keep the existing password, role and activation status.';
  $('save').textContent = 'Save changes'; $('cancel-edit').hidden = false; $('first-name').focus();
}
async function deactivateCustomer(customer, button) {
  if (!window.confirm(`Deactivate ${customer.firstName} ${customer.lastName}? The account and order history will be retained.`)) return;
  button.disabled = true;
  try {
    await request(`/customers/${customer.id}`, { method: 'PATCH', body: { isActive: false } });
    if (state.editing === customer.id) resetForm();
    feedback(`Customer #${customer.id} deactivated (HTTP 200). History retained.`);
    await loadCustomers();
  } catch (error) { button.disabled = false; feedback(error.message, true, error.details); }
}
$('customer-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const body = { firstName: $('first-name').value.trim(), lastName: $('last-name').value.trim(), email: $('email').value.trim(), address: $('address').value.trim() || null, phone: $('phone').value.trim() || null };
  const errors = {};
  for (const field of ['firstName', 'lastName', 'email']) if (!body[field]) errors[field] = 'This field is required.';
  if (state.editing === null) { body.password = $('password').value; if (!body.password) errors.password = 'A password is required.'; }
  // Client catches empty fields; the API remains authoritative for detailed rules.
  if (Object.keys(errors).length) { feedback('Client-side validation failed. No request was sent.', true, errors); return; }
  const editing = state.editing; $('save').disabled = true;
  try {
    const { result, status } = await request(editing === null ? '/customers' : `/customers/${editing}`, { method: editing === null ? 'POST' : 'PUT', body });
    feedback(`Customer #${result.id} ${editing === null ? 'created' : 'updated'} successfully (HTTP ${status}).`);
    resetForm();
    if (editing === null) { $('filters').reset(); state.search = ''; state.active = ''; state.limit = 2; state.page = 1; }
    await loadCustomers(editing === null);
  } catch (error) { feedback(error.message, true, error.details); }
  finally { $('save').disabled = false; }
});
$('filters').addEventListener('submit', (event) => { event.preventDefault(); state.search = $('search').value.trim(); state.active = $('active-filter').value; state.limit = Number($('page-size').value); state.page = 1; loadCustomers(); });
$('previous').onclick = () => { if (!state.loading && state.page > 1) { state.page--; loadCustomers(); } };
$('next').onclick = () => { if (!state.loading && state.page < state.pages) { state.page++; loadCustomers(); } };
$('new-customer').onclick = () => { resetForm(); $('feedback').hidden = true; $('first-name').focus(); };
$('cancel-edit').onclick = resetForm;
$('connection-form').addEventListener('submit', (event) => { event.preventDefault(); resetForm(); $('feedback').hidden = true; state.page = 1; loadCustomers(); });
loadCustomers();
