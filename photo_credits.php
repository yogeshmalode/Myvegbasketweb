<?php
require_once __DIR__ . '/config.php';

$photos = [
    'Tomato' => ['Tomato (1).jpg', 'Renee Comet / National Cancer Institute', 'Public domain'],
    'Potato' => ['A Potato.jpg', 'Gaurav Dhwaj Khadka', 'CC BY 4.0'],
    'Onion' => ['Onions 700x530.jpg', 'Rfc1394', 'Public domain'],
    'Carrot' => ['CARROT.jpg', 'Ranjithkumar Murugesan', 'CC0 1.0'],
    'Spinach' => ['Spinach.jpg', 'Krish Dulal', 'CC BY-SA 3.0'],
    'Cauliflower' => ['19 - cauliflower.jpg', 'Susan Slater', 'CC BY-SA 4.0'],
    'Capsicum' => ['Capsicum.jpg', 'Assianir', 'CC BY-SA 3.0'],
    'Brinjal' => ['Brinjal eggplant.jpg', 'Bhuvaneshwari kandhasamy', 'CC BY 4.0'],
    'Cucumber' => ['Cucumber.jpg', 'Καλλιόπη Αλεξοπούλου', 'Attribution required; see source'],
    'Cabbage' => ['Cabbage.jpg', 'USDA', 'Public domain'],
    'Green Peas' => ['Green Pea.jpg', 'James Moore200', 'CC BY-SA 4.0'],
    'Ginger' => ['Ginger Root.jpg', 'Sanjay Acharya', 'CC BY-SA 4.0'],
    'Garlic' => ['Garlic image.jpg', 'Dr. Satish Upalkar', 'CC BY-SA 4.0'],
    'Beetroot' => ['Beetroot fruit.jpg', 'Uploader: see source', 'CC0 1.0'],
    'Radish' => ['Radish.jpg', 'Felixphoto', 'CC0 1.0'],
    'Sweet Potato' => ['A sweet potato.jpg', 'JacquesDemien', 'CC0 1.0'],
    'Ladyfinger' => ['Ladyfinger.jpg', 'Shillika', 'CC BY-SA 3.0'],
    'Bottle Gourd' => ['Bottle gourd.jpg', 'Vis M', 'CC BY 4.0; see source'],
    'Bitter Gourd' => ['Bitter gourd.jpg', 'Tenbon', 'CC BY-SA 3.0'],
    'Green Beans' => ['French-beans.jpg', 'Vassia Atanassova - Spiritia', 'CC BY-SA; see source'],
    'Broccoli' => ['Broccoli.jpg', 'USDA Lance Cheung', 'CC BY 2.0'],
    'Green Chilli' => ['Green Chilli.jpg', 'Tahir mq', 'CC BY-SA 3.0'],
    'Sweet Corn' => ['Sweet corn.jpg', 'Challiyil Eswaramangalath Vipin', 'CC BY-SA 2.0'],
    'Apple' => ['Apple Fruit.jpg', 'Iwai-Dialax', 'CC BY 4.0'],
    'Banana' => ['Banana.jpg', 'Shinealight~commonswiki', 'Public domain'],
    'Mango' => ['Mango.jpg', 'See source page', 'CC BY 3.0'],
    'Orange' => ['Orange (1).jpg', 'Renee Comet / National Cancer Institute', 'Public domain'],
    'Grapes' => ['Ripe grapes.jpg', 'USDA Scott Bauer', 'Public domain'],
    'Papaya' => ['Papaya.jpg', 'USDA Scott Bauer', 'Public domain'],
    'Watermelon' => ['Watermelon.jpg', 'USDA Scott Bauer', 'Public domain'],
    'Pomegranate' => ['Pomegranate photo.jpg', 'Abithavelu', 'CC0 1.0'],
    'Guava' => ['Guava.jpg', 'Parvathisri', 'CC BY-SA 3.0'],
    'Pineapple' => ['Pineapple.jpg', 'Renee Comet / National Cancer Institute', 'Public domain'],
    'Milk' => ['Milk (24299977096).jpg', 'Pixel.la Free Stock Photos', 'CC0 1.0'],
    'Coriander' => ['Coriander leaves.jpg', 'Azaadsameer3', 'CC BY-SA 4.0'],
    'Fenugreek' => ['Fenugreek.jpg', 'Sathya Madhu', 'CC BY-SA 4.0'],
    'Mint' => ['Mint.jpg', 'See source page', 'CC BY-SA; see source'],
    'Curry Leaves' => ['Curry leaves.jpg', 'Dijaxavier', 'CC BY-SA 4.0'],
    'Amaranth' => ['Leaves of Amaranthus tricolor.jpg', 'Soramimi', 'CC BY-SA 4.0'],
    'Lettuce' => ['Raw lettuce.jpg', 'See source page', 'CC0 1.0'],
];

include __DIR__ . '/includes/header.php';
?>
<div class="container" style="padding:36px 0 56px; max-width:1000px;">
  <h1 style="margin-bottom:8px;">Product Photo Credits</h1>
  <p style="color:#5B6656; max-width:760px; line-height:1.6;">
    MyVegBasket uses real photographs from Wikimedia Commons under their listed free-use licences.
    Click a source to review the current file page and licence before publishing changes to your catalogue.
  </p>
  <div style="overflow:auto; margin-top:24px; border:1px solid #E4E9DD; border-radius:14px; background:#fff;">
    <table style="width:100%; border-collapse:collapse; min-width:760px;">
      <thead><tr style="background:#F4F7EF; text-align:left;">
        <th style="padding:12px;">Product</th><th style="padding:12px;">Photograph / creator</th><th style="padding:12px;">Licence</th><th style="padding:12px;">Source</th>
      </tr></thead>
      <tbody>
      <?php foreach ($photos as $product => [$file, $creator, $license]):
          $source = 'https://commons.wikimedia.org/wiki/File:' . rawurlencode($file);
      ?>
        <tr style="border-top:1px solid #EEF2E9;">
          <td style="padding:11px 12px; font-weight:700;"><?= h($product) ?></td>
          <td style="padding:11px 12px;"><?= h($file) ?><br><small style="color:#66705F;">©/creator: <?= h($creator) ?></small></td>
          <td style="padding:11px 12px;"><?= h($license) ?></td>
          <td style="padding:11px 12px;"><a href="<?= h($source) ?>" target="_blank" rel="noopener noreferrer">Wikimedia Commons</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p style="margin-top:18px; color:#687360; font-size:.88rem;">
    Licences can change or have additional conditions. The linked Commons file page is the authoritative source for reuse terms.
  </p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
