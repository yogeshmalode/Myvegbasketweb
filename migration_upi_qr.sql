-- =========================================================
-- VegBasket - UPI QR payment migration
-- Run this ONCE on your existing live database (via phpMyAdmin
-- "SQL" tab, or `mysql -u user -p dbname < migration_upi_qr.sql`).
-- Safe to run even if some columns already exist won't be — run only once.
-- =========================================================

ALTER TABLE orders
    ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'upi_qr' AFTER razorpay_payment_id,
    ADD COLUMN utr_number VARCHAR(50) DEFAULT NULL AFTER payment_method,
    MODIFY COLUMN payment_status ENUM('pending','awaiting_verification','paid','failed') NOT NULL DEFAULT 'pending';
