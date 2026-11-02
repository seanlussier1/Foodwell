# Foodwell security preparation (#63)

Status: design draft ready for resource-owner review, November 1, 2026.
Owner: Sean (@seanlussier1). Required resource reviews: Will (@Wlszn) and Mohamed (@MohAmine2006). Neither review is recorded yet. No authentication or authorization implementation is delivered by this document.


## How to read this plan

This plan helps the team agree on who should be allowed to use each API operation and how login should work. Each resource owner will use those decisions when implementing security in Build 3.

Start with **Current Build 2 limitations** to see what works today. Read **Roles and account administration** and **Ownership and nested resources** to review permissions. **Session lifecycle** and **Login throttling and logging** describe the future implementation in more detail. The final sections assign the work and record the reviews needed to finish #63.

There are three important labels throughout these documents:

| Label | Meaning |
|---|---|
| Current behavior | What the reviewed code actually does today. A route can work while its intended permission checks are still missing. |
| Intended rule | The access rule the team plans to enforce in Build 3. It is not proof that the code already checks it. |
| Proposed default | A specific setting, such as the two-hour session lifetime, for the team to confirm during review. |

### Terms used in the plan

| Term | Meaning in Foodwell |
|---|---|
| Route / operation | An HTTP method and URL, such as GET /customers/{id}. GET and PUT on the same URL are different operations. |
| Authentication | Checking who is making the request. |
| Authorization / permission check | Checking whether that person is allowed to perform this operation on this particular record. |
| Owner | The account that a database record belongs to. For example, a cart belongs to its customer. |
| Nested resource | A record inside another resource, such as an item inside a customer's cart. Both relationships must be checked. |
| Session | A server-side record showing that an account logged in and when that login expires or was revoked. |
| Bearer token | A secret sent with requests to identify a session. Anyone holding it can attempt to use that session, so it must be protected. |
| Opaque token | A random token that contains no readable account or role information. The server looks up its meaning. |
| Hash / digest | A one-way representation stored instead of a secret. Passwords use a password-hashing method; random tokens use SHA-256 in this design. |
| Revocation | Cancelling a session before its expiry, for example after logout or deactivation. |
| Atomic change / transaction | Related database changes succeed together or are rolled back together. |
| Throttling / rate limit | Temporarily limiting repeated requests, such as too many login attempts. |
| Redaction | Removing secrets and sensitive values from logs or error output. |
| Loopback | A request from the same computer, such as localhost. This alone does not identify a particular user. |
| CORS / preflight | Browser rules and an OPTIONS request that check whether a page may call an API from a different origin. These do not establish user identity. |
| Origin | The scheme, host and port of a webpage, such as http://localhost:8081. |
| Identity / actor | The signed-in account responsible for the request or recorded action. |

### One example connecting the rules

Suppose customer 7 is signed in and requests customer 8's cart. **Authentication** establishes that the caller is customer 7. **Authorization** then rejects the request because customer 7 does not own customer 8's cart. Changing the URL or putting customerId=8 in the body must not grant access. An administrator is allowed only where the route matrix explicitly names that exception.

## 1. Documents covered and how they connect

The [route matrix](SECURITY_ROUTE_MATRIX.md) covers all 48 method/path operations currently documented in [OpenAPI](openapi.yaml). It separates intended permissions from actual implementation. `x-access` and `security` describe the target contract; `x-current-access` records the current boundary, and `x-security-enforcement` states whether the target private-access rules are enforced.

