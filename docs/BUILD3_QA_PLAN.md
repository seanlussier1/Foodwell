# Foodwell Build 3 QA plan and candidate handoff (#66)

Prepared by Mohamed Amine on November 18, 2026. The issue lists November 12 as the due date for the written plan. This document records its actual preparation date.

## Scope and responsibilities

The November 19 checkpoint covers the original product reads, catalog and slot management, customer authentication and cart behavior, checkout and order history, the agreed representation matrix, and delivery-area composition. The separate November 27 team report incorporates peer results, defect decisions, fixes and reruns. Deployment validation and unimplemented operations listed in OpenAPI stay outside this candidate run.

| Area | Owner | Evidence and independent rerun |
| --- | --- | --- |
| Catalog, slots and representation negotiation | Will | [Catalog handoff](BUILD3_CATALOG_QA.md) and [representation matrix](REPRESENTATION_MATRIX.md). Mohamed reruns the catalog and format cases. |
| Customer, authentication, cart and release setup | Sean | [Customer handoff](BUILD3_CUSTOMER_QA.md) and [release setup and upgrade proof](RELEASE_REPRODUCIBILITY.md). Will reruns an account, role and cart case. |
| Categories, order lifecycle, checkout and QA consolidation | Mohamed | [Order handoff](BUILD3_ORDER_QA.md) and [integrity coverage](ORDER_CHECKOUT_INTEGRITY.md). Sean reruns checkout, cancellation and history. |

The independent reruns above are the proposed peer assignments. Each owner checks their own domain and supplies the tested SHA and report path. The submitted owner handoffs tested catalog at `a6731d2`, customers at `f01c5da`, and orders at `2808534`. The consolidated run below supplements those reports.

## Candidate and fixtures

The consolidated run started from GitHub `main` at `495a2d36e7d8f56329fafb79865f62138b83664c`, the latest merged snapshot when testing began. The retest included the catalog authorization test-harness correction below. The retested file has Git blob ID `1556705666b4f9edf8113aedd40b14a93bd93c9e`. The application code and lockfiles came from that SHA. For a newer submission SHA, rerun affected suites and record the evidence against that SHA. The earlier #67 working-candidate retest used `2e565ab70f2fb4158e5c7e4ad75ca05c7d99a2d5`.

- Clean tracked source exported with `git archive`. Dependencies installed from the committed Composer and npm lockfiles.
- Local runtime used for this run is Windows, PHP 8.2.12, MariaDB 10.4.32 and Node 24.14.1. Earlier owner evidence also covers PHP 8.0.30 on Windows and PHP 8.3.6 on Ubuntu at the SHAs in their respective reports.
- A dedicated loopback MariaDB instance on port 33366 hosted the disposable database and the per-suite schemas. HTTP workers ran on loopback. Database access was scoped to that test server, and login throttling used a generated test secret.
- Git fixture blob IDs are schema `eedd8a13ee9061fece749852863849261f088796`, seed `61df6c260cdac97d42d604d91f944d8627cdd6b8`, Composer lock `9846c8cd68e1980f43dab9728e36d1a00f1a4598`, and npm lock `7e23d92b3d61327b086714f2d0840fc51e074600`.
- Seed accounts, addresses and orders are synthetic. Slot dates are relative to import. Order stock unit tests use labeled PDO doubles. MariaDB tests use real writes, locks and rollback, including a test trigger. Delivery composition uses provider doubles and labeled demo responses, with real MariaDB for availability and the no-booking check.

## Entry and exit criteria

**Entry.** Record the candidate SHA and clean setup. Install locked dependencies, initialize a disposable MariaDB server and confirm the schema, seed and upgrade fixtures are available. Use the merged order, catalog and customer suites and the agreed representation matrix. Keep real credentials, tokens and production data out of reports.

**Exit.** Record each result against the candidate SHA with the command, expected and actual behavior and evidence. Verify rejected writes leave the relevant domain rows unchanged. Unresolved defects that affect required functionality, access control or data integrity block acceptance until fixed and retested or explicitly deferred by the team. Record independent peer results and reviewer acceptance separately from the automated run. Deployment and live provider validation require their own evidence.

## Test cases and expected results

| Risk | Checks | Expected |
| --- | --- | --- |
| Original product reads regress | Original GET, product checks | Seeded list and existing item return 200 and agree with the database. Missing item returns 404. Empty database returns 200 with empty data. |
| Fresh setup or migration damages records | Schema and upgrade checks | All three supported upgrade paths match the fresh schema and preserve their seeded records. |
| Catalog changes allow bad input or oversell a slot | Catalog repository and Newman suites, catalog integrity, slot and authorization checks | Valid staff writes work. Wrong roles and invalid input fail. Referenced slots and capacity constraints are enforced, and rejected writes preserve the affected records. |
| Customer data or sessions can be used by the wrong caller | Customer Newman, auth, authorization, registration and cart checks | Valid sessions work. Invalid or revoked sessions return 401. Cross-user and wrong-role writes return 403. Rejected mutations leave resource rows unchanged. |
| Checkout writes twice or partially | Order PHPUnit and Newman, concurrent checkout | One concurrent duplicate commits. A losing cart is retained when capacity is exhausted. A forced failure rolls back order, stock, cart and history. Repeated cancellation restores stock and releases capacity once. |
| Representations diverge | Representation matrix checks | Catalog JSON, XML and YAML agree on values. Unsupported responses return 406 and unsupported request bodies follow each route's contract. Error paths preserve database state. |
| Provider failure invents eligibility or makes a booking | Delivery checks | Fixtures cover success and upstream failures. The fallback reports unknown eligibility and no offered slots. Lookup leaves orders and slots untouched. |

