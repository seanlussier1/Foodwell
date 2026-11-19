'use strict';
const $ = id => document.getElementById(id);
const prefix = location.pathname.includes('/client') ? location.pathname.split('/client')[0] : '';
const base = window.foodwellAuth.base();
let customer = null, page = 1, pages = 0, busy = false, favoritesPage = 1, favoritesPages = 0;
function message(text, error = false) { const el = $('feedback'); el.hidden = false; el.classList.toggle('error', error); el.textContent = text; }
async function request(path, options = {}) {
  const headers = {'Accept':'application/json', ...window.foodwellAuth.headers(base + path), ...(options.body ? {'Content-Type':'application/json'} : {})};
  const response = await fetch(base + path, {method: options.method || 'GET', headers, ...(options.body ? {body:JSON.stringify(options.body)} : {})});
  window.foodwellAuth.checkResponse(response, headers.Authorization);
  const body = response.status === 204 ? null : await response.json();
  if (!response.ok) throw new Error(`HTTP ${response.status}: ${body?.error?.message || 'Request failed.'}`);
  return body;
}
function element(tag, text) { const e = document.createElement(tag); e.textContent = text; return e; }
function button(label, action) { const b = element('button', label); b.type = 'button'; b.onclick = action; return b; }
async function loadProducts() {
  try {
    const query = new URLSearchParams({page, limit:5, search:$('search').value.trim()});
    const result = await request(`/products?${query}`); pages = result.meta.totalPages;
    const out = $('products'); out.replaceChildren();
    if (!result.data.length) out.append(element('p','No products found.'));
    for (const p of result.data) { const row = document.createElement('div'); row.className = 'slot-card'; row.append(element('p',`${p.name} · $${p.price.toFixed(2)} / ${p.unit} · ${p.stock} available`)); const add = button(`Add ${p.name} to cart`, async () => { if (!customer) { if (!window.foodwellAuth.customer()) window.foodwellAuth.navigate('signin'); else message('Choose a customer cart first.',true); return; } add.disabled=true; try { await request(`/customers/${customer}/cart/items`,{method:'POST',body:{productId:p.id,quantity:1}}); await loadCart(); message(`${p.name} added to cart.`); } catch(e) {message(e.message,true);} finally {add.disabled=false;} }); add.disabled = !p.isActive || p.stock < 1; row.append(add); out.append(row); }
    for (const [index, p] of result.data.entries()) { const save = button(`Save ${p.name} to favorites`, async () => { const account = window.foodwellAuth.customer(); if (!account) { window.foodwellAuth.navigate('signin'); return; } if (account.role !== 'customer') { message('Sign in as a customer to save favorites.', true); return; } save.disabled = true; try { await request(`/customers/${account.id}/favorites/${p.id}`, {method:'PUT'}); favoritesPage = 1; await loadFavorites(); message(`${p.name} saved to favorites.`); } catch(e) { message(e.message, true); } finally { save.disabled = false; } }); out.children[index].append(save, button(`View reviews for ${p.name}`, () => openReviews(p))); }
    $('page').textContent = pages ? `Page ${page} of ${pages}` : 'No results'; $('previous').disabled=page<=1; $('next').disabled=page>=pages;
  } catch(e) { $('products').replaceChildren(element('p','Products could not be loaded.')); message(e.message,true); }
}
async function loadFavorites() {
  const account = window.foodwellAuth.customer();
  const out = $('favorites');
  if (!account || account.role !== 'customer') { out.replaceChildren(element('p', 'Sign in as a customer to view your favorites.')); $('favorites-page').textContent = ''; $('favorites-previous').disabled = $('favorites-next').disabled = true; return; }
  const result = await request(`/customers/${account.id}/favorites?page=${favoritesPage}&limit=10`);
  if (window.foodwellAuth.customer()?.id !== account.id) return;
  favoritesPages = result.metadata.totalPages;
  if (favoritesPages && favoritesPage > favoritesPages) { favoritesPage = favoritesPages; return loadFavorites(); }
  out.replaceChildren();
  if (!result.data.length) out.append(element('p', 'No favorites saved yet.'));
  for (const favorite of result.data) { const row = document.createElement('div'); row.className = 'slot-card'; row.append(element('p', `${favorite.product.name} · $${favorite.product.price.toFixed(2)} / ${favorite.product.unit}${favorite.product.isActive ? '' : ' · unavailable'}`)); const remove = button(`Remove ${favorite.product.name} from favorites`, async () => { remove.disabled = true; try { await request(`/customers/${account.id}/favorites/${favorite.productId}`, {method:'DELETE'}); await loadFavorites(); message('Favorite removed.'); } catch(e) { message(e.message, true); } finally { remove.disabled = false; } }); row.append(remove); out.append(row); }
  $('favorites-page').textContent = favoritesPages ? `Page ${favoritesPage} of ${favoritesPages}` : 'No favorites';
  $('favorites-previous').disabled = favoritesPage <= 1; $('favorites-next').disabled = favoritesPage >= favoritesPages;
}
$('favorites-previous').onclick = () => { if (favoritesPage > 1) { favoritesPage--; loadFavorites().catch(e => message(e.message, true)); } };
$('favorites-next').onclick = () => { if (favoritesPage < favoritesPages) { favoritesPage++; loadFavorites().catch(e => message(e.message, true)); } };
async function loadCart() {
  const cart = await request(`/customers/${customer}/cart`); const out=$('cart'); out.replaceChildren();
  if (!cart.items.length) out.append(element('p','Your cart is empty.'));
  for (const item of cart.items) { const row=document.createElement('div'); row.className='slot-card'; row.append(element('p',`${item.productName} · ${item.quantity} × $${item.unitPrice.toFixed(2)} = $${item.lineTotal.toFixed(2)}`));
    const qty=document.createElement('input'); qty.type='number'; qty.min='1'; qty.max='1000000'; qty.value=item.quantity; qty.setAttribute('aria-label',`Quantity for ${item.productName}`); row.append(qty);
    row.append(button('Update',async()=>{try {await request(`/customers/${customer}/cart/items/${item.id}`,{method:'PUT',body:{quantity:Number(qty.value)}}); await loadCart(); message('Cart updated.');} catch(e){message(e.message,true);}}));
    row.append(button('Remove',async()=>{try {await request(`/customers/${customer}/cart/items/${item.id}`,{method:'DELETE'}); await loadCart(); message('Item removed.');} catch(e){message(e.message,true);}})); out.append(row); }
  out.append(element('strong',`Subtotal: $${cart.subtotal.toFixed(2)}`));
}
$('customer-form').onsubmit=async e=>{e.preventDefault(); const id=Number($('customer-id').value); if(!Number.isInteger(id)||id<1||id>4294967295) return message('Enter a valid customer ID.',true); customer=id; try {await loadCart(); message(`Cart loaded for customer ${id}.`);} catch(err){customer=null; $('cart').replaceChildren(element('p','Cart could not be loaded.')); message(err.message,true);}};
$('search-form').onsubmit=e=>{e.preventDefault(); page=1; loadProducts();}; $('previous').onclick=()=>{if(page>1){page--;loadProducts();}}; $('next').onclick=()=>{if(page<pages){page++;loadProducts();}};
// Delivery windows arrive from the API in UTC ("2026-11-22T14:00:00Z"). Show them the way
// people read them, in the store's time zone: "November 22, 9 AM – 11 AM". Minutes appear only
// when they aren't :00 ("9:30 AM"), and the date repeats only if a window crosses midnight.
const STORE_TIME_ZONE = 'America/Toronto';
function windowLabel(startsAt, endsAt) {
  const start = new Date(startsAt), end = new Date(endsAt);
  const day = d => d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', timeZone: STORE_TIME_ZONE });
  const time = d => d.toLocaleTimeString('en-US', { hour: 'numeric', ...(d.getUTCMinutes() ? { minute: '2-digit' } : {}), timeZone: STORE_TIME_ZONE });
  return day(start) === day(end)
    ? `${day(start)}, ${time(start)} – ${time(end)}`
    : `${day(start)}, ${time(start)} – ${day(end)}, ${time(end)}`;
}
$('delivery-form').onsubmit=async e=>{e.preventDefault(); const select=$('slot'); select.replaceChildren(new Option('No window selected','')); try {const data=await request(`/delivery-options?postalCode=${encodeURIComponent($('postal').value.trim())}`); $('delivery-message').textContent=`Delivery: ${data.delivery.status}. ${data.warnings.map(w=>w.message).join(' ')}`; for(const s of data.delivery.slots) select.append(new Option(`${windowLabel(s.startsAt, s.endsAt)} (${s.remainingCapacity} available)`,s.id));} catch(err){$('delivery-message').textContent=err.message;}};
$('checkout-form').onsubmit=async e=>{e.preventDefault(); if(busy)return; if(!customer){if(!window.foodwellAuth.customer()) window.foodwellAuth.navigate('signin'); else message('Choose a customer cart first.',true); return;} busy=true; $('checkout').disabled=true; $('confirmation').replaceChildren(); try {const body=checkoutAddress(); if($('slot').value) body.deliverySlotId=Number($('slot').value); const created=await request('/orders',{method:'POST',body}); let order; let warning=''; try {order=await request(`/orders/${created.id}`);} catch(err){order=created; warning=`Order #${created.id} was created, but read-back failed: ${err.message}`;} const out=$('confirmation'); out.append(element('h3',`Order #${order.id} confirmed`),element('p',`Status: ${order.status} · Total: $${order.total.toFixed(2)}`),element('p',`Deliver to: ${order.deliveryAddress}`)); if(order.deliverySlotId !== null) out.append(element('p',`Delivery window #${order.deliverySlotId}`)); for(const item of order.items) out.append(element('p',`${item.productName} · ${item.quantity} × $${item.unitPrice.toFixed(2)}`)); $('slot').replaceChildren(new Option('No window selected','')); $('delivery-message').textContent='Check delivery windows again for current availability.'; try {await loadCart(); await loadProducts();} catch(err){warning=`Order #${created.id} was created, but the cart could not be refreshed: ${err.message}`;} message(warning || `Order #${order.id} placed and read back.`,!!warning); } catch(err){message(err.message,true);} finally {busy=false; $('checkout').disabled=false;}};
loadProducts();

