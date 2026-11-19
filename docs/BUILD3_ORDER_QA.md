# Build 3 order and checkout QA readiness (#54)

Mohamed Amine · November 18, 2026

This is the order and checkout handoff for the November 19 candidate. It supports the team QA work in [#66](https://github.com/seanlussier1/Foodwell/issues/66) and the clean-setup evidence in [#67](https://github.com/seanlussier1/Foodwell/issues/67). The November 27 report will record peer results, defects and retests against their actual snapshots.

## Tested snapshot

- GitHub `main` at `28085341b2081f56987f3bcc6688311a6b3319a5` (PR #97). Source was exported with `git archive` into a fresh directory. PHP and Node dependencies were installed from the committed lockfiles.
- Windows, PHP 8.2.12, MariaDB 10.4.32, Node 24.14.1 and PHPUnit 9.6.39. The tests ran against a dedicated loopback MariaDB instance and isolated PHP workers.
- The fixture was the snapshot's `database/schema.sql` and synthetic `database/seed.sql`. The order suite used a labeled PDO double for the stock unit tests. Real MariaDB handled checkout, stock, slot capacity, rollback, history and retries. The forced rollback used a temporary test trigger. The standard composition suite used provider doubles and labeled demo modes.

## Clean-run results

Twelve of the fourteen suite commands exited successfully. The order and checkout suite, original GET regressions and separate rehearsal passed. The two failures below remain open findings for the November 19 candidate.

| Command | Observed result |
| --- | --- |
| `composer test:unit` | Pass, 9 tests and 27 assertions |
| `composer check` | Pass, 4 original GET regressions |
| `composer test:orders` | Pass, 2 stock unit tests with 6 assertions, 3 MariaDB tests with 83 assertions, and 14 Newman requests with 21 assertions |
| `composer test:api` | Pass, 12 Newman requests and 12 assertions |
| `php scripts/check-schema.php` | Pass, 72 schema checks |
| `php scripts/check-products.php` | Pass, 79 product and representation checks |
| `php scripts/check-auth.php` and `php scripts/check-authorization.php` | Both passed |
| `php scripts/check-registration.php` | Failed at line 75. The script expects the Customer workspace title at `/client/`, which now serves the Foodwell landing page. The dedicated Customer workspace is at `/client/customers.html`. |
| `php scripts/check-cart.php` | Failed at line 64. The script expects an unsigned cart request to return the seeded cart with HTTP 200. The current route requires a bearer session. |
| `php scripts/check-delivery.php` | Pass, 62 checks with labeled provider doubles |
| `php scripts/check-delivery-slots.php` | Pass, 71 slot checks |
| `php scripts/check-catalog-authorization.php` | Pass, 250 checks |
| `php scripts/check-order-concurrency.php` | Pass, competing checkout and account-deactivation races |

The Composer entries were invoked through their underlying PHP commands so each run could use the isolated temporary directory and save the PHPUnit JUnit output. Both legacy failures were retained in the result. The signed-in API suite and the follow-up HTTP smoke checks verified the current cart flow and client paths. The legacy script updates and their retests remain pending. The live postal provider and deployed environment were outside this run.

### Submission evidence

- [PHPUnit unit report](qa/issue-54/phpunit-unit.xml), [stock report](qa/issue-54/phpunit-order-stock.xml), [MariaDB order report](qa/issue-54/phpunit-order-integrity.xml), and [Newman order report](qa/issue-54/newman-orders.xml)
- [Original product GET results](qa/issue-54/original-get-results.txt), [checkout rehearsal transcript](qa/issue-54/checkout-rehearsal.txt) and [legacy failure follow-up](qa/issue-54/legacy-followup.txt)

## Peer testing and demo rehearsal

1. Start from the tested commit or record the newer commit you are testing. Install dependencies with `composer install` and `npm ci`. Set the `SCHEMA_TEST_*` variables for a disposable MariaDB instance. Run `composer test:orders` and retain the three JUnit reports.
2. For a live walkthrough, initialize a fresh synthetic database with `php scripts/db-setup.php` and point the API at it before starting `composer start`. Run the reset only against the disposable instance. In Postman, use the [order collection](../requests/Foodwell_Orders_Checkout.postman_collection.json) with its `baseUrl` set to the running API, or send the requests below manually.
3. Sign in as the synthetic Alex account through `POST /auth/sessions`. Use its bearer token to place `POST /orders` with customer 7, slot 304 and a synthetic delivery address. Show HTTP 201 and the read-back from `GET /orders/{id}`. Expect `pending` and a total of $12.97 for seeded products 101 and 104.
4. Resubmit the same checkout with Alex's token. Expect HTTP 409 because the cart is empty. Try cancelling under that token and expect 403. A rejected request leaves the saved order and inventory unchanged.
5. Sign in as the synthetic staff account. Cancel with `PATCH /orders/{id}` and body `{"status":"cancelled"}`. Repeat the request. Both return 200. With Alex's token, show `GET /orders/{id}/status-events` and verify the `pending` and `cancelled` events and staff actor ID 9. In MariaDB, verify inventory for 101 and 104 returned to 42 and 120, cart 30 stayed empty, and slot 304's booked count returned to zero.
6. If a check differs, capture the commit, fixture, request method and URL, expected and actual status, request ID, and relevant before/after state. Omit session tokens. Send the finding and report path to the #66 QA owner and setup findings to #67. Peer execution and November 27 retests are pending.

The rehearsal used the Postman-compatible HTTP flow and direct database observations. The presenter can show the same sequence in Postman and use the saved results for the submission.
