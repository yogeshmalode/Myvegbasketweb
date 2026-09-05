-- =========================================================
-- VegBasket - Mobile OTP login migration
-- Run this ONCE on your existing live database (phpMyAdmin ->
-- SQL tab). Makes email and password optional on customer
-- accounts (so someone can sign up with just a phone number +
-- OTP, no email/password needed), and makes phone number the
-- unique identifier customers log in with.
--
-- IMPORTANT: this will FAIL if any two existing customers already
-- share the same phone number, or if any customer has a blank
-- phone. If it errors with a duplicate-key message, tell me the
-- exact error and I'll help you find and fix those rows first.
-- =========================================================

ALTER TABLE customers
    MODIFY COLUMN email VARCHAR(100) DEFAULT NULL,
    MODIFY COLUMN password VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN phone VARCHAR(20) NOT NULL,
    ADD UNIQUE KEY unique_phone (phone);
