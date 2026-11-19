# Customer-owner peer review: addresses, reviews and checkout

Reviewed against merged main commit `30ee887ecab041b066321629b4351606773f1374` on November 19, 2026. The independent test script in this change uses synthetic data and a randomly named disposable MariaDB database. Run `php scripts/check-address-review-peer.php` with `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER` and `SCHEMA_TEST_PASS` pointing to a dedicated test server.

The script passed 19 checks using PHP 8.0 and MariaDB 11.4. It verifies anonymous, cross-customer and admin denial for private addresses; address creation, update and deletion; checkout using an owned address and preservation of the order's address snapshot; public review reads, author updates, cross-user denial, duplicate review rejection and administrator moderation. It does not cover every edge case, concurrent mutation, every format or deployment environment.

The customer login Content-Type fix and customer QA handoff are separately pushed on branch `customer-final-qa` (commits `4fef6e2` and `f941e30`); they are not part of this tested main snapshot. Those changes still require review and merge. Final QA should use the eventual merged commit and rerun affected checks.
