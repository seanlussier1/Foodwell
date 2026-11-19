# Testing Foodwell

GitHub Actions runs the test workflow on pushes to `main` and pull requests targeting `main`. See the [Actions runs](https://github.com/seanlussier1/Foodwell/actions/workflows/ci.yml) for results and logs for each commit. Both the PHP 8.0 and PHP 8.2 checks and an approving review are required to merge into `main`.

## Run locally

Install the locked dependencies with `composer install` and `npm ci`. Run the independent checks with:

```sh
composer test:unit
node --test tests/client/*.test.js
```

For the integration suite, use a **dedicated disposable MariaDB server** and a test account permitted to create and drop databases. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER`, and `SCHEMA_TEST_PASS` to the test server. Then set `FOODWELL_DISPOSABLE_TESTS=1` and run `composer test:integration`.

The original product regression resets the database named by `DB_NAME` and `foodwell_empty`. Never point the suite at shared, personal, or production data. The remaining integration scripts create their own temporary databases. See [database setup and migrations](../database/README.md).

The integration runner includes Newman collections and checks for schema and upgrade paths, product reads and writes, authentication, authorization, registration, cart, delivery, slots, catalog integrity, concurrent orders, representations, favorites, inventory adjustments, and the independent address/review checks. Run an individual `scripts/check-*.php` script for a focused check; most use the `SCHEMA_TEST_*` settings above.

## Outside CI

The delivery tests use labeled provider doubles. A live-provider lookup, browser walkthrough, deployment verification, and manual demonstrations require separate checks. Do not record passwords, tokens, or personal data in test reports.
