# Foodwell API

> For the current clean-setup verification and known documentation gaps, see [release reproducibility and QA handoff](docs/RELEASE_REPRODUCIBILITY.md).

Foodwell is a PHP/MariaDB REST API for an online grocery store. Customers browse products, manage carts and place delivery orders; staff manage stock and fulfillment.

## Current status

The database has **14 tables** and the [OpenAPI contract](docs/openapi.yaml) documents **48 operations**. Product list/item GET, category operations, customer registration and the public delivery-area lookup work in PHP. Customer management and cart operations work as explicitly enabled local demos; authentication and role/owner/admin enforcement remain planned. Product, category and delivery-slot writes require a staff or admin login (#40). Other operations, including checkout, remain planned.

Customer registration accepts JSON with `firstName`, `lastName`, `email`, `password`, and optional nullable `address`/`phone`. Names are trimmed (1-100 characters); email is trimmed and lowercased (maximum 254 characters). Passwords are 8-72 bytes, are not trimmed, and are stored using bcrypt. Registration always creates an active customer; caller-supplied roles, activation and other unknown fields are rejected. Responses never include passwords or hashes. Successful creation returns 201; malformed/non-object JSON returns 400, duplicate email 409, unsupported/missing Content-Type 415 and invalid fields 422 with `error.details`. JSON is the only implemented customer format. Login remains planned; the local customer dashboard is described below.

### Local customer-profile demonstration

For synthetic local testing, add `APP_CUSTOMER_PROFILE_DEMO=1` to `.env` and access the API through localhost. The flag defaults to 0; profile reads/writes return 403 unless the flag is enabled and the request comes from a loopback address. This gate is not authentication: any local caller can access any profile while enabled. Keep it off on shared/deployed servers; #45/#46 must implement the documented owner/admin permissions before private deployment.

`GET /api/v1/customers/{id}` returns the saved profile or 404. `PUT /api/v1/customers/{id}` requires `firstName`, `lastName`, `email`, and accepts optional nullable `address`/`phone`; omitted optional fields are cleared. It returns the updated profile with 200. The password is neither required nor accepted; role, activation and unknown fields are rejected. Profile writes preserve credentials/privileged fields, reject duplicate email with 409 and reject invalid fields with 422. Missing IDs return 404. The existing `error.details` response format is retained until the shared #32 error work is integrated.

`GET /api/v1/customers` uses the same local demo setting and returns only customer-role accounts, ordered by ID ascending. Query parameters: `page` (default 1, maximum 2147483647), `limit` (default 10, range 1-100), `search` (trimmed, up to 100 characters; case-insensitive literal substring in first/last name), and `isActive` (exactly `true` or `false`; omitted means both). Filters combine with AND. Unknown parameters, arrays and invalid values return 400 with field details. The intended customer-list access is staff/admin once authorization is implemented.

Collection success responses contain `metadata` (`page`, `limit`, `total`, `totalPages`, `next`, `previous`) and a `data` array of profiles with `isActive`. Navigation URLs are relative, preserve both filters and limit, and retain Apache subfolder prefixes. Empty matches return 200 with zero totalPages and null links. Beyond-final pages return empty data with a previous link back to the final page when matches exist. Shared error/request-ID middleware (#32) is merged. Content negotiation (#33) remains pending; do not claim customer 406 handling is implemented.

`PATCH /api/v1/customers/{id}` accepts exactly `{"isActive": false}` in the local demo. It deactivates only customer-role accounts, retains profile/history records and revokes existing sessions atomically. Repeated requests return 200. Staff/admin targets return 403, missing IDs 404, invalid fields 422, malformed JSON 400 and unsupported Content-Type 415. Reactivation is not implemented. Intended staff/admin authorization remains planned.

### Lab 7 customer client

With Wamp running and the local demo enabled, open `http://localhost/foodwell-api/client/`. Or run `php -S localhost:8081 -t client` from the repository root and visit `http://localhost:8081` to demonstrate cross-origin consumption. Expand API connection if the base URL differs. The client has filtered pagination, registration/profile forms, client validation, backend field-error feedback and a per-customer deactivation button. See [client instructions](client/README.md). Products/categories remain the other owners' client work.

API CORS middleware accepts same-origin requests and configured `CORS_ALLOWED_ORIGINS` (default: `http://localhost:8081,http://127.0.0.1:8081`). OPTIONS returns 204 before connecting to the database; allowed methods are GET/POST/PUT/PATCH/DELETE/OPTIONS and allowed headers are Accept/Content-Type/Authorization. Disallowed origins return 403; unsupported preflight methods/headers are rejected. Allowed origins receive CORS headers on errors too. No cross-origin cookie credentials are enabled. CORS does not enforce identity or ownership.

Run `php scripts/check-registration.php` for 122 isolated registration/profile/collection/deactivation/CORS HTTP and database checks. Like the schema checks below, this uses `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER` and `SCHEMA_TEST_PASS`, creates temporary databases and removes them afterward. It does not load or reset the application database. Optional `FOODWELL_TEST_ROOT` points these checks at another copy of the same application.

The expanded resources include sessions, saved addresses, favorites, reviews, manual inventory adjustments and order status history.

### Cart demonstration and checkout handoff

Enable `APP_CART_DEMO=1` in `.env` for synthetic localhost testing. GET `/api/v1/customers/{id}/cart` reads the cart; POST `/cart/items` under the same customer adds `{productId, quantity}`; PUT `/cart/items/{itemId}` sets `{quantity}`; DELETE removes the line with 204/no body. Quantity must be an integer from 1 to 1,000,000. POST increases an existing product line (200) or creates a new one (201), so do not blindly retry it. GET never creates a cart; a new customer gets an empty response with id=null until their first successful add. Clearing/removing all lines retains the reusable cart row.

Totals come from current catalog prices and are not checkout guarantees. Inactive/out-of-stock products and excessive combined quantities return 409 without partial changes. Cart edits do not change product stock. Item IDs must belong to the specified customer's cart; real session-based ownership remains pending #45/#46. The cart demo flag is separate from the profile demo flag and defaults off.

Import [the saved Postman demonstration](requests/Foodwell_Cart.postman_collection.json) to show the workflow using a newly created synthetic customer. Read [the checkout handoff](docs/CART_CHECKOUT_HANDOFF.md) for the shared lock order, cart-clearing rules and Mohamed's #36 transaction requirements. His confirmation is still required before #35 is Done. No checkout is implemented here.

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

Run `php scripts/check-catalog-authorization.php` with the SCHEMA_TEST settings above: 250 checks cover all eight catalog/slot write routes, confirm each is wrapped in the staff check in `public/index.php`, and verify public reads. `check-products.php` now logs in as the seeded staff account for its write checks. Planned inventory-adjustment routes are not implemented, so they are not claimed as protected.

## Security preparation

The [security design](docs/SECURITY_DESIGN.md) and [48-operation permission matrix](docs/SECURITY_ROUTE_MATRIX.md) define intended roles, ownership, account administration and opaque-session behavior for #63. They distinguish intended permissions from current public/local-demo access and link Build 3 enforcement to #40/#45/#46/#47. The draft still requires Will's and Mohamed's resource-rule reviews; this documentation does not implement login or authorization. Catalog and slot writes are now staff/admin-only (#40, see below). Link the final reviewed design/commit from the #64 Build 2 manifest before marking #63 Done.

## Team responsibilities

| Member | Features | Shared work |
|---|---|---|
| Will (@Wlszn) | Products, inventory adjustments, delivery slots | Response formats and documentation review |
| Sean (@seanlussier1) | Customers, sessions, carts, favorites | Authentication helpers and external integration |
| Mohamed Amine (@MohAmine2006) | Categories, checkout, orders, addresses, reviews, order history | Errors, logging and transactions |

Each owner supplies database changes, validation, permissions, tests, OpenAPI and a client demonstration. Will reviews Sean; Sean reviews Mohamed; Mohamed reviews Will.

[Project board](https://github.com/users/seanlussier1/projects/5). Use All work, Bugs, Build 2 and Build 3. Bugs need reproduction steps, expected/actual behavior and retest evidence.

## Requirements

- PHP 8+ with PDO, `pdo_mysql` and cURL for the external lookup/consumer.
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

If you already have the project, open its folder and run `composer install`. These instructions describe the expanded version; use the branch containing this change until it is merged into main.

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

The seed creates 4 categories, 10 products, 4 demo accounts, 2 inactive sessions, 2 carts, 2 cart items, 4 delivery slots, 5 orders, 6 order items, 2 addresses, 3 favorites, 2 reviews, 1 inventory adjustment and 5 order baseline events. All demo accounts use `FoodwellDemo!2026`, stored as a password hash. Login is still planned. These accounts are only for local demonstrations.

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

Open [the product page](http://localhost:8080/), load the product list, then look up product `101`. In phpMyAdmin, select `foodwell` to inspect the 14 tables and seeded records. Newly planned endpoints will not work until their PHP implementation is added.

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

The session endpoints do not secure existing customer, cart or order routes; authorization is tracked separately in #46.

Run the isolated authentication checks with `php scripts/check-auth.php`; configure SCHEMA_TEST_HOST, SCHEMA_TEST_PORT, SCHEMA_TEST_USER and SCHEMA_TEST_PASS for a disposable MariaDB server. The test account needs permission to create and drop a randomly named test database.


## Authorization (#46)

Customer profile, cart, checkout and order detail routes require a valid bearer session and the documented owner or administrator rule. Customer listing, deactivation, product writes and category writes require staff or administrator. The former loopback demo flags do not bypass these checks. The browser workspace uses the Account page to sign in, and retains the token in memory while navigating. Some older local-demo test scripts still require updating. Run `php scripts/check-authorization.php` with the same disposable-database configuration as the authentication checks.

## Automated customer, session and cart checks (#48)

Install development dependencies with `composer install` and `npm ci` (PHP 8.0 or newer, including PDO SQLite for the unit tests, and Node.js). Run `composer test:unit` for validation and password-hashing checks. The repository test uses an in-memory SQLite database; it does not replace the MariaDB API checks.

Run `composer test:api` for the Newman collection against a temporary PHP server and randomly named disposable MariaDB database. Configure `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER`, and `SCHEMA_TEST_PASS` for a test server; the database user must be able to create and drop a database. The runner seeds synthetic data and deletes only the database it created. The collection registers a synthetic account, checks login errors and ownership, modifies that account's cart, logs out, and verifies revocation. It does not export session tokens or alter the configured application database. The collection is also available at `requests/Foodwell_Customer_Auth_Cart.postman_collection.json`; only run it directly against disposable synthetic data.
