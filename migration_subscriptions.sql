-- Create subscriptions table for D2C recurring deliveries
CREATE TABLE IF NOT EXISTS subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT DEFAULT NULL,
    customer_name VARCHAR(200) NOT NULL,
    customer_phone VARCHAR(40) DEFAULT NULL,
    product_id INT NOT NULL,
    variant_id INT DEFAULT NULL,
    quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    unit VARCHAR(30) DEFAULT NULL,
    frequency VARCHAR(30) NOT NULL, -- daily, alternate, weekly
    interval_days INT NOT NULL DEFAULT 1, -- number of days between deliveries
    next_delivery_date DATE DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES vegetables(id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES vegetable_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;