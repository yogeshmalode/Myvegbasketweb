-- =========================================================
-- VegBasket - Flower catalog: switch some items to weight-based
-- pricing (100 g) instead of bunch/piece, matching how flower
-- markets actually sell loose/garland flowers vs stem flowers.
--
-- Safe to re-run: every statement targets an exact name/name_mr match.
-- =========================================================

-- ---- Loose / garland flowers -> sold per 100 g ----
UPDATE vegetables SET unit = '100g', price = 25.00  WHERE LOWER(name) = LOWER('Marigold') OR LOWER(name_mr) = LOWER('झेंडू');
UPDATE vegetables SET unit = '100g', price = 60.00  WHERE LOWER(name) = LOWER('Arabian Jasmine') OR LOWER(name_mr) = LOWER('मोगरा');
UPDATE vegetables SET unit = '100g', price = 45.00  WHERE LOWER(name) = LOWER('Common Jasmine') OR LOWER(name_mr) = LOWER('जुई');
UPDATE vegetables SET unit = '100g', price = 50.00  WHERE LOWER(name) = LOWER('Spanish Jasmine') OR LOWER(name_mr) = LOWER('जाई');
UPDATE vegetables SET unit = '100g', price = 50.00  WHERE LOWER(name) = LOWER('Chrysanthemum') OR LOWER(name_mr) = LOWER('शेवंती');
UPDATE vegetables SET unit = '100g', price = 35.00  WHERE LOWER(name) = LOWER('Crossandra') OR LOWER(name_mr) = LOWER('अबोली');
UPDATE vegetables SET unit = '100g', price = 65.00  WHERE LOWER(name) = LOWER('Tuberose') OR LOWER(name_mr) = LOWER('निशिगंधा');
UPDATE vegetables SET unit = '100g', price = 55.00  WHERE LOWER(name) = LOWER('Night Blooming Jasmine') OR LOWER(name_mr) = LOWER('रातराणी');
UPDATE vegetables SET unit = '100g', price = 60.00  WHERE LOWER(name) = LOWER('Coral Jasmine') OR LOWER(name_mr) = LOWER('पारिजातक / प्राजक्त');
UPDATE vegetables SET unit = '100g', price = 40.00  WHERE LOWER(name) = LOWER('Periwinkle') OR LOWER(name_mr) = LOWER('सदाफुली');
UPDATE vegetables SET unit = '100g', price = 45.00  WHERE LOWER(name) = LOWER('Crape Jasmine') OR LOWER(name_mr) = LOWER('तगर');
UPDATE vegetables SET unit = '100g', price = 30.00  WHERE LOWER(name) = LOWER('Giant Milkweed') OR LOWER(name_mr) = LOWER('रुई');
UPDATE vegetables SET unit = '100g', price = 25.00  WHERE LOWER(name) = LOWER('Flower Bud') OR LOWER(name_mr) = LOWER('कळी');
UPDATE vegetables SET unit = '100g', price = 35.00  WHERE LOWER(name) = LOWER('Hibiscus') OR LOWER(name_mr) = LOWER('जास्वंद');

-- ---- Stem / display flowers -> stay bunch or piece (unchanged, listed
-- here only for clarity/reference; no price change needed) ----
-- Rose, Lotus, Sunflower, Oleander, Daisy, Bougainvillea, Touch-Me-Not,
-- Datura, Flame of the Forest, Delonix Regia / Gulmohar, Spanish Cherry,
-- Cypress Vine, Rangoon Creeper, Tulip, Lily, Dahlia, Orchid, Balsam,
-- Cockscomb, Screw Pine, Pansy, Poppy, Gladiolus, Lavender,
-- Passion Flower, Brahma Kamal, Kadamba, Saffron Flower, Bluebell,
-- Water Lily, Plumeria / Frangipani remain 'bunch' or 'piece'.
