-- =========================================================
-- VegBasket - Sale price migration
-- Run this ONCE on your existing live database (phpMyAdmin ->
-- SQL tab). Adds an optional "sale price" per product — set one
-- lower than the normal price and the shop page automatically
-- shows a "was / now" discounted price with a SALE badge.
-- =========================================================

ALTER TABLE vegetables
    ADD COLUMN sale_price DECIMAL(10,2) DEFAULT NULL AFTER price;
