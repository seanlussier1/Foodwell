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
$("api-url").value =
  location.port === "8081"
    ? "http://localhost/foodwell-api/api/v1"
    : `${location.origin}${apachePrefix}/api/v1`;

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
  const headers = { Accept: "application/json" };
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
    "Stock is read-only here: it changes only through inventory adjustments.";
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
  $("feedback").hidden = true;
  state.page = 1;
  await loadCategories();
  await loadProducts();
});

loadCategories().then(() => loadProducts());
