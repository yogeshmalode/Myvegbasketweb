-- =========================================================
-- VegBasket - Flower size/weight variants
-- Same approach as migration_variants.sql uses for kg-priced
-- vegetables (250 g / 500 g / 1 kg) — here applied to the
-- weight-based (100 g) flowers so customers can pick 100 g /
-- 250 g / 500 g packs, each with its own price.
--
-- Requires vegetable_variants table (created by migration_variants.sql).
-- Safe to re-run: skips any flower that already has variants.
-- =========================================================

INSERT INTO vegetable_variants (vegetable_id, label, price, sort_order)
SELECT v.id, '100 g', v.price, 1
FROM vegetables v
WHERE v.category = 'Flower' AND v.unit = '100g'
  AND NOT EXISTS (SELECT 1 FROM vegetable_variants vv WHERE vv.vegetable_id = v.id)
UNION ALL
SELECT v.id, '250 g', ROUND(v.price * 2.5, 2), 2
FROM vegetables v
WHERE v.category = 'Flower' AND v.unit = '100g'
  AND NOT EXISTS (SELECT 1 FROM vegetable_variants vv WHERE vv.vegetable_id = v.id)
UNION ALL
SELECT v.id, '500 g', ROUND(v.price * 5, 2), 3
FROM vegetables v
WHERE v.category = 'Flower' AND v.unit = '100g'
  AND NOT EXISTS (SELECT 1 FROM vegetable_variants vv WHERE vv.vegetable_id = v.id);
