-- Add standard small-pack sizes to existing products without duplicating
-- any size labels already configured by the store.
CREATE TABLE IF NOT EXISTS vegetable_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vegetable_id INT NOT NULL,
    label VARCHAR(30) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (vegetable_id) REFERENCES vegetables(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO vegetable_variants (vegetable_id, label, price, sort_order)
SELECT v.id, sizes.label, ROUND(v.price * sizes.price_fraction, 2), sizes.sort_order
FROM vegetables v
CROSS JOIN (
    SELECT '100 g' AS label, 0.10 AS price_fraction, 0 AS sort_order
    UNION ALL SELECT '250 g', 0.25, 1
    UNION ALL SELECT '500 g', 0.50, 2
    UNION ALL SELECT '1 kg', 1.00, 3
) sizes
WHERE LOWER(TRIM(v.unit)) IN ('kg', 'kilogram', 'kilograms', 'kgs', 'kilo')
  AND NOT EXISTS (
      SELECT 1
      FROM vegetable_variants vv
      WHERE vv.vegetable_id = v.id
        AND REPLACE(REPLACE(REPLACE(LOWER(TRIM(vv.label)), ' ', ''), 'grams', 'g'), 'gm', 'g')
            = REPLACE(REPLACE(REPLACE(LOWER(TRIM(sizes.label)), ' ', ''), 'grams', 'g'), 'gm', 'g')
  );

INSERT INTO vegetable_variants (vegetable_id, label, price, sort_order)
SELECT v.id, sizes.label, ROUND(v.price * sizes.price_fraction, 2), sizes.sort_order
FROM vegetables v
CROSS JOIN (
    SELECT '100 ml' AS label, 0.10 AS price_fraction, 0 AS sort_order
    UNION ALL SELECT '250 ml', 0.25, 1
    UNION ALL SELECT '500 ml', 0.50, 2
    UNION ALL SELECT '1 litre', 1.00, 3
) sizes
WHERE LOWER(TRIM(v.unit)) IN ('litre', 'litres', 'liter', 'liters', 'l')
  AND NOT EXISTS (
      SELECT 1
      FROM vegetable_variants vv
      WHERE vv.vegetable_id = v.id
        AND REPLACE(REPLACE(REPLACE(LOWER(TRIM(vv.label)), ' ', ''), 'liters', 'litre'), 'liter', 'litre')
            = REPLACE(REPLACE(REPLACE(LOWER(TRIM(sizes.label)), ' ', ''), 'liters', 'litre'), 'liter', 'litre')
  );
