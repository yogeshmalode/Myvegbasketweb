CREATE TABLE IF NOT EXISTS back_in_stock_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vegetable_id INT NOT NULL,
    email VARCHAR(254) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_back_in_stock_email (vegetable_id, email),
    KEY idx_back_in_stock_pending (notified_at, vegetable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
