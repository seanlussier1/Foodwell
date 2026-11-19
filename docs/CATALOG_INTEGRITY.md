# Catalog failure and integrity verification (#41)

## How to run

```bash
php scripts/check-catalog-integrity.php
```

It uses the same `SCHEMA_TEST_HOST`, `SCHEMA_TEST_PORT`, `SCHEMA_TEST_USER` and `SCHEMA_TEST_PASS` settings as the other check scripts. It creates a disposable database from `database/schema.sql` and `database/seed.sql`, adds four synthetic products with tied prices and names, starts the normal API entry point on a free loopback port, runs the checks and drops the database. The application database is never used.

The run recorded here used PHP 8.3.6 and MariaDB 10.11.14, starting from `102a8d1` plus the fixes below. All 235 checks passed.

## What is checked

After every refused write, the script compares the `products`, `categories`, `inventory_adjustments`, `cart_items` and `order_items` tables with a copy taken before the request. Every error response must carry a `requestId` equal to its `X-Request-ID` header; 105 JSON error responses were checked this way.

| Case | Expected and observed result |
| --- | --- |
| Bad list queries | 400 for page/limit/category out of range or not whole numbers, unknown sorts, array values, long searches, repeated keys (`sort=price&sort=name`) and unknown or misspelled keys (`Sort`, `pagee`). Odd but valid queries (empty values, `&&`, a category with no products) return 200. |
| LIKE wildcards | `search=%` and `search=_` match only products containing those characters. A search that looks like SQL injection returns 0 matches, not an error. |
| Page boundaries | For the default order and all four sorts, following `next` links 3 per page returns every product exactly once, in the same order as SQL with the ID tie-breaker, including tied prices (2.99 x4) and a duplicated name. `totalPages` matches the pages walked; the first page has no `previous` and the last no `next`. Filters survive in the links. A page past the end is 200 with empty data and a `previous` link to the last page. |
| Broken bodies | Bad JSON, empty body, arrays, `null`, strings, numbers and JSON nested deeper than 512 levels return 400 on product POST/PUT and category POST. `text/plain` and form encoding return 415. `application/json; charset=utf-8` is accepted. |
| Invalid fields | Products: 422 naming exactly the bad fields (name length/type, price type/sign/decimals/maximum, categoryId type/zero, stock sign/whole number, client-sent `id`), including several at once. Categories: 422 for missing, empty, blank, non-text and over-100-character names; 409 for duplicates regardless of case or spaces. |
| Media types and methods | Unsupported `Accept` returns 406 as JSON. A 404 asked for as XML is XML and a 400 asked for as YAML is YAML, both with the request id. Unsupported methods return 405; non-numeric ids return 404. |
| Foreign keys | A missing category gives 422 on product create and update. A category with products and a product with inventory history give 409. Deleting a product that is only in past orders is allowed: the order line keeps its name and price and its product link becomes NULL. Deleting a product in a cart removes the cart line. |
| Data changing mid-request | Temporary triggers make the database refuse at the moment of the write: the category disappears during a product insert or update (422), stock history appears during a product delete (409), and a product is added during a category delete (409). |
| Server failure and logs | A trigger raises a database error during a product insert. The client gets a plain 500 with the request id and no SQL or database message; nothing is saved. The server log has an `error` entry with the same request id and the real cause. The whole log contains no bearer token, token hash, demo password or password hash. |

## Problems found and fixed

1. **Repeated query keys were silently collapsed.** PHP keeps only the last value, so `?sort=price&sort=name` sorted by name and `?page=1&page=2` returned page 2, both as 200. `ProductController` now counts keys in the raw query string and returns 400.
2. **Unknown product query keys were silently ignored**, so a typo such as `?Sort=price` returned unsorted results with 200. They now return 400, matching customers and delivery slots. The product OpenAPI description and `check-products.php` were updated.
3. **Category names were measured in bytes.** `strlen` counted `é` as 2, so a valid 60-letter accented name was refused. It now uses `mb_strlen`, matching the `VARCHAR(100)` character limit.
4. **Category delete could return 500.** If a product was added between the "in use" count and the delete, the foreign key refused with error 1451 and nothing caught it. The controller now returns the same 409, and the count uses a locking read so it sees products committed after the request's database snapshot began (the same snapshot issue found in #52).

## Not changed (noted for the team)

- Category POST ignores unknown body fields and returns no `Location` header; product POST rejects unknown fields and returns one.
- A product id above the column maximum (e.g. `/products/99999999999999999999`) returns 404 with the id shown as `9223372036854775807`.
- `HEAD` returns 405 on all routes.

## Automated tests (#42)

The end-to-end checks above are complemented by PHPUnit and Newman:

| Command | What runs | Result on this branch |
| --- | --- | --- |
| `composer test:unit` | `ProductInputTest` and `DeliverySlotInputTest` (no database), plus the existing customer and order unit tests | 67 tests, 104 assertions passed |
| `composer test:catalog` | `CatalogRepositoryTest` against a disposable MariaDB database, then the `Foodwell_Catalog_Slots` Newman collection on a fresh seed | 17 tests / 56 assertions and 38 requests / 90 assertions passed |

Writing the validation tests showed that `ProductInput` keeps a trimmed name in its cleaned data even when the name is invalid. This is harmless because controllers only use the cleaned data when there are no errors, so the tests check the reported errors rather than the data in that case.

