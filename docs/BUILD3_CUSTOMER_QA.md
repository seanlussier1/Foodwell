# Customer, authentication, cart and delivery QA handoff (#49)

This handoff supports the team QA plan (#66) and clean-checkout verification (#67). It records Sean's domain checks and a synthetic-data rehearsal; it is not the team's final release-candidate acceptance or a deployment test.

## Tested snapshot and environment

- Base: `102a8d1e26f8a6b6a933043d622bcb00890f7955` (main, PR #98), plus the regression and PHP 8.0 compatibility changes in this branch. Record the final merged commit separately for the release candidate.
- Local run: November 18, 2026, Windows, PHP 8.0.30, MariaDB 11.4.9 and Node 24.11.1. The PHP and Node dependencies were reused from an existing local installation whose lockfiles match this snapshot; this run is not evidence of a fresh dependency installation.
- All databases were on a dedicated local MariaDB instance initialized for QA. The checks that create databases use synthetic schema and seed data. The original product check resets `foodwell_qa_main` and `foodwell_empty` on that dedicated instance; do not point it at a shared or production server. Separate random test databases were dropped by the scripts.
- Postal provider behavior in the automated suite and the browser rehearsal used clearly labeled doubles or `demo-success`; no live provider or deployed environment was tested.

## Results

| Check | Result |
|---|---|
| `php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml` | Pass: 9 tests, 27 assertions. |
| `php scripts/run-checks.php` | Pass: 4 original product GET checks, including an empty database. |
| `php scripts/check-newman.php` | Pass: 12 customer/auth/cart requests, 12 assertions. |
| `php scripts/check-newman.php --orders` | Initial PHP 8.0 run failed because a strict test assertion expected integers while PDO returned numeric strings. After normalizing the returned quantities, the retest passed: 2 stock tests/6 assertions, 3 MariaDB tests/83 assertions, and 14 Newman requests/21 assertions. |
| `php scripts/check-schema.php` | Pass: 72 schema checks. |
| `php scripts/check-products.php` | Pass: 79 product checks. |
| `php scripts/check-auth.php`, `php scripts/check-authorization.php` | Pass: authentication, ownership and denial checks. |
| `php scripts/check-registration.php` | Initial legacy script failed on the changed landing page. After updating the page expectation and using a synthetic administrator session for private routes, the retest passed: 124 checks. |
| `php scripts/check-cart.php` | Initial legacy script expected unauthenticated demo access. After using a synthetic administrator session and checking anonymous 401 responses, the retest passed: 79 checks. |
| `php scripts/check-delivery.php` | Pass: 62 checks with provider doubles; no live-provider claim. |
| `php scripts/check-delivery-slots.php` | Pass: 71 checks. |
| `php scripts/check-catalog-authorization.php` | Pass: 250 checks. |
| `php scripts/check-order-concurrency.php` | Pass: competing checkouts and deactivation/cart-write races. |

The first PHP 8.0 regression run also exposed first-class-callable syntax in `OrderRepository` that requires PHP 8.1, even though Composer declares PHP >=8.0. The equivalent closure in this branch restores PHP 8.0 parsing; the successful HTTP and order retests above ran with PHP 8.0.30. Review with the order owner.

## Browser rehearsal

On a synthetic local database with `APP_POSTAL_DEMO=1` and `POSTAL_LOOKUP_MODE=demo-success`, the browser flow successfully:

1. Signed in as seeded customer Alex and loaded the existing cart.
2. Added one product and observed the updated cart subtotal.
3. Checked `H3N 1X1` and saw the explicitly labeled demonstration-provider result and an available local slot.
4. Signed out, opened **Create an account**, and registered a separate synthetic customer. The page confirmed account creation and linked back to sign-in.

This was a local browser rehearsal, not a live provider request or an assertion that the QA team has performed its peer run. Do not record tokens or passwords in screenshots or reports.

## Peer testing instructions

1. Check out the candidate commit and record its SHA. Install locked dependencies with `composer install` and `npm ci`. Use an isolated MariaDB server and configure `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER`, and `SCHEMA_TEST_PASS` for a user that can create and drop disposable databases and create test triggers.
2. Run the commands in the results table. Run `scripts/run-checks.php` only with `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS` and `DB_NAME` pointing to a dedicated disposable server, because it resets the named database and `foodwell_empty`.
3. For the browser walkthrough, initialize a separate disposable seeded database, configure a unique `AUTH_LIMITER_SECRET`, and start the application. Enable the local postal demo settings above. Use seeded synthetic accounts; disable demo mode after the rehearsal.
4. Check customer registration, sign-in, own cart, a denied cross-user request, a cart change, delivery lookup and sign-out. Use the scripts and [security testing guide](SECURITY_TESTING.md) for repeatable API and rejected-write checks. Never paste tokens into evidence.
5. If observed results differ, capture the tested SHA, fixture, command or request, expected and actual result, request ID and affected database state. Record a bug and preserve retest evidence under #66; feed setup findings into #67.

## Remaining work

The agreed release-candidate SHA, clean dependency install, full team peer testing and deployment validation remain to be established under #66/#67. #47 still needs its cross-team security evidence formally linked, and deferred representation work remains tracked under #65. Later defects and the November 27 QA report/retests must use their actual snapshots.
