# Representation matrix (#33, completed in #65)

Which request and response formats every implemented route supports. Owner of the
shared negotiation and serializers: Will. Each route's owner is named in its row.
Every cell is either **implemented** or an explicit, tested **not supported**
contract (406 or 415). There are no deferred cells and no silent fallbacks.

Media types: **JSON** `application/json` · **XML** `application/xml` · **YAML** `application/yaml`

## Agreed scope (#65)

- **Catalog responses** (products, categories, delivery slots) are available in JSON, XML and YAML.
- **Request bodies are JSON on every route.** XML has no number or boolean types, so
  accepting XML bodies would mean converting each field before the strict validators,
  and YAML bodies would need a parser dependency. A POST/PUT/PATCH with any other
  Content-Type is refused (415, with the order exception listed below).
- **All other routes answer in JSON only.** Asking them for XML or YAML gives 406 with
  `This route can return: application/json.` Their owners can add formats later with
  the steps at the end of this document.

## Matrix

| Method | Route | Owner | Response formats | Request body |
|---|---|---|---|---|
| GET | `/products` | Will | JSON, XML, YAML | — |
| GET | `/products/{id}` | Will | JSON, XML, YAML | — |
| POST | `/products` | Will | JSON, XML, YAML | JSON only (415) |
| PUT | `/products/{id}` | Will | JSON, XML, YAML | JSON only (415) |
| DELETE | `/products/{id}` | Will | 204 no body; errors JSON, XML, YAML | — |
| GET | `/categories`, `/categories/{id}` | Mohamed | JSON, XML, YAML | — |
| POST | `/categories` | Mohamed | JSON, XML, YAML | JSON only (415) |
| DELETE | `/categories/{id}` | Mohamed | JSON, XML, YAML (200 with message) | — |
| GET | `/delivery-slots`, `/delivery-slots/{id}` | Will | JSON, XML, YAML | — |
| POST | `/delivery-slots` | Will | JSON, XML, YAML | JSON only (415) |
| PUT | `/delivery-slots/{id}` | Will | JSON, XML, YAML | JSON only (415) |
| DELETE | `/delivery-slots/{id}` | Will | 204 no body; errors JSON, XML, YAML | — |
| POST | `/customers` | Sean | JSON only (406) | JSON only (415) |
| GET | `/customers`, `/customers/{id}` | Sean | JSON only (406) | — |
| PUT, PATCH | `/customers/{id}` | Sean | JSON only (406) | JSON only (415) |
| GET | `/customers/{id}/cart` | Sean | JSON only (406) | — |
| POST, PUT | `/customers/{id}/cart/items[/{itemId}]` | Sean | JSON only (406) | JSON only (415) |
| DELETE | `/customers/{id}/cart/items/{itemId}` | Sean | 204 no body; errors JSON | — |
| GET, PUT, DELETE | `/customers/{id}/favorites[/{productId}]` | Sean | JSON only (406); writes return 204 | — |
| GET, POST | `/products/{id}/inventory-adjustments` | Will | JSON only (406) | JSON only (415) |
| GET | `/inventory-adjustments/{adjustmentId}` | Will | JSON only (406) | — |
| POST | `/auth/sessions` | Sean | JSON only (406) | JSON only (415) |
| GET, DELETE | `/auth/sessions/current` | Sean | JSON only (406) | — |
| POST | `/orders` | Mohamed | JSON only (406) | JSON only; non-JSON is **422** (see exceptions) |
| GET | `/orders/{id}` | Mohamed | JSON only (406) | — |
| PATCH | `/orders/{id}` | Mohamed | JSON only (406) | JSON only (415) |
| GET | `/customers/{id}/orders`, `/orders/{id}/status-events` | Mohamed | JSON only (406) | — |
| GET, POST, PATCH, DELETE | `/customers/{id}/addresses[/{addressId}]` | Mohamed | JSON only (406), DELETE returns 204 | POST and PATCH use JSON (415) |
| GET, POST, PATCH, DELETE | `/products/{id}/reviews`, `/reviews/{reviewId}` | Mohamed | JSON only (406), DELETE returns 204 | POST and PATCH use JSON (415) |
| GET | `/delivery-options` | Sean | JSON only (406) | — |


### Exceptions to "415 for a non-JSON body"

The order endpoint refuses the request and saves nothing, but answers with a different status.
- **`POST /orders`** reads `customerId` from the JSON body for its permission check,
  which runs before the controller. A non-JSON body therefore fails that check first
  with 422 `Valid customerId required.`

## How the response format is chosen (Accept)

The router negotiates **after** it finds the route, so an unknown route is still 404
and a wrong method is still 405, whatever the Accept header says.

