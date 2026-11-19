# Foodwell final candidate QA handoff

## Snapshot and scope

Mohamed's follow-up to the [Build 3 QA plan](BUILD3_QA_PLAN.md) tested GitHub `main` at `ab080267a32ac9e76db14967148efbf24bd13bbb` on November 19, 2026. The clean archive included favorites, inventory adjustments, saved addresses and reviews. It used PHP 8.2.12, MariaDB 10.4.32, Node 24.14.1 and the candidate's Composer and npm lockfiles. The schema and synthetic seed came from the same commit. Testing used a dedicated loopback MariaDB instance, disposable databases, synthetic sessions and local HTTP workers. The delivery checks used labeled provider fixtures.

Fixture Git blobs were schema `eedd8a13ee9061fece749852863849261f088796`, seed `61df6c260cdac97d42d604d91f944d8627cdd6b8`, Composer lockfile `e48475251fc5a22eb7bec9a3fa61cdfdbd007062`, and npm lockfile `7e23d92b3d61327b086714f2d0840fc51e074600`.

## Mohamed's verification

**60 assertions passed.** The isolated run exercised every address and review operation, including validation and permission denials, and checked the resulting MariaDB rows. The combined inventory and checkout flow moved product 101 from 42 to 47 after restock, to 45 after checkout, and back to 47 after cancellation. The second cancellation left all compared rows unchanged. The persisted order continued to show the deleted saved address.

| Case | Expected result and database check |
| --- | --- |
| Saved addresses | The owner can create, list with pagination, read, patch and delete a saved address. Missing sessions, wrong owners, administrator access, invalid country and caller-supplied owner are refused. Compare `customer_addresses` rows before and after rejected writes. |
| Product reviews | Public reads succeed. The author can edit and delete. An administrator can moderate by deleting, and staff cannot delete another person's review. Invalid ratings, spoofed author fields and duplicate reviews are refused. Compare `product_reviews` rows before and after rejected writes. |
| Saved address checkout with adjusted stock | Staff restock product 101 by five. Alex checks out cart 30 using an owned address and slot 304. The order stores the address text and checkout decrements product 101 by two. Trying Sam's address fails without changing domain rows. |
| Cancellation after inventory adjustment | Deleting the saved address preserves the order's delivery address. Staff cancellation restores the purchased quantity to the post-restock balance. Repeating it preserves stock, order, history and inventory audit rows. |

### Reproduction

Use a freshly seeded disposable database. Sign in separately as Alex, Sam, staff and administrator with the synthetic accounts from the seed. Send JSON requests as described in [OpenAPI](openapi.yaml). Use the bearer token belonging to the role being tested.

1. Try to create an address for customer 7 as Sam and as administrator. Both return 403. As Alex, try an address with `countryCode: US` or an extra `customerId`. Both return 422. Try patching or deleting address 2 under Alex's URL. Both return 404 and the saved-address rows stay unchanged.
2. As Alex, create an address with `label: Work`, `recipientName: Alex Synthetic`, `addressLine1: 11 Test Street`, `addressLine2: Unit 2`, `city: Montréal`, `province: QC` and `postalCode: H1A 1A1`. Read the new ID, page the list with `page=2&limit=1`, then patch `addressLine2` to null.
3. On product 101, attempt an unauthenticated review, a staff review, a rating of 6, a supplied `customerId` and a second review by Alex. Expect 401, 403, 422, 422 and 409 respectively. Create as Sam, read it publicly and try editing as Alex and administrator. Both edits return 403. Patch as Sam and delete as administrator. Recreate as Sam and verify author deletion.
4. As staff, post `{"quantityChange":5,"reason":"restock","note":"Synthetic QA"}` to `/products/101/inventory-adjustments`. Product 101 starts at 42 and should now have 47 units. As Alex, attempt checkout with `addressId: 2` and expect 404. Then use the new owned address with `customerId: 7` and `deliverySlotId: 304`. Expect 201, a persisted address and stock 45.
5. Delete the saved address, read back the order, then cancel twice as staff. The address snapshot stays on the order. Stock returns to 47, the original adjustment record remains intact, and the second cancellation creates no changes.

## Clean candidate regression run

**20 of 21 commands passed on the unmodified candidate.** The passing commands included the original product GETs, schema and upgrade paths, PHP unit tests, three Newman runs, order concurrency, authentication, authorization, registration, cart and delivery checks. The representation suite passed 457 checks, the inventory suite passed 66 and the favorites suite passed 27. The [result index](qa/final-candidate/results.json) gives each command and exit code. The [suite transcript](qa/final-candidate/suite-results.txt) preserves the original product test failure and Mohamed's HTTP check output.

## Review of Will's work

Reviewed the merged inventory adjustment and catalog-format code in [PR #113](https://github.com/seanlussier1/Foodwell/pull/113) and [PR #109](https://github.com/seanlussier1/Foodwell/pull/109). Inventory authorization applies the staff policy, and the adjustment path locks the same product row that checkout uses. The adjustment writes both stock and the audit entry inside the authorization transaction. The integrated check above verifies that an adjustment remains intact across checkout and cancellation. Catalog representation coverage is included in the candidate regression run.

GitHub records Sean's approvals on [#109](https://github.com/seanlussier1/Foodwell/pull/109), [#113](https://github.com/seanlussier1/Foodwell/pull/113) and [#115](https://github.com/seanlussier1/Foodwell/pull/115). Those reviews and the earlier owner reports have their own dates and snapshots. Independent peer reruns against `ab08026` are pending unless a tester supplies separate evidence.

## Team evidence and remaining work

- **Product regression finding, proposed owner Will.** On `ab08026`, `php scripts/check-products.php` stops at the XML/YAML alias assertion. Its expected headers are `application/xml` and `application/yaml` for requests that accept `text/xml` and `application/x-yaml`. The current contract and dedicated representation suite expect the negotiated aliases themselves. This is a test expectation mismatch. Replacing the two expected header prefixes with `text/xml;` and `application/x-yaml;` in the disposable copy produced [81 passing product checks](qa/final-candidate/products-alias-retest.txt). The candidate still contains the original assertion. Will can review the correction and rerun the affected suite on the agreed submission commit.
- Owner evidence from earlier snapshots remains in the [customer QA handoff](BUILD3_CUSTOMER_QA.md), [catalog QA handoff](BUILD3_CATALOG_QA.md), [order QA handoff](BUILD3_ORDER_QA.md) and [release reproducibility report](RELEASE_REPRODUCIBILITY.md).
- Continue the [defect and retest index](BUILD3_QA_PLAN.md#defect-and-retest-index) for the November 27 report. Record reviewer, tested SHA, cases, outcome, evidence and any residual risks for each independent run.
- The README's stale checkout claim is corrected in this handoff. Its separate stale inventory sentence belongs to the catalog documentation review. GitHub approval of prior PRs does not establish a newer candidate peer run.
- Deployment, the live postal provider, and the November 27 team report require their own evidence.

Codex assisted with source review, the disposable test setup and execution, and drafting this handoff. The command outputs record the checks performed. Independent teammate verification is tracked separately.
