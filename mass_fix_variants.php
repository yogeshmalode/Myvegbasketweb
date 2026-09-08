<?php
require __DIR__ . '/config.php';
try {
    $units = ['kg','litre'];
    $now = time();
    $vegBackup = "vegetables_mass_backup_{$now}";
    $varBackup = "vegetable_variants_mass_backup_{$now}";
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$vegBackup} LIKE vegetables");
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$varBackup} LIKE vegetable_variants");

    $summary = [];

    foreach ($units as $unit) {
        // average price for this unit among products with price>0
        $avg = (float)$pdo->query("SELECT AVG(price) FROM vegetables WHERE unit = '" . $unit . "' AND price > 0")->fetchColumn();
        if (!$avg) $avg = ($unit === 'kg') ? 60.0 : 30.0;

        // find veg needing update: either base price 0 OR has variant with price 0
        $sql = "SELECT v.id, v.name, v.price FROM vegetables v WHERE v.unit = ? AND (v.price <= 0 OR EXISTS (SELECT 1 FROM vegetable_variants vv WHERE vv.vegetable_id = v.id AND (vv.price IS NULL OR vv.price = 0)))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$unit]);
        $vegs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($vegs as $veg) {
            $id = (int)$veg['id'];
            $name = $veg['name'];
            $originalPrice = (float)$veg['price'];

            // backup rows
            $pdo->exec("INSERT INTO {$vegBackup} SELECT * FROM vegetables WHERE id = {$id}");
            $pdo->exec("INSERT INTO {$varBackup} SELECT * FROM vegetable_variants WHERE vegetable_id = {$id}");

            $applied = ['veg_id' => $id, 'name' => $name, 'old_price' => $originalPrice, 'new_price' => null, 'variants_updated' => []];

            // set base price if zero
            if ($originalPrice <= 0) {
                $basePrice = round($avg, 2);
                $cost = round($basePrice / 1.45, 2);
                $u = $pdo->prepare("UPDATE vegetables SET price = ?, cost_price = ? WHERE id = ?");
                $u->execute([$basePrice, $cost, $id]);
                $applied['new_price'] = $basePrice;
            } else {
                $basePrice = $originalPrice;
                $applied['new_price'] = $basePrice;
            }

            // update any variant with price 0 to proportional fraction
            $vstmt = $pdo->prepare('SELECT id,label,price FROM vegetable_variants WHERE vegetable_id = ?');
            $vstmt->execute([$id]);
            $vars = $vstmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($vars as $vv) {
                $vid = (int)$vv['id'];
                $vlabel = $vv['label'];
                $vprice = (float)$vv['price'];
                // skip non-weight labels (e.g., "5 Rs") for kg/litre
                $fraction = null;
                try { $fraction = size_fraction_of_base_unit($vlabel, $unit); } catch (Throwable $e) { $fraction = null; }
                if ($fraction !== null && $fraction > 0) {
                    $newp = round($basePrice * $fraction, 2);
                    if ($vprice <= 0 || abs($vprice - $newp) > 0.005) {
                        $pdo->prepare('UPDATE vegetable_variants SET price = ? WHERE id = ?')->execute([$newp, $vid]);
                        $applied['variants_updated'][] = ['id' => $vid, 'label' => $vlabel, 'old' => $vprice, 'new' => $newp];
                    }
                }
            }

            $summary[] = $applied;
        }
    }

    echo "Mass update completed. Backups: {$vegBackup}, {$varBackup}\n";
    foreach ($summary as $s) {
        echo "Veg {$s['veg_id']} ({$s['name']}): base {$s['old_price']} -> {$s['new_price']}\n";
        foreach ($s['variants_updated'] as $vu) {
            echo "  Var {$vu['id']} ({$vu['label']}): {$vu['old']} -> {$vu['new']}\n";
        }
    }

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
