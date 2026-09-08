-- Delivery manifests and linkage
CREATE TABLE IF NOT EXISTS delivery_manifests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    total_orders INT DEFAULT 0,
    total_km DECIMAL(8,2) DEFAULT 0,
    status ENUM('open','dispatched','completed','cancelled') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS delivery_manifest_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manifest_id INT NOT NULL,
    order_id INT NOT NULL,
    seq INT NOT NULL DEFAULT 0,
    notes VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (manifest_id) REFERENCES delivery_manifests(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link orders to a manifest
ALTER TABLE orders ADD COLUMN manifest_id INT DEFAULT NULL;