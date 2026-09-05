-- =========================================================
-- VegBasket - Customer accounts migration
-- Run this ONCE on your existing live database (phpMyAdmin ->
-- SQL tab, or `mysql -u user -p dbname < migration_customers.sql`).
-- Adds customer login/signup so customers can view their past
-- orders and reorder with one click.
-- =========================================================

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE orders
    ADD COLUMN customer_id INT DEFAULT NULL AFTER id,
    ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL;
