"use strict";
// Products section of the Lab 7 dashboard (#30). Same structure as Sean's app.js:
// one request() helper, a list loader, a form handler and per-row actions.

const $ = (id) => document.getElementById(id);

// Current list query. Next/Previous follow the API's own links, which keep these filters.
const state = {
  search: "",
  category: "",
  sort: "",
  limit: 5,
  page: 1,
  editing: null,
  loading: false,
};
let lastMeta = null; // meta from the last list response (holds the next/previous links)
let listRequest = 0; // counts list loads so a slow old response can't overwrite a newer one
// ---------------------------------------------------------------- connection

// Same default as Sean's dashboard: Wamp when served from port 8081, otherwise same origin.
const apachePrefix = location.pathname.includes("/client")
  ? location.pathname.split("/client")[0]
  : "";
$("api-url").value = window.foodwellAuth.base();

function base() {
  const url = new URL($("api-url").value.trim());
  if (
    !["http:", "https:"].includes(url.protocol) ||
    url.username ||
    url.password ||
    url.search ||
    url.hash
  ) {
    throw new Error(
      "Use an HTTP API base URL without credentials, query or fragment.",
    );
  }
  return url.href.replace(/\/$/, "");
}
/**
 * Sends one request and returns { status, body }.
 * Throws an Error with .status, .details (field => message) and .line on failure.
 * `target` is a path like "/products?page=2" or an absolute URL from meta.next.
 */
