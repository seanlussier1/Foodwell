# Customer dashboard (Lab 7 Part 4)

This directory contains Sean's customer client. Products and categories remain the other owners' sections; they are not implemented by this client.

## Start with Wamp

1. Keep Wamp running and use an updated development database with synthetic data.
2. In the application's `.env`, use the intended database settings and `APP_CUSTOMER_PROFILE_DEMO=1` for local testing.
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

The same local-demo restrictions apply to collection/profile/PATCH routes. This is not login or role/ownership enforcement. Keep the demo flag off on shared/deployed servers; #45/#46 must supply actual authorization. Browser fetches do not send cross-origin credentials.

## Verification

```powershell
$env:SCHEMA_TEST_PORT = '3307'
php -d xdebug.mode=off scripts/check-registration.php
```

Run from the repository root; adjust the test port/credentials for your machine. Expected: 122 checks passed, using temporary databases only.

Browser verification covered cross-origin list/create/update/PATCH, previous/next, filters, client-side blank validation, server 422 rendering, successful save/reset and inactive status. All browser checks used disposable fixture data, not the application database. Manual use of this dashboard writes to your configured local database.
