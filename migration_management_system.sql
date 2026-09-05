-- MyVegBasket Management System migration
ALTER TABLE admins ADD COLUMN role ENUM('admin','staff') NOT NULL DEFAULT 'admin';
ALTER TABLE vegetables ADD COLUMN supplier_name VARCHAR(120) DEFAULT NULL;
ALTER TABLE vegetables ADD COLUMN cost_price DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE order_items ADD COLUMN cost_price DECIMAL(10,2) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS wastage (
 id INT AUTO_INCREMENT PRIMARY KEY, vegetable_id INT NOT NULL, quantity INT NOT NULL,
 reason ENUM('Expired','Damaged','Spoiled','Other') NOT NULL, notes VARCHAR(255) DEFAULT NULL,
 created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (vegetable_id) REFERENCES vegetables(id) ON DELETE RESTRICT,
 FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS inventory_movements (
 id INT AUTO_INCREMENT PRIMARY KEY, vegetable_id INT NOT NULL,
 movement_type ENUM('purchase','adjustment','sale','wastage') NOT NULL,
 quantity INT NOT NULL, reference_id INT DEFAULT NULL, notes VARCHAR(255) DEFAULT NULL,
 created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (vegetable_id) REFERENCES vegetables(id) ON DELETE CASCADE,
 FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;
UPDATE vegetables SET cost_price = ROUND(price / 1.45, 2) WHERE cost_price = 0 AND price > 0;