| Accept header | Result on catalog routes | Result on JSON-only routes |
|---|---|---|
| missing, empty, or only unreadable entries | JSON (the default) | JSON |
| `application/xml` (or `text/xml`) | XML | **406** |
| `application/yaml` (or `application/x-yaml`, `text/yaml`, `text/x-yaml`) | YAML | **406** |
| `*/*` or `application/*` | JSON | JSON |
| `application/xml;q=0.5, application/yaml;q=0.9` | YAML (highest q wins) | **406** |
| `application/yaml, application/xml` (same q) | YAML (equal q: the client's order decides) | **406** |
| `application/json;q=0, */*` | XML (q=0 excludes JSON) | **406** |
| a browser's `text/html,...,application/xml;q=0.9,*/*;q=0.8` | XML | JSON |
| `text/html`, `image/png`, or `application/json;q=0` alone | **406** | **406** |

Details: the most specific matching range sets the quality (`application/xml` beats
`application/*` beats `*/*`). Parameters other than `q` (such as `charset`) are
ignored. An invalid `q` value makes that one entry ignored. Successful negotiated
responses send `Vary: Accept`. Responses use the negotiated media type, including
an accepted alias such as `text/xml`, with `; charset=utf-8`. Each alias is
evaluated independently, so excluding one media type does not exclude another.

Opening a catalog URL directly in a browser shows XML, because browsers prefer XML
over `*/*`. Clients that want JSON send `Accept: application/json` (the dashboard,
the shop page and all check scripts do).

## Errors

- **406** is always sent as JSON, because the client accepted none of the route's
  formats. Its message lists them, for example
  `This route can return: application/json, application/xml, application/yaml.`
- Once a format is negotiated, every error from that route uses it, including the
  login check's 401 (still with `WWW-Authenticate: Bearer`) and 403, 400, 404, 409,
  415 and 422. In XML the root element is `<error>` holding `status`, `message`,
  `details` (when present) and `requestId`. In JSON and YAML the same fields sit
  under an `error` key.
- A client that sends an XML body and asks for XML gets the 415 in XML, naming
  `application/json`.
- Errors raised **before** routing stay JSON: CORS 403, 404 unknown route,
  405 wrong method, and a 500 while connecting to the database.

## Request bodies (Content-Type)

POST/PUT/PATCH routes accept only `application/json` (a `charset` parameter is
allowed), apart from the order exception above. Any other or missing Content-Type
returns **415** without reading the body. Broken JSON, or JSON that is not an
object, returns **400**. Valid JSON with invalid fields returns **422** with
`error.details`. A refused request never changes the database.

## Semantic parity

JSON, XML and YAML carry the same fields and values:

| JSON | YAML | XML |
|---|---|---|
| `{"id": 101}` | `id: 101` | `<id>101</id>` |
| `"price": 12.3` | `price: 12.3` | `<price>12.3</price>` |
| `"name": "Milk"` | `name: "Milk"` (strings always quoted) | `<name>Milk</name>` |
| `true` / `false` | `true` / `false` | `<isActive>true</isActive>` |
| `null` | `null` | `<imageUrl nil="true"/>` |
| list `[...]` | `- ...` items | one child element per item (`<product>`, `<category>`, `<deliverySlot>`) |
| `[]` (empty list) | `data: []` | `<data/>` |
| `{"error": {...}}` | `error:` map | root `<error>` element |

XML has no types, so numbers and booleans are text there. Field names that are not
valid XML element names are written as `<entry key="...">`. Strings that YAML could
misread (`"null"`, `"yes"`, `"no"`, `"~"`, text starting with `- `) are always quoted.

| Route | XML root | XML list item |
|---|---|---|
| `GET /products` | `<products>` | `<product>` |
| `GET/POST/PUT /products/{id}` | `<product>` | — |
| `GET /categories` | `<categories>` | `<category>` |
| `GET /categories/{id}`, `POST /categories` | `<category>` | — |
| `DELETE /categories/{id}` | `<categoryDeletion>` | — |
| `GET /delivery-slots` | `<deliverySlots>` | `<deliverySlot>` |
| `GET/POST/PUT /delivery-slots/{id}` | `<deliverySlot>` | — |

## Verification (#65)

`php scripts/check-representations.php` (same `SCHEMA_TEST_*` settings as the other
checks; disposable database) sends real requests and compares the answers. It passed with
448 checks on the snapshot in [CATALOG QA](BUILD3_CATALOG_QA.md) plus this change:

- **Parity:** every catalog read, write and error is requested in JSON, XML and YAML.
  XML is read with PHP's DOM parser and YAML with a strict reader that rejects anything
  unexpected. Each is compared with the JSON value by value: ids, text with quotes, `&`,
  `<`, `#`, `:`, accents and an emoji, a product literally named `null`, null image URLs,
  booleans, empty lists, stock quantities and prices such as 12.30 and 0.05. Errors also
  check that each `requestId` matches its own `X-Request-ID` header.
- **Accept:** each row of the table above on a product, a category and a slot read, and
  406 for unsupported types on each catalog collection.
- **Request bodies:** XML, `text/xml`, YAML, form and `text/plain` bodies on thirteen
  write routes across all owners: 415 (or the documented 400/422) and no database change.
- **JSON-only routes:** eight customer, cart, order, session and delivery-option reads
  work in JSON and give 406 for XML and for YAML.

Independently, the YAML from 12 catalog responses (including names `yes`, `no`, `~`
and `- dash: colon # hash`) was parsed with PyYAML and matched the JSON exactly.
`check-products.php`, `check-catalog-integrity.php` and `check-catalog-authorization.php`
were updated for the new contract (product writes and catalog reads now answer in XML/YAML).

## How an owner adds formats to a route

1. Register the route with its formats:
   `$router->get(API_PREFIX . '/customers/{id}', ..., [Negotiation::JSON, Negotiation::XML, Negotiation::YAML]);`
   (for a protected route, after the `protect(...)` argument).
2. In the controller, answer with `Response::send($status, $payload, $headers, 'xmlRoot', 'xmlListItem')`
   instead of `Response::json(...)`.
3. Errors already follow the negotiated format through `Response::error()`.
4. Update this matrix and OpenAPI (use the `Negotiated...` response components), and add
   the route to `scripts/check-representations.php`.
