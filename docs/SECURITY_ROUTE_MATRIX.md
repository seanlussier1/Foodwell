# Foodwell route permissions (#63)

Draft for Will and Mohamed to review, November 1, 2026. This file lists all 48 API operations and their intended access rules. Read the [security plan](SECURITY_DESIGN.md) for the full rules, explanations and implementation tasks. No endpoint or permission check is implemented by this document.

## How to read the tables

- **Who should be allowed:** the rule to enforce when Build 3 security is implemented.
- **What works today:** the current state of the reviewed code at main commit `c55cacd`.
- **Important check:** the record relationship or restriction the owner must enforce.
- Every path starts with `/api/v1`. For example, `/customers/{id}` means `/api/v1/customers/7` when the ID is 7.

### Meaning of the access rules

| Access rule | Meaning |
|---|---|
| Public | No login required. |
| Owner or administrator | The account that owns the resource, or an administrator where explicitly allowed. |
| Customer owner only | The customer who owns the resource. Administrators do not automatically get access. |
| Staff or administrator | An active signed-in account with either of those roles. |
| Authenticated account | Any active signed-in account, acting only on its own current session. |
| Customer role / customer author | A signed-in customer; author means the customer who wrote the review. |
| Owner, staff or administrator | The owner can read their own record, and the listed staff/admin roles have explicit access. |

**Working local demo** means the existing demo flag and a request from the same computer are required. It does not check who the person is or enforce the intended roles. **Working; public access** does not mean private permission checks are complete: category creation/deletion currently accept requests without role checks. **Not implemented yet** means the contract describes a future operation.

The permission rules do not give administrators unrestricted access to everything. Only the exceptions explicitly named in the tables apply.


## Products

Resource owner: **Will**.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|

| GET | `/products` | Public | Working; public access | Anyone may read this resource. |
| POST | `/products` | Staff or administrator | Not implemented yet | Check the listed role; get the acting account from its session. |
| GET | `/products/{id}` | Public | Working; public access | Anyone may read this resource. |
| PUT | `/products/{id}` | Staff or administrator | Not implemented yet | Check the listed role; get the acting account from its session. |
| DELETE | `/products/{id}` | Staff or administrator | Not implemented yet | Check the listed role; get the acting account from its session. |


## Categories

Resource owner: **Mohamed**.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|

| GET | `/categories` | Public | Working; public access | Anyone may read this resource. |
| POST | `/categories` | Staff or administrator | Working; public access | Role checks are missing today; staff/admin access is the intended rule. |
| GET | `/categories/{id}` | Public | Working; public access | Anyone may read this resource. |
| DELETE | `/categories/{id}` | Staff or administrator | Working; public access | Role checks are missing today; staff/admin access is the intended rule. |


## Customer accounts

Resource owner: **Sean**.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|

| POST | `/customers` | Public | Working; public access | Registration always creates a regular customer; callers cannot choose a role. |
| GET | `/customers` | Staff or administrator | Working local demo | List customer accounts only; do not include staff/admin accounts or secrets. |
| GET | `/customers/{id}` | Owner or administrator | Working local demo | Return safe profile fields; never return passwords or password hashes. |
| PUT | `/customers/{id}` | Owner or administrator | Working local demo | Profile changes cannot change password, role or activation. |
| PATCH | `/customers/{id}` | Staff or administrator | Working local demo | Deactivate customer accounts only; cancel their sessions and retain history. |


## Carts

Resource owner: **Sean**.

Cart implementation status was updated for #35 on top of main `0c3613d`. These routes now use the separate `APP_CART_DEMO=1` setting and loopback access. They check the target account and cart/item relationships, but do not authenticate the caller or enforce the intended owner/admin policy. See [the checkout handoff](CART_CHECKOUT_HANDOFF.md). The other rows retain the earlier reviewed snapshot described above.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|

| GET | `/customers/{id}/cart` | Owner or administrator | Working local demo | Check that the cart belongs to the customer and the item belongs to that cart. |
| POST | `/customers/{id}/cart/items` | Owner or administrator | Working local demo | Check that the cart belongs to the customer and the item belongs to that cart. |
| PUT | `/customers/{id}/cart/items/{itemId}` | Owner or administrator | Working local demo | Check that the cart belongs to the customer and the item belongs to that cart. |
| DELETE | `/customers/{id}/cart/items/{itemId}` | Owner or administrator | Working local demo | Check that the cart belongs to the customer and the item belongs to that cart. |


## Orders and status history

Resource owner: **Mohamed**.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|

| POST | `/orders` | Owner or administrator | Not implemented yet | Verify customerId against the caller; check cart and address ownership. |
| GET | `/orders/{id}` | Owner or administrator | Not implemented yet | Staff access to full order details is not currently agreed; Mohamed must confirm. |
| PATCH | `/orders/{id}` | Staff or administrator | Not implemented yet | Get the order owner from the database; record the signed-in actor on status changes. |
| GET | `/customers/{id}/orders` | Owner or administrator | Not implemented yet | Check the listed role; get the acting account from its session. |
| GET | `/orders/{id}/status-events` | Owner, staff or administrator | Not implemented yet | Get the order owner from the database; record the signed-in actor on status changes. |


