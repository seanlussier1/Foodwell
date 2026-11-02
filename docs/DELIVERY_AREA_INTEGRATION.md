# Delivery-area lookup (#37)

## What the feature does

`GET /api/v1/delivery-options?postalCode=H3N%201X1` combines two sources:

- **Zippopotam.us / GeoNames:** place/province information for a Canadian postal-code prefix (the first three characters).
- **Foodwell:** configured service-area prefixes, future active delivery slots and capacity already used by non-cancelled orders.

The customer can find out whether Foodwell's configured rules serve that postal area and see up to 10 future slots with remaining capacity. This lookup does not validate a street address or the last three postal-code characters, create an order, reserve stock or reserve a slot. Checkout must recheck eligibility, capacity and all order rules transactionally.

No database migration is needed. The existing delivery_slots and orders tables are read; service prefixes are configuration and provider responses use a file cache. No customer identity, address or cart data is sent to the provider. Only the three-character area prefix is sent externally.

## Provider, data and attribution

- API: `https://api.zippopotam.us/ca/{prefix}` (fixed HTTPS host, CA only).
- [Provider introduction and Canadian coverage limitation](https://docs.zippopotam.us/docs/getting-started/).
- [Places-by-postal-code reference](https://docs.zippopotam.us/docs/v1/).
- [GeoNames data/attribution terms](https://www.geonames.org/export/): credit GeoNames and link to it; data is provided as-is without a coverage/accuracy guarantee.

Read-only calls need no key or account for this endpoint. The client and API response identify Zippopotam.us and GeoNames, with source/license links. This integration uses the API; it does not copy the provider's server software. The existing Foodwell application license is unchanged. Keep attribution if the feature is reused.

## Configuration

| Setting | Default | Purpose |
|---|---|---|
| DELIVERY_AREA_PREFIXES | H3N,H3S,H3R | Example Montreal service area for this classroom demonstration. Replace with the team's agreed store service area before treating eligibility as an operational promise. Maximum 100 valid prefixes. Empty means no areas served. |
| POSTAL_CA_BUNDLE | Empty | Optional path to a trusted CA bundle if Windows PHP cURL lacks one; otherwise PHP's normal CA configuration is used. TLS verification stays enabled. |
| POSTAL_LOOKUP_MODE | live | Normal operation. Other modes below are explicit local demonstrations. |
| APP_POSTAL_DEMO | 0 | A non-live mode is allowed only when this is 1 and the client address is loopback. |
| DELIVERY_API_BASE_URL | http://localhost/foodwell-api/api/v1 | Foodwell API base for the PHP CLI consumer; use localhost:8080/api/v1 with composer start. |

PHP cURL is required and listed in Composer's platform requirements. A Windows HTTPS certificate error is a configuration problem, not a reason to set SSL verification to false. Use a trusted CA file through POSTAL_CA_BUNDLE or configure PHP's curl.cainfo. Keep machine-specific paths in .env. var/ is ignored by Git and is not exposed through the Front Controller.

Successful validated provider results are cached for 24 hours in var/postal-cache by prefix. Writes use a temporary file and rename. Cache failures do not prevent a successful live result. Corrupt, expired or future-dated entries are ignored. No failures or demo results are cached. Do not interpret cached area data as freshly verified full-address information.

## API contract

Only one query parameter is supported: postalCode, a scalar Canadian-format code such as H3N 1X1 or H3N1X1. Input is trimmed/uppercased and returned with a space. Extra parameters, arrays, missing values and invalid syntax return 400. A syntactically valid full code is not proof it is assigned to an actual address.

The provider client uses a 2-second connection timeout, 5-second overall timeout, 64 KiB body limit and bounded JSON depth/place counts. Redirects are not followed and HTTPS verification remains on. Country/prefix and required place/province fields are checked before use. Multiple places are retained. Request parameters cannot override the upstream host, country, mode or file paths.

| Response | Meaning |
|---|---|
| 200, status=complete, delivery.status=available | Area found, prefix served and future capacity exists. Slots are options, not bookings. |
| 200, complete, no-slots | Area served, but no matching future slot currently has capacity. |
| 200, complete, outside-area | Area found but its prefix is not in Foodwell's configured service area. No slots are offered. |
| 200, status=partial, delivery.status=unknown | Provider coverage/result could not confirm the area. eligible=null and slots=[]; no false claim of invalid address or available delivery. |
| 400 | Invalid local query. |
| 500 | Unexpected application/database/configuration failure outside the controlled provider fallback. Shared middleware returns a safe error with request ID. |

Top-level fields: status, postalCode, area, delivery, sources, notice, warnings. area includes prefix, lookupStatus, places, source and fetchedAt. delivery includes eligible, status, slots and bookingConfirmed (always false). Dates in slots are UTC ISO strings; the JavaScript view displays them in America/Toronto. Remaining capacity counts every non-cancelled order assigned to that slot; inactive/past/full slots are excluded and results are ordered by start time/ID.

area.source identifies `live`, `cache`, `demo` or `unavailable`. The API's partial result is a deliberate fallback, not a silent success or invented area. Provider 404 means unknown coverage, not proof that the full postal code is invalid. Timeout, 429, other HTTP failures, network/certificate issues, malformed JSON and mismatched/oversized responses have explicit warning codes. No automatic retries are performed.

## JavaScript and PHP consumers

**Browser:** open `http://localhost/foodwell-api/client/delivery.html`, or use the customer dashboard's Check delivery area link. With the separate client server (`php -S localhost:8081 -t client`), open `http://localhost:8081/delivery.html`. Adjust API connection if needed. The view renders area details and Foodwell windows, handles errors/partial results, and keeps attribution visible. Invalid syntax is caught before a request. Provider text is rendered as text, not HTML.

**PHP:** with Wamp or the PHP API server running:

```powershell
php scripts/delivery-client.php "H3N 1X1"
```

This CLI consumer calls Foodwell's composite API, not Zippopotam.us directly. It outputs the HTTP status and structured response, including source/fallback labels. It uses bounded timeouts/response size and checks JSON. Set DELIVERY_API_BASE_URL if your API URL differs. This preserves both PHP and JavaScript consumption evidence for #37 without adding another web-server route.

## Controlled failures and fixtures

For a deliberate local demonstration only, set APP_POSTAL_DEMO=1 and one mode in .env:

- demo-success: a clearly marked fixture area; actual slot/capacity data still comes from the local Foodwell database.
- demo-timeout: returns the documented partial response with timeout warning.
- demo-malformed: returns a controlled invalid-response warning.
- demo-not-found: returns unknown provider coverage, not an address-validity verdict.

All demo results/failures are marked area.source=demo and carry a demo-data warning. Demo handling runs before the cache so it cannot accidentally show an earlier live success. Non-live modes without both local requirements yield a configuration fallback, not fixture data. Restore POSTAL_LOOKUP_MODE=live and APP_POSTAL_DEMO=0 after the demonstration. Never use demo fixtures as evidence of live provider coverage.

## Verification and evidence

```powershell
$env:SCHEMA_TEST_PORT = '3307'
php -d xdebug.mode=off scripts/check-delivery.php
# Optional: real provider requests, requiring working TLS configuration.
php -d xdebug.mode=off scripts/check-delivery.php --live
```

Use SCHEMA_TEST_HOST/USER/PASS if needed. The default suite uses isolated temporary databases and labeled provider doubles; it makes no live provider calls. --live adds a real uncached HTTPS request and the live/cache composite route. The PHP consumer is checked against the disposable composite API. Tests validate cancellation/full/inactive/past capacity rules, no booking side effects, input/schema bounds, cache behavior and controlled failures. No application database is reset.

For #64 evidence, record the submitted commit, service-prefix configuration, provider/data attribution, a working browser lookup, PHP output, source labels, input failure and explicit controlled upstream failure. Keep a real live-provider check separate from demo/test fixtures. Normal lookups do not require APP_CART_DEMO or APP_CUSTOMER_PROFILE_DEMO.

## What remains for #51 and the team

This delivers initial bounded requests, caching, validation and explicit failures. #51 still owns broader provider-outage/quota testing, traffic controls against bursts, deployment/cache-permission checks and any revised retry/stale-cache policy. This is not a delivery booking or complete address-validation service. Will reviews #37; coordinate slot capacity/status definitions with Mohamed so checkout rechecks the same business rules. Agree real store service prefixes before using eligibility beyond the classroom demonstration.