async function request(target, { method = "GET", body } = {}) {
  const url = target.startsWith("http") ? target : `${base()}${target}`;
  const line = `${method} ${url}`;
  const headers = { Accept: "application/json", ...window.foodwellAuth.headers(url) };
  if (body !== undefined) headers["Content-Type"] = "application/json";

  let response;
  try {
    response = await fetch(url, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    const error = new Error(
      "Cannot reach the API. Check the API URL, that the server is running, and CORS settings.",
    );
    error.line = `${line} → no response`;
    throw error;
  }
  window.foodwellAuth.checkResponse(response, headers.Authorization);
  let result = null;
  if (response.status !== 204) {
    try {
      result = await response.json();
    } catch {
      const error = new Error(
        `HTTP ${response.status}: the API did not return JSON. Check the API URL.`,
      );
      error.line = `${line} → ${response.status}`;
      throw error;
    }
  }
  if (!response.ok) {
    const error = new Error(
      result?.error?.message || `Request failed (HTTP ${response.status}).`,
    );
    error.status = response.status;
    error.details = result?.error?.details || {};
    error.line = `${line} → ${response.status}`;
    throw error;
  }
  return {
    status: response.status,
    body: result,
    line: `${line} → ${response.status}`,
  };
}
// ------------------------------------------------------------------ feedback

function feedback(title, { error = false, details = {}, line = "" } = {}) {
  $("feedback").hidden = false;
  $("feedback").classList.toggle("error", error);
  $("feedback-title").textContent = title;
  $("feedback-details").replaceChildren();
  for (const [field, message] of Object.entries(details)) {
    const li = document.createElement("li");
    li.textContent = `${field}: ${message}`; // textContent: user data is never parsed as HTML
    $("feedback-details").append(li);
  }
  $("feedback-request").textContent = line;
}
/** Shows each message under its input (client checks and the server's 422 use the same field names). */
function fieldErrors(errors) {
  for (const small of document.querySelectorAll("#product-form .field-error")) {
    const message = errors[small.dataset.field] || "";
    small.textContent = message;
    small
      .closest("label")
      .querySelector("input,select")
      .setAttribute("aria-invalid", message ? "true" : "false");
  }
}

// ---------------------------------------------------------------- categories

/** Fills both category dropdowns. Uses GET /categories; falls back to the categories seen in products. */
async function loadCategories() {
  let categories = [];
  try {
    const { body } = await request("/categories");
    categories = Array.isArray(body) ? body : body.data || [];
  } catch {
    try {
      const { body } = await request("/products?limit=100");
      const seen = new Map(
        body.data.map((p) => [p.categoryId, p.categoryName]),
      );
      categories = [...seen].map(([id, name]) => ({ id, name }));
    } catch {
      /* the list load will report the connection problem */
    }
  }
  categories.sort((a, b) => a.name.localeCompare(b.name));
  for (const select of [$("category-filter"), $("category")]) {
    const keep = select.value;
    select.replaceChildren(select.options[0]); // keep "All categories" / "Choose…"
    for (const c of categories) select.append(new Option(c.name, String(c.id)));
    select.value = keep;
  }
}

// ------------------------------------------------------- (a) list + paging

function listPath() {
  const query = new URLSearchParams({ page: state.page, limit: state.limit });
  if (state.search) query.set("search", state.search);
  if (state.category) query.set("category", state.category);
  if (state.sort) query.set("sort", state.sort);
  return `/products?${query}`;
}

/** Turns a link from meta ("/foodwell-api/api/v1/products?page=2...") into a full URL on the API's server. */
function apiLink(path) {
  return new URL(path, base()).href;
}

async function loadProducts(target = listPath()) {
  const current = ++listRequest;
  state.loading = true;
  updatePager();
  $("list-summary").textContent = "Loading products…";

  try {
    const { body } = await request(target);
    if (current !== listRequest) return; // a newer load started meanwhile
    lastMeta = body.meta;
    state.page = body.meta.page;

    // Past the last page (e.g. after deleting its only product): follow "previous" back.
    if (body.data.length === 0 && body.meta.previous && body.meta.total > 0) {
      return loadProducts(apiLink(body.meta.previous));
    }
    renderRows(body.data);
    const { total, totalPages, page } = body.meta;
    $("list-summary").textContent =
      `${total} matching product${total === 1 ? "" : "s"} · ${body.data.length} shown`;
    $("page-label").textContent = totalPages
      ? `Page ${page} of ${totalPages}`
      : "No results";
  } catch (error) {
    if (current !== listRequest) return;
    lastMeta = null;
    $("products").replaceChildren();
    $("list-summary").textContent = "Products could not be loaded.";
    $("page-label").textContent = "Unavailable";
    feedback(error.message, {
      error: true,
      details: error.details,
      line: error.line,
    });
  } finally {
    if (current === listRequest) {
      state.loading = false;
      updatePager();
    }
  }
}

function updatePager() {
  $("previous").disabled = state.loading || !lastMeta?.previous;
  $("next").disabled = state.loading || !lastMeta?.next;
}

function renderRows(products) {
  const tbody = $("products");
  tbody.replaceChildren();
  if (products.length === 0) {
    const cell = tbody.insertRow().insertCell();
    cell.colSpan = 5;
    cell.className = "empty-state";
    cell.textContent = "No products match these filters.";
    return;
  }
  for (const p of products) {
    const row = tbody.insertRow();
    if (!p.isActive) row.className = "inactive-row";

    const nameCell = row.insertCell();
    const name = document.createElement("span");
    name.className = "customer-name";
    name.textContent = p.name;
    const meta = document.createElement("span");
    meta.className = "customer-email";
    meta.textContent = `#${p.id} · ${p.categoryName} · per ${p.unit}`;
    nameCell.append(name, meta);

    row.insertCell().textContent = `$${p.price.toFixed(2)}`;
    row.insertCell().textContent = String(p.stock);

    const pill = document.createElement("span");
    pill.className = `status-pill${p.isActive ? "" : " inactive"}`;
    pill.textContent = p.isActive ? "Active" : "Inactive";
    row.insertCell().append(pill);

    // (c) per-row actions
    const actions = document.createElement("div");
    actions.className = "row-actions";
    actions.append(
      rowButton("Edit", "secondary", `Edit ${p.name}`, () => startEdit(p)),
      rowButton("Stock", "secondary", `Adjust stock for ${p.name}`, () =>
        openStock(p),
      ),
      rowButton(
        p.isActive ? "Deactivate" : "Activate",
        "deactivate",
        `${p.isActive ? "Deactivate" : "Activate"} ${p.name}`,
        (b) => setActive(p, !p.isActive, b),
      ),
      rowButton("Delete", "danger", `Delete ${p.name}`, (b) =>
        deleteProduct(p, b),
      ),
    );
    row.insertCell().append(actions);
  }
}

function rowButton(text, className, label, onClick) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = className;
  button.textContent = text;
  button.setAttribute("aria-label", label);
  button.onclick = () => onClick(button);
  return button;
}

