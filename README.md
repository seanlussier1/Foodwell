# Foodwell API


## Project Description

Grocery Store API is a RESTful Web service for an online grocery store that lets customers browse products, manage a shopping cart, and place orders for delivery. Store staff can manage inventory and update order status, while administrators oversee the product catalog and user accounts. The service is built around resource-oriented endpoints for products, categories, customers, carts, and orders, with a checkout flow that validates stock and calculates totals server-side. It solves the problem of giving a small grocery business a clean, well-documented API that a web or mobile client can consume to sell groceries online without needing custom integration work for every client.

## Team Members

| Name | Role / Responsibilities |
|---|---|
| Will Kerdy Elveus | Backend — Products & Categories endpoints, database schema |
| Sean Lussier | Backend — Customers & Authentication |
| Mohamed Amine | Backend — Cart & Order endpoints, checkout logic |

## Tech Stack

- PHP 8
- Apache
- MariaDB
- Composer
- PDO (database access)
- PHPUnit (testing)
- Postman / Newman (API testing)
- OpenAPI 3.0 (API documentation)

## API Documentation

The full API contract is documented using the OpenAPI 3.0 Specification:

📄 [`docs/openapi.yaml`](./docs/openapi.yaml)

Paste the file into the [Swagger Editor](https://editor.swagger.io) to view it as interactive documentation, or use it to generate a Postman collection for testing.

### Core Resources

- **Products** — browse, search, filter, and manage the product catalog
- **Categories** — organize products (dairy, produce, bakery, etc.)
- **Customers** — registration and profile management
- **Cart** — per-customer cart with line items
- **Orders** — checkout, order history, and status tracking

## Getting Started
