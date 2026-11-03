# Representation matrix (#33)

Which request and response formats each implemented route supports in Build 2.
Owner: Will. Every cell marked **deferred** is tracked under #65 (B3-17). Anything
not marked **implemented** does not work yet; OpenAPI must not claim it does.

Media types: **JSON** `application/json` · **XML** `application/xml` · **YAML** `application/yaml`

## Matrix

| Method | Route | Response formats | Request body formats |
|---|---|---|---|
| GET | `/products` | JSON, XML, YAML — **implemented** | — (no body) |
| GET | `/products/{id}` | JSON, XML, YAML — **implemented** | — (no body) |
| POST | `/products` | JSON implemented; XML/YAML **deferred** | JSON implemented; XML/YAML **deferred** |
| PUT | `/products/{id}` | JSON implemented; XML/YAML **deferred** | JSON implemented; XML/YAML **deferred** |
| DELETE | `/products/{id}` | 204, no body (error bodies JSON) | — (no body) |
| GET | `/categories`, `/categories/{id}` | JSON implemented; XML/YAML **deferred** | — |
| POST | `/categories` | JSON implemented; XML/YAML **deferred** | JSON implemented; XML/YAML **deferred** |
| DELETE | `/categories/{id}` | JSON implemented; XML/YAML **deferred** | — |
| POST | `/customers` | JSON implemented; XML/YAML **deferred** | JSON implemented; XML/YAML **deferred** |
| GET | `/customers`, `/customers/{id}` | JSON implemented; XML/YAML **deferred** | — |
| PUT, PATCH | `/customers/{id}` | JSON implemented; XML/YAML **deferred** | JSON implemented; XML/YAML **deferred** |
| GET | `/customers/{id}/cart` | JSON implemented; XML/YAML **deferred** | — |
| POST, PUT | `/customers/{id}/cart/items[/{itemId}]` | JSON implemented; XML/YAML **deferred** | JSON implemented; XML/YAML **deferred** |
| DELETE | `/customers/{id}/cart/items/{itemId}` | 204, no body (error bodies JSON) | — |
| GET | `/delivery-options` | JSON implemented; XML/YAML **deferred** | — |

Output-only XML/YAML support on the two product reads does **not** complete the
matrix: all XML/YAML request bodies and every other route's XML/YAML output remain
deferred to #65.

## How the response format is chosen (Accept)

The router negotiates **after** it finds the route, so an unknown route is still 404
and a wrong method is still 405, whatever the Accept header says.

| Accept header | Result |
|---|---|
| missing, empty, or only unreadable entries | the route's default: **JSON** |
| `application/xml` (or `text/xml`) | XML on product reads; **406** on JSON-only routes |
| `application/yaml` (or `application/x-yaml`, `text/yaml`, `text/x-yaml`) | YAML on product reads; **406** on JSON-only routes |
| `*/*` or `application/*` | JSON |
| `application/xml;q=0.5, application/yaml;q=0.9` | YAML (highest q wins) |
| `application/xml, application/json` (same q) | XML (equal q: the client's order decides) |
| `application/json;q=0, */*` on product reads | XML (q=0 excludes JSON) |
| `text/html`, or `application/json;q=0` alone | **406 Not Acceptable** |

Details: the most specific matching range sets the quality (`application/xml` beats
`application/*` beats `*/*`). Parameters other than `q` (such as `charset`) are
ignored. Invalid `q` values cause that one entry to be ignored. Successful
negotiated responses send `Vary: Accept`. Responses always name the main media type
(`application/xml`, `application/yaml`) even when an alias was requested.

Note: browsers send `application/xml;q=0.9, */*;q=0.8`, so opening a product URL
directly in a browser shows XML. Clients that want JSON send `Accept: application/json`
(the dashboard, Build 1 page and all check scripts already do).

## Errors

- **406** body lists the formats the route can return. It is sent as JSON, because
  the client accepted none of the available formats.
- Once a format is negotiated, errors from that route use it: for example
  `GET /products/999999` with `Accept: application/xml` returns a 404 as
  `<error><status>404</status><message>…</message><requestId>…</requestId></error>`.
- Errors raised **before** routing stay JSON: CORS 403, 404 unknown route,
  405 wrong method, and a 500 while connecting to the database.

## Request bodies (Content-Type)

All implemented POST/PUT/PATCH routes accept only `application/json` (a `charset`
parameter is allowed). Any other or missing Content-Type returns **415**. Broken
JSON, or JSON that is not an object, returns **400**. Valid JSON with invalid
fields returns **422** with `error.details`. These checks run in each controller's
`readJson()`, before validation.

## Semantic parity

JSON, XML and YAML carry the same fields and values:

| JSON | YAML | XML |
|---|---|---|
| `{"id": 101}` | `id: 101` | `<id>101</id>` |
| `"name": "Milk"` | `name: "Milk"` (strings always quoted) | `<name>Milk</name>` |
| `true` / `false` | `true` / `false` | `<isActive>true</isActive>` |
| `null` | `null` | `<imageUrl nil="true"/>` |
| list `[...]` | `- ...` items | one child element per item (`<product>` in a product list) |
| `{"error": {...}}` | `error:` map | root `<error>` element |

XML has no types, so numbers and booleans are text there. Field names that are not
valid XML element names are written as `<entry key="...">`.

## How a teammate adds formats to a route (#65)

1. Register the route with the formats it supports:
   `$router->get(API_PREFIX . '/categories', [$categories, 'index'], [Negotiation::JSON, Negotiation::XML, Negotiation::YAML]);`
2. In the controller, answer with `Response::send($status, $payload, [], 'categories', 'category')`
   instead of `Response::json(...)`. The last two arguments name the XML root and list items.
3. Errors already follow the negotiated format through `Response::error()`.
4. Add the route's cells to this matrix and verify parity.

## Verification (Build 2)

Checked against a seeded database: every Accept case in the table above; XML and
YAML parsed with independent parsers and compared with the JSON for a full list,
two items (including a name containing quotes, `&`, `<`, `#`, `:`, accents and an
emoji, and a product named `null`), an empty list, a 404 and a 400 — all identical.
Existing suites still pass: `run-checks.php` 4/4, `check-registration.php` 122,
`check-cart.php` 79, `check-delivery.php` 62.