window.addEventListener('foodwell-auth-change', () => { customer = null; favoritesPage = 1; loadFavorites().catch(e => message(e.message, true)); const account = window.foodwellAuth.customer(); $('customer-form').hidden = account?.role !== 'admin'; if (account?.role === 'customer') { $('customer-id').value = account.id; customer = account.id; loadCart().catch(e => message(e.message,true)); } else { $('cart').replaceChildren(element('p', account ? 'Choose a customer cart.' : 'Sign in to view your cart.')); } });
const activeAccount = window.foodwellAuth.customer();
$('customer-form').hidden = activeAccount?.role !== 'admin';
if (activeAccount?.role === 'customer') { customer = activeAccount.id; $('customer-id').value = customer; loadCart().catch(error => message(error.message, true)); }
else { $('cart').replaceChildren(element('p', activeAccount ? 'Choose a customer cart.' : 'Sign in to view your cart.')); }
loadFavorites().catch(error => message(error.message, true));

let addressEditId = null;
let addressLoad = 0;
let reviewProduct = null;
let reviewEditId = null;
let reviewPage = 1;
let reviewLoad = 0;

function sectionMessage(id, text = '', error = false) {
  const feedback = $(id);
  feedback.hidden = !text;
  feedback.classList.toggle('error', error);
  feedback.textContent = text;
}

