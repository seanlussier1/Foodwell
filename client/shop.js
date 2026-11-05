'use strict';
const $ = id => document.getElementById(id);
const prefix = location.pathname.includes('/client') ? location.pathname.split('/client')[0] : '';
const base = location.port === '8081' ? 'http://localhost/foodwell-api/api/v1' : `${location.origin}${prefix}/api/v1`;
let customer = null, page = 1, pages = 0, busy = false;
function message(text, error = false) { const el = $('feedback'); el.hidden = false; el.classList.toggle('error', error); el.textContent = text; }
async function request(path, options = {}) {
  const response = await fetch(base + path, {method: options.method || 'GET', headers: {'Accept':'application/json', ...(options.body ? {'Content-Type':'application/json'} : {})}, ...(options.body ? {body:JSON.stringify(options.body)} : {})});
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
    for (const p of result.data) { const row = document.createElement('div'); row.className = 'slot-card'; row.append(element('p',`${p.name} · $${p.price.toFixed(2)} / ${p.unit} · ${p.stock} available`)); const add = button(`Add ${p.name} to cart`, async () => { if (!customer) return message('Load a customer cart first.',true); add.disabled=true; try { await request(`/customers/${customer}/cart/items`,{method:'POST',body:{productId:p.id,quantity:1}}); await loadCart(); message(`${p.name} added to cart.`); } catch(e) {message(e.message,true);} finally {add.disabled=false;} }); add.disabled = !p.isActive || p.stock < 1; row.append(add); out.append(row); }
    $('page').textContent = pages ? `Page ${page} of ${pages}` : 'No results'; $('previous').disabled=page<=1; $('next').disabled=page>=pages;
  } catch(e) { $('products').replaceChildren(element('p','Products could not be loaded.')); message(e.message,true); }
}
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
$('delivery-form').onsubmit=async e=>{e.preventDefault(); const select=$('slot'); select.replaceChildren(new Option('No window selected','')); try {const data=await request(`/delivery-options?postalCode=${encodeURIComponent($('postal').value.trim())}`); $('delivery-message').textContent=`Delivery: ${data.delivery.status}. ${data.warnings.map(w=>w.message).join(' ')}`; for(const s of data.delivery.slots) select.append(new Option(`${s.startsAt} – ${s.endsAt} (${s.remainingCapacity} available)`,s.id));} catch(err){$('delivery-message').textContent=err.message;}};
$('checkout-form').onsubmit=async e=>{e.preventDefault(); if(busy)return; if(!customer)return message('Load a customer cart first.',true); busy=true; $('checkout').disabled=true; $('confirmation').replaceChildren(); try {const body={customerId:customer,deliveryAddress:$('address').value.trim()}; if($('slot').value) body.deliverySlotId=Number($('slot').value); const created=await request('/orders',{method:'POST',body}); let order; let warning=''; try {order=await request(`/orders/${created.id}`);} catch(err){order=created; warning=`Order #${created.id} was created, but read-back failed: ${err.message}`;} const out=$('confirmation'); out.append(element('h3',`Order #${order.id} confirmed`),element('p',`Status: ${order.status} · Total: $${order.total.toFixed(2)}`),element('p',`Deliver to: ${order.deliveryAddress}`)); if(order.deliverySlotId !== null) out.append(element('p',`Delivery window #${order.deliverySlotId}`)); for(const item of order.items) out.append(element('p',`${item.productName} · ${item.quantity} × $${item.unitPrice.toFixed(2)}`)); $('slot').replaceChildren(new Option('No window selected','')); $('delivery-message').textContent='Check delivery windows again for current availability.'; try {await loadCart(); await loadProducts();} catch(err){warning=`Order #${created.id} was created, but the cart could not be refreshed: ${err.message}`;} message(warning || `Order #${order.id} placed and read back.`,!!warning); } catch(err){message(err.message,true);} finally {busy=false; $('checkout').disabled=false;}};
loadProducts();
