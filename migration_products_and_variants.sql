-- =========================================================
-- VegBasket - COMBINED migration: new products + size variants
-- This is migration_new_products.sql and migration_variants.sql
-- pasted together in the right order, so you only have to run
-- ONE file instead of two.
--
-- HOW TO RUN THIS (phpMyAdmin):
--   1. Log into your Hostinger hPanel -> Databases -> phpMyAdmin
--   2. Click your database (u437666696_Vegbasket) on the left
--   3. Click the "SQL" tab along the top
--   4. Select ALL the text in this file (Ctrl+A) and paste it into
--      the big text box
--   5. Click "Go" at the bottom right
--   6. You should see a green success message. If you see a red
--      error instead, copy the exact error text and send it back.
-- =========================================================

-- =========================================================
-- VegBasket - New products migration (fruits, milk, more veg)
-- Run this ONCE on your existing live database (phpMyAdmin ->
-- SQL tab, or `mysql -u user -p dbname < migration_new_products.sql`).
--
-- Each row only gets inserted if a product with that exact name
-- doesn't already exist, so this is safe to run even if you've
-- already added some of these yourself — it just fills in the gaps.
-- =========================================================

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Tomato','Farm fresh red tomatoes',30.00,'kg',120,'tomato.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Tomato');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Potato','Clean washed potatoes',25.00,'kg',200,'potato.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Potato');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Onion','Premium quality onions',35.00,'kg',150,'onion.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Onion');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Carrot','Crunchy orange carrots',40.00,'kg',90,'carrot.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Carrot');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Spinach','Leafy green spinach bunch',20.00,'bunch',70,'spinach.svg','Leafy'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Spinach');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Cauliflower','Whole white cauliflower',30.00,'piece',60,'cauliflower.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Cauliflower');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Cucumber','Fresh green cucumber',22.00,'kg',100,'cucumber.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Cucumber');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Green Peas','Shelled fresh peas',60.00,'kg',40,'peas.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Green Peas');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Ginger','Fresh ginger root',90.00,'kg',50,'ginger.svg','Root'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Ginger');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Garlic','Fresh garlic bulbs',180.00,'kg',60,'garlic.svg','Root'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Garlic');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Beetroot','Deep red beetroot',35.00,'kg',60,'beetroot.svg','Root'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Beetroot');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Radish','White radish',20.00,'kg',60,'radish.svg','Root'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Radish');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Sweet Potato','Orange sweet potato',45.00,'kg',50,'sweetpotato.svg','Root'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Sweet Potato');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Ladyfinger','Fresh okra / bhindi',40.00,'kg',60,'ladyfinger.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Ladyfinger');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Bottle Gourd','Fresh lauki',22.00,'kg',50,'bottlegourd.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Bottle Gourd');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Bitter Gourd','Fresh karela',45.00,'kg',40,'bittergourd.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Bitter Gourd');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Green Beans','Fresh French beans',50.00,'kg',50,'greenbeans.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Green Beans');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Broccoli','Fresh green broccoli',90.00,'piece',30,'broccoli.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Broccoli');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Green Chilli','Spicy green chillies',60.00,'kg',40,'chilli.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Green Chilli');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Sweet Corn','Fresh corn cob',15.00,'piece',80,'corn.svg','Vegetable'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Sweet Corn');

-- Fruits
INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Apple','Crisp red apples',180.00,'kg',80,'apple.svg','Fruit'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Apple');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Banana','Ripe yellow bananas',50.00,'dozen',90,'banana.svg','Fruit'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Banana');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Mango','Sweet Alphonso mango',120.00,'kg',60,'mango.svg','Fruit'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Mango');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Orange','Juicy oranges',80.00,'kg',70,'orange.svg','Fruit'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Orange');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Grapes','Seedless green grapes',90.00,'kg',50,'grapes.svg','Fruit'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Grapes');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Papaya','Ripe papaya',30.00,'kg',40,'papaya.svg','Fruit'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Papaya');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Watermelon','Sweet watermelon',25.00,'kg',50,'watermelon.svg','Fruit'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Watermelon');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Pomegranate','Fresh pomegranate',150.00,'kg',40,'pomegranate.svg','Fruit'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Pomegranate');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Guava','Fresh guava',60.00,'kg',40,'guava.svg','Fruit'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Guava');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Pineapple','Sweet pineapple',40.00,'piece',30,'pineapple.svg','Fruit'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Pineapple');

-- Dairy
INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Milk','Fresh full-cream milk',60.00,'litre',100,'milk.svg','Dairy'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Milk');
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
