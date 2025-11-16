<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

ini_set('memory_limit', '512M');

echo str_repeat('=', 100) . "\n";
echo "VALIDATING FINAL CONSOLIDATED DATA\n";
echo str_repeat('=', 100) . "\n\n";

$finalFile = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/output/FINAL_ALL_CARRIERS_FCL_EXP.xlsx';

if (!file_exists($finalFile)) {
    die("Error: Final file not found at $finalFile\n");
}

echo "Loading Excel file...\n";
$spreadsheet = IOFactory::load($finalFile);
$worksheet = $spreadsheet->getActiveSheet();
$highestRow = $worksheet->getHighestDataRow();

echo "  ✓ Total rows (including header): $highestRow\n";
echo "  ✓ Data rows: " . ($highestRow - 1) . "\n\n";

// Read all data
$headers = [];
$data = [];

// Get headers from row 1
for ($col = 'A'; $col <= 'U'; $col++) {
    $headers[] = $worksheet->getCell($col . '1')->getValue();
}

// Get all data rows
for ($row = 2; $row <= $highestRow; $row++) {
    $rowData = [];
    for ($colIndex = 0; $colIndex < count($headers); $colIndex++) {
        $col = chr(65 + $colIndex);
        $rowData[$headers[$colIndex]] = $worksheet->getCell($col . $row)->getValue() ?? '';
    }
    $data[] = $rowData;
}

// Validation 1: Count by carrier
echo "Validation 1: Carrier Distribution\n";
echo str_repeat('-', 100) . "\n";
$carrierCounts = [];
$emptyCarrier = 0;

foreach ($data as $rate) {
    $carrier = trim($rate['CARRIER'] ?? '');
    if (empty($carrier)) {
        $emptyCarrier++;
    } else {
        $carrierCounts[$carrier] = ($carrierCounts[$carrier] ?? 0) + 1;
    }
}

arsort($carrierCounts);
foreach ($carrierCounts as $carrier => $count) {
    echo sprintf("  %-20s: %4d rates\n", $carrier, $count);
}

if ($emptyCarrier > 0) {
    echo "  ⚠️  Empty CARRIER   : $emptyCarrier rates (ISSUE!)\n";
}

echo "\n";

// Validation 2: Check for missing critical fields
echo "Validation 2: Missing Critical Fields\n";
echo str_repeat('-', 100) . "\n";

$missingPOD = 0;
$missingRates = 0;
$missingBoth = 0;

foreach ($data as $index => $rate) {
    $hasPOD = !empty(trim($rate['POD'] ?? ''));
    $has20 = !empty(trim($rate["20'"] ?? ''));
    $has40 = !empty(trim($rate["40'"] ?? ''));

    if (!$hasPOD) $missingPOD++;
    if (!$has20 && !$has40) $missingRates++;
    if (!$hasPOD && !$has20 && !$has40) $missingBoth++;
}

echo "  Missing POD:                  $missingPOD rows\n";
echo "  Missing both 20' and 40':     $missingRates rows\n";
echo "  Missing POD AND rates:        $missingBoth rows\n";

if ($missingPOD > 0 || $missingRates > 0) {
    echo "  ⚠️  Data quality issues detected!\n";
} else {
    echo "  ✓ All rows have POD and at least one rate\n";
}

echo "\n";

// Validation 3: Check for exact duplicates
echo "Validation 3: Duplicate Detection\n";
echo str_repeat('-', 100) . "\n";

$seen = [];
$duplicates = 0;
$duplicateDetails = [];

foreach ($data as $index => $rate) {
    $key = $rate['CARRIER'] . '|' . $rate['POL'] . '|' . $rate['POD'] . '|' .
           $rate["20'"] . '|' . $rate["40'"];

    if (isset($seen[$key])) {
        $duplicates++;
        $duplicateDetails[] = [
            'original_row' => $seen[$key],
            'duplicate_row' => $index + 2, // +2 because index starts at 0 and row 1 is header
            'carrier' => $rate['CARRIER'],
            'pol' => $rate['POL'],
            'pod' => $rate['POD']
        ];
    } else {
        $seen[$key] = $index + 2;
    }
}

