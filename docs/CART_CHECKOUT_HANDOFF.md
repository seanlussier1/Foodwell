# Cart handoff to checkout (#35 -> #36)

Prepared by Sean for Mohamed. Status: cart implemented and tested locally; checkout is not implemented by this change. Mohamed still needs to confirm the shared transaction/locking protocol below. Will is the #35 reviewer. Authentication and owner/admin enforcement remain #45/#46 work.

## 1. What is ready

| Method | Route (after /api/v1) | Behavior |
|---|---|---|
| GET | /customers/{id}/cart | Read the customer's cart and current catalog prices |
| POST | /customers/{id}/cart/items | Add quantity to a product line; create cart on first successful add |
| PUT | /customers/{id}/cart/items/{itemId} | Replace that line's quantity |
| DELETE | /customers/{id}/cart/items/{itemId} | Remove that line; 204 with no body |

These are JSON, local-demo endpoints. Enable `APP_CART_DEMO=1` and use localhost. The path account must be an active customer. The setting defaults off, and it does not authenticate the person making the request: any local caller can choose another customer ID while enabled. A nested item ID is always checked against the specified customer's cart, but real session-based ownership is still pending. Do not deploy this as a secured cart service.

Cart response fields: id, customerId, items, subtotal, itemCount and totalQuantity. Each line includes id (the **cart item ID**), productId, productName, unit, quantity, unitPrice, lineTotal, availableStock and isActive. The item ID and product ID are different; PUT/DELETE use the item ID.

## 2. Cart rules checkout must preserve

- There is one reusable cart per customer (`carts.customer_id` is unique). A product appears once per cart (`cart_items.cart_id/product_id` is unique).
- GET is read-only. If no cart row exists, it returns an empty cart with id=null; checkout must treat that as empty rather than try to use null as a stored cart ID.
- A successful first add creates the cart. A failed first add rolls that creation back. Removing the final item retains the cart row with an empty items array.
- POST adds to the existing quantity, with 201 for a new line or 200 for an existing line. It is not idempotent: repeating POST adds again. After an uncertain response, read the cart before deciding what to do; do not blindly retry.
- PUT sets an absolute quantity. Quantity must be a JSON integer from 1 to 1,000,000; zero does not mean remove. Product ID and quantity are the only POST inputs; PUT accepts only quantity. Client prices, totals, ownership and actor fields are rejected.
- Combined quantities must fit current stock and the per-line limit. Inactive products or insufficient stock give 409 without partial changes. Unknown customer/product/item IDs give 404; wrong account role/inactivity gives 403; invalid fields 422; malformed/non-object JSON 400; unsupported media 415.
- GET reports current stock/activation even when a line has become unavailable. It does not silently reduce/remove that line. Users can still remove an inactive or out-of-stock product line. Repeated removal of an already missing line returns 404.
- **Cart additions do not reserve stock.** Two different customers may both have the last available unit in their carts. Only checkout decides who can buy it, using a transaction and locked stock checks.
- Cart prices follow the current catalog. Database DECIMAL arithmetic computes line totals/subtotal; the API's numeric display values are not a frozen quote. Checkout recomputes exact monetary values from locked product rows and stores purchase snapshots; never trust submitted or earlier cart totals.

## 3. Shared lock order: confirm before checkout implementation

Each CartRepository operation starts a transaction and obtains these locks in order:

1. Customer row with FOR UPDATE, checking role/activity.
2. Existing reusable cart row with FOR UPDATE, or create the row for a successful first add.
3. The affected product row with FOR UPDATE for an addition/quantity change.
4. Write the scoped cart item and update the cart timestamp; commit the response state.

All operations changing the same customer's cart must take the customer/cart locks first. This serializes cart edits even when no cart row existed initially. A checkout transaction must follow that protocol so edits cannot arrive between its cart read and clear. If multiple product rows are needed, lock them in ascending product ID order. If slots are used, agree their place before product locks and keep checkout/cancellation consistent; Mohamed must confirm the broader order/slot lock sequence as part of #36/#50.

