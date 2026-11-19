# Foodwell API

> For automated checks and local test setup, see the [testing guide](docs/TESTING.md).

Foodwell is a PHP/MariaDB REST API for an online grocery store. Customers browse products, manage carts and place delivery orders; staff manage stock and fulfillment.

## Current status

### Continuous integration

Pushes to `main` and pull requests targeting `main` run the GitHub Actions workflow on PHP 8.0 and 8.2 with an isolated MariaDB service. It installs locked Composer and npm dependencies, then runs unit, client-session, Newman, schema/upgrade, authentication, authorization, domain, representation and concurrency checks. The suite uses synthetic data and delivery-provider doubles; it does not verify a live provider, deployed environment or every manual browser flow.

To run the integration suite locally, use a **dedicated disposable MariaDB server** with permission to create and drop databases. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` and the corresponding `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER`, `SCHEMA_TEST_PASS`, then set `FOODWELL_DISPOSABLE_TESTS=1` and run `composer test:integration`. The original product regression resets `DB_NAME` and `foodwell_empty`; never point the suite at shared or production data. Run `composer test:unit` and `node --test tests/client/*.test.js` separately. Individual scripts remain available for focused work.

The database has **14 tables** and the [OpenAPI contract](docs/openapi.yaml) documents **48 operations** with all **48 implemented**.

Implemented features include products, categories, delivery slots, delivery-area lookup, customer accounts, saved addresses, favorites, product reviews, inventory adjustments, carts, checkout and order history. Protected routes require a bearer session and return **401** without a working login or **403** for the wrong account or role. See the [permission matrix](docs/SECURITY_ROUTE_MATRIX.md).

Customers can manage their own saved addresses at `/customers/{id}/addresses` and pass an `addressId` at checkout. An order stores the delivery address as a snapshot. Product reviews are public to read. Customers can write one review per product and edit or delete their own reviews. Administrators can also delete reviews. Both lists accept `page` and `limit`.

Customers can save and remove favorites from the shop and browse their saved products. The owner-only favorites API supports paginated `GET /customers/{id}/favorites` (page defaults to 1, limit to 20, maximum 100) and idempotent `PUT` and `DELETE /customers/{id}/favorites/{productId}`. Saving an unknown product returns 404; removing an absent favorite returns 204. Staff and administrators cannot access another customer's favorites. Product changes are reflected in the list; unavailable products remain visible until removed.

Run `php scripts/check-favorites.php` against a dedicated disposable MariaDB server configured with `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER` and `SCHEMA_TEST_PASS` to verify ownership, pagination and idempotent writes. The script creates and removes its own test database.

Inventory adjustments are the only way to change a product's stock after it is created. Staff and administrators record one with `POST /products/{id}/inventory-adjustments` and a JSON body such as `{"quantityChange": 12, "reason": "restock", "note": "Tuesday delivery"}`. A restock must be positive, damage negative, and a correction can go either way. The product row is locked while the stock changes and the audit row is written in the same transaction, so two adjustments at the same moment never overwrite each other. The acting account and the before/after balances come from the server. A change that would take stock below 0 or above 1,000,000 returns 409 and saves nothing. History is read with paginated `GET /products/{id}/inventory-adjustments` (newest first, limit 20 by default, maximum 100) and `GET /inventory-adjustments/{adjustmentId}`. Adjustments cannot be edited or deleted; fix a mistake with a new `correction`. Customers get 403. Run `php scripts/check-inventory-adjustments.php` with the same `SCHEMA_TEST_*` settings to check permissions, validation, stock limits, pagination and 10 parallel requests (66 checks). `composer test:inventory` runs [the inventory Postman collection](requests/Foodwell_Inventory_Adjustments.postman_collection.json) with Newman on a disposable database (24 requests, 57 assertions; JUnit report in `var/test-results/inventory/`). On the Products page, staff use the **Stock** button on a product row to record an adjustment and page through its history.

Customer registration accepts JSON with `firstName`, `lastName`, `email`, `password`, and optional nullable `address`/`phone`. Names are trimmed (1-100 characters); email is trimmed and lowercased (maximum 254 characters). Passwords are 8-72 bytes, are not trimmed, and are stored using bcrypt. Registration always creates an active customer; caller-supplied roles, activation and other unknown fields are rejected. Responses never include passwords or hashes. Successful creation returns 201; malformed/non-object JSON returns 400, duplicate email 409, unsupported/missing Content-Type 415 and invalid fields 422 with `error.details`. JSON is the only implemented customer format. After registering, log in with `POST /api/v1/auth/sessions` (see [Authentication setup](#authentication-setup)).

### Customer profiles and administration

Profile reads and updates require a login as that customer or an administrator. Listing and deactivating customers require a staff or administrator login. The old `APP_CUSTOMER_PROFILE_DEMO` flag is a legacy setting and no longer changes access.

`GET /api/v1/customers/{id}` returns the saved profile or 404. `PUT /api/v1/customers/{id}` requires `firstName`, `lastName`, `email`, and accepts optional nullable `address`/`phone`; omitted optional fields are cleared. It returns the updated profile with 200. The password is neither required nor accepted; role, activation and unknown fields are rejected. Profile writes preserve credentials/privileged fields, reject duplicate email with 409 and reject invalid fields with 422. Missing IDs return 404. Errors use the shared `error` format with `details` and `requestId` (#32).

`GET /api/v1/customers` requires a staff or administrator login and returns only customer-role accounts, ordered by ID ascending. Query parameters: `page` (default 1, maximum 2147483647), `limit` (default 10, range 1-100), `search` (trimmed, up to 100 characters; case-insensitive literal substring in first/last name), and `isActive` (exactly `true` or `false`; omitted means both). Filters combine with AND. Unknown parameters, arrays and invalid values return 400 with field details.

Collection success responses contain `metadata` (`page`, `limit`, `total`, `totalPages`, `next`, `previous`) and a `data` array of profiles with `isActive`. Navigation URLs are relative, preserve both filters and limit, and retain Apache subfolder prefixes. Empty matches return 200 with zero totalPages and null links. Beyond-final pages return empty data with a previous link back to the final page when matches exist. Shared error/request-ID middleware (#32) is merged. Customer routes return JSON only; any other `Accept` gets 406.

`PATCH /api/v1/customers/{id}` requires a staff or administrator login and accepts exactly `{"isActive": false}`. It deactivates only customer-role accounts, retains profile/history records and revokes existing sessions atomically. Repeated requests return 200. Staff/admin targets return 403, missing IDs 404, invalid fields 422, malformed JSON 400 and unsupported Content-Type 415. Reactivation is not implemented.

### Lab 7 customer client

With Wamp running, open `http://localhost/foodwell-api/client/` and sign in on the Account page (staff or admin for the customer dashboard; seeded accounts and password are listed in [step 4](#4-choose-a-fresh-installation-or-an-upgrade)). Or run `php -S localhost:8081 -t client` from the repository root and visit `http://localhost:8081` to demonstrate cross-origin consumption. Expand API connection if the base URL differs. The client has filtered pagination, registration/profile forms, client validation, backend field-error feedback and a per-customer deactivation button. See [client instructions](client/README.md). Products/categories remain the other owners' client work.

API CORS middleware accepts same-origin requests and configured `CORS_ALLOWED_ORIGINS` (default: `http://localhost:8081,http://127.0.0.1:8081`). OPTIONS returns 204 before connecting to the database; allowed methods are GET/POST/PUT/PATCH/DELETE/OPTIONS and allowed headers are Accept/Content-Type/Authorization. Disallowed origins return 403; unsupported preflight methods/headers are rejected. Allowed origins receive CORS headers on errors too. No cross-origin cookie credentials are enabled. CORS does not enforce identity or ownership.

Run `php scripts/check-registration.php` for 124 isolated registration/profile/collection/deactivation/CORS HTTP and database checks. Like the schema checks below, this uses `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER` and `SCHEMA_TEST_PASS`, creates temporary databases and removes them afterward. It does not load or reset the application database. Optional `FOODWELL_TEST_ROOT` points these checks at another copy of the same application.

The expanded resources include sessions, saved addresses, favorites, reviews, manual inventory adjustments and order status history.

### Carts and checkout handoff

Cart routes require a login as that customer or an administrator (the old `APP_CART_DEMO` flag no longer changes access). GET `/api/v1/customers/{id}/cart` reads the cart; POST `/cart/items` under the same customer adds `{productId, quantity}`; PUT `/cart/items/{itemId}` sets `{quantity}`; DELETE removes the line with 204/no body. Quantity must be an integer from 1 to 1,000,000. POST increases an existing product line (200) or creates a new one (201), so do not blindly retry it. GET never creates a cart; a new customer gets an empty response with id=null until their first successful add. Clearing/removing all lines retains the reusable cart row.

Totals come from current catalog prices and are not checkout guarantees. Inactive/out-of-stock products and excessive combined quantities return 409 without partial changes. Cart edits do not change product stock. Item IDs must belong to the specified customer's cart, and the bearer session must belong to that customer (or an administrator).

Run `composer test:orders` to verify authenticated checkout on a disposable MariaDB database. For a demonstration, use the [orders and checkout collection](requests/Foodwell_Orders_Checkout.postman_collection.json) or the [checkout walkthrough](docs/CHECKOUT_DEMO.md). Checkout accepts an owned saved address, copies it onto the order, and updates the cart, stock and order history together. See the [checkout handoff](docs/CART_CHECKOUT_HANDOFF.md) for the transaction and locking rules.

Run `php scripts/check-cart.php` with the same SCHEMA_TEST settings as above: 79 checks cover persistence, rollback, stock, item scoping, repeated requests and concurrent additions in temporary databases. No schema reset/migration is needed for these endpoints if the existing 14-table foundation is installed.

### Delivery-area integration (#37)

Open `http://localhost/foodwell-api/client/delivery.html` to look up a Canadian postal area and current Foodwell delivery windows. The public `GET /api/v1/delivery-options?postalCode=H3N%201X1` combines Zippopotam.us/GeoNames area data with configured service prefixes and existing slot/order capacity. It does not verify a full address or reserve a slot, and requires no database migration. Example prefixes H3N/H3S/H3R are classroom demo rules, not a real store delivery promise.

PHP consumption: `php scripts/delivery-client.php "H3N 1X1"` calls the same composite API. PHP cURL is required. On Windows, configure `POSTAL_CA_BUNDLE` in `.env` if PHP lacks a trusted CA bundle; never disable TLS verification. See [provider/setup/failure details](docs/DELIVERY_AREA_INTEGRATION.md) for attribution, caching, data sources and explicit local demo modes.

Run `php scripts/check-delivery.php` with the SCHEMA_TEST settings above: 62 checks use temporary databases and labeled doubles. Add `--live` for 69 checks including real provider calls (working HTTPS required). Provider failure gives a clearly marked partial response with unknown eligibility, rather than invented location or slot data. Live/cached/demo results are labeled separately.

### Delivery slots (#39)

`GET /api/v1/delivery-slots` lists delivery windows by start time (public, no login). Filters: `date=YYYY-MM-DD` (UTC day the slot starts) and `available=true|false`. `GET /delivery-slots/{id}` returns one slot. Each slot has `startsAt`/`endsAt` in UTC, `capacity`, `bookedCount` (non-cancelled orders), `availableCapacity`, `isActive` and `isAvailable` (active, in the future and not full, the same rule checkout uses).

`POST`, `PUT` and `DELETE` require a staff or admin bearer session (401 without one, 403 for customers). Body: `{"startsAt": "2026-11-20T19:00:00Z", "endsAt": "2026-11-20T21:00:00Z", "capacity": 4, "isActive": true}`. Times need `Z` or `+hh:mm`; windows are at most 12 hours, start in the future and at most 365 days ahead; capacity is 1-1000. Once orders are booked, a slot's window cannot move and its capacity cannot drop below the bookings (409). Deactivate with `"isActive": false` to stop new bookings. Slots any order has used cannot be deleted (409).

Customers attach a slot to an order at checkout: `POST /api/v1/orders` with `"deliverySlotId"`. Checkout refuses inactive, past or full slots with 409.

Run `php scripts/check-delivery-slots.php` with the SCHEMA_TEST settings above: 71 checks in a temporary database cover filters, permissions, validation, checkout booking, update/delete rules and cleanup. No migration is needed.

### Catalog permissions (#40)

Anyone can read products, categories and delivery slots. Creating, updating or deleting them requires a bearer login for a **staff** or **admin** account: no login, a broken token, or an expired, logged-out or deactivated session returns **401** with `WWW-Authenticate: Bearer`; a customer login returns **403**. The permission check runs before the request body is read, and a refused request changes nothing.

Run `php scripts/check-catalog-authorization.php` with the SCHEMA_TEST settings above: 251 checks cover all nine catalog/slot/inventory write routes (3 product, 1 inventory adjustment, 2 category, 3 slot), confirm each is wrapped in the staff check in `public/index.php`, and verify public reads. `check-products.php` (81 checks) logs in as the seeded staff account for its write checks. The inventory-adjustment reads and writes have their own denial tests in `check-inventory-adjustments.php`.

### Catalog failure and integrity checks (#41)

Run `php scripts/check-catalog-integrity.php` with the SCHEMA_TEST settings above. Its 235 checks cover bad list queries (including repeated and unknown keys, which now return 400), paging with tied sort values, broken bodies, invalid fields, unsupported media types and methods, foreign-key failures (also when the data changes mid-request), and a forced server error whose request id matches the log. Every refused write is checked to leave the catalog tables unchanged. Results and the bugs it found are in [docs/CATALOG_INTEGRITY.md](docs/CATALOG_INTEGRITY.md).

## Security preparation

The [security design](docs/SECURITY_DESIGN.md) and [48-operation permission matrix](docs/SECURITY_ROUTE_MATRIX.md) define roles, ownership, account administration and opaque-session behavior. The matrix records the enforced rules for implemented routes.

## Team responsibilities

| Member | Features | Shared work |
|---|---|---|
| Will (@Wlszn) | Products, inventory adjustments, delivery slots | Response formats and documentation review |
| Sean (@seanlussier1) | Customers, sessions, carts, favorites | Authentication helpers and external integration |
| Mohamed Amine (@MohAmine2006) | Categories, checkout, orders, addresses, reviews, order history | Errors, logging and transactions |

Each owner supplies database changes, validation, permissions, tests, OpenAPI and a client demonstration. Will reviews Sean; Sean reviews Mohamed; Mohamed reviews Will.

[Project board](https://github.com/users/seanlussier1/projects/5). Use All work, Bugs, Build 2 and Build 3. Bugs need reproduction steps, expected/actual behavior and retest evidence.

## Requirements

- PHP 8+ with PDO, `pdo_mysql`, cURL, `mbstring` and `xmlwriter`. Enable these extensions in the PHP configuration used by both your web server and command line.
- MariaDB, Composer, and Git.
- WampServer on Windows, or an equivalent PHP/MariaDB installation. Apache is optional when using the PHP development server.

Run commands from the Foodwell repository folder. Confirm `php --version` and `composer --version` work in your terminal. Start WampServer and wait for its icon to turn green.

## First-time setup on Windows / Wamp

### 1. Get the code and dependencies

For a new checkout:

```powershell
git clone https://github.com/seanlussier1/Foodwell.git
cd Foodwell
composer install
```

If you already have the project, open its folder and run `composer install`. These instructions describe `main`.

### 2. Create a local database account

Open phpMyAdmin, sign in as a MariaDB administrator, and import `database/create-user.sql`. Review/change its development password before importing. The script grants access to `foodwell` and the isolated `foodwell_empty` fixture.

If the `foodwell` account already exists, `CREATE USER IF NOT EXISTS` does not reset its password. Use the account's actual password in the next step.

### 3. Configure the connection

```powershell
Copy-Item .env.example .env
```

Edit `.env` to match your machine:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3307
DB_NAME=foodwell
DB_USER=foodwell
DB_PASS=change_me
APP_BASE_PATH=
```

This Wamp installation uses MariaDB port **3307**. Other machines may use **3306**; check your MariaDB settings. `change_me` matches the unedited development account script; replace it if you changed the password. Never commit `.env`.

Leave `APP_BASE_PATH` empty for `composer start`. The template uses portable defaults, so adjust the port before connecting.

### 4. Choose a fresh installation or an upgrade

**Fresh or disposable development database:**

```powershell
composer db:setup
```

**This deletes all project tables in DB_NAME and reloads synthetic data.** Confirm the database name and back up any records you need before running it.

The seed creates 4 categories, 10 products, 4 demo accounts, 2 inactive sessions, 2 carts, 2 cart items, 4 delivery slots, 5 orders, 6 order items, 2 addresses, 3 favorites, 2 reviews, 1 inventory adjustment and 5 order baseline events. All demo accounts use `FoodwellDemo!2026`, stored as a password hash: `alex@example.com` and `sam@example.com` (customers), `staff@example.com` (staff) and `admin@example.com` (administrator). Log in with `POST /api/v1/auth/sessions`. These accounts are only for local demonstrations.

**Existing database with records to preserve:** back up first, select that database in phpMyAdmin, then use its Import tab:

| Existing schema | Import in order |
|---|---|
| Original two-table Build 1 | `database/migrations/002_full_project.sql`, then `database/migrations/003_project_expansion.sql` |
| Nine-table foundation | Only `database/migrations/003_project_expansion.sql` |
| Expanded 14-table schema | No migration needed |

Run each migration once. Do not import `schema.sql` or the full seed over existing data. MariaDB schema changes commit automatically; restore your backup if an upgrade fails partway. Migration 003 adds baseline events for existing order states without inventing their earlier history.

### 5. Start and check the application

```powershell
composer start
```

Open [the product page](http://localhost:8080/), load the product list, then look up product `101`. In phpMyAdmin, select `foodwell` to inspect the 14 tables and seeded records.

For Apache instead, set the site DocumentRoot to `public/`, enable `mod_rewrite`, and allow the supplied `.htaccess`. If using an Apache subfolder URL, set `APP_BASE_PATH` to that URL prefix, such as `/foodwell-api`.

## Verification

**Isolated database checks:**

```powershell
$env:SCHEMA_TEST_PORT = '3307'
$env:SCHEMA_TEST_USER = 'root'
php scripts/check-schema.php
```

The test account needs CREATE/DROP DATABASE privileges. It defaults to an empty password; set `SCHEMA_TEST_PASS` locally if needed. The checks do not load `.env` or modify its configured application database. They create a random temporary database and remove it afterward.

The expanded schema passed 72 checks covering migrations, data preservation, fresh/upgraded definitions, seed relationships, constraints and existing product repository response compatibility. The 45-operation document also passed OpenAPI 3.0 validation. These are foundation checks, not evidence that planned endpoints work.

**Original API checks:**

```powershell
composer check
```

This resets the configured application database to the synthetic seed and resets `foodwell_empty`, then starts temporary servers to check item 200, item 404, populated list 200 and empty list 200. Back up data first. Do not run this against a database containing records you need to preserve.

## Database and implementation rules

[Database design](database/README.md) explains keys and relationships. The old `database/ERD.png` depicts Build 1 only.

- Address selection must verify ownership and copy the delivery address into the order.
- Cart/checkout totals and stock are calculated server-side in a transaction.
- Manual stock changes write an audit record with the actor and before/after balances. Products with audit history are deactivated instead of deleted.
- Order changes append status events atomically; cancellation restores stock only once.
- Resource-level roles, ownership checks and immutable audit API behavior still require application code.

Request path: browser → `public/index.php` → router → controller → repository → MariaDB → response.

## Authentication setup

Set `AUTH_LIMITER_SECRET` to a unique, randomly generated secret of at least 32 characters in the environment or `.env`. Keep it private and consistent across all application workers. Without it login returns 503. Apply `database/migrations/004_auth_login_limits.sql` to existing databases; fresh schema includes it. Expire old limiter entries using an operator scheduled task such as `DELETE FROM auth_login_limits WHERE window_start < UTC_TIMESTAMP() - INTERVAL 7 DAY`. Protect database access and logs. Login uses REMOTE_ADDR, not forwarded headers. Use HTTPS outside local development.

Customer, cart and order routes use these sessions for their permission checks; see [Authorization (#46)](#authorization-46).

Run the isolated authentication checks with `php scripts/check-auth.php`; configure SCHEMA_TEST_HOST, SCHEMA_TEST_PORT, SCHEMA_TEST_USER and SCHEMA_TEST_PASS for a disposable MariaDB server. The test account needs permission to create and drop a randomly named test database.


## Authorization (#46)

Customer profile, cart, checkout and order detail routes require a valid bearer session and the documented owner or administrator rule. Customer listing, deactivation, product writes and category writes require staff or administrator. The former loopback demo flags do not bypass these checks. The browser workspace uses the Account page to sign in, and retains the token in memory while navigating. All check scripts sign in where needed (updated in #40-#42 and #101). Delivery-slot writes also require staff or administrator (#39/#40). Run `php scripts/check-authorization.php` with the same disposable-database configuration as the authentication checks.

## Response formats (#65)

Product, category and delivery slot routes answer in JSON, XML or YAML, chosen with the `Accept` header (`application/json`, `application/xml`, `application/yaml`); other types get 406. Request bodies are JSON on every route (415 otherwise). Customer, cart, order, session and delivery-option routes answer in JSON only and give 406 for XML/YAML. The full route-by-route contract, the negotiation rules and two documented exceptions are in the [representation matrix](docs/REPRESENTATION_MATRIX.md). Run `php scripts/check-representations.php` (same `SCHEMA_TEST_*` settings) to compare real JSON, XML and YAML responses value by value (457 checks).

## Automated catalog and delivery slot checks (#42)

`composer test:unit` now also runs `tests/catalog/ProductInputTest.php` and `tests/catalog/DeliverySlotInputTest.php` (58 tests, no database): every product and slot field rule, inclusive limits, time-zone conversion to UTC, impossible dates, and the create/update differences.

Run `composer test:catalog` (needs `npm ci` and the same `SCHEMA_TEST_*` settings as `composer test:api`). It creates a disposable MariaDB database and API server, then:

1. runs `tests/catalog/CatalogRepositoryTest.php` (17 tests) against the real database: product search, paging totals, sort tie-breakers, LIKE escaping and foreign keys; category listing, case-insensitive uniqueness and the three delete outcomes (also inside an outer transaction); slot booking counts, filters, UTC output, duplicate windows and row locking;
2. reloads the seed data and runs `requests/Foodwell_Catalog_Slots.postman_collection.json` with Newman (44 requests, 103 assertions): public reads, filters, paging links, XML, 401/403 on writes, 422, 409 conflicts, attaching a slot at checkout and every error's request id.

JUnit reports are written to `var/test-results/catalog/` (git-ignored). The test file refuses to run against any database not named `foodwell_newman_<random>`.

To rehearse the catalog and delivery slot demo, run `php scripts/demo-catalog-rehearsal.php` (same `SCHEMA_TEST_*` settings): it plays the demo in presentation order on a disposable database and prints each status and the database state.

## Automated customer, session and cart checks (#48)

Install development dependencies with `composer install` and `npm ci` (PHP 8.0 or newer, including PDO SQLite for the unit tests, and Node.js). Run `composer test:unit` for validation and password-hashing checks. The repository test uses an in-memory SQLite database; it does not replace the MariaDB API checks.

Run `composer test:api` for the Newman collection against a temporary PHP server and randomly named disposable MariaDB database. Configure `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER`, and `SCHEMA_TEST_PASS` for a test server; the database user must be able to create and drop a database. The runner seeds synthetic data and deletes only the database it created. The collection registers a synthetic account, checks login errors and ownership, modifies that account's cart, logs out, and verifies revocation. It does not export session tokens or alter the configured application database. The collection is also available at `requests/Foodwell_Customer_Auth_Cart.postman_collection.json`; only run it directly against disposable synthetic data.