// -------------------------------------------------- (b) create / edit form

/** Same rules and field names as src/Validation/ProductInput.php. */
function validate(v, creating) {
  const errors = {};
  if (v.name === "") errors.name = "Name is required.";
  else if (v.name.length > 150) errors.name = "Use at most 150 characters.";

  if (v.price === "") errors.price = "Price is required.";
  else if (!/^\d+(\.\d{1,2})?$/.test(v.price))
    errors.price =
      "Use a number that is not negative, with at most 2 decimals.";
  else if (Number(v.price) > 999999.99)
    errors.price = "Price must be at most 999999.99.";

  if (v.unit.length > 30) errors.unit = "Use at most 30 characters.";

  if (
    creating &&
    v.stock !== "" &&
    (!/^\d+$/.test(v.stock) || Number(v.stock) > 1000000)
  ) {
    errors.stock = "Use a whole number from 0 to 1000000.";
  }
  if (v.categoryId === "") errors.categoryId = "Choose a category.";

  if (v.imageUrl !== "") {
    let ok = false;
    try {
      ok =
        ["http:", "https:"].includes(new URL(v.imageUrl).protocol) &&
        v.imageUrl.length <= 255;
    } catch {
      /* not a URL */
    }
    if (!ok) errors.imageUrl = "Use an http(s) URL of at most 255 characters.";
  }
  return errors;
}

function readForm() {
  return {
    name: $("name").value.trim(),
    price: $("price").value.trim(),
    unit: $("unit").value.trim(),
    stock: $("stock").value.trim(),
    categoryId: $("category").value,
    imageUrl: $("image-url").value.trim(),
  };
}

/** Builds the JSON body. Values are sent as typed (numbers only when they look like numbers),
 *  so "Skip browser checks" really lets the server see the bad data. */
function toBody(v, creating, isActive) {
  const number = (text) =>
    text !== "" && !Number.isNaN(Number(text)) ? Number(text) : text;
  const body = {
    name: v.name,
    price: number(v.price),
    categoryId: number(v.categoryId),
  };
  if (v.unit !== "") body.unit = v.unit;
  body.imageUrl = v.imageUrl === "" ? null : v.imageUrl;
  if (creating) {
    if (v.stock !== "") body.stock = number(v.stock);
  } else body.isActive = isActive; // keep the product's current status
  return body;
}

function resetForm() {
  state.editing = null;
  $("product-form").reset();
  fieldErrors({});
  $("form-mode").textContent = "NEW PRODUCT";
  $("editor-title").textContent = "Add a product";
  $("editor-help").textContent = "Stock entered here is the opening balance.";
  $("stock").disabled = false;
  $("stock-label").querySelector(".optional").hidden = false;
  $("save").textContent = "Create product";
  $("cancel-edit").hidden = true;
}

function startEdit(p) {
  state.editing = p;
  fieldErrors({});
  $("name").value = p.name;
  $("price").value = p.price.toFixed(2);
  $("unit").value = p.unit;
  $("stock").value = String(p.stock);
  $("stock").disabled = true; // PUT cannot change stock (inventory adjustments only)
  $("stock-label").querySelector(".optional").hidden = true;
  $("category").value = String(p.categoryId);
  $("image-url").value = p.imageUrl || "";
  $("form-mode").textContent = `EDIT PRODUCT #${p.id}`;
  $("editor-title").textContent = "Update product";
  $("editor-help").textContent =
    "Stock is read-only here: use the Stock button in the list to record an inventory adjustment.";
  $("save").textContent = "Save changes";
  $("cancel-edit").hidden = false;
  $("name").focus();
}