## Delivery slots

Resource owner: **Will**.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|

| GET | `/delivery-slots` | Public | Not implemented yet | Anyone may read this resource. |
| POST | `/delivery-slots` | Staff or administrator | Not implemented yet | Check the listed role; get the acting account from its session. |
| GET | `/delivery-slots/{id}` | Public | Not implemented yet | Anyone may read this resource. |
| PUT | `/delivery-slots/{id}` | Staff or administrator | Not implemented yet | Check the listed role; get the acting account from its session. |
| DELETE | `/delivery-slots/{id}` | Staff or administrator | Not implemented yet | Check the listed role; get the acting account from its session. |


## Login sessions

Resource owner: **Sean**.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|

| POST | `/auth/sessions` | Public | Not implemented yet | Use generic login errors; issue a fresh token after successful login. |
| GET | `/auth/sessions/current` | Authenticated account (own session) | Not implemented yet | Return only the caller's current session; never return the token/digest. |
| DELETE | `/auth/sessions/current` | Authenticated account (own session) | Not implemented yet | Cancel only the caller's current session. |


## Saved addresses

Resource owner: **Mohamed**.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|

| GET | `/customers/{id}/addresses` | Customer owner only | Not implemented yet | Check that this address belongs to the customer in the URL. |
| POST | `/customers/{id}/addresses` | Customer owner only | Not implemented yet | Check that this address belongs to the customer in the URL. |
| GET | `/customers/{id}/addresses/{addressId}` | Customer owner only | Not implemented yet | Check that this address belongs to the customer in the URL. |
| PATCH | `/customers/{id}/addresses/{addressId}` | Customer owner only | Not implemented yet | Check that this address belongs to the customer in the URL. |
| DELETE | `/customers/{id}/addresses/{addressId}` | Customer owner only | Not implemented yet | Check that this address belongs to the customer in the URL. |


## Favorites

Resource owner: **Sean**.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|

| GET | `/customers/{id}/favorites` | Customer owner only | Not implemented yet | Only the customer who owns these favorites; no automatic admin exception. |
| PUT | `/customers/{id}/favorites/{productId}` | Customer owner only | Not implemented yet | Only the customer who owns these favorites; no automatic admin exception. |
| DELETE | `/customers/{id}/favorites/{productId}` | Customer owner only | Not implemented yet | Only the customer who owns these favorites; no automatic admin exception. |


## Product reviews

Resource owner: **Mohamed**.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|

| GET | `/products/{id}/reviews` | Public | Not implemented yet | Anyone may read published reviews. |
| POST | `/products/{id}/reviews` | Customer role (session author) | Not implemented yet | The signed-in customer is the author; do not accept a different author ID. |
| GET | `/reviews/{reviewId}` | Public | Not implemented yet | Anyone may read published reviews. |
| PATCH | `/reviews/{reviewId}` | Customer author only | Not implemented yet | Only the customer who wrote the review may edit it. |
| DELETE | `/reviews/{reviewId}` | Customer author or administrator | Not implemented yet | The author may delete their review; an administrator may moderate it. |


## Inventory adjustments

Resource owner: **Will**.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|

| GET | `/products/{id}/inventory-adjustments` | Staff or administrator | Not implemented yet | Record the signed-in actor; existing audit entries cannot be edited/deleted. |
| POST | `/products/{id}/inventory-adjustments` | Staff or administrator | Not implemented yet | Record the signed-in actor; existing audit entries cannot be edited/deleted. |
| GET | `/inventory-adjustments/{adjustmentId}` | Staff or administrator | Not implemented yet | Record the signed-in actor; existing audit entries cannot be edited/deleted. |


## Delivery-area lookup

Resource owner: **Sean**. Added for #37 on top of main `06c2b84`. This public operation accepts a postal code but sends only its prefix externally, offers available windows without making a booking and exposes no customer/order identities. Demo modes require explicit local settings; normal live lookup is public.

| Method | Path | Who should be allowed | What works today | Important check |
|---|---|---|---|---|
| GET | `/delivery-options` | Public | Working; public access | Validate postal syntax, fixed upstream host and payload; keep unknown coverage distinct from invalid address and retain source labels. |

## Shared handling outside these 48 operations

OPTIONS is the browser's CORS preflight check. The existing code checks allowed origins, methods and headers. Preflight must not require a login token or return private data. This transport handling is not an extra domain operation in the count above.

The home page and explicitly mapped client HTML/JS/CSS are public static files with no embedded secrets. Unknown API paths still return 404 and unsupported methods return 405. New routes must receive an explicit permission rule before being treated as public or private.

## Review and handoff

Will reviews products, delivery slots, inventory and the customer/session proposals. Mohamed reviews categories, orders, addresses, reviews and history. Sean maintains the customer/cart/session/favorites design and incorporates their comments. Correcting route ownership labels in this document does not transfer implementation work between teammates.

The shared helpers and resource checks are implemented later under #40/#45/#46 and the owners' security-test issues. Record actual review links in the security plan. Link the final reviewed documents and commit from the #64 Build 2 evidence before marking #63 complete.
