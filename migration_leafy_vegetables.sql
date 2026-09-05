-- =========================================================
-- VegBasket - Leafy greens migration
-- Run this ONCE on your existing live database (phpMyAdmin ->
-- SQL tab). Safe to run even if you already have some of these —
-- it only adds a product if that exact name doesn't already exist.
-- =========================================================

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Coriander','Fresh coriander (dhania) bunch',15.00,'bunch',80,'coriander.svg','Leafy'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Coriander');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Fenugreek','Fresh fenugreek (methi) bunch',18.00,'bunch',60,'fenugreek.svg','Leafy'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Fenugreek');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Mint','Fresh mint (pudina) bunch',12.00,'bunch',70,'mint.svg','Leafy'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Mint');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Curry Leaves','Fresh curry leaves bunch',10.00,'bunch',60,'curryleaves.svg','Leafy'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Curry Leaves');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Amaranth','Fresh amaranth (chaulai) bunch',20.00,'bunch',50,'amaranth.svg','Leafy'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Amaranth');

INSERT INTO vegetables (name, description, price, unit, stock, image, category)
SELECT 'Lettuce','Crisp fresh lettuce',35.00,'piece',40,'lettuce.svg','Leafy'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE name = 'Lettuce');