function customerAccount() {
  const account = window.foodwellAuth.customer();
  return account?.role === 'customer' ? account : null;
}

function updateAddressMode() {
  const manual = !$('saved-address').value;
  $('manual-address').hidden = !manual;
  $('address').required = manual;
}

function checkoutAddress() {
  const savedId = $('saved-address').value;
  return savedId && customerAccount()?.id === customer
    ? {customerId: customer, addressId: Number(savedId)}
    : {customerId: customer, deliveryAddress: $('address').value.trim()};
}

function editAddress(address = null) {
  const form = $('address-form');
  form.reset();
  addressEditId = address?.id ?? null;
  $('address-editor-title').textContent = address ? 'Edit saved address' : 'Add an address';
  $('address-editor').open = Boolean(address);
  if (!address) return;
  for (const input of form.querySelectorAll('input[name]')) input.value = address[input.name] ?? '';
  form.elements.label.focus();
}

async function loadAddresses() {
  const account = customerAccount();
  const version = ++addressLoad;
  if (!account) return;
  const path = `/customers/${account.id}/addresses`;
  const addresses = [];
  for (let page = 1; ; page++) {
    const {data} = await request(`${path}?page=${page}&limit=100`);
    if (version !== addressLoad || customerAccount()?.id !== account.id) return;
    addresses.push(...data);
    if (data.length < 100) break;
  }
  const select = $('saved-address');
  const selected = select.value;
  select.replaceChildren(new Option('Enter an address manually', ''));
  const list = $('addresses');
  list.replaceChildren();
  if (!addresses.length) list.append(element('p', 'No saved addresses yet.'));
  for (const address of addresses) {
    select.add(new Option(`${address.label} · ${address.addressLine1}`, String(address.id)));
    const row = element('div', '');
    row.className = 'slot-card';
    row.append(
      element('strong', address.label),
      element('p', [address.recipientName, address.addressLine1, address.addressLine2,
        address.city, address.province, address.postalCode].filter(Boolean).join(', '))
    );
    const actions = element('div', '');
    actions.className = 'shop-actions';
    actions.append(button(`Edit ${address.label}`, () => editAddress(address)));
    const remove = button(`Delete ${address.label}`, async () => {
      if (customerAccount()?.id !== account.id || !window.confirm(`Delete the saved address "${address.label}"?`)) return;
      remove.disabled = true;
      try {
        await request(`${path}/${address.id}`, {method: 'DELETE'});
        if (customerAccount()?.id !== account.id) return;
        if (addressEditId === address.id) editAddress();
        await loadAddresses();
        sectionMessage('address-feedback', 'Address deleted.');
      } catch (error) {
        sectionMessage('address-feedback', error.message, true);
      } finally {
        remove.disabled = false;
      }
    });
    remove.className = 'secondary';
    actions.append(remove);
    row.append(actions);
    list.append(row);
  }
  select.value = addresses.some(address => String(address.id) === selected) ? selected : '';
  $('saved-address-choice').hidden = addresses.length === 0;
  updateAddressMode();
}

