-- Synthetic seed data (no real customers or products).
-- Development only. Load after schema.sql; this file is not rerunnable alone.
START TRANSACTION;
INSERT INTO categories (id, name) VALUES
    (1, 'Produce'),
    (2, 'Dairy'),
    (3, 'Bakery'),
    (4, 'Pantry');

INSERT INTO products (id, name, price, unit, stock, category_id, image_url) VALUES
    (101, 'Whole Milk 2L',         4.99, 'each',  42, 2, 'https://example.com/images/whole-milk.jpg'),
    (102, 'Old Cheddar 400g',      7.49, 'each',  25, 2, 'https://example.com/images/old-cheddar.jpg'),
    (103, 'Plain Yogurt 750g',     4.29, 'each',  30, 2, 'https://example.com/images/plain-yogurt.jpg'),
    (104, 'Gala Apples',           2.99, 'lb',   120, 1, 'https://example.com/images/gala-apples.jpg'),
    (105, 'Bananas',               0.79, 'lb',   200, 1, 'https://example.com/images/bananas.jpg'),
    (106, 'Baby Spinach 142g',     3.99, 'each',  18, 1, 'https://example.com/images/baby-spinach.jpg'),
    (107, 'Sourdough Loaf',        5.50, 'each',  12, 3, 'https://example.com/images/sourdough.jpg'),
    (108, 'Butter Croissants (6)', 6.99, 'each',  15, 3, 'https://example.com/images/croissants.jpg'),
    (109, 'Basmati Rice 2kg',     11.99, 'each',  40, 4, 'https://example.com/images/basmati-rice.jpg'),
    (110, 'Chickpeas 540ml',       1.89, 'each',  80, 4, 'https://example.com/images/chickpeas.jpg');

-- Full project demo data (requires the expanded schema).
-- All four accounts use FoodwellDemo!2026, hashed with PHP password_hash().
-- These publicly documented credentials are for local development only.
INSERT INTO customers (id, first_name, last_name, email, password_hash, address, phone, role) VALUES
    (7, 'Alex', 'Demo', 'alex@example.com', '$2y$10$cyeL3qYvTJvYrURU5PMyP.rd6InhXrieh6MmkY0qkiunuagzgUEAu', '123 Demo Street, Montreal, QC', '514-555-0101', 'customer'),
    (8, 'Sam', 'Demo', 'sam@example.com', '$2y$10$cyeL3qYvTJvYrURU5PMyP.rd6InhXrieh6MmkY0qkiunuagzgUEAu', '456 Sample Avenue, Montreal, QC', '514-555-0102', 'customer'),
    (9, 'Taylor', 'Staff', 'staff@example.com', '$2y$10$cyeL3qYvTJvYrURU5PMyP.rd6InhXrieh6MmkY0qkiunuagzgUEAu', NULL, NULL, 'staff'),
    (10, 'Jordan', 'Admin', 'admin@example.com', '$2y$10$cyeL3qYvTJvYrURU5PMyP.rd6InhXrieh6MmkY0qkiunuagzgUEAu', NULL, NULL, 'admin');

-- Expired/revoked examples only: seed data never grants a live session.
INSERT INTO auth_sessions (id, customer_id, token_hash, created_at, expires_at, revoked_at) VALUES
    (1, 7, SHA2('foodwell-expired-demo-session', 256), '2026-01-01 09:00:00', '2026-01-01 10:00:00', NULL),
    (2, 8, SHA2('foodwell-revoked-demo-session', 256), '2026-01-01 09:00:00', '2026-01-01 10:00:00', '2026-01-01 09:30:00');

-- A populated cart and an empty cart; cart contents do not reserve stock.
INSERT INTO carts (id, customer_id) VALUES (30, 7), (31, 8);
INSERT INTO cart_items (id, cart_id, product_id, quantity) VALUES
    (501, 30, 101, 2),
    (502, 30, 104, 1);

-- UTC windows relative to import date keep future availability demos usable.
INSERT INTO delivery_slots (id, starts_at, ends_at, capacity, is_active) VALUES
    (301, UTC_DATE() + INTERVAL 1 DAY + INTERVAL 14 HOUR, UTC_DATE() + INTERVAL 1 DAY + INTERVAL 16 HOUR, 2, TRUE),
    (302, UTC_DATE() + INTERVAL 2 DAY + INTERVAL 14 HOUR, UTC_DATE() + INTERVAL 2 DAY + INTERVAL 16 HOUR, 1, TRUE),
    (303, UTC_DATE() - INTERVAL 1 DAY + INTERVAL 14 HOUR, UTC_DATE() - INTERVAL 1 DAY + INTERVAL 16 HOUR, 2, FALSE),
    (304, UTC_DATE() + INTERVAL 3 DAY + INTERVAL 14 HOUR, UTC_DATE() + INTERVAL 3 DAY + INTERVAL 16 HOUR, 3, TRUE);

-- Every lifecycle status is represented. Stock above is the current balance,
-- after these synthetic purchases/cancellation; importing does not decrement it.
INSERT INTO orders (id, customer_id, delivery_slot_id, status, total, delivery_address, created_at) VALUES
    (900, 7, 301, 'pending', 9.98, '123 Demo Street, Montreal, QC', UTC_TIMESTAMP()),
    (901, 8, 301, 'packed', 11.99, '456 Sample Avenue, Montreal, QC', UTC_TIMESTAMP()),
    (902, 7, 302, 'out_for_delivery', 12.48, '123 Demo Street, Montreal, QC', UTC_TIMESTAMP()),
    (903, 8, 303, 'delivered', 5.50, '456 Sample Avenue, Montreal, QC', UTC_TIMESTAMP() - INTERVAL 2 DAY),
    (904, 7, NULL, 'cancelled', 1.89, '123 Demo Street, Montreal, QC', UTC_TIMESTAMP());

INSERT INTO order_items (id, order_id, product_id, product_name, unit, quantity, unit_price) VALUES
    (1001, 900, 101, 'Whole Milk 2L', 'each', 2, 4.99),
    (1002, 901, 109, 'Basmati Rice 2kg', 'each', 1, 11.99),
    (1003, 902, 102, 'Old Cheddar 400g', 'each', 1, 7.49),
    (1004, 902, 101, 'Whole Milk 2L', 'each', 1, 4.99),
    (1005, 903, 107, 'Sourdough Loaf', 'each', 1, 5.50),
    (1006, 904, 110, 'Chickpeas 540ml', 'each', 1, 1.89);

COMMIT;