$("product-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const editing = state.editing;
  const values = readForm();

  // 1. Browser checks. If they fail, no request is sent.
  if (!$("skip-checks").checked) {
    const errors = validate(values, editing === null);
    fieldErrors(errors);
    if (Object.keys(errors).length) {
      feedback("Please fix the highlighted fields. No request was sent.", {
        error: true,
        details: errors,
      });
      return;
    }
  } else {
    fieldErrors({});
  }
  // 2. Send: POST to create, PUT to update.
  const body = toBody(values, editing === null, editing?.isActive);
  $("save").disabled = true;
  try {
    const {
      body: saved,
      status,
      line,
    } = editing === null
      ? await request("/products", { method: "POST", body })
      : await request(`/products/${editing.id}`, { method: "PUT", body });
    resetForm();
    feedback(
      `Product #${saved.id} "${saved.name}" ${editing === null ? "created" : "updated"} (HTTP ${status}).`,
      { line },
    );
    await loadCategories();
    await loadProducts(); // same filters and page
  } catch (error) {
    // 3. The server's 422: show each message under its field, and in the message box.
    fieldErrors(error.details || {});
    const title =
      error.status === 422
        ? `The server rejected the product: ${error.message}`
        : error.message;
    feedback(title, { error: true, details: error.details, line: error.line });
  } finally {
    $("save").disabled = false;
  }
});

// ------------------------------------------------------- (c) state changes

/** Deactivate/activate with PUT: the full product is sent back with only isActive changed. */
async function setActive(p, isActive, button) {
  button.disabled = true;
  const body = {
    name: p.name,
    price: p.price,
    unit: p.unit,
    categoryId: p.categoryId,
    imageUrl: p.imageUrl,
    isActive,
  };
  try {
    const { line } = await request(`/products/${p.id}`, {
      method: "PUT",
      body,
    });
    if (state.editing?.id === p.id) resetForm();
    feedback(`Product #${p.id} ${isActive ? "activated" : "deactivated"}.`, {
      line,
    });
    await loadProducts();
  } catch (error) {
    button.disabled = false;
    feedback(error.message, {
      error: true,
      details: error.details,
      line: error.line,
    });
  }
}

async function deleteProduct(p, button) {
  if (
    !window.confirm(
      `Delete product #${p.id} "${p.name}"? This cannot be undone.`,
    )
  )
    return;
  button.disabled = true;
  try {
    const { line } = await request(`/products/${p.id}`, { method: "DELETE" });
    if (state.editing?.id === p.id) resetForm();
    if (stock.product?.id === p.id) closeStock();
    feedback(
      `Product #${p.id} deleted (HTTP 204). Past orders keep their copy of the product name.`,
      { line },
    );
    await loadProducts();
  } catch (error) {
    button.disabled = false;
    // 409: inventory history. The server's message already says to deactivate instead.
    const title =
      error.status === 409 ? `Cannot delete: ${error.message}` : error.message;
    feedback(title, { error: true, details: error.details, line: error.line });
  }
}
// ------------------------------------------------ (d) inventory adjustments
// The only way to change stock after a product is created (PUT refuses "stock").
// POST /products/{id}/inventory-adjustments records one change; the server locks
// the product, calculates the new balance and saves an audit row we list below.

const STOCK_MAX = 1000000; // same ceiling as InventoryAdjustmentInput::MAX_STOCK
const HISTORY_LIMIT = 5; // rows per history page
const stock = { product: null, page: 1, totalPages: 0, loading: false };
let historyRequest = 0; // like listRequest: a slow old history load can't overwrite a newer one