CartRepository methods manage their own transactions. **Do not call get/add/update/remove inside an already-started checkout transaction**, and do not call cart HTTP endpoints from checkout. Mohamed should perform the checkout SQL on one shared PDO connection inside one checkout transaction, using the same locking protocol.

## 4. Checkout sequence for #36

1. Resolve the intended caller/customer according to the applicable security stage. In the final secured version, derive the caller from its validated session and enforce the reviewed permission rules; a supplied customerId is not proof of identity.
2. Begin one transaction. Lock/check the active customer and reusable cart, then read its items. Reject a nonexistent/empty cart without creating an order or clearing data.
3. Lock any selected slot in the agreed global order and verify active window/capacity. Lock all required products in ascending ID order; recheck existence, activation and stock against each cart quantity. Failure leaves all records unchanged.
4. Compute current unit prices and total with database DECIMAL or checked integer cents. Validate/copy the customer's selected address, or the accepted delivery-address input; later address edits must not change the order.
5. Create the order and order-item purchase snapshots, decrement stock, record the initial status event and book slot capacity as applicable. Keep these writes in the same transaction.
6. Delete only this cart's items; retain the reusable cart row. Commit after every step succeeds. Any failure must roll everything back, including stock, order lines, history, capacity and cart clearing.
7. Return the order details after commit. Define duplicate/uncertain POST behavior explicitly. A second checkout must not create another purchase from the same cleared cart. Do not automatically retry an uncertain checkout response without an agreed idempotency/reconciliation design.

Concurrent cart additions were tested using two separate processes against the same customer/cart. One addition succeeds and the other returns a stock conflict once the combined quantity is too large; one product line remains. This verifies cart serialization, not checkout concurrency. Mohamed still needs concurrent checkout, rollback, retry and actual stock/order/cart-state evidence for #36.

## 5. Show the cart in Postman

Import `requests/Foodwell_Cart.postman_collection.json`. Run its requests in order. It creates a new synthetic customer and stores the returned customer/item IDs as collection variables. Default baseUrl is `http://localhost/foodwell-api/api/v1`; change it if using composer start.

The demo uses product 101, which must be active with at least 3 units in your local fixture. It shows an empty cart, successful addition, saved read-back, absolute update, an excessive-stock conflict, unchanged read-back, validation error, removal and a retained empty cart. These manual requests save records in your configured development database; they do not perform checkout or reduce stock. The excessive quantity example assumes the normal seed stock is below 1,000,000.

For handoff evidence, show the response, customer/cart/item database rows and unchanged product stock. Do not reset existing data just to demonstrate the cart; use a synthetic account and an available product.

## 6. Verify and finish the handoff

```powershell
$env:SCHEMA_TEST_PORT = '3307'
php -d xdebug.mode=off scripts/check-cart.php
php -d xdebug.mode=off scripts/check-registration.php
```

Use the actual test server port and SCHEMA_TEST_USER/SCHEMA_TEST_PASS if needed. Tests use random temporary databases, not foodwell/foodwell_empty. The cart suite currently has 79 passing checks; the existing customer/dashboard suite has 122. Browser CORS now permits DELETE as needed for cart removal and category deletion; this does not authorize the caller.

- [x] Cart implementation, contract and isolated persistence/failure/concurrency checks.
- [x] Saved Postman demonstration and checkout protocol written.
- [ ] Mohamed confirms the transaction/locking and cart-clearing handoff, with review/comment link recorded here.
- [ ] Will reviews #35; resolve comments, merge and record the final commit/PR for the team's Build 2 evidence.

#35 is not marked Done just because this document exists. Complete the handoff agreement and review/merge first. #36 remains Mohamed's implementation work; #45/#46 remain the later identity/permission enforcement work.
