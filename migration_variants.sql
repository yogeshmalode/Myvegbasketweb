-- =========================================================
-- VegBasket - Product size/weight variants migration
-- Run this ONCE on your existing live database (phpMyAdmin ->
-- SQL tab). Run migration_new_products.sql FIRST if you haven't
-- already, so the variant-seeding step below has all products
-- to work with.
--
-- Adds a vegetable_variants table (e.g. "250 g", "500 g", "1 kg"
-- per product, each with its own price) and links order_items to
-- whichever variant was actually ordered. A product with no rows
-- here just keeps using its normal price/unit as before — fully
-- backward compatible.
-- =========================================================

CREATE TABLE IF NOT EXISTS vegetable_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vegetable_id INT NOT NULL,
    label VARCHAR(30) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (vegetable_id) REFERENCES vegetables(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE order_items
    ADD COLUMN vegetable_variant_id INT DEFAULT NULL,
    ADD COLUMN variant_label VARCHAR(30) DEFAULT NULL;

-- Seed 250 g / 500 g / 1 kg options for every kg-priced product that
-- doesn't already have variants (skips items already sold per-piece,
-- per-bunch, per-dozen, etc, and skips anything you've already added
-- variants to yourself).
INSERT INTO vegetable_variants (vegetable_id, label, price, sort_order)
SELECT v.id, '250 g', ROUND(v.price/4, 2), 1
FROM vegetables v
WHERE v.unit = 'kg'
  AND NOT EXISTS (SELECT 1 FROM vegetable_variants vv WHERE vv.vegetable_id = v.id)
UNION ALL
SELECT v.id, '500 g', ROUND(v.price/2, 2), 2
FROM vegetables v
WHERE v.unit = 'kg'
  AND NOT EXISTS (SELECT 1 FROM vegetable_variants vv WHERE vv.vegetable_id = v.id)
UNION ALL
SELECT v.id, '1 kg', v.price, 3
FROM vegetables v
WHERE v.unit = 'kg'
  AND NOT EXISTS (SELECT 1 FROM vegetable_variants vv WHERE vv.vegetable_id = v.id);

-- Same idea for Milk (or anything sold per litre): 250 ml / 500 ml / 1 litre.
INSERT INTO vegetable_variants (vegetable_id, label, price, sort_order)
SELECT v.id, '250 ml', ROUND(v.price/4, 2), 1
FROM vegetables v
WHERE v.unit = 'litre'
  AND NOT EXISTS (SELECT 1 FROM vegetable_variants vv WHERE vv.vegetable_id = v.id)
UNION ALL
SELECT v.id, '500 ml', ROUND(v.price/2, 2), 2
FROM vegetables v
WHERE v.unit = 'litre'
  AND NOT EXISTS (SELECT 1 FROM vegetable_variants vv WHERE vv.vegetable_id = v.id)
UNION ALL
SELECT v.id, '1 litre', v.price, 3
FROM vegetables v
WHERE v.unit = 'litre'
  AND NOT EXISTS (SELECT 1 FROM vegetable_variants vv WHERE vv.vegetable_id = v.id);
