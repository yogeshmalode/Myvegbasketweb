-- =========================================================
-- VegBasket - Offers & coupons migration
-- Run this ONCE on your existing live database (phpMyAdmin ->
-- SQL tab). Adds a manageable Offers page plus real coupon codes
-- that apply a discount at checkout.
-- =========================================================

ALTER TABLE orders
    ADD COLUMN coupon_code VARCHAR(30) DEFAULT NULL,
    ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    coupon_code VARCHAR(30) DEFAULT NULL UNIQUE,
    discount_type ENUM('percent','flat','free_delivery') NOT NULL DEFAULT 'percent',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    valid_until DATE DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Two starter offers so the page isn't empty on day one — edit or delete
-- these anytime from Admin -> Offers.
INSERT INTO offers (title, description, coupon_code, discount_type, discount_value, min_order_amount, valid_until, is_active) VALUES
('Welcome Offer', 'Get 10% off your first order', 'WELCOME10', 'percent', 10.00, 150.00, NULL, 1),
('Free Delivery Weekend', 'Free delivery on all orders, no minimum', 'FREESHIP', 'free_delivery', 0.00, 0.00, NULL, 1);
