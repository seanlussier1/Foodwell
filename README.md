# Foodwell API

Foodwell is a PHP/MariaDB REST API for an online grocery store. Customers browse products, manage carts and place delivery orders; staff manage stock and fulfillment.

## Current status

The database has **14 tables** and the [OpenAPI contract](docs/openapi.yaml) documents **45 operations**. Only `GET /api/v1/products` and `GET /api/v1/products/{id}` currently work in PHP. All other operations are marked planned. Creating tables does not implement authentication or the remaining endpoints.

The expanded resources include sessions, saved addresses, favorites, reviews, manual inventory adjustments and order status history.

## Team responsibilities

| Member | Features | Shared work |
|---|---|---|
| Will (@Wlszn) | Products, inventory adjustments, delivery slots | Response formats and documentation review |
| Sean (@seanlussier1) | Customers, sessions, carts, favorites | Authentication helpers and external integration |
| Mohamed Amine (@MohAmine2006) | Categories, checkout, orders, addresses, reviews, order history | Errors, logging and transactions |

Each owner supplies database changes, validation, permissions, tests, OpenAPI and a client demonstration. Will reviews Sean; Sean reviews Mohamed; Mohamed reviews Will.

[Project board](https://github.com/users/seanlussier1/projects/5). Use All work, Bugs, Build 2 and Build 3. Bugs need reproduction steps, expected/actual behavior and retest evidence.

## Requirements

- PHP 8+ with PDO and `pdo_mysql`.
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
- Session validation, roles, ownership checks and immutable audit API behavior still require application code.

Request path: browser → `public/index.php` → router → controller → repository → MariaDB → response.
