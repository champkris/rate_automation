<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

ini_set('memory_limit', '512M');

echo str_repeat('=', 100) . "\n";
echo "VALIDATING CLEANED DATA\n";
echo str_repeat('=', 100) . "\n\n";

$cleanFile = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/output/FINAL_ALL_CARRIERS_FCL_EXP_CLEAN.xlsx';

$spreadsheet = IOFactory::load($cleanFile);
$worksheet = $spreadsheet->getActiveSheet();
$highestRow = $worksheet->getHighestDataRow();

echo "Total rows: " . ($highestRow - 1) . " data rows + 1 header\n\n";

// Read headers and data
$headers = [];
for ($col = 'A'; $col <= 'U'; $col++) {
    $headers[] = $worksheet->getCell($col . '1')->getValue();
}

$data = [];
for ($row = 2; $row <= $highestRow; $row++) {
    $rowData = [];
    foreach ($headers as $index => $header) {
        $col = chr(65 + $index);
        $rowData[$header] = $worksheet->getCell($col . $row)->getValue() ?? '';
    }
    $data[] = $rowData;
}

// Validation checks
echo "✓ Validation 1: All CARRIER fields populated\n";
$emptyCarrier = 0;
foreach ($data as $rate) {
    if (empty(trim($rate['CARRIER'] ?? ''))) {
        $emptyCarrier++;
    }
}
echo "  Empty CARRIER: $emptyCarrier (should be 0)\n\n";

echo "✓ Validation 2: All rows have POD\n";
$missingPOD = 0;
foreach ($data as $rate) {
    if (empty(trim($rate['POD'] ?? ''))) {
        $missingPOD++;
    }
}
echo "  Missing POD: $missingPOD (should be 0)\n\n";

echo "✓ Validation 3: All rows have at least one rate\n";
$missingRates = 0;
foreach ($data as $rate) {
    $has20 = !empty(trim($rate["20'"] ?? '')) && trim($rate["20'"]) !== '0';
    $has40 = !empty(trim($rate["40'"] ?? '')) && trim($rate["40'"]) !== '0';
    if (!$has20 && !$has40) {
        $missingRates++;
    }
}
echo "  Missing both rates: $missingRates (should be 0)\n\n";

echo "✓ Validation 4: No duplicates\n";
$seen = [];
$duplicates = 0;
foreach ($data as $rate) {
    $key = $rate['CARRIER'] . '|' . $rate['POL'] . '|' . $rate['POD'] . '|' .
           $rate["20'"] . '|' . $rate["40'"];
    if (isset($seen[$key])) {
        $duplicates++;
    } else {
        $seen[$key] = true;
    }
}
echo "  Duplicates: $duplicates (should be 0)\n\n";

// Carrier breakdown
echo "✓ Carrier Distribution:\n";
echo str_repeat('-', 100) . "\n";
$carrierCounts = [];
foreach ($data as $rate) {
    $carrier = trim($rate['CARRIER'] ?? '');
    if ($carrier) {
        $carrierCounts[$carrier] = ($carrierCounts[$carrier] ?? 0) + 1;
    }
}

arsort($carrierCounts);
foreach ($carrierCounts as $carrier => $count) {
    echo sprintf("  %-20s: %4d rates\n", $carrier, $count);
}

echo "\n" . str_repeat('=', 100) . "\n";

if ($emptyCarrier == 0 && $missingPOD == 0 && $missingRates == 0 && $duplicates == 0) {
    echo "✅ ALL VALIDATIONS PASSED!\n";
    echo "✅ Data is CLEAN and ready for Laravel automation\n";
    echo "✅ Total: " . count($data) . " high-quality rate cards from " . count($carrierCounts) . " carriers\n";
} else {
    echo "⚠️  Some validation checks failed\n";
}

echo str_repeat('=', 100) . "\n";
