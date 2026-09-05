-- =========================================================
-- VegBasket - Live order tracking migration
-- Run this ONCE on your existing live database (phpMyAdmin ->
-- SQL tab, or `mysql -u user -p dbname < migration_tracking.sql`).
-- Adds columns to store the live delivery location (updated from
-- your phone while delivering) and the geocoded delivery address.
-- =========================================================

ALTER TABLE orders
    ADD COLUMN delivery_lat DECIMAL(10,7) DEFAULT NULL,
    ADD COLUMN delivery_lng DECIMAL(10,7) DEFAULT NULL,
    ADD COLUMN location_updated_at DATETIME DEFAULT NULL,
    ADD COLUMN address_lat DECIMAL(10,7) DEFAULT NULL,
    ADD COLUMN address_lng DECIMAL(10,7) DEFAULT NULL;
