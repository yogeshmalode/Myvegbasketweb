<?php
require_once __DIR__ . '/includes/auth.php';

$orders = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC")->fetchAll();

$itemsByOrder = [];
$itemRows = $pdo->query("SELECT order_id, name, quantity FROM order_items ORDER BY id")->fetchAll();
foreach ($itemRows as $row) {
    $itemsByOrder[$row['order_id']][] = $row['name'] . ' x' . $row['quantity'];
}

$filename = 'orders_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

// UTF-8 BOM so ₹ and names with accents open correctly in Excel
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Order #', 'Customer', 'Email', 'Phone', 'Address', 'Items', 'Total', 'Payment Method', 'Payment Status', 'Order Status', 'Placed On']);

foreach ($orders as $o) {
    fputcsv($out, [
        $o['id'],
        $o['customer_name'],
        $o['email'],
        $o['phone'],
        $o['address'],
        implode(', ', $itemsByOrder[$o['id']] ?? []),
        number_format($o['total_amount'], 2),
        $o['payment_method'] ?? 'razorpay',
        ucwords(str_replace('_', ' ', $o['payment_status'])),
        ucwords(str_replace('_', ' ', $o['order_status'])),
        format_ist($o['created_at']),
    ]);
}

fclose($out);
exit;
