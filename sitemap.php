<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/xml; charset=UTF-8');

$categories = $pdo->query("SELECT DISTINCT category FROM vegetables WHERE is_active = 1 ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= h(SITE_URL) ?>/index.php</loc>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
  </url>
  <?php foreach ($categories as $cat): ?>
  <url>
    <loc><?= h(SITE_URL) ?>/index.php?category=<?= urlencode($cat) ?></loc>
    <changefreq>daily</changefreq>
    <priority>0.7</priority>
  </url>
  <?php endforeach; ?>
  <url>
    <loc><?= h(SITE_URL) ?>/login.php</loc>
    <changefreq>monthly</changefreq>
    <priority>0.3</priority>
  </url>
  <url>
    <loc><?= h(SITE_URL) ?>/register.php</loc>
    <changefreq>monthly</changefreq>
    <priority>0.3</priority>
  </url>
</urlset>
