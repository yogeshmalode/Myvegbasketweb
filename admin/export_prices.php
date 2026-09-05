<?php
require_once __DIR__ . '/includes/auth.php';

$vegetables = $pdo->query("SELECT * FROM vegetables ORDER BY category, name")->fetchAll();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="myvegbasket_prices_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₹/Marathi text correctly

fputcsv($out, ['ID', 'Name', 'Price', 'Sale Price', 'Stock', 'Unit (do not edit)']);
foreach ($vegetables as $veg) {
    fputcsv($out, [$veg['id'], $veg['name'], $veg['price'], $veg['sale_price'] ?? '', $veg['stock'], $veg['unit']]);
}
fclose($out);
exit;
