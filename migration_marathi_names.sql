-- =========================================================
-- VegBasket - Marathi product names migration
-- Run this ONCE on your existing live database (phpMyAdmin ->
-- SQL tab). Adds a Marathi name next to the English name for
-- every product already in your catalog.
-- =========================================================

ALTER TABLE vegetables
    ADD COLUMN name_mr VARCHAR(100) DEFAULT NULL AFTER name;

UPDATE vegetables SET name_mr = 'वांगे' WHERE name = 'Brinjal';
UPDATE vegetables SET name_mr = 'कोबी' WHERE name = 'Cabbage';
UPDATE vegetables SET name_mr = 'ढोबळी मिरची' WHERE name = 'Capsicum';
UPDATE vegetables SET name_mr = 'टोमॅटो' WHERE name = 'Tomato';
UPDATE vegetables SET name_mr = 'बटाटा' WHERE name = 'Potato';
UPDATE vegetables SET name_mr = 'कांदा' WHERE name = 'Onion';
UPDATE vegetables SET name_mr = 'गाजर' WHERE name = 'Carrot';
UPDATE vegetables SET name_mr = 'पालक' WHERE name = 'Spinach';
UPDATE vegetables SET name_mr = 'फ्लॉवर' WHERE name = 'Cauliflower';
UPDATE vegetables SET name_mr = 'काकडी' WHERE name = 'Cucumber';
UPDATE vegetables SET name_mr = 'वाटाणा' WHERE name = 'Green Peas';
UPDATE vegetables SET name_mr = 'आले' WHERE name = 'Ginger';
UPDATE vegetables SET name_mr = 'लसूण' WHERE name = 'Garlic';
UPDATE vegetables SET name_mr = 'बीट' WHERE name = 'Beetroot';
UPDATE vegetables SET name_mr = 'मुळा' WHERE name = 'Radish';
UPDATE vegetables SET name_mr = 'रताळे' WHERE name = 'Sweet Potato';
UPDATE vegetables SET name_mr = 'भेंडी' WHERE name = 'Ladyfinger';
UPDATE vegetables SET name_mr = 'दुधी भोपळा' WHERE name = 'Bottle Gourd';
UPDATE vegetables SET name_mr = 'कारले' WHERE name = 'Bitter Gourd';
UPDATE vegetables SET name_mr = 'घेवडा' WHERE name = 'Green Beans';
UPDATE vegetables SET name_mr = 'ब्रोकोली' WHERE name = 'Broccoli';
UPDATE vegetables SET name_mr = 'हिरवी मिरची' WHERE name = 'Green Chilli';
UPDATE vegetables SET name_mr = 'मका' WHERE name = 'Sweet Corn';
UPDATE vegetables SET name_mr = 'सफरचंद' WHERE name = 'Apple';
UPDATE vegetables SET name_mr = 'केळे' WHERE name = 'Banana';
UPDATE vegetables SET name_mr = 'आंबा' WHERE name = 'Mango';
UPDATE vegetables SET name_mr = 'संत्रे' WHERE name = 'Orange';
UPDATE vegetables SET name_mr = 'द्राक्षे' WHERE name = 'Grapes';
UPDATE vegetables SET name_mr = 'पपई' WHERE name = 'Papaya';
UPDATE vegetables SET name_mr = 'कलिंगड' WHERE name = 'Watermelon';
UPDATE vegetables SET name_mr = 'डाळिंब' WHERE name = 'Pomegranate';
UPDATE vegetables SET name_mr = 'पेरू' WHERE name = 'Guava';
UPDATE vegetables SET name_mr = 'अननस' WHERE name = 'Pineapple';
UPDATE vegetables SET name_mr = 'दूध' WHERE name = 'Milk';
UPDATE vegetables SET name_mr = 'कोथिंबीर' WHERE name = 'Coriander';
UPDATE vegetables SET name_mr = 'मेथी' WHERE name = 'Fenugreek';
UPDATE vegetables SET name_mr = 'पुदिना' WHERE name = 'Mint';
UPDATE vegetables SET name_mr = 'कढीपत्ता' WHERE name = 'Curry Leaves';
UPDATE vegetables SET name_mr = 'चवळई' WHERE name = 'Amaranth';
UPDATE vegetables SET name_mr = 'लेट्युस' WHERE name = 'Lettuce';
