# Release reproducibility and QA handoff (#67)

## Snapshot and limits

- Tested GitHub `main` commit: `adf29bdeca43ce09933ba1776bef37d8d3fbf311` (PR #101). This is a reproducible snapshot, **not an agreed final release candidate**. Team owners must confirm the candidate SHA and repeat any checks affected by later merges.
- Verified on November 18, 2026, in a fresh local checkout, using Windows, PHP 8.0.30, MariaDB 11.4.9, Node 24.11.1 and the committed Composer/npm lockfiles. `composer install` and `npm ci` succeeded; npm reported deprecation warnings for transitive test dependencies.
- Fixture Git blob IDs: schema `eedd8a13ee9061fece749852863849261f088796`, seed `61df6c260cdac97d42d604d91f944d8627cdd6b8`, Composer lock `9846c8cd68e1980f43dab9728e36d1a00f1a4598`, npm lock `7e23d92b3d61327b086714f2d0840fc51e074600`. Seed accounts, addresses, orders and credentials are synthetic. Seed delivery dates are relative to import time.
- Dependencies were installed from the lockfiles into the clean checkout. The upgrade-path verifier in this change was added to the tested snapshot without modifying application runtime files.

## Recreate the setup

1. Check out the agreed commit in a new directory and record `git rev-parse HEAD`. Install PHP 8.0+ with PDO MySQL and cURL; development tests also need PDO SQLite. Install Composer, Node/npm and MariaDB. From the checkout run `composer install` and `npm ci`.
2. Use a **dedicated disposable MariaDB server** for these checks. On that server, an administrator may run `database/create-user.sql` to create the documented development user and database-scoped grants. Replace its example password in your local setup and do not commit credentials. The test user used for the full QA run had permission to create and drop test databases and create triggers; those permissions are broader than the normal application user's database-scoped grants and are for the isolated server only.
3. Copy `.env.example` to `.env` and set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` and a unique `AUTH_LIMITER_SECRET` of at least 32 characters. Keep secrets out of Git and evidence. Set `APP_BASE_PATH` only when serving from a subfolder. The old `APP_CUSTOMER_PROFILE_DEMO` and `APP_CART_DEMO` values are not authentication bypasses in this snapshot.
4. **Only on a disposable development database**, run `composer db:setup`. This resets all project tables and loads the synthetic seed. For an empty fixture use `php scripts/db-setup.php <disposable_name> --empty`. `composer check` also resets the configured database and `foodwell_empty`; never point it at shared or production data.
5. Start with `composer start` or the documented Wamp setup. For a separate browser origin, configure `CORS_ALLOWED_ORIGINS` explicitly. Sign in with a synthetic seeded customer only in the disposable environment. Tokens are in-memory and must not be included in evidence.
6. For an existing database, first back it up. A two-table Build 1 database needs migrations 002, 003 and 004; a nine-table database needs 003 and 004; a fourteen-table database needs 004. Apply each once, in order. MariaDB DDL can commit implicitly; restore the backup if an upgrade fails. Never run the reset schema as an upgrade.

## Database verification

`scripts/check-upgrade-paths.php` was run against the isolated server. It creates four randomly named disposable databases and compares normalized `SHOW CREATE TABLE` definitions while checking pre-upgrade rows:

| Starting point | Migrations | Result |
| --- | --- | --- |
| Build 1 categories/products with seeded catalog | 002 → 003 → 004 | Pass: original rows retained; final definitions match fresh schema. |
| Nine-table schema with synthetic records | 003 → 004 | Pass: all existing table rows retained; final definitions match fresh schema. |
| Fourteen-table schema with synthetic records | 004 | Pass: all existing table rows retained; final definitions match fresh schema. |

The verifier creates and drops only the databases it creates. Run with `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER`, and `SCHEMA_TEST_PASS` pointing to a disposable server. `scripts/check-schema.php` additionally passed its 72 schema/seed checks. The documented schema-scoped development user was provisioned on the isolated server, used to initialize `foodwell`, and successfully served product reads, synthetic customer sign-in/cart reads and a labeled postal demonstration. This is a development-user smoke check, not a production privilege audit.

## Release checks on the snapshot

All 14 checks below exited successfully. Output and exit codes are in [the issue #67 evidence directory](qa/issue-67/results.json); the upgrade output is [upgrade-paths.txt](qa/issue-67/upgrade-paths.txt).

| Check | Observed result |
| --- | --- |
| PHPUnit unit suite | 9 tests, 27 assertions passed. |
| Original product GET regression | 4/4 passed, including an empty fixture. |
| Order/checkout PHPUnit and Newman | 2 stock tests/6 assertions, 3 MariaDB integrity tests/83 assertions, and 14 requests/21 assertions passed. |
| Customer/auth/cart Newman | 12 requests/12 assertions passed. |
| Schema and product checks | 72 schema checks and 79 product/representation checks passed. |
| Authentication and authorization | Both suites passed, including owner/role denials and revoked/expired tokens. |
| Customer registration and cart | 124 registration and 79 cart checks passed. |
| Delivery composition and slots | 62 delivery checks with labeled provider doubles and 71 slot checks passed. |
| Catalog authorization and order concurrency | 250 catalog authorization checks passed; competing checkout and deactivation/cart-write races passed. |

Re-run using `composer test:unit`, `composer test:api`, `composer test:orders`, the `scripts/check-*.php` commands in the evidence files, and `composer check` **only on a dedicated disposable server**. Set `SCHEMA_TEST_*` variables for isolated test databases and `DB_*` variables separately for the destructive original product check. The standard delivery checks use doubles; the application smoke used `POSTAL_LOOKUP_MODE=demo-success` and `APP_POSTAL_DEMO=1` on loopback, with the response labeled demo. A live external-provider lookup was not performed.

## Requirements and remaining work

The original [Build 2 evidence/remainder checklist #64](https://github.com/seanlussier1/Foodwell/issues/64) and [representation matrix](REPRESENTATION_MATRIX.md) are the handoff baseline. Original reads, customer/auth/cart, order and composition smoke checks were exercised as listed above. XML/YAML beyond the supported product-read formats remains explicitly deferred in the matrix and tracked by [#65](https://github.com/seanlussier1/Foodwell/issues/65); no claim is made that every documented OpenAPI operation is implemented.

The following Build 3 items were open when this snapshot was checked: catalog automated tests [#42](https://github.com/seanlussier1/Foodwell/issues/42), documentation audit [#43](https://github.com/seanlussier1/Foodwell/issues/43), Will's QA rehearsal [#44](https://github.com/seanlussier1/Foodwell/issues/44), cross-team security evidence [#47](https://github.com/seanlussier1/Foodwell/issues/47), Sean's QA handoff [#49](https://github.com/seanlussier1/Foodwell/issues/49), representation completion [#65](https://github.com/seanlussier1/Foodwell/issues/65), team QA execution [#66](https://github.com/seanlussier1/Foodwell/issues/66), and this reproduction issue. Other B3 items marked closed still require their owners' release-candidate validation; issue status alone is not proof of acceptance. The fixes for [#99](https://github.com/seanlussier1/Foodwell/issues/99) and [#100](https://github.com/seanlussier1/Foodwell/issues/100) are present in PR #101, while those issue cards were still open at this check.

The top-level README and parts of the older client/security notes still describe authentication and checkout as planned/local demos; that conflicts with the observed bearer-protected runtime and is tracked for the documentation audit #43. The nine-table upgrade instruction in `database/README.md` has been corrected in this change. Do not infer access control or implemented routes merely from OpenAPI declarations.

## Deployment and QA handoff

The snapshot is reproducible locally, but **deployment is not validated**. Before final delivery, agree on the exact candidate, have each owner validate their domain, resolve or explicitly accept the listed gaps, and test the actual target environment: PHP/MariaDB versions, configuration and secrets, HTTPS, production DB permissions, migrations, logs, backup/recovery, external-service failure behavior, and deployment/run instructions. Do not use seed accounts, fixture credentials or demo provider mode as production settings.

Provide this document and the evidence folder to the #66 QA owner. Record peer results and defects with their tested commit, expected/actual behavior and retest evidence. Preserve the separate November 27 report/retest handoff; it is not complete at this snapshot. Review and merge of this evidence, owner validations and an agreed candidate are still required before #67 can be marked Done.
