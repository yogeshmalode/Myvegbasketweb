-- Delivery confirmations table
CREATE TABLE IF NOT EXISTS delivery_confirmations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    manifest_id INT DEFAULT NULL,
    rider_id INT DEFAULT NULL,
    confirmed_by INT DEFAULT NULL, -- admin id who scanned
    method ENUM('scan','manual') NOT NULL DEFAULT 'scan',
    note VARCHAR(255) DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (manifest_id) REFERENCES delivery_manifests(id) ON DELETE SET NULL,
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;