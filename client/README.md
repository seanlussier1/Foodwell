> The main navigation opens Shop, Delivery and Account in one workspace. Staff and administrators also see Customers and Products. Sign in from Account; the session stays in memory while navigating and ends when the page is reloaded.

# Customer dashboard (Lab 7 Part 4)

New customers can choose **Sign in → Create an account**, enter their name, email and password, and then sign in. Registration uses the public customer endpoint and does not accept role or activation fields. The form checks matching passwords and displays API validation or duplicate-email errors. Use synthetic accounts when testing locally.

The checkout page is at `/client/shop.html` (or `shop.html` on the separate client server). Sign in as an active customer first; the page uses that account's cart. Browse and search products, add and update cart lines, optionally check a postal area and select an offered window, then enter an address and place an order. The confirmation reads the saved order back. Cart and order routes require the signed-in customer's bearer session and only allow that customer's own cart and orders (or an administrator). Delivery-area lookup is advisory and does not validate the address. A selected window is rechecked for time and capacity at checkout.

See [checkout demonstration and evidence](../docs/CHECKOUT_DEMO.md) for the PHP consumer, concurrent booking check and manifest contribution.

This directory contains Sean's customer client. Products and categories remain the other owners' sections; they are not implemented by this client.

The separate `delivery.html` page provides the #37 public delivery-area checker. Open it through the dashboard's **Check delivery area** link or directly at `http://localhost/foodwell-api/client/delivery.html`. With the separate client server, use `http://localhost:8081/delivery.html`. It combines Zippopotam.us/GeoNames postal-area details with Foodwell delivery windows and handles partial provider failures. No profile/cart demo flag is needed for normal delivery lookups. See [integration documentation](../docs/DELIVERY_AREA_INTEGRATION.md) for configuration, attribution, limitations, PHP consumption and controlled demo modes.

## Start with Wamp

1. Keep Wamp running and use an updated development database with synthetic data.
2. In the application's `.env`, use the intended database settings and set `AUTH_LIMITER_SECRET` (needed for sign-in). The old `APP_CUSTOMER_PROFILE_DEMO` flag no longer changes access.
3. Open `http://localhost/foodwell-api/client/`. The dashboard infers the API base `http://localhost/foodwell-api/api/v1`.

Alternatively, to demonstrate cross-origin browser consumption, run from the repository root:

```powershell
php -d xdebug.mode=off -S localhost:8081 -t client
```

Leave that terminal open and visit `http://localhost:8081`. Its default API is the Wamp Apache URL above. Expand **API connection** to change the base URL if needed. For a separate PHP API server started with `composer start`, use `http://localhost:8080/api/v1`.

`CORS_ALLOWED_ORIGINS` defaults to `http://localhost:8081,http://127.0.0.1:8081`. Add a different intentional client origin if using a different port. Same-origin calls also work. Do not open index.html using file://.

## Workflow

- Search first/last names, filter active status and navigate pages.
- Create a synthetic customer. Successful creation resets filters and opens the final page so the new account is visible.
- Edit a row to load its profile; save uses PUT and never sends a password.
- Deactivate a synthetic customer with PATCH. Confirm the action, then select Inactive to see it. The account/history remain in the database and existing sessions are revoked. Reactivation is not provided.
- Blank required fields are caught in JavaScript without a request. Detailed rules remain server-side: a nonempty invalid email or a short nonempty password produces the backend's structured 422 feedback. Forms use novalidate deliberately so the lab can demonstrate both layers.

The collection and deactivation routes require a staff or administrator sign-in; profile routes allow the customer themself or an administrator. The session token is kept in memory and sent as a bearer header; browser fetches do not send cross-origin cookies.

## Verification

```powershell
$env:SCHEMA_TEST_PORT = '3307'
php -d xdebug.mode=off scripts/check-registration.php
```

Run from the repository root; adjust the test port/credentials for your machine. Expected: 122 checks passed, using temporary databases only.

Browser verification covered cross-origin list/create/update/PATCH, previous/next, filters, client-side blank validation, server 422 rendering, successful save/reset and inactive status. All browser checks used disposable fixture data, not the application database. Manual use of this dashboard writes to your configured local database.
