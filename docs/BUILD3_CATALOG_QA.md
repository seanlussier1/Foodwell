# Build 3 catalog and delivery slot QA readiness (#44)

Will Kerdy Elveus · November 18, 2026

This is the catalog, category and delivery slot handoff for the November 19 candidate. It supports the team QA plan [#66](https://github.com/seanlussier1/Foodwell/issues/66) and the clean-checkout release check [#67](https://github.com/seanlussier1/Foodwell/issues/67). It records one clean run and a scripted rehearsal; it is not the team's final acceptance, a peer test or a deployment test.

## Tested snapshot and environment

- GitHub `main` at `a6731d215453e4dcbc80a6b38b6f3b7a3423b749` (PR #107). The source was exported with `git archive` into an empty directory, so no working-copy files were included. PHP and Node dependencies were installed from the committed lockfiles with `composer install` and `npm ci` (npm printed 4 deprecation warnings for transitive test dependencies).
- Ubuntu 24.04, PHP 8.3.6, MariaDB 10.11.14, Node 22.23.4, npm 10.9.9, Composer 2.8.12 and PHPUnit 9.6.39. Sean's (#49) and Mohamed's (#54) runs used Windows with PHP 8.0 and 8.2, so this run adds a second platform rather than repeating theirs.
- **PHP 8.0 was not executed in this run.** `composer.json` allows PHP 8.0, so the catalog, category and slot files from #39-#43 were scanned for PHP 8.1+ syntax and functions (enums, `readonly`, `never`, first-class callables, `array_is_list` and others); none were found. A PHP 8.0 run of `composer test:catalog` is still recommended before the demo.
- All databases were on a dedicated local MariaDB instance. The check scripts create and drop their own randomly named databases from `database/schema.sql` and the synthetic `database/seed.sql`. `scripts/run-checks.php` resets the configured `foodwell_qa44` and `foodwell_empty` databases, so it was pointed only at that disposable instance. Delivery provider behaviour used the labelled test doubles; no live provider or deployed server was tested.

## Clean-run results

All 17 suite commands exited 0, and the rehearsal passed. Exit codes are in [results.json](qa/issue-44/results.json) and each command's full output is beside it.

| Command | Observed result |
| --- | --- |
| `composer test:unit` | Pass: 67 tests, 104 assertions (including 58 catalog/slot validation tests from #42) |
| `php scripts/run-checks.php` | Pass: 4/4 original product GET checks, including the empty database |
| `composer test:api` | Pass: 12 customer/auth/cart requests, 12 assertions |
| `composer test:orders` | Pass: 2 stock tests/6 assertions, 3 MariaDB tests/83 assertions, 14 requests/21 assertions |
| **`composer test:catalog`** | **Pass: 17 repository tests/56 assertions against MariaDB, then 38 Newman requests/90 assertions** |
| `php scripts/check-schema.php` | Pass: 72 checks |
| `php scripts/check-upgrade-paths.php` | Pass: all three upgrade paths matched the fresh schema and kept their rows |
| **`php scripts/check-products.php`** | **Pass: 80 checks** |
| `php scripts/check-auth.php`, `php scripts/check-authorization.php` | Pass: 13 and 100 checks |
| `php scripts/check-registration.php`, `php scripts/check-cart.php` | Pass: 124 and 79 checks |
| `php scripts/check-delivery.php` | Pass: 62 checks with labelled provider doubles |
| **`php scripts/check-delivery-slots.php`** | **Pass: 71 checks** |
| **`php scripts/check-catalog-authorization.php`** | **Pass: 250 checks** |
| **`php scripts/check-catalog-integrity.php`** | **Pass: 235 checks** |
| `php scripts/check-order-concurrency.php` | Pass: competing checkouts and deactivation/cart-write races |
| **`php scripts/demo-catalog-rehearsal.php`** | **Pass: the full demo flow below, with database state at each step** |

Bold rows are Will's areas. The Composer entries were run through their underlying PHP commands so the JUnit files could be saved. The evidence files contain no session tokens, secrets, passwords or password hashes, and absolute paths were removed from the JUnit reports.

### Contract and format evidence

- **OpenAPI against the running API:** the #43 audit ([DOCUMENTATION_AUDIT.md](DOCUMENTATION_AUDIT.md)) replayed the catalog, customer and order collections and validated all 64 JSON responses against the documented schemas with no mismatches. It also confirmed that all 32 routes match their documented access rules.
- **Formats in Will's areas** (at the tested snapshot): product reads returned JSON, XML or YAML; category and slot routes were JSON only; bodies were JSON only. **Updated by #65:** all product, category and delivery slot routes now answer in JSON, XML or YAML, and bodies stay JSON-only by agreement. See [REPRESENTATION_MATRIX.md](REPRESENTATION_MATRIX.md) and `php scripts/check-representations.php`.

### Evidence files

- [results.json](qa/issue-44/results.json) and one `.txt` output per command in [qa/issue-44](qa/issue-44/)
- JUnit: [unit tests](qa/issue-44/phpunit-unit.xml), [catalog repository tests](qa/issue-44/phpunit-catalog-repositories.xml), [catalog Newman run](qa/issue-44/newman-catalog.xml)
- [Rehearsal transcript](qa/issue-44/catalog-rehearsal.txt)

## Demo rehearsal

`php scripts/demo-catalog-rehearsal.php` plays the demo in presentation order against its own disposable database, prints each request with its status and the database counts at key moments, and stops at the first unexpected result. Run it before presenting to make sure your machine is ready. The [transcript](qa/issue-44/catalog-rehearsal.txt) from this run shows:

1. **Browsing without logging in.** Dairy sorted by price gives page 1 of 2 with a `next` link that keeps the filters. Product 101 is returned as XML when asked for XML. Only slot 304 is bookable.
2. **Who may change the catalog.** Alex (customer) and Taylor (staff) sign in through `POST /auth/sessions`. Creating a product with no login gives 401 with `WWW-Authenticate: Bearer`; as Alex it gives 403. The database counts are unchanged.
3. **Staff manages the catalog.** A new Frozen category is created (201); the same name in another case is a 409. A product is created in it (201 with `Location`), then repriced and deactivated (200; stock unchanged). The category can't be deleted while it has a product (409), and Basmati Rice can't be deleted because it has stock history (409). After the new product is deleted (204), the category deletes too (200), and the counts return to the seed values.
4. **Delivery slots and checkout.** Staff creates a 2-hour slot 5 days ahead (201). Alex checks out with it (201), and the slot then shows 1 booked and 1 place left. Moving its window is refused (409), deactivating it is allowed and keeps Alex's booking (200), and deleting it is refused (409). The database confirms the order still points at the slot.
5. **Bad input.** A repeated `sort` key gives a 400 that names the problem, with the same request id as `X-Request-ID`. A slot that ends before it starts with capacity 0 gives one 422 naming both fields.

### Presenting it live in Postman

1. Reset a **disposable** database with `php scripts/db-setup.php`, start the API with `composer start`, and import [the catalog collection](../requests/Foodwell_Catalog_Slots.postman_collection.json). Set the `baseUrl` variable to `http://localhost:8080/api/v1` (or your Wamp URL).
2. Run the two "Sign in" requests first: they store the staff and customer tokens in collection variables. The collection follows the same story as the rehearsal, so you can send its requests in order and show each response's status and test results.
3. Keep phpMyAdmin open on the same database to show the rows changing (new product, slot booking) and not changing (after the 401/403 and 409 responses).

## Peer testing

1. Check out the tested commit (or record the newer commit you test). Run `composer install` and `npm ci`. Set `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER` and `SCHEMA_TEST_PASS` for a disposable MariaDB server whose user can create and drop databases and create triggers (the integrity checks use temporary triggers).
2. Run `composer test:unit` and `composer test:catalog` (JUnit reports go to `var/test-results/catalog/`), then `php scripts/check-products.php`, `php scripts/check-delivery-slots.php`, `php scripts/check-catalog-authorization.php` and `php scripts/check-catalog-integrity.php`.
3. Run `php scripts/demo-catalog-rehearsal.php` and compare its output with the [saved transcript](qa/issue-44/catalog-rehearsal.txt). Ids can differ if the seed changes; the statuses and database counts should match.
4. If anything differs, record the commit, your PHP/MariaDB versions, the request method and URL, expected and actual status, the `requestId`, and the relevant database state. Never include session tokens. Send it to the #66 QA owner, and send setup problems to #67.

## Updates after the tested snapshot (November 19, 2026)

These were added after the snapshot above and are not part of its evidence files. They were run against `main` at `ab08026` (PR #115) on the same Ubuntu/PHP 8.3/MariaDB 10.11 setup.

- **Inventory adjustments (PR #113):** `POST` and `GET /products/{id}/inventory-adjustments` and `GET /inventory-adjustments/{adjustmentId}`, staff and administrators only. `php scripts/check-inventory-adjustments.php` passes 66 checks, including 10 parallel removals that never take stock below 0. `composer test:inventory` runs the new [inventory collection](../requests/Foodwell_Inventory_Adjustments.postman_collection.json) (24 requests, 57 assertions).
- **Products page:** a **Stock** button on each row opens the adjustment form and history (see `client/README.md`).
- **Updated counts:** `check-catalog-authorization.php` now covers 9 write routes (251 checks) because the inventory POST lives under `/products`. `check-products.php` passes 81 checks after its alias test was updated to the #111 negotiation rule (a `text/xml` request is answered as `text/xml`). `check-representations.php` passes 457 checks. The catalog collection run by `composer test:catalog` has grown to 44 requests and 103 assertions.
- All other check scripts, `composer test:unit` (69 tests), `test:api`, `test:catalog`, `test:orders` and `demo-catalog-rehearsal.php` passed at `ab08026`.

## Open items

- PHP 8.0 has not been executed for the catalog, category and slot code (static scan only, see above).
- XML/YAML for catalog routes was completed afterwards in #65 (not part of the tested snapshot above).
- Minor behaviour noted in [CATALOG_INTEGRITY.md](CATALOG_INTEGRITY.md) (category POST ignores unknown fields and has no `Location` header; `HEAD` returns 405) is unchanged.
- Peer execution and the November 27 retests are pending.
