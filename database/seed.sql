-- Synthetic seed data (no real customers or products).
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
