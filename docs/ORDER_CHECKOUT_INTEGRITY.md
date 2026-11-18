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

The temporary harness and raw results were kept in `.issue-52-tests`. Use the cases and expected database state above as the handoff for the PHPUnit and Newman automation in #53.