This snapshot is based on main commit `c55cacd` (customer PRs #70/#72 and category PR #73). Later routes must update both the matrix and OpenAPI. Category PUT is not currently agreed in this contract. The public /delivery-options composite route is added under #37, with prefix-only external disclosure and no booking side effects; see DELIVERY_AREA_INTEGRATION.md. It does not change the private-route permission rules. No route is implicitly public because its policy is missing.

## 2. Current Build 2 limitations: what is and is not enforced

- Product GET and category GET are public. Registration is public, validates inputs, hashes passwords and fixes role=customer/is_active=1 server-side.
- Category POST/DELETE currently have no identity/role check. They are intended staff/admin operations, not safe public administration. OpenAPI now labels the intended policy and explicitly records current public access. Restrict this snapshot to controlled synthetic development; #46 and Mohamed's resource checks must enforce the intended policy before shared deployment.
- Customer collection/profile/deactivation routes require APP_CUSTOMER_PROFILE_DEMO=1 and loopback access. This is a demonstration gate, not identity: while enabled any local caller can access other profiles. Keep disabled on shared/deployed servers. #45/#46 must replace this bypass with real session and permission checks; simply adding a bearer header to today's requests does not authenticate them.
- CORS is implemented for selected origins and preflight, but does not establish identity, grant a role or prevent non-browser calls. Do not trust Origin, Referer, X-Forwarded-For or a customerId body field as identity.
- Session endpoints, session validation, ownership/role enforcement and login throttling remain planned. Table constraints and OpenAPI security declarations do not enforce them.
- A top-level exception catch exists. Shared structured logging/error work (#32) is separate and must integrate redaction. Do not claim a completed logging system from this design.

## 3. Roles and account administration

| Role | Intended capabilities | Exclusions |
|---|---|---|
| Public caller | Read public catalog/slot/review resources; register; attempt login | No access to private profiles, carts, addresses, favorites, order details or stock mutations |
| Customer | Own profile/cart/checkout/orders; own addresses/favorites; own review actions | No other customer's resources, catalog writes, manual stock adjustments or staff operations |
| Staff | Catalog/category/slot administration, inventory audit, customer listing/deactivation and order status/history as explicitly listed | No blanket profile/cart/address/favorite access; no role grants or privileged-account deactivation |
| Administrator | Explicit staff/admin operations and explicit admin overrides such as profile/cart/order access and review moderation | No implicit override on owner-only addresses/favorites or author-only review editing; no public role-grant route |

### How roles and ownership work together

An authenticated account is an active customer/staff/admin account with a valid session. A role never overrides an operation's explicit ownership rule unless the matrix says so. Staff/admin may access their own profile; staff cannot inspect another profile merely because they can list limited customer records. Listing returns only customer-role accounts and the existing limited profile fields, not staff/admin identities or credential fields.

### Which account fields may change

Public registration rejects role, isActive, password_hash and unknown fields. Profile PUT accepts only firstName, lastName, email and nullable address/phone, never passwords, roles or activation. Its email uniqueness checks remain server-side.

PATCH customer deactivation accepts exactly isActive=false and targets customer-role accounts only; staff/admin targets remain rejected even for an admin caller. It revokes that customer's sessions atomically and retains history. No reactivation, role promotion, privileged-account management, password-change or recovery endpoint is designed here.

### Creating staff and administrator accounts

Initial staff/admin provisioning uses a deliberate operator-controlled setup procedure outside public API requests. Do not introduce a hidden public promotion parameter. If future administration permits password/role/activity changes, design that endpoint separately and revoke affected sessions; role/account activity must be re-read on protected requests so old tokens cannot retain stale privileges.

## 4. Checking ownership and related records

Authenticate first, then apply the route's role rule and resolve the object from the database. Compare immutable database owner IDs to the authenticated account, not client-provided actor IDs.

The planned status rules are:

- **401:** missing, malformed, expired or revoked identity.
- **403:** a valid active identity that does not have permission.

 Check path ownership before fetching a foreign private resource. Once the caller is allowed into a parent scope, nonexistent children or mismatched parent/child associations return 404. Do not expose another customer's record in error messages.

- Profiles: owner means account.id equals path id; explicit administrator override is permitted. Ordinary profile writes cannot change role, credentials or activity.
- Carts: carts.customer_id must match path id and the caller, unless the route explicitly permits admin access. itemId must belong to that cart; a guessed item ID from another cart returns 404 within the authorized scope. Cart access does not reserve stock.
- Checkout: preserve the current OrderInput.customerId for compatibility, but treat it as a claimed owner. A customer must submit their own ID; an administrator may explicitly operate on a target customer where owner_or_admin is specified. Verify the target is an active customer, cart ownership and address ownership in the same checkout workflow. Staff cannot place another customer's order under this policy. Never trust submitted prices, totals or actor IDs.
- Orders: owner comes from orders.customer_id, not a request parameter. Customer order lists scope to the path customer. Current contract permits owner/admin to read order detail; staff may change status and read status history but do not receive broader order-detail access by assumption. Mohamed must confirm this distinction; broader fulfillment reads require an explicit reviewed contract update.
- Addresses/favorites: customer-role owner only; no administrator override is currently declared. Address IDs must belong to the path customer. Checkout copies an owned saved address into the order so later edits do not rewrite history.
- Reviews: public reads; creation requires a customer role and session-derived author. Editing requires that customer author; deletion permits the author or administrator. Staff cannot post customer reviews or moderate by default. Ignore/reject caller-supplied customer/author fields; preserve the one-review-per-product/customer rule.
- Inventory: staff/admin only. actor_id comes from the session; before/after balances come from locked server calculations. Audit records remain append-only; no client audit edit/delete routes. Orders/status events likewise derive actors server-side and commit the event with the state change.

Shared authorization must apply to every format and method, including item routes and mutations; hiding a button or filtering a list is not object-level authorization. Every owner supplies cross-user and nested-ID tests.

## 5. How login sessions will work (#45)

The following concrete values are proposed project defaults for review, not values mandated by OWASP or the course.

1. **Check the login details.** Login accepts email/password through JSON over HTTPS outside local development. Normalize email exactly as registration does; do not trim passwords. Verify with password_verify against the stored hash. Use a dummy hash verification path for unknown accounts so obvious timing differences are reduced. Wrong password, unknown account and inactive account all return the same 401 message: `Invalid email or password.` Do not expose whether the email exists through login errors. The existing registration duplicate-email 409 is a known separate behavior; it does not become generic automatically.
2. **Create a new secret token.** Generate a fresh opaque token using 32 cryptographically random bytes (256 bits), encoded as 64 lowercase hexadecimal characters. Never accept a caller-selected token or make a token from an account ID. No JWT or embedded-role assumption.
3. **Store a digest and return the token once.** Store only SHA-256 of the token in auth_sessions.token_hash, with customer_id, created_at, expires_at and revoked_at. Return the raw token once in the successful login body with Bearer token type and expiresAt. Use Cache-Control: no-store for login/current-session/private profile responses. Passwords stay as hashes; the current bcrypt registration implementation is retained, with rehashing considered during #45. The inactive synthetic session seeds do not become valid production credentials.
4. **Expire the session.** Proposed initial expiry: an absolute 2-hour lifetime, checked server-side using UTC timestamps. There is no sliding extension or refresh token in this scope. expires_at <= now is invalid. The current schema has no last_seen_at field, so idle expiry is not claimed; adding it requires an explicit migration and design review. Multiple independent login sessions may exist, each with its own expiry and revocation.
5. **Validate every protected request.** Protected requests accept one `Authorization: Bearer <token>` value. Reject malformed, missing or oversized credentials with generic 401; do not accept URL query tokens. Hash the supplied token, find its session, verify expiry/revocation and join the current account to enforce activity and current role. Do not trust cached role claims from the browser. Use WWW-Authenticate: Bearer on 401 and keep the response secret-free.
6. **Inspect the current session or log out.** GET /auth/sessions/current returns safe account details and expiresAt, never a raw token or digest. DELETE of current session revokes only that authenticated session; the client clears its in-memory token. Subsequent use gives 401. An already-revoked token follows the same 401 rule; do not promise repeated logout success under an invalid session.
7. **Cancel sessions when an account is deactivated.** Customer deactivation must revoke all that customer's sessions and retain order/history records. The existing local-demo PATCH already revokes sessions, but request authentication and authorization are not implemented. Revocation/account checks must also be enforced on every request under #45/#46. Expired session cleanup is maintenance, not a substitute for validating expiry.
8. **Handle simultaneous requests safely.** When a private mutation races with deactivation, coordinate the active-account/session check and the mutation transaction or lock so a post-deactivation request cannot commit unauthorized work. Order/cart owners must supply a concurrency case; do not claim an earlier middleware read alone closes this race.

### Where clients keep the token

The planned JavaScript client keeps raw tokens only in memory (cleared on reload/logout), not localStorage, URLs, logs or screenshots. This means re-login after reload, deliberately. A server-side PHP consumer holds secrets server-side; do not embed them in rendered markup. No cross-origin cookie authentication is introduced. HTTPS, safe DOM rendering and origin allowlisting complement access checks but do not replace them.

## 6. Login throttling and logging

### Limits on repeated login attempts

Proposed #45 thresholds: at most 5 failed attempts per normalized account key per 15 minutes and 30 login attempts per source address per 15 minutes. Apply the same account-key rule to known and unknown emails. Return generic 429 with Retry-After while limited; use temporary backoff rather than permanent account locking. Success clears the account's failed-attempt counter but does not clear the IP-wide budget. Review thresholds against classroom usage before implementation.

### Where attempt counters are stored

Counters must be atomic/shared across workers and survive ordinary request boundaries (database or a deliberately configured shared store). Process-local PHP arrays are insufficient. The repository does not yet contain a rate-limit store; #45 must select it and provide any migration/setup instructions. Derive source address from REMOTE_ADDR until a trusted proxy configuration is explicitly established. Hash account lookup keys with a separate server secret; never log raw emails solely for throttling. Protect and expire limiter records.

### What to record and what to leave out

Under #32/#45/#46, log authentication success/failure, throttling, logout/revocation, role/ownership denials and unexpected failures. Include requestId, UTC time, safe route template, method/status, event type and internal actor ID when known. Do not log Authorization headers, raw/digested bearer tokens, passwords/hashes, complete request/response bodies, addresses or full customer profiles. Avoid raw query strings and SQL exception text containing user values. Any operational IP collection needs restricted access and a short documented retention period; proposed classroom default is 7 days with operator-only access, subject to team deployment policy.

### How to verify safe error output

Client errors remain generic; detailed stack traces stay out of responses. Logs need restricted file permissions, no public web serving, size/retention controls and injection-safe formatting. Add a sentinel-secret test proving representative sensitive values are absent from response/log output. This design specifies future behavior; it does not claim the current error_log($e->getMessage()) catch already meets these rules.

## 7. Who implements and tests each part in Build 3

| Issue / owner | Required implementation and acceptance evidence |
|---|---|
| [#45](https://github.com/seanlussier1/Foodwell/issues/45) / Sean | Login/session creation, digest-only storage, generic errors, expiry/revocation/inactivity, rate limiter, current-session/logout and token handling; matching OpenAPI schemas/statuses and any limiter migration |
| [#46](https://github.com/seanlussier1/Foodwell/issues/46) / Sean + all route owners | Shared identity/permission helpers; apply this matrix to every private route; remove/disable local bypass in secured configuration; enforce category writes as staff/admin |
| [#40](https://github.com/seanlussier1/Foodwell/issues/40) / Will | Catalog/slot/inventory role enforcement, actor binding and resource-specific denial tests |
| [#47](https://github.com/seanlussier1/Foodwell/issues/47) / Sean | Missing/malformed/unknown/expired/revoked tokens, inactive accounts, wrong roles, cross-user profile/cart/nested IDs, invalid/duplicate inputs and unchanged data after rejection |
| #41/#42 / Will; #52/#53 / Mohamed | Catalog/category/order ownership and role cases, transactional actor binding, rollback, deactivation races, repeat mutations and unchanged state evidence |
| #48 / Sean | Automate representative validation/auth/permission checks through PHPUnit/Newman; existing local-demo tests are not identity tests |
| #32/#33 / Mohamed/Will | Safe correlated errors/log redaction and representation parity; access checks must work identically across supported media types |
| [#64](https://github.com/seanlussier1/Foodwell/issues/64) / Will coordinates | Link this design and route matrix in the B2 manifest; record exact submitted commit and every known unsecured/private-route limitation; each owner supplies their evidence |

### Minimum cases to demonstrate

Minimum security case set: anonymous public read; valid owner access; valid other-customer denial; allowed staff/admin operation; wrong-role denial; nested child/parent mismatch; password/role/actor tampering; generic login outcomes; expired/revoked/inactive identity; throttling/reset; logout/deactivation invalidation; secret redaction; and no partial writes on rejection. Schema checks, a local-loopback test and a manual screenshot do not prove these future cases.

## 8. What the team must review before #63 is complete

- [x] Draft covers all 48 agreed operations and distinguishes current access from intended enforcement.
- [x] Roles, ownership, administration, opaque sessions, expiry/revocation, inactive accounts, login throttling and logging are specified.
- [x] Linked from README/OpenAPI and local B2 handoff notes; #64 still needs the final reviewed commit link in its submitted manifest.
- [ ] Will reviews catalog/slots/inventory permissions, customer policy and session assumptions.
- [ ] Mohamed reviews category/order/address/review/history rules, checkout actor binding and error/logging integration.
- [ ] Resolve review comments, validate the final route matrix/OpenAPI and merge the design change.

Completion of this document alone does not close #63. Record actual review links/dates here (none yet); do not manufacture sign-off. Review does not require implementing #45/#46 ahead of Build 3. A design PR should reference #63, and close it only once the required reviews/evidence and merge are satisfied.

## 9. Background guidance

These sources inform the design; exact lifetimes, thresholds and storage choices above are project decisions awaiting review:

- [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html): random session identifiers and server-side session expiration.
- [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html): generic authentication failures and throttling.
- [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html): useful authentication events and exclusion of secrets.
