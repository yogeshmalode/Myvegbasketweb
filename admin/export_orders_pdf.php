<?php
require_once __DIR__ . '/includes/auth.php';

$orders = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC")->fetchAll();

$itemsByOrder = [];
$itemRows = $pdo->query("SELECT order_id, name, quantity FROM order_items ORDER BY id")->fetchAll();
foreach ($itemRows as $row) {
    $itemsByOrder[$row['order_id']][] = $row['name'] . ' x' . $row['quantity'];
}

// =========================================================
// A tiny, dependency-free PDF writer.
// Shared hosting often doesn't have Composer/TCPDF/mPDF available,
// so this builds a valid PDF by hand using only PHP's built-in
// string functions — no libraries to install.
// =========================================================
class SimplePdf
{
    private array $pages = [];
    private string $current = '';
    private float $y = 0;
    private const PAGE_W = 792; // Letter, landscape
    private const PAGE_H = 612;
    private const MARGIN = 30;

    public function __construct(private string $title)
    {
        $this->newPage();
    }

    private function newPage(): void
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }
        $this->current = '';
        $this->y = self::PAGE_H - self::MARGIN;
        $this->text(self::MARGIN, $this->y, 14, $this->title, true);
        $this->y -= 22;
        $this->drawHeaderRow();
    }

    private function esc(string $s): string
    {
        // Keep to WinAnsi-safe characters; drop the rupee sign and any
        // other characters the base-14 Helvetica font can't render.
        $s = str_replace('₹', 'Rs. ', $s);
        $s = preg_replace('/[^\x20-\x7E]/', '', $s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function text(float $x, float $y, int $size, string $str, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->current .= "BT /$font $size Tf $x $y Td (" . $this->esc($str) . ") Tj ET\n";
    }

    private function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->current .= "0.7 w $x1 $y1 m $x2 $y2 l S\n";
    }

    public function ensureSpace(float $needed): void
    {
        if ($this->y - $needed < self::MARGIN) {
            $this->newPage();
        }
    }

    /** Draws one order as a small block; the address and item list wrap onto their own lines below the main row. */
    public function addOrderRow(array $cols): void
    {
        // cols: ['#'=>, 'customer'=>, 'phone'=>, 'address'=>, 'items'=>, 'total'=>, 'payment'=>, 'status'=>, 'date'=>]
        $addressLines = explode("\n", wordwrap('Address: ' . $cols['address'], 110, "\n", true));
        $itemLines    = explode("\n", wordwrap('Items: ' . $cols['items'], 110, "\n", true));
        $extraLines   = array_merge($addressLines, $itemLines);
        $rowHeight    = 14 + (11 * count($extraLines)) + 8;
        $this->ensureSpace($rowHeight);

        $topY = $this->y;
        $this->text(self::MARGIN, $topY, 9, '#' . $cols['#'], true);
        $this->text(self::MARGIN + 30, $topY, 9, $cols['customer']);
        $this->text(self::MARGIN + 185, $topY, 9, $cols['phone']);
        $this->text(self::MARGIN + 270, $topY, 9, $cols['total']);
        $this->text(self::MARGIN + 345, $topY, 9, $cols['payment']);
        $this->text(self::MARGIN + 450, $topY, 9, $cols['status']);
        $this->text(self::MARGIN + 555, $topY, 9, $cols['date']);

        $ay = $topY - 13;
        foreach ($extraLines as $line) {
            $this->text(self::MARGIN + 30, $ay, 8, $line);
            $ay -= 11;
        }

        $this->y = $ay - 4;
        $this->line(self::MARGIN, $this->y + 5, self::PAGE_W - self::MARGIN, $this->y + 5);
        $this->y -= 6;
    }

    private function drawHeaderRow(): void
    {
        $y = $this->y;
        $this->text(self::MARGIN, $y, 9, '#', true);
        $this->text(self::MARGIN + 30, $y, 9, 'Customer', true);
        $this->text(self::MARGIN + 185, $y, 9, 'Phone', true);
        $this->text(self::MARGIN + 270, $y, 9, 'Total', true);
        $this->text(self::MARGIN + 345, $y, 9, 'Payment', true);
        $this->text(self::MARGIN + 450, $y, 9, 'Status', true);
        $this->text(self::MARGIN + 555, $y, 9, 'Placed', true);
        $this->y -= 16;
        $this->line(self::MARGIN, $this->y + 3, self::PAGE_W - self::MARGIN, $this->y + 3);
        $this->y -= 4;
    }

    public function output(string $filename): void
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }

        $objects = [];
        $n = 1;
        $catalogObj = $n++;
        $pagesObj   = $n++;
        $fontObj1   = $n++; // Helvetica
        $fontObj2   = $n++; // Helvetica-Bold

        $pageObjs = [];
        $contentObjs = [];
        foreach ($this->pages as $i => $content) {
            $contentObjs[$i] = $n++;
            $pageObjs[$i] = $n++;
        }

        $objects[$catalogObj] = "<< /Type /Catalog /Pages $pagesObj 0 R >>";
        $kids = implode(' ', array_map(fn($o) => "$o 0 R", $pageObjs));
        $objects[$pagesObj] = "<< /Type /Pages /Kids [$kids] /Count " . count($this->pages) . " >>";
        $objects[$fontObj1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[$fontObj2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        foreach ($this->pages as $i => $content) {
            $len = strlen($content);
            $objects[$contentObjs[$i]] = "<< /Length $len >>\nstream\n$content\nendstream";
            $objects[$pageObjs[$i]] = "<< /Type /Page /Parent $pagesObj 0 R "
                . "/MediaBox [0 0 " . self::PAGE_W . " " . self::PAGE_H . "] "
                . "/Resources << /Font << /F1 $fontObj1 0 R /F2 $fontObj2 0 R >> >> "
                . "/Contents {$contentObjs[$i]} 0 R >>";
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "$num 0 obj\n$body\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $totalObjs = $n; // highest object number + 1
        $pdf .= "xref\n0 $totalObjs\n0000000000 65535 f \n";
        for ($i = 1; $i < $totalObjs; $i++) {
            $off = $offsets[$i] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size $totalObjs /Root $catalogObj 0 R >>\nstartxref\n$xrefStart\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}

$pdf = new SimplePdf((defined('SITE_NAME') && SITE_NAME ? SITE_NAME . ' - ' : '') . 'Customer Orders (' . date('d M Y') . ')');

foreach ($orders as $o) {
    $pdf->addOrderRow([
        '#'        => (string)$o['id'],
        'customer' => $o['customer_name'] . ' (' . $o['email'] . ')',
        'phone'    => $o['phone'],
        'address'  => $o['address'],
        'items'    => implode(', ', $itemsByOrder[$o['id']] ?? []),
        'total'    => 'Rs. ' . number_format($o['total_amount'], 2),
        'payment'  => ucwords(str_replace('_', ' ', $o['payment_status'])),
        'status'   => ucwords(str_replace('_', ' ', $o['order_status'])),
        'date'     => format_ist($o['created_at'], 'd M Y'),
    ]);
}

$pdf->output('orders_' . date('Y-m-d_His') . '.pdf');
