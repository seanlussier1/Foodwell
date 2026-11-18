# Order and checkout integrity verification (#52)

## Scope and fixtures

The checks used MariaDB 10.4.32, PHP 8.2.12 and two loopback PHP servers running the normal API entry point. The starting application snapshot was `d722d4b` with the checkout fix in this change. The disposable database was loaded from `database/schema.sql` and `database/seed.sql`. All accounts, carts, inventory and slots were synthetic. The test runner used the login API and created a dedicated empty slot for retry checks.

For the concurrent cases, a separate database connection held the relevant customer, product or slot row. The runner waited until MariaDB showed both HTTP workers executing locking reads before releasing the row. The rollback fixture used a temporary trigger on `order_status_events` to raise an error after earlier writes in the same transaction.

## Results

All 74 assertions passed after the checkout fix.

| Case | Expected and observed result |
| --- | --- |
| Empty cart, insufficient stock, full slot | Each returned HTTP 409 with the relevant error. Product, slot, cart, order, line item and history rows matched their pre-request snapshots. |
| Cross-user and wrong-role requests | HTTP 403 for checkout under another customer, staff checkout, unauthorized order detail, another customer's history, another customer's status events and a customer status update. The domain rows stayed unchanged. |
| Invalid status transitions | HTTP 409. Pending could not skip to delivered, and a cancelled order could not be packed. Status, stock, capacity and history stayed unchanged. |
| Injected checkout and cancellation failures | Each returned HTTP 500. All domain rows matched the snapshots taken before the failed requests, including cart items, stock, order data, slot bookings and status history. |
| Same cart checked out concurrently with spare slot capacity | One HTTP 201 and one HTTP 409 for an empty cart. Exactly one order, one slot booking and one initial event were created. The cart was cleared and each product was decremented once. The unmodified starting snapshot returned two HTTP 201 responses in this case. |
| Concurrent and repeated cancellation | Both concurrent requests returned HTTP 200 with cancelled status. Stock was restored once, capacity was released once, and one authenticated actor was recorded in one cancellation event. A later retry returned HTTP 200 with no further writes. |
| Two customers competing for one spot | One HTTP 201 and one HTTP 409. Only the winner reserved capacity and decremented stock. The losing cart was retained. Repeating the same request returned HTTP 409, and selecting another slot returned HTTP 201 and cleared the cart. |

The duplicate checkout came from a MariaDB snapshot opened during authentication. The checkout now uses locking reads for cart contents and totals after acquiring the customer and cart locks. A request that waited for another checkout sees the committed cart state.

Checkout retries use the current cart. A retry after a rolled-back write can succeed once the failure is removed. After a successful checkout, resubmitting the same request with an empty cart returns HTTP 409. If the cart is replenished, checkout can place a new order.

The temporary #52 harness and raw results were kept in `.issue-52-tests`.

## Automated checks and QA handoff (#53, #66)

Install the locked dependencies with `composer install` and `npm ci`. Point the test runner at a development MariaDB instance using `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER` and `SCHEMA_TEST_PASS` as needed. The account must be able to create and drop a disposable schema and create triggers in it. The concurrent checks inspect the same account's connections.

```powershell
composer test:orders
```

- PHPUnit's isolated stock validation tests use labeled PDO doubles to verify that a rejected quantity never reaches a write. The MariaDB PHPUnit suite checks actual totals and stock, concurrent requests, forced rollback, authorization, and the resulting domain rows.
- Newman runs `requests/Foodwell_Orders_Checkout.postman_collection.json` against a freshly reseeded API fixture. It checks checkout, rejection, cancellation retries and filtered history through the public HTTP interface.
- The runner starts two loopback API workers and creates a randomly named test schema. It stops the workers and drops that schema on exit. JUnit reports and worker logs are stored in `.issue-53-tests`, which can be deleted after collecting the evidence. Keep the committed PHPUnit tests and Postman collection for reruns.

For #66, attach the JUnit reports with the tested commit, MariaDB version and command. Record the three fixture inputs as the schema, the synthetic seed and the test-local trigger and capacity changes. Capture any failures and rerun the same command after applying a fix.

The local #53 run used the merged #52 base `5a049f0`, MariaDB 10.4.32 and PHP 8.2.12. PHPUnit passed 2 stock unit tests with 6 assertions and 3 MariaDB tests with 83 assertions. Newman passed 14 requests and 21 assertions. The cancelled-history check verifies membership and status because seed timestamps use UTC while new order timestamps use the database session clock. On the local non-UTC instance, a seed order sorted ahead of the new order.
