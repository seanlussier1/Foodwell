# Documentation audit (#43)

Audit of `docs/openapi.yaml`, the README, the route matrix and the older client/security notes against the running code, on `main` at `a602d48` (after PR #104), November 18, 2026. This pass changes documentation only. Runtime gaps it found are listed at the end for their owners and for the release candidate (#67).

## How it was checked

| Check | Method | Result after this pass |
| --- | --- | --- |
| Routes vs contract | Every `$router->...` route in `public/index.php` compared with every OpenAPI operation | 32 routes, all documented as `implemented`; 16 planned operations have no route; no operation claims `implemented` without one |
| Access rules | Each route's `protect(..., policy)` compared with its OpenAPI `security`/`x-access` and the route matrix | All 32 match; every protected operation documents 401 and 403 |
| Examples | Every request/response example (79) validated against its own schema | 0 problems |
| Error messages | Every example `error.message` (12) looked up in the source; the two assembled messages were confirmed against the live API | All are messages the API really returns |
| Real responses | The catalog, customer and order Newman collections replayed on a disposable database; each JSON response (64; 50 route/status combinations) validated against the documented schema for that route and status | 0 mismatches |
| Stale wording | Searched all docs for "local demo", demo flags, "loopback", "planned", "#45/#46 must", "not implemented yet" | Remaining matches are planned routes, the real postal demo mode, or dated QA history |

## What changed

**OpenAPI (`docs/openapi.yaml`)**

- 10 customer, cart and order operations changed from `x-status: local-demo` to `implemented`; their summaries, descriptions and 403 texts now state the enforced login rule instead of the removed demo flags.
- 6 session and order-history operations gained the `implemented` tag that every other implemented operation has; the `Sessions` and `Order history` tags no longer say "Planned".
- 401 (`#/components/responses/Unauthorized`) added to the 10 protected operations that lacked it. The replay found `GET /customers/{id}/cart` returning an undocumented 401.
- `previousStatus` on order status events is now nullable: an order's first event has no previous status, which the replay caught as a schema mismatch.
- Four 422 descriptions copied from registration ("no customer is created") on profile and cart updates now describe their own operation.
- The `info` description now states 32 implemented and 16 planned operations and that security on implemented operations is enforced.

**README**: the status summary, registration, customer profile/list/deactivation, client and cart sections, the seed accounts, setup and authentication/authorization notes now describe bearer-session enforcement instead of the removed demo flags and "planned" login. Check counts were updated (registration 124).

**Route matrix**: 8 customer/cart rows marked "Working local demo" and 2 order rows marked "Not implemented yet" now read "Bearer session enforced", with the observed order rules; it now agrees with the code for all 32 routes.

**Owners' notes** (please confirm your entries): `client/README.md` and `docs/SECURITY_DESIGN.md` (Sean) and `docs/CHECKOUT_DEMO.md` and `docs/CART_CHECKOUT_HANDOFF.md` (Mohamed) had sentences describing demo-flag access. Those sentences were corrected; recorded evidence and design history were kept and labelled as history.

## Gaps found (not fixed by this pass)

1. **Resolved PHP checkout consumer authentication.** The audit observed HTTP 401 on the cart request after #46. The client now signs in and sends a bearer token for cart and order requests. Checkout and order read-back were verified against disposable MariaDB. See the [authenticated checkout demo](CHECKOUT_DEMO.md#authenticated-php-checkout-demonstration).
2. **XML/YAML beyond product reads** was completed in #65: catalog routes answer in JSON, XML or YAML, request bodies are JSON on every route, and the other routes are explicit JSON-only contracts (see the [representation matrix](REPRESENTATION_MATRIX.md)).
3. **16 planned operations** (addresses, favorites, reviews, inventory adjustments) are contract only. Their routes return 404/405, and their security is the intended design, not enforcement.
4. Minor items from [the catalog integrity run](CATALOG_INTEGRITY.md): category POST ignores unknown fields and has no `Location` header; very large product ids show a clamped id in the 404 message; `HEAD` returns 405.
5. A live external postal provider lookup was not exercised in these checks (the delivery checks use labelled test doubles).
