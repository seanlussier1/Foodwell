-- Run once after migration 002 on a backed-up database. MariaDB DDL commits.
-- Existing catalog/customer/cart/order records are preserved.
ALTER TABLE products ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE,
    ADD CONSTRAINT chk_products_active CHECK (is_active IN (0, 1));

CREATE TABLE customer_addresses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT UNSIGNED NOT NULL,
    label VARCHAR(50) NOT NULL,
    recipient_name VARCHAR(200) NOT NULL,
    address_line1 VARCHAR(150) NOT NULL,
    address_line2 VARCHAR(150) NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(50) NOT NULL,
    postal_code VARCHAR(20) NOT NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'CA',
    phone VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_addresses_customer (customer_id),
    CONSTRAINT fk_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT chk_addresses_country CHECK (country_code = 'CA')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customer_favorites (
    customer_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id, product_id),
    KEY idx_favorites_product (product_id),
    CONSTRAINT fk_favorites_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_reviews (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment VARCHAR(2000) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reviews_customer_product (customer_id, product_id),
    KEY idx_reviews_product_date (product_id, created_at, id),
    CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inventory_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    quantity_change INT NOT NULL,
    stock_before INT UNSIGNED NOT NULL,
    stock_after INT UNSIGNED NOT NULL,
    reason ENUM('restock', 'damage', 'correction') NOT NULL,
    note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_adjustments_product_date (product_id, created_at, id),
    KEY idx_adjustments_actor (actor_id),
    CONSTRAINT fk_adjustments_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_adjustments_actor FOREIGN KEY (actor_id) REFERENCES customers(id) ON DELETE RESTRICT,
    CONSTRAINT chk_adjustments_change CHECK (quantity_change <> 0),
    CONSTRAINT chk_adjustments_balance CHECK (
        CAST(stock_after AS SIGNED) = CAST(stock_before AS SIGNED) + quantity_change)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_status_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id INT UNSIGNED NOT NULL,
    previous_status ENUM('pending','packed','out_for_delivery','delivered','cancelled') NULL,
    new_status ENUM('pending','packed','out_for_delivery','delivered','cancelled') NOT NULL,
    changed_by INT UNSIGNED NULL,
    source ENUM('application','migration_baseline') NOT NULL DEFAULT 'application',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_events_order_date (order_id, created_at, id),
    KEY idx_status_events_actor (changed_by),
    CONSTRAINT fk_status_events_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_status_events_actor FOREIGN KEY (changed_by) REFERENCES customers(id) ON DELETE RESTRICT,
    CONSTRAINT chk_status_events_change CHECK (previous_status IS NULL OR previous_status <> new_status),
    CONSTRAINT chk_status_events_source CHECK (
        (source = 'migration_baseline' AND previous_status IS NULL AND changed_by IS NULL)
        OR (source = 'application' AND changed_by IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- These are known current states, not invented historical transitions.
INSERT INTO order_status_events (order_id, previous_status, new_status, changed_by, source)
SELECT id, NULL, status, NULL, 'migration_baseline' FROM orders;