$('saved-address').onchange = updateAddressMode;
$('address-cancel').onclick = () => editAddress();
$('address-form').onsubmit = async event => {
  event.preventDefault();
  const account = customerAccount();
  if (!account) return;
  const form = event.currentTarget;
  const submit = form.querySelector('[type=submit]');
  if (submit.disabled) return;
  const id = addressEditId;
  const body = Object.fromEntries([...new FormData(form)].map(([key, value]) => [key, value.trim()]));
  body.addressLine2 ||= null;
  body.phone ||= null;
  const path = `/customers/${account.id}/addresses`;
  submit.disabled = true;
  try {
    const saved = await request(id ? `${path}/${id}` : path, {method: id ? 'PATCH' : 'POST', body});
    if (customerAccount()?.id !== account.id) return;
    editAddress();
    await loadAddresses();
    if (customerAccount()?.id !== account.id) return;
    $('saved-address').value = String(saved.id);
    updateAddressMode();
    sectionMessage('address-feedback', id ? 'Address updated.' : 'Address saved and selected for checkout.');
  } catch (error) {
    sectionMessage('address-feedback', error.message, true);
  } finally {
    submit.disabled = false;
  }
};

function editReview(review = null) {
  $('review-form').reset();
  reviewEditId = review?.id ?? null;
  $('review-form-title').textContent = review ? 'Edit your review' : 'Write a review';
  $('review-cancel').hidden = !review;
  if (!review) return;
  $('review-rating').value = String(review.rating);
  $('review-comment').value = review.comment;
  $('review-rating').focus();
}

function loadReviewPage() {
  const pending = loadReviews();
  const version = reviewLoad;
  return pending.catch(error => {
    if (version !== reviewLoad) return;
    $('reviews').replaceChildren();
    $('reviews-previous').disabled = reviewPage === 1;
    sectionMessage('review-feedback', error.message, true);
  });
}

