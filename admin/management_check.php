<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin']);
$page_title = 'Management System Check';
$checks = [];
$tables = ['admins','vegetables','orders','order_items','wastage','inventory_movements'];
foreach ($tables as $table) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $st->execute([DB_NAME, $table]);
    $checks[$table] = (int)$st->fetchColumn() > 0;
}
$columns = [
 'admins.role' => ['admins','role'],
 'vegetables.supplier_name' => ['vegetables','supplier_name'],
 'vegetables.cost_price' => ['vegetables','cost_price'],
 'orders.discount_amount' => ['orders','discount_amount'],
 'order_items.cost_price' => ['order_items','cost_price'],
];
foreach ($columns as $label => [$table,$column]) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([DB_NAME,$table,$column]);
    $checks[$label] = (int)$st->fetchColumn() > 0;
}
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head" style="text-align:left;margin-top:0"><h2 style="display:block">Management System Check</h2><p>This page verifies that the Inventory, Billing, Wastage and Reports database objects exist.</p></div>
<div class="form-card"><table style="width:100%"><tr><th style="text-align:left">Database object</th><th>Status</th></tr>
<?php foreach ($checks as $name=>$ok): ?><tr><td><?=h($name)?></td><td><?= $ok ? '✅ Ready' : '❌ Missing' ?></td></tr><?php endforeach; ?></table>
<p style="margin-top:18px">If anything is missing, log out and back in once. The current version automatically repairs missing management columns/tables when the admin area loads. If your hosting account blocks ALTER/CREATE permissions, import <code>migration_management_system.sql</code> in phpMyAdmin using the database selected in <code>config.php</code>.</p></div>
<?php include __DIR__ . '/includes/admin_footer.php';