/** Opens the panel for one product. Reads the product again so the stock is up to date. */
async function openStock(p) {
  stock.product = p;
  stock.page = 1;
  $("stock-form").reset();
  stockFieldErrors({});
  $("stock-title").textContent = p.name;
  $("stock-mode").textContent = `ADJUST STOCK · PRODUCT #${p.id}`;
  showCurrentStock(p.stock);
  updateQuantityLabel();
  $("stock-panel").hidden = false;
  $("stock-panel").scrollIntoView({ behavior: "smooth", block: "start" });
  $("adj-quantity").focus({ preventScroll: true });
  await refreshProductStock(); // someone else may have changed it since the list loaded
  await loadHistory();
}

function closeStock() {
  stock.product = null;
  $("stock-panel").hidden = true;
}

function showCurrentStock(value) {
  stock.product.stock = value;
  $("stock-current").textContent = String(value);
  updatePreview();
}

/** GET /products/{id}: the panel always works from the server's current stock. */
async function refreshProductStock() {
  const id = stock.product?.id;
  try {
    const { body } = await request(`/products/${id}`);
    if (stock.product?.id === id) showCurrentStock(body.stock);
  } catch {
    /* keep the list value; the history load reports connection problems */
  }
}

/** The label tells the user what to type: an amount for restock/damage, a signed number for corrections. */
function updateQuantityLabel() {
  const labels = {
    restock: ["Quantity to add", "12"],
    damage: ["Quantity to remove", "3"],
    correction: ["Change (+ or −)", "-2"],
  };
  const [text, placeholder] = labels[$("adj-reason").value] || labels.correction;
  $("adj-quantity-label").textContent = text;
  $("adj-quantity").placeholder = placeholder;
  updatePreview();
}

/**
 * Turns what was typed into the quantityChange the API expects.
 *   restock "12" -> 12      damage "3" -> -3      correction "-2" -> -2
 * A sign typed on purpose is kept (damage "+3" -> 3), so with "Skip browser
 * checks" the server's own 422 can be shown. Text that is not a whole number
 * is returned unchanged (only reachable with the checks skipped).
 */
function quantityChange(reason, text) {
  if (!/^[+-]?\d+$/.test(text)) return text;
  const n = Number(text);
  const signed = /^[+-]/.test(text);
  return reason === "damage" && !signed ? -n : n;
}

/** Same rules as src/Validation/InventoryAdjustmentInput.php, plus the 0..1000000 stock range (409 on the server). */
function validateAdjustment(v, currentStock) {
  const errors = {};
  if (!["restock", "damage", "correction"].includes(v.reason))
    errors.reason = "Choose restock, damage or correction.";

  if (v.quantity === "") errors.quantityChange = "Enter a quantity.";
  else if (!/^[+-]?\d+$/.test(v.quantity))
    errors.quantityChange = "Use a whole number.";
  else {
    const change = quantityChange(v.reason, v.quantity);
    if (change === 0)
      errors.quantityChange = "0 changes nothing. Enter a number other than 0.";
    else if (v.reason === "restock" && change < 0)
      errors.quantityChange = "A restock adds stock, so use a positive number.";
    else if (v.reason === "damage" && change > 0)
      errors.quantityChange = "Damage removes stock. Type how many to remove.";
    else if (Math.abs(change) > STOCK_MAX)
      errors.quantityChange = `Use a number from -${STOCK_MAX} to ${STOCK_MAX}.`;
    else if (currentStock + change < 0)
      errors.quantityChange = `Only ${currentStock} in stock; stock cannot go below 0.`;
    else if (currentStock + change > STOCK_MAX)
      errors.quantityChange = `Stock cannot go over ${STOCK_MAX}.`;
  }
  if (v.note.length > 500) errors.note = "Use at most 500 characters.";
  return errors;
}

function readAdjustment() {
  return {
    reason: $("adj-reason").value,
    quantity: $("adj-quantity").value.trim(),
    note: $("adj-note").value.trim(),
  };
}