## Candidate execution on November 18

The consolidated retest passed all 18 commands. The [machine-readable results](qa/issue-66/results.json) and [suite transcript](qa/issue-66/suite-results.txt) record the individual commands and exit codes.

| Coverage | Observed result |
| --- | --- |
| Unit, original product GET and product regressions | 67 unit tests and 104 assertions, 4 original GET cases, and 81 product checks passed. |
| Setup | 72 schema checks and all three supported upgrade paths passed. Seeded records were preserved across each upgrade. |
| Customer, authentication and cart | 12 Newman requests and 12 assertions, 124 registration checks, and 79 cart checks passed. Authentication and authorization suites exited successfully. |
| Catalog and slots | 17 repository tests with 56 assertions and 44 Newman requests with 103 assertions passed. The slot, authorization, and integrity scripts passed 71, 250, and 236 checks respectively. |
| Orders and checkout | 2 stock tests with 6 assertions, 3 MariaDB tests with 83 assertions, 14 Newman requests with 21 assertions, and the separate concurrency script passed. |
| Representations | 448 checks passed across catalog formats, negotiation and structured errors, including database checks after rejected writes. |
| Delivery composition | 62 checks passed using labeled provider doubles and real availability data. The run covered success, timeouts, malformed data and rate limits, and confirmed no booking writes. |

The first attempt started Newman while npm was still unpacking its dependencies. All three Newman invocations failed to load a module. After the install completed, Newman reported version 6.2.3 and all three suites passed. The separate catalog authorization failure reproduced in the clean CRLF snapshot and required the one-line matcher correction recorded below.

## How to reproduce and record peer evidence

1. Check out the candidate in a fresh directory. Run `composer install` and `npm ci`. Point the `SCHEMA_TEST_*` variables at a dedicated disposable MariaDB server with permission to create and drop fixture schemas and create test triggers.
2. For the original GET checks also set `DB_*` for the disposable server and a dedicated test schema before running `composer check`. It resets its configured schema and creates an empty fixture schema. Run the other commands in the [candidate results](qa/issue-66/results.json).
3. Use the linked domain handoff for requests and synthetic test accounts. Check success, one rejected operation and before/after state. Save the tester, timestamp, SHA, fixture version, command or request, expected and actual result, request ID and report path. Omit bearer tokens, passwords and private data.
4. For a new peer finding, use an existing matching GitHub issue or file one with the `bug` label. Include the affected SHA, owner, severity and priority, reproduction steps and failing test link. Record the fix PR, retest SHA and before/after state in the defect index after retest.

## Defect and retest index

| Finding and affected snapshot | Owner and triage | Fix and retest |
| --- | --- | --- |
| [#99](https://github.com/seanlussier1/Foodwell/issues/99). Under PHP 8.0 at `102a8d1`, `GET /products` returned 500 during repository load. | Sean. High severity and high priority because a supported runtime could not serve requests. | [PR #101](https://github.com/seanlussier1/Foodwell/pull/101). [PHP 8.0 product and order evidence](qa/issue-49/results.json) and [#67 clean retest](qa/issue-67-retest/results.json). |
| [#100](https://github.com/seanlussier1/Foodwell/issues/100). At `102a8d1`, registration expected the old landing page and cart tests expected unsigned access. [Original observation](BUILD3_ORDER_QA.md#clean-run-results). | Sean. Medium severity and high priority for restoring regression coverage. | [PR #101](https://github.com/seanlussier1/Foodwell/pull/101). Retest expected 124 customer and 79 cart checks, including authorization. See the [candidate transcript](qa/issue-66/suite-results.txt). |
| #67 catalog test output overlap. At `d6bbb63`, stderr and PHP error logging shared one file on Windows and the catalog integrity assertions could not parse a complete log record. | Will for catalog coverage and Sean for release reproduction. Medium severity and high priority for repeatable evidence. | [PR #103](https://github.com/seanlussier1/Foodwell/pull/103) separated the output files. [Original passing retest](qa/issue-67-retest/catalog-integrity.log). |
| PHP checkout consumer HTTP 401 observed before the login fix. Reproduce by running the consumer against the secured API without a session on `b440204`. [Audit finding](DOCUMENTATION_AUDIT.md). | Mohamed. Medium severity and high priority for the PHP demo. | [PR #107](https://github.com/seanlussier1/Foodwell/pull/107). The isolated retest produced one order, decremented stock once and cleared the correct cart. A mismatched login caused no cart write. |
| Catalog authorization stops early on Windows CRLF at `495a2d3`. Run [`scripts/check-catalog-authorization.php`](../scripts/check-catalog-authorization.php) in a CRLF checkout. Expected eight route declarations. The original matcher found none and exited before the HTTP cases. [Failure transcript](qa/issue-66/catalog-authorization-before.txt). | Will for catalog coverage, Mohamed for the #66 harness fix. Medium severity and high priority for restoring authorization coverage. | This change accepts both CRLF and LF in the route matcher. The HTTP checks and their state assertions are unchanged. See the [candidate transcript](qa/issue-66/suite-results.txt). |

## November 27 handoff

Carry forward the [candidate results](qa/issue-66/results.json), [suite transcript](qa/issue-66/suite-results.txt), the domain evidence above, the issue tracker links, and the separate [release setup evidence](RELEASE_REPRODUCIBILITY.md). At this checkpoint, the three owner handoffs and the consolidated run are recorded. The independent peer reruns and November 27 retests are pending. For each later report entry use the actual tested commit and date, and record whether each affected row remained unchanged after a rejected mutation. Keep deployment validation and the November 27 team report on their own evidence.