if ($duplicates > 0) {
    echo "  ⚠️  Found $duplicates exact duplicate rows\n";
    echo "\n  Sample duplicates (showing first 5):\n";
    foreach (array_slice($duplicateDetails, 0, 5) as $dup) {
        echo "    Row {$dup['duplicate_row']}: {$dup['carrier']} | {$dup['pol']} → {$dup['pod']} (duplicate of row {$dup['original_row']})\n";
    }
} else {
    echo "  ✓ No exact duplicates found\n";
}

echo "\n";

// Validation 4: Source breakdown
echo "Validation 4: Data Source Analysis\n";
echo str_repeat('-', 100) . "\n";

echo "  Expected sources:\n";
echo "    - Excel files (RCL, KMTC):     100 rates\n";
echo "    - Azure OCR (11 carriers):     331 rates\n";
echo "    - Total expected:              431 rates\n";
echo "    - Actual in file:              " . count($data) . " rates\n";

if (count($data) == 431) {
    echo "  ✓ Row count matches expected total\n";
} else {
    $diff = count($data) - 431;
    echo "  ⚠️  Row count mismatch: " . ($diff > 0 ? "+$diff" : "$diff") . " rows\n";
}

echo "\n";

// Validation 5: Rate value checks
echo "Validation 5: Rate Value Validation\n";
echo str_repeat('-', 100) . "\n";

$invalidRates = 0;
$zeroRates = 0;
$veryHighRates = 0;

foreach ($data as $rate) {
    $rate20 = trim($rate["20'"] ?? '');
    $rate40 = trim($rate["40'"] ?? '');

    if (!empty($rate20)) {
        $numRate = floatval(str_replace(',', '', $rate20));
        if ($numRate <= 0) $zeroRates++;
        if ($numRate > 10000) $veryHighRates++;
        if (!is_numeric(str_replace(',', '', $rate20))) $invalidRates++;
    }

    if (!empty($rate40)) {
        $numRate = floatval(str_replace(',', '', $rate40));
        if ($numRate <= 0) $zeroRates++;
        if ($numRate > 20000) $veryHighRates++;
        if (!is_numeric(str_replace(',', '', $rate40))) $invalidRates++;
    }
}

echo "  Invalid rate formats:         $invalidRates\n";
echo "  Zero or negative rates:       $zeroRates\n";
echo "  Unusually high rates (>10k):  $veryHighRates\n";

if ($invalidRates == 0 && $zeroRates == 0) {
    echo "  ✓ All rates have valid numeric values\n";
}

echo "\n";

// Summary
echo str_repeat('=', 100) . "\n";
echo "VALIDATION SUMMARY\n";
echo str_repeat('=', 100) . "\n";

$issues = 0;

if ($emptyCarrier > 0) {
    echo "  ❌ Missing carrier names: $emptyCarrier rows\n";
    $issues++;
}

if ($missingPOD > 0) {
    echo "  ❌ Missing POD: $missingPOD rows\n";
    $issues++;
}

if ($missingRates > 0) {
    echo "  ❌ Missing rates: $missingRates rows\n";
    $issues++;
}

if ($duplicates > 0) {
    echo "  ⚠️  Duplicates found: $duplicates rows\n";
    $issues++;
}

if ($invalidRates > 0) {
    echo "  ❌ Invalid rates: $invalidRates\n";
    $issues++;
}

if ($issues == 0) {
    echo "  ✅ ALL VALIDATIONS PASSED!\n";
    echo "  ✅ Data quality is excellent\n";
    echo "  ✅ Ready for Laravel automation import\n";
} else {
    echo "  ⚠️  Found $issues validation issues that may need attention\n";
}

echo str_repeat('=', 100) . "\n";
