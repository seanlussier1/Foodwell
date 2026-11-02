'use strict';
const deliveryElement = (id) => document.getElementById(id);
const deliveryPrefix = location.pathname.includes('/client') ? location.pathname.split('/client')[0] : '';
deliveryElement('delivery-api').value = location.port === '8081' ? 'http://localhost/foodwell-api/api/v1' : `${location.origin}${deliveryPrefix}/api/v1`;
let lookupSequence = 0;
let lookupAbort;
function deliveryFeedback(message, error = false) {
  const feedback = deliveryElement('delivery-feedback'); feedback.hidden = false;
  feedback.classList.toggle('error', error); feedback.textContent = message;
}
function appendDeliveryText(parent, tag, text, className = '') {
  const element = document.createElement(tag); element.textContent = text; element.className = className; parent.append(element); return element;
}
async function checkDelivery(event) {
  event.preventDefault();
  const code = deliveryElement('postal-code').value.trim().toUpperCase();
  const current = ++lookupSequence; lookupAbort?.abort();
  deliveryElement('check-delivery').disabled = false;
  deliveryElement('delivery-result').replaceChildren();
  if (!/^[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z] ?\d[ABCEGHJ-NPRSTV-Z]\d$/.test(code)) {
    deliveryFeedback('Enter a Canadian postal code such as H3N 1X1. No request was sent.', true); return;
  }
  lookupAbort = new AbortController(); deliveryElement('check-delivery').disabled = true;
  deliveryFeedback('Checking the postal area and Foodwell availability…');
  try {
    const url = new URL(deliveryElement('delivery-api').value.trim());
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) throw new Error('Use a valid HTTP API base URL without credentials, query or fragment.');
    const response = await fetch(`${url.href.replace(/\/$/, '')}/delivery-options?postalCode=${encodeURIComponent(code)}`, { headers: { Accept: 'application/json' }, signal: lookupAbort.signal });
    const result = await response.json();
    if (current !== lookupSequence) return;
    if (!response.ok) throw new Error(`HTTP ${response.status}: ${result.error?.message || 'The request failed.'}`);
    const output = deliveryElement('delivery-result');
    const headings = { available: 'Delivery windows available', 'no-slots': 'Area served, no windows available', 'outside-area': 'Outside our configured delivery area', unknown: 'Availability could not be confirmed' };
    appendDeliveryText(output, 'h3', headings[result.delivery.status] || 'Delivery status unavailable');
    appendDeliveryText(output, 'p', `Postal area: ${result.area.prefix}`);
    for (const place of result.area.places) appendDeliveryText(output, 'p', `${place.name}, ${place.province} (${place.provinceCode})`);
    const sources = { live: 'Live provider lookup', cache: 'Cached provider lookup', demo: 'Controlled demonstration — not live data', unavailable: 'Provider information unavailable' };
    appendDeliveryText(output, 'p', sources[result.area.source] || 'Provider information unavailable', 'muted');
    for (const slot of result.delivery.slots) {
      const card = document.createElement('div'); card.className = 'slot-card';
      const format = new Intl.DateTimeFormat('en-CA', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Toronto' });
      appendDeliveryText(card, 'strong', `Slot #${slot.id}: ${format.format(new Date(slot.startsAt))}`);
      appendDeliveryText(card, 'p', `Ends ${format.format(new Date(slot.endsAt))} · ${slot.remainingCapacity} spaces currently available`, 'muted');
      output.append(card);
    }
    appendDeliveryText(output, 'p', 'Times shown in America/Toronto. Slots come from Foodwell; postal-area details come from Zippopotam.us / GeoNames.', 'muted');
    const warnings = result.warnings.map((warning) => warning.message).join(' ');
    deliveryFeedback(warnings || 'Lookup complete. No booking has been made.', result.status === 'partial' || result.area.source === 'demo');
  } catch (error) {
    if (error.name !== 'AbortError' && current === lookupSequence) deliveryFeedback(error.message.startsWith('HTTP') ? error.message : 'Unable to load delivery options. Check the API connection and try again.', true);
  } finally { if (current === lookupSequence) deliveryElement('check-delivery').disabled = false; }
}
deliveryElement('postal-form').addEventListener('submit', checkDelivery);
deliveryElement('delivery-connection').addEventListener('submit', (event) => { event.preventDefault(); lookupSequence++; lookupAbort?.abort(); deliveryElement('check-delivery').disabled = false; deliveryFeedback('Connection updated. Check your postal code to load options.'); deliveryElement('delivery-result').replaceChildren(); });