/** Live "40 → 52" preview under the form, so staff see the result before saving. */
function updatePreview() {
  if (!stock.product) return;
  const v = readAdjustment();
  const preview = $("adj-preview");
  const change = quantityChange(v.reason, v.quantity);
  if (typeof change !== "number" || change === 0) {
    preview.textContent = "";
    return;
  }
  const after = stock.product.stock + change;
  const sign = change > 0 ? "+" : "−";
  preview.textContent = `Stock ${stock.product.stock} ${sign} ${Math.abs(change)} → ${after}`;
  preview.classList.toggle("invalid", after < 0 || after > STOCK_MAX);
}

/** Shows each message under its field (browser checks and the server's 422 use the same names). */
function stockFieldErrors(errors) {
  for (const small of document.querySelectorAll("#stock-form .field-error")) {
    const message = errors[small.dataset.field] || "";
    small.textContent = message;
    small
      .closest("label")
      .querySelector("input,select")
      .setAttribute("aria-invalid", message ? "true" : "false");
  }
}

$("stock-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const product = stock.product;
  if (!product) return;
  const v = readAdjustment();

  // 1. Browser checks. If they fail, no request is sent.
  if (!$("adj-skip-checks").checked) {
    const errors = validateAdjustment(v, product.stock);
    stockFieldErrors(errors);
    if (Object.keys(errors).length) {
      feedback("Please fix the highlighted fields. No request was sent.", {
        error: true,
        details: errors,
      });
      return;
    }
  } else {
    stockFieldErrors({});
  }

  // 2. Send. actorId and the balances are NOT sent: the server works them out.
  const body = { quantityChange: quantityChange(v.reason, v.quantity), reason: v.reason };
  if (v.note !== "") body.note = v.note;
  $("adj-save").disabled = true;
  try {
    const { body: saved, status, line } = await request(
      `/products/${product.id}/inventory-adjustments`,
      { method: "POST", body },
    );
    $("stock-form").reset();
    updateQuantityLabel();
    if (stock.product?.id === product.id) showCurrentStock(saved.stockAfter);
    feedback(
      `Stock for #${product.id} "${product.name}" changed from ${saved.stockBefore} to ${saved.stockAfter} (HTTP ${status}).`,
      { line },
    );
    stock.page = 1;
    await Promise.all([loadHistory(), loadProducts()]); // new row on top; Stock column updated
  } catch (error) {
    // 422: messages under the fields. 409: the stock moved or the change is too big;
    // re-read the stock so the preview shows the real number.
    stockFieldErrors(error.details || {});
    if (error.status === 409) await refreshProductStock();
    const title =
      error.status === 422
        ? `The server rejected the adjustment: ${error.message}`
        : error.status === 409
          ? `Stock not changed: ${error.message}`
          : error.message;
    feedback(title, { error: true, details: error.details, line: error.line });
  } finally {
    $("adj-save").disabled = false;
  }
});

/** GET /products/{id}/inventory-adjustments?page=..&limit=5, newest first. */
async function loadHistory() {
  const product = stock.product;
  if (!product) return;
  const current = ++historyRequest;
  stock.loading = true;
  updateHistoryPager();
  try {
    const { body } = await request(
      `/products/${product.id}/inventory-adjustments?page=${stock.page}&limit=${HISTORY_LIMIT}`,
    );
    if (current !== historyRequest) return;
    stock.totalPages = body.metadata.totalPages;
    renderHistory(body.data);
    $("adj-page-label").textContent = stock.totalPages
      ? `Page ${body.metadata.page} of ${stock.totalPages} · ${body.metadata.total} change${body.metadata.total === 1 ? "" : "s"}`
      : "No changes yet";
  } catch (error) {
    if (current !== historyRequest) return;
    stock.totalPages = 0;
    $("adj-history").replaceChildren();
    $("adj-page-label").textContent = "History unavailable";
    feedback(error.message, { error: true, details: error.details, line: error.line });
  } finally {
    if (current === historyRequest) {
      stock.loading = false;
      updateHistoryPager();
    }
  }
}

