-- =========================================================
-- VegBasket - order status "Pending" migration
-- Run this ONCE on your existing live database (phpMyAdmin ->
-- SQL tab, or `mysql -u user -p dbname < migration_order_status.sql`).
-- Adds "pending" as a valid order_status value so you can set an
-- order back to Pending from the admin panel, alongside Delivered etc.
-- =========================================================

ALTER TABLE orders
    MODIFY COLUMN order_status ENUM('pending','placed','processing','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'placed';
