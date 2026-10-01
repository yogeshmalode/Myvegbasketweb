-- =========================================================
-- VegBasket - delivery schedule migration
-- Run this ONCE on your existing live database (phpMyAdmin ->
-- SQL tab, or `mysql -u user -p dbname < migration_delivery_schedule.sql`).
-- Adds delivery_date and delivery_slot columns to the orders table so
-- customers can pick a preferred delivery date/time slot at checkout.
-- =========================================================

ALTER TABLE orders
    ADD COLUMN delivery_date DATE NULL AFTER address,
    ADD COLUMN delivery_slot VARCHAR(50) NULL AFTER delivery_date;