function renderHistory(rows) {
  const tbody = $("adj-history");
  tbody.replaceChildren();
  if (rows.length === 0) {
    const cell = tbody.insertRow().insertCell();
    cell.colSpan = 5;
    cell.className = "empty-state";
    cell.textContent = "No stock changes yet. The opening stock was set when the product was created.";
    return;
  }
  for (const a of rows) {
    const row = tbody.insertRow();
    // createdAt is UTC ("...Z"); the browser shows it in the viewer's time zone.
    row.insertCell().textContent = new Date(a.createdAt).toLocaleString([], {
      dateStyle: "medium",
      timeStyle: "short",
    });
    const change = row.insertCell();
    change.textContent = a.quantityChange > 0 ? `+${a.quantityChange}` : `−${Math.abs(a.quantityChange)}`;
    change.className = a.quantityChange > 0 ? "change-up" : "change-down";
    row.insertCell().textContent = `${a.stockBefore} → ${a.stockAfter}`;

    const reason = row.insertCell();
    const label = document.createElement("span");
    label.className = "customer-name";
    label.textContent = a.reason.charAt(0).toUpperCase() + a.reason.slice(1);
    reason.append(label);
    if (a.note) {
      const note = document.createElement("span");
      note.className = "customer-email";
      note.textContent = a.note; // textContent: the note is never parsed as HTML
      reason.append(note);
    }
    row.insertCell().textContent = `#${a.actorId}`; // the account id of the staff member or admin
  }
}

function updateHistoryPager() {
  $("adj-previous").disabled = stock.loading || stock.page <= 1;
  $("adj-next").disabled = stock.loading || stock.page >= stock.totalPages;
}

$("adj-reason").addEventListener("change", updateQuantityLabel);
$("adj-quantity").addEventListener("input", updatePreview);
$("stock-close").onclick = closeStock;
$("adj-previous").onclick = () => {
  if (stock.loading || stock.page <= 1) return;
  stock.page -= 1;
  loadHistory();
};
$("adj-next").onclick = () => {
  if (stock.loading || stock.page >= stock.totalPages) return;
  stock.page += 1;
  loadHistory();
};

// -------------------------------------------------- #33 format preview

$("format-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const id = $("format-id").value.trim();
  const accept = $("format-type").value;
  const output = $("format-output");
  try {
    const url = `${base()}/products/${encodeURIComponent(id)}`;
    const response = await fetch(url, { headers: { Accept: accept } });
    $("format-status").textContent =
      `GET ${url} (Accept: ${accept}) → ${response.status} ${response.headers.get("Content-Type") || ""}`;
    output.textContent = await response.text(); // raw text, shown as-is
    output.hidden = false;
  } catch {
    $("format-status").textContent = "Cannot reach the API.";
    output.hidden = true;
  }
});

// ------------------------------------------------------------------ wiring

$("filters").addEventListener("submit", (event) => {
  event.preventDefault();
  state.search = $("search").value.trim();
  state.category = $("category-filter").value;
  state.sort = $("sort").value;
  state.limit = Number($("page-size").value);
  state.page = 1; // new filters always start at page 1
  loadProducts();
});
// Next/Previous use the links the API returned, so the filters, sort and limit are kept.
$("next").onclick = () => {
  if (!state.loading && lastMeta?.next) loadProducts(apiLink(lastMeta.next));
};
$("previous").onclick = () => {
  if (!state.loading && lastMeta?.previous)
    loadProducts(apiLink(lastMeta.previous));
};
$("new-product").onclick = () => {
  resetForm();
  $("feedback").hidden = true;
  $("name").focus();
};
$("cancel-edit").onclick = resetForm;
$("connection-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  resetForm();
  closeStock();
  $("feedback").hidden = true;
  state.page = 1;
  await loadCategories();
  await loadProducts();
});

loadCategories().then(() => loadProducts());
