# Local checkout demonstration

Run the application on loopback with a disposable synthetic database. Sign in as a seeded customer, then open `/client/shop.html`, add items, and use the cart controls. Optionally check a postal code and choose an offered window. Enter a synthetic delivery address and place the order. The confirmation fetches the saved order.

## Authenticated PHP checkout demonstration

Set `CHECKOUT_DEMO_EMAIL` and `CHECKOUT_DEMO_PASSWORD` in the local environment using a seeded customer. For example, `sam@example.com` is customer 8. Use the synthetic seed password documented in the README.

```powershell
$env:DELIVERY_API_BASE_URL = 'http://localhost:8080/api/v1'
php scripts/checkout-client.php 8 101 "123 Test Street, Montreal"
```

The consumer signs in through `POST /auth/sessions`, checks that the signed-in customer matches the supplied ID, and uses the bearer token for cart changes, checkout, and order read-back. It creates a real order and changes stock in the configured test database. Use a fresh seed to demonstrate a single-line checkout with customer 8's empty cart.

## Historical Build 2 integration evidence

On 2026-11-04, using a disposable seeded MariaDB database and PHP 8.0, the following actual local requests were observed:

| Request | Result |
| --- | --- |
| `GET /api/v1/customers/8/cart` | 200, empty cart |
| `POST /api/v1/customers/8/cart/items` with `{"productId":101,"quantity":2}` | 201, subtotal 9.98 |
| `POST /api/v1/orders` with `{"customerId":8,"deliveryAddress":"123 Test Street, Montreal"}` | 201, saved two units at 4.99 |
| `GET /api/v1/orders/911` | 200, matching persisted order |
| Repeat checkout with an empty cart | 409 |
| Checkout against an already full capacity-one window | 409; database retained one booking and cart item |
| Checkout against a past window | 409; cart item retained |

The database showed stock falling from 34 to 32 and the cart empty after successful checkout. A browser run added products, updated and removed a quantity, placed a two-line order, and showed the saved order and an empty cart. A second browser run used `POSTAL_LOOKUP_MODE=demo-success` (a labelled fixture, not live provider data), selected an offered window and placed order 913; read-back succeeded. See `DELIVERY_AREA_INTEGRATION.md` for the PHP delivery consumer and its separate provider evidence.

The PHP consumer `php scripts/checkout-client.php 8 101 "123 Test Street, Montreal"`, pointed at the same local test API, observed `GET /products?limit=5` 200, `POST /customers/8/cart/items` 201, `POST /orders` 201 and `GET /orders/915` 200. It creates an order and changes stock, so use only a disposable synthetic database. Set `DELIVERY_API_BASE_URL` to the loopback API URL before running it.

`SCHEMA_TEST_PORT=13387 php scripts/check-order-concurrency.php` passed against a separate temporary MariaDB database. Two independent PHP servers received competing orders for a capacity-one slot while the slot row was locked: one returned 201, one 409, exactly one booking was saved and the rejected cart remained. The script creates and deletes its own uniquely named test database; set the `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER` and `SCHEMA_TEST_PASS` environment variables as needed.

After integrating `main`, the cart suite passed 79 checks and the delivery suite passed 62 checks on PHP 8.0. The product suite passed 79 checks on both PHP 8.0 and PHP 8.3 after normalizing PDO integer values in the test and replacing the PHP 8.1-only list detection used by XML/YAML serialization.

## Order/client contribution for the Build 2 manifest (#64)

Include this document, `client/shop.html`, `client/shop.js`, `scripts/checkout-client.php`, `scripts/check-order-concurrency.php`, `docs/openapi.yaml`, and `docs/DELIVERY_AREA_INTEGRATION.md`. Record the final submitted commit after review and merge; the results above used the issue 38 base commit `f076af0` plus the local changes described here. The JavaScript and PHP examples use synthetic local data. Login, authorization and customer ownership remain planned work; neither the demo flag nor a customer ID is authentication. A connection failure after submitting checkout can still leave the customer uncertain whether an order was accepted; reliable retry requires a separate idempotency design.