function openReviews(product) {
  reviewProduct = product;
  reviewPage = 1;
  editReview();
  $('review-panel').hidden = false;
  $('review-title').textContent = `Reviews for ${product.name}`;
  $('review-title').focus();
  loadReviewPage();
}

async function loadReviews() {
  if (!reviewProduct) return;
  const product = reviewProduct;
  const version = ++reviewLoad;
  const account = window.foodwellAuth.customer();
  $('review-form').hidden = account?.role !== 'customer';
  $('review-signin').hidden = Boolean(account);
  $('reviews').replaceChildren(element('p', 'Loading reviews…'));
  $('reviews-previous').disabled = $('reviews-next').disabled = true;
  sectionMessage('review-feedback');
  const {data} = await request(`/products/${product.id}/reviews?page=${reviewPage}&limit=10`);
  if (version !== reviewLoad) return;
  const list = $('reviews');
  list.replaceChildren();
  if (!data.length) list.append(element('p', reviewPage === 1 ? 'No reviews yet.' : 'No reviews on this page.'));
  for (const review of data) {
    const mine = account?.role === 'customer' && account.id === review.customerId;
    const row = element('div', '');
    row.className = 'slot-card';
    row.append(element('strong', `${review.rating} / 5 · ${mine ? 'Your review' : 'Customer review'}`));
    if (review.comment) {
      const comment = element('p', review.comment);
      comment.className = 'review-comment';
      row.append(comment);
    }
    const actions = element('div', '');
    actions.className = 'shop-actions';
    if (mine) actions.append(button('Edit your review', () => editReview(review)));
    if (mine || account?.role === 'admin') {
      const remove = button('Delete review', async () => {
        if (!window.confirm('Delete this review?')) return;
        remove.disabled = true;
        try {
          await request(`/reviews/${review.id}`, {method: 'DELETE'});
          if (product !== reviewProduct) return;
          if (reviewEditId === review.id) editReview();
          if (data.length === 1 && reviewPage > 1) reviewPage--;
          await loadReviews();
          sectionMessage('review-feedback', 'Review deleted.');
        } catch (error) {
          if (product === reviewProduct) sectionMessage('review-feedback', error.message, true);
        } finally {
          remove.disabled = false;
        }
      });
      remove.className = 'secondary';
      actions.append(remove);
    }
    if (actions.childElementCount) row.append(actions);
    list.append(row);
  }
  $('reviews-page').textContent = `Page ${reviewPage}`;
  $('reviews-previous').disabled = reviewPage === 1;
  $('reviews-next').disabled = data.length < 10;
}

$('review-cancel').onclick = () => editReview();
$('reviews-previous').onclick = () => { reviewPage--; loadReviewPage(); };
$('reviews-next').onclick = () => { reviewPage++; loadReviewPage(); };
$('review-form').onsubmit = async event => {
  event.preventDefault();
  if (!reviewProduct || !customerAccount()) return;
  const product = reviewProduct;
  const id = reviewEditId;
  const submit = event.currentTarget.querySelector('[type=submit]');
  if (submit.disabled) return;
  const body = {rating: Number($('review-rating').value), comment: $('review-comment').value};
  submit.disabled = true;
  try {
    await request(id ? `/reviews/${id}` : `/products/${product.id}/reviews`, {method: id ? 'PATCH' : 'POST', body});
    if (product !== reviewProduct) return;
    editReview();
    if (!id) reviewPage = 1;
    await loadReviews();
    sectionMessage('review-feedback', id ? 'Review updated.' : 'Review published.');
  } catch (error) {
    if (product === reviewProduct) sectionMessage('review-feedback', error.message, true);
  } finally {
    submit.disabled = false;
  }
};

function refreshShopFeatures() {
  addressLoad++;
  $('addresses').replaceChildren();
  $('saved-address').replaceChildren(new Option('Enter an address manually', ''));
  $('saved-address-choice').hidden = true;
  $('address-panel').hidden = !customerAccount();
  sectionMessage('address-feedback');
  editAddress();
  updateAddressMode();
  loadAddresses().catch(error => sectionMessage('address-feedback', error.message, true));
  editReview();
  loadReviewPage();
}

window.addEventListener('foodwell-auth-change', refreshShopFeatures);
refreshShopFeatures();
