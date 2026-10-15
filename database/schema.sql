-- Foodwell schema (Build 1: categories + products).
-- Re-runnable: drops and recreates the tables in the target database.
-- Later builds add customers, carts, orders, delivery slots.

DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;

CREATE TABLE categories (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150)  NOT NULL,
    price       DECIMAL(8,2)  NOT NULL,
    unit        VARCHAR(30)   NOT NULL DEFAULT 'each',
    stock       INT UNSIGNED  NOT NULL DEFAULT 0,
    category_id INT UNSIGNED  NOT NULL,
    image_url   VARCHAR(255)  NULL,
    PRIMARY KEY (id),
    KEY idx_products_category (category_id),
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
