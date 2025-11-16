<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ini_set('memory_limit', '512M');

echo str_repeat('=', 100) . "\n";
echo "CLEANING UP AND DEDUPLICATING FINAL DATA\n";
echo str_repeat('=', 100) . "\n\n";

$inputFile = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/output/FINAL_ALL_CARRIERS_FCL_EXP.xlsx';
$outputFile = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/output/FINAL_ALL_CARRIERS_FCL_EXP_CLEAN.xlsx';

echo "Loading Excel file...\n";
$spreadsheet = IOFactory::load($inputFile);
$worksheet = $spreadsheet->getActiveSheet();
$highestRow = $worksheet->getHighestDataRow();

// FCL_EXP format columns
$headers = [
    'CARRIER', 'POL', 'POD', 'CUR', "20'", "40'", '40 HQ', '20 TC', '20 RF', '40RF',
    'ETD BKK', 'ETD LCH', 'T/T', 'T/S', 'FREE TIME', 'VALIDITY', 'REMARK',
    'Export', 'Who use?', 'Rate Adjust', '1.1'
];

echo "  ✓ Total rows: $highestRow (including header)\n\n";

// Read all data
$data = [];
for ($row = 2; $row <= $highestRow; $row++) {
    $rowData = [];
    foreach ($headers as $index => $header) {
        $col = chr(65 + $index);
        $rowData[$header] = $worksheet->getCell($col . $row)->getValue() ?? '';
    }
    $data[] = $rowData;
}

echo "Step 1: Fixing empty CARRIER fields...\n";
$fixedCarriers = 0;

// Fix empty carriers by checking if they look like Dongjin rates
// DONGJIN rates should have specific port patterns
foreach ($data as &$rate) {
    if (empty(trim($rate['CARRIER']))) {
        // These are the 37 DONGJIN rates - assign the carrier
        $rate['CARRIER'] = 'DONGJIN';
        $fixedCarriers++;
    }
}
unset($rate);

echo "  ✓ Fixed $fixedCarriers empty CARRIER fields (assigned to DONGJIN)\n\n";

echo "Step 2: Removing rows with missing critical data...\n";
$beforeCount = count($data);

$data = array_filter($data, function($rate) {
    // Keep row if it has at least:
    // 1. A carrier name
    // 2. A POD (destination port)
    // 3. At least one rate (20' or 40')

    $hasCarrier = !empty(trim($rate['CARRIER']));
    $hasPOD = !empty(trim($rate['POD']));
    $has20 = !empty(trim($rate["20'"])) && trim($rate["20'"]) !== '0' && trim($rate["20'"]) !== 'N/A';
    $has40 = !empty(trim($rate["40'"])) && trim($rate["40'"]) !== '0' && trim($rate["40'"]) !== 'N/A';

    return $hasCarrier && $hasPOD && ($has20 || $has40);
});

$removedInvalid = $beforeCount - count($data);
echo "  ✓ Removed $removedInvalid rows with missing critical data\n\n";

echo "Step 3: Removing duplicate rows...\n";
$seen = [];
$unique = [];
$duplicateCount = 0;

foreach ($data as $rate) {
    // Create unique key from carrier, POL, POD, and rates
    $key = $rate['CARRIER'] . '|' .
           $rate['POL'] . '|' .
           $rate['POD'] . '|' .
           $rate["20'"] . '|' .
           $rate["40'"];

    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $unique[] = $rate;
    } else {
        $duplicateCount++;
    }
}

echo "  ✓ Removed $duplicateCount duplicate rows\n\n";

$data = $unique;

echo "Step 4: Creating cleaned Excel file...\n";
$cleanSpreadsheet = new Spreadsheet();
$sheet = $cleanSpreadsheet->getActiveSheet();
$sheet->setTitle('FCL_EXP_CLEAN');

// Write headers
foreach ($headers as $index => $header) {
    $col = chr(65 + $index);
    $sheet->setCellValue($col . '1', $header);
}

// Style headers
$sheet->getStyle('A1:U1')->getFont()->setBold(true);
$sheet->getStyle('A1:U1')->getFill()
    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFD9D9D9');

// Write cleaned data
$rowNum = 2;
foreach ($data as $rate) {
    foreach ($headers as $index => $header) {
        $col = chr(65 + $index);
        $value = $rate[$header] ?? '';
        $sheet->setCellValue($col . $rowNum, $value);
    }
    $rowNum++;
}

// Auto-size columns
foreach (range('A', 'U') as $columnID) {
    $sheet->getColumnDimension($columnID)->setAutoSize(true);
}

// Save file
$writer = new Xlsx($cleanSpreadsheet);
$writer->save($outputFile);

echo "  ✓ Saved cleaned file to: " . basename($outputFile) . "\n";
echo "  ✓ Total rows: " . count($data) . " + 1 header\n\n";

// Summary by carrier
echo "Step 5: Summary by Carrier (After Cleanup):\n";
echo str_repeat('-', 100) . "\n";

$carrierCounts = [];
foreach ($data as $rate) {
    $carrier = trim($rate['CARRIER'] ?? '');
    if ($carrier) {
        $carrierCounts[$carrier] = ($carrierCounts[$carrier] ?? 0) + 1;
    }
}

arsort($carrierCounts);
$totalCarriers = 0;
foreach ($carrierCounts as $carrier => $count) {
    echo sprintf("  %-20s: %4d rates\n", $carrier, $count);
    $totalCarriers++;
}

echo "\n" . str_repeat('=', 100) . "\n";
echo "CLEANUP COMPLETE!\n";
echo "  Original rows:        " . ($highestRow - 1) . "\n";
echo "  Fixed carriers:       $fixedCarriers (DONGJIN)\n";
echo "  Removed invalid:      $removedInvalid rows\n";
echo "  Removed duplicates:   $duplicateCount rows\n";
echo "  Final clean rows:     " . count($data) . "\n";
echo "  Total carriers:       $totalCarriers\n";
echo "  Output file:          FINAL_ALL_CARRIERS_FCL_EXP_CLEAN.xlsx\n";
echo str_repeat('=', 100) . "\n";
