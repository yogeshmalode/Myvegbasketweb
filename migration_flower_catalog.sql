-- =========================================================
-- VegBasket - Flower catalog additions
-- Run this once in phpMyAdmin or with MySQL to add a new "Flower" tab
-- alongside the existing vegetable/fruits categories.
-- =========================================================

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Rose','गुलाब','Fresh rose bunch for garlands, pooja and decoration',80.00,'bunch',35,'rose.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Rose') OR LOWER(name_mr) = LOWER('गुलाब'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Lotus','कमळ','Fresh lotus bloom for rituals and home decor',120.00,'piece',20,'lotus.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Lotus') OR LOWER(name_mr) = LOWER('कमळ'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Marigold','झेंडू','Bright marigold flowers for festive use and garlands',50.00,'bunch',45,'marigold.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Marigold') OR LOWER(name_mr) = LOWER('झेंडू'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Sunflower','सूर्यफूल','Fresh sunflower blooms for gifting and decoration',100.00,'piece',25,'sunflower.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Sunflower') OR LOWER(name_mr) = LOWER('सूर्यफूल'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Hibiscus','जास्वंद','Fresh hibiscus flowers with vibrant colour and fragrance',60.00,'bunch',30,'hibiscus.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Hibiscus') OR LOWER(name_mr) = LOWER('जास्वंद'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Arabian Jasmine','मोगरा','Fragrant Arabian jasmine bunch for puja and perfumed corners',90.00,'bunch',28,'arabianjasmine.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Arabian Jasmine') OR LOWER(name_mr) = LOWER('मोगरा'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Common Jasmine','जुई','Fresh common jasmine flowers for daily worship and garlands',75.00,'bunch',30,'commonjasmine.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Common Jasmine') OR LOWER(name_mr) = LOWER('जुई'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Spanish Jasmine','जाई','Fresh Spanish jasmine blooms with a sweet scent',85.00,'bunch',26,'spanishjasmine.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Spanish Jasmine') OR LOWER(name_mr) = LOWER('जाई'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Periwinkle','सदाफुली','Vivid periwinkle blooms for temple offerings and décor',70.00,'bunch',32,'periwinkle.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Periwinkle') OR LOWER(name_mr) = LOWER('सदाफुली'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Chrysanthemum','शेवंती','Fresh chrysanthemum flowers for festive arrangements',90.00,'bunch',24,'chrysanthemum.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Chrysanthemum') OR LOWER(name_mr) = LOWER('शेवंती'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Plumeria / Frangipani','चाफा / सोनचाफा','Fragrant plumeria flowers for decoration and pooja',100.00,'bunch',28,'plumeria.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Plumeria / Frangipani') OR LOWER(name_mr) = LOWER('चाफा / सोनचाफा'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Tuberose','निशिगंधा','Elegant tuberose flowers with a rich floral fragrance',110.00,'bunch',22,'tuberose.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Tuberose') OR LOWER(name_mr) = LOWER('निशिगंधा'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Oleander','कण्हेर','Fresh oleander stems for decor and festive use',65.00,'bunch',30,'oleander.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Oleander') OR LOWER(name_mr) = LOWER('कण्हेर'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Night Blooming Jasmine','रातराणी','Night-flowering jasmine for a rich and sweet scent',95.00,'bunch',24,'nightbloomingjasmine.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Night Blooming Jasmine') OR LOWER(name_mr) = LOWER('रातराणी'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Coral Jasmine','पारिजातक / प्राजक्त','Fresh coral jasmine bunches for ritual and décor use',100.00,'bunch',25,'coraljasmine.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Coral Jasmine') OR LOWER(name_mr) = LOWER('पारिजातक / प्राजक्त'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Water Lily','कुमुदिनी','Fresh water lily blooms for serene floral arrangements',120.00,'piece',16,'waterlily.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Water Lily') OR LOWER(name_mr) = LOWER('कुमुदिनी'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Daisy','गुलबहार','Fresh daisy flowers for cheerful, soft arrangements',80.00,'bunch',30,'daisy.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Daisy') OR LOWER(name_mr) = LOWER('गुलबहार'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Bougainvillea','बोगनवेल','Colourful bougainvillea blooms for festive décor',70.00,'bunch',35,'bougainvillea.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Bougainvillea') OR LOWER(name_mr) = LOWER('बोगनवेल'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Crossandra','अबोली','Bright crossandra blooms for temple offerings and decor',60.00,'bunch',34,'crossandra.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Crossandra') OR LOWER(name_mr) = LOWER('अबोली'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Touch-Me-Not','लाजाळू','Fresh touch-me-not blooms for ornamental use',55.00,'bunch',28,'touchmenot.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Touch-Me-Not') OR LOWER(name_mr) = LOWER('लाजाळू'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Datura','धोतरा','Fresh datura blooms used in temple rituals and décor',75.00,'bunch',26,'datura.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Datura') OR LOWER(name_mr) = LOWER('धोतरा'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Flame of the Forest','पळस','Vibrant flame-of-the-forest flowers with fiery colour',90.00,'bunch',20,'flameoftheforest.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Flame of the Forest') OR LOWER(name_mr) = LOWER('पळस'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Delonix Regia / Gulmohar','गुलमोहर','Fresh gulmohar blossoms for dramatic festive décor',100.00,'bunch',22,'gulmohar.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Delonix Regia / Gulmohar') OR LOWER(name_mr) = LOWER('गुलमोहर'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Spanish Cherry','बकुळ','Fresh Spanish cherry flowers for ornamental arrangements',80.00,'bunch',24,'spanishcherry.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Spanish Cherry') OR LOWER(name_mr) = LOWER('बकुळ'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Cypress Vine','गणेशवेल','Fresh cypress vine flowers for home gardens and décor',70.00,'bunch',28,'cypressvine.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Cypress Vine') OR LOWER(name_mr) = LOWER('गणेशवेल'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Rangoon Creeper','मधुमालती','Fragrant rangoon creeper blooms for trellis gardens',85.00,'bunch',24,'rangooncreeper.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Rangoon Creeper') OR LOWER(name_mr) = LOWER('मधुमालती'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Tulip','ट्युलिप','Fresh tulip blooms in bright seasonal colours',90.00,'bunch',26,'tulip.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Tulip') OR LOWER(name_mr) = LOWER('ट्युलिप'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Lily','लिली','Fresh lily blooms for festive gifting and home styling',95.00,'bunch',22,'lily.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Lily') OR LOWER(name_mr) = LOWER('लिली'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Dahlia','डहाळी','Elegant dahlia blooms for premium floral décor',85.00,'bunch',20,'dahlia.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Dahlia') OR LOWER(name_mr) = LOWER('डहाळी'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Orchid','ऑर्किड','Premium orchid blooms for gifting and luxury décor',140.00,'bunch',18,'orchid.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Orchid') OR LOWER(name_mr) = LOWER('ऑर्किड'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Balsam','तेरडा','Bright balsam flowers for fresh home décor',70.00,'bunch',28,'balsam.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Balsam') OR LOWER(name_mr) = LOWER('तेरडा'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Giant Milkweed','रुई','Fresh giant milkweed blooms for festival décor',60.00,'bunch',30,'giantmilkweed.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Giant Milkweed') OR LOWER(name_mr) = LOWER('रुई'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Crape Jasmine','तगर','Fresh crape jasmine flowers with a soft fragrance',80.00,'bunch',26,'crapejasmine.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Crape Jasmine') OR LOWER(name_mr) = LOWER('तगर'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Cockscomb','मोरशिखा / कुर्डू','Unique cockscomb blooms for decorative styling',75.00,'bunch',20,'cockscomb.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Cockscomb') OR LOWER(name_mr) = LOWER('मोरशिखा / कुर्डू'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Screw Pine','केवडा / केतकी','Fresh screw pine flower clusters for festive décor',90.00,'bunch',18,'screwpine.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Screw Pine') OR LOWER(name_mr) = LOWER('केवडा / केतकी'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Pansy','बनफूल','Fresh pansy flowers for soft, decorative flower arrangements',65.00,'bunch',30,'pansy.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Pansy') OR LOWER(name_mr) = LOWER('बनफूल'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Poppy','खसखसचे फूल','Fresh poppy blooms for seasonal décor and gifting',85.00,'bunch',24,'poppy.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Poppy') OR LOWER(name_mr) = LOWER('खसखसचे फूल'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Gladiolus','तलवारफूल','Fresh gladiolus spikes for premium floral arrangements',110.00,'bunch',18,'gladiolus.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Gladiolus') OR LOWER(name_mr) = LOWER('तलवारफूल'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Lavender','लॅव्हेंडर','Fresh lavender stems for fragrance and gifting',100.00,'bunch',18,'lavender.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Lavender') OR LOWER(name_mr) = LOWER('लॅव्हेंडर'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Passion Flower','कृष्णकमळ','Fresh passion flower blooms for ornamental displays',90.00,'bunch',20,'passionflower.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Passion Flower') OR LOWER(name_mr) = LOWER('कृष्णकमळ'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Brahma Kamal','ब्रह्मकमळ','Rare brahma kamal blooms for auspicious occasions',200.00,'piece',12,'brahmakamal.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Brahma Kamal') OR LOWER(name_mr) = LOWER('ब्रह्मकमळ'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Kadamba','कदंब','Fresh kadamba flower bunches for ceremony and décor',75.00,'bunch',22,'kadamba.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Kadamba') OR LOWER(name_mr) = LOWER('कदंब'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Saffron Flower','केशर फूल','Fresh saffron flower blooms for special festive use',180.00,'bunch',12,'saffronflower.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Saffron Flower') OR LOWER(name_mr) = LOWER('केशर फूल'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Bluebell','नीलघंटी','Fresh bluebell blooms for delicate arrangements',70.00,'bunch',26,'bluebell.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Bluebell') OR LOWER(name_mr) = LOWER('नीलघंटी'));

INSERT INTO vegetables (name, name_mr, description, price, unit, stock, image, category)
SELECT 'Flower Bud','कळी','Fresh flower buds for traditional décor and festive arrangements',40.00,'bunch',35,'flowerbud.svg','Flower'
WHERE NOT EXISTS (SELECT 1 FROM vegetables WHERE LOWER(name) = LOWER('Flower Bud') OR LOWER(name_mr) = LOWER('कळी'));
