<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ini_set('memory_limit', '1024M');
set_time_limit(600);

echo str_repeat('=', 100) . "\n";
echo "CONVERTING AND MERGING ALL RATE CARDS TO FCL_EXP FORMAT\n";
echo str_repeat('=', 100) . "\n\n";

// FCL_EXP format columns
$headers = [
    'CARRIER', 'POL', 'POD', 'CUR', "20'", "40'", '40 HQ', '20 TC', '20 RF', '40RF',
    'ETD BKK', 'ETD LCH', 'T/T', 'T/S', 'FREE TIME', 'VALIDITY', 'REMARK',
    'Export', 'Who use?', 'Rate Adjust', '1.1'
];

$allRates = [];

// Step 1: Load existing Excel rates (100 rates from RCL, KMTC, SITC)
echo "Step 1: Loading existing Excel rates...\n";
$existingExcel = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/output/EXTRACTED_RATES_FCL_EXP.xlsx';

if (file_exists($existingExcel)) {
    $spreadsheet = IOFactory::load($existingExcel);
    $worksheet = $spreadsheet->getActiveSheet();
    $highestRow = $worksheet->getHighestDataRow();

    for ($row = 2; $row <= $highestRow; $row++) {
        $rateData = [];
        foreach ($headers as $index => $header) {
            $col = chr(65 + $index); // A, B, C, etc.
            $rateData[$header] = $worksheet->getCell($col . $row)->getValue() ?? '';
        }
        if (!empty($rateData['CARRIER'])) {
            $allRates[] = $rateData;
        }
    }
    echo "  ✓ Loaded " . count($allRates) . " existing rates from Excel\n\n";
}

// Step 2: Convert Azure OCR tables to FCL_EXP format
echo "Step 2: Converting Azure OCR tables...\n";
$azureDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/azure_ocr_results/';
$tableFiles = glob($azureDir . '*_tables.txt');

// Remove duplicates (keep only non-d39fe3 versions)
$uniqueFiles = [];
foreach ($tableFiles as $file) {
    if (strpos($file, '_d39fe3') === false) {
        $uniqueFiles[] = $file;
    }
}

echo "  Found " . count($uniqueFiles) . " unique table files\n\n";

$azureRatesAdded = 0;

foreach ($uniqueFiles as $tableFile) {
    $filename = basename($tableFile);
    echo "  Processing: $filename\n";

    // Extract carrier name from filename
    $carrier = '';
    if (preg_match('/SINOKOR/', $filename)) {
        $carrier = 'SINOKOR';
    } elseif (preg_match('/BOXMAN/', $filename)) {
        $carrier = 'BOXMAN';
    } elseif (preg_match('/WANHAI|INDIA/', $filename)) {
        $carrier = 'WANHAI';
    } elseif (preg_match('/CK LINE/', $filename)) {
        $carrier = 'CK LINE';
    } elseif (preg_match('/HEUNG A/', $filename)) {
        $carrier = 'HEUNG A';
    } elseif (preg_match('/SM LINE/', $filename)) {
        $carrier = 'SM LINE';
    } elseif (preg_match('/DONGJIN/', $filename)) {
        $carrier = 'DONGJIN';
    } elseif (preg_match('/TS LINE|Rate 1st/', $filename)) {
        $carrier = 'TS LINE';
    } elseif (preg_match('/PUBLIC QUOTATION/', $filename)) {
        $carrier = 'RCL/SITC';
    }

    // Read and parse table file
    $content = file_get_contents($tableFile);
    $ratesFromFile = parseTableFile($content, $carrier, $filename);

    $allRates = array_merge($allRates, $ratesFromFile);
    $azureRatesAdded += count($ratesFromFile);

    echo "    → Added " . count($ratesFromFile) . " rates\n";
}

echo "\n  ✓ Total Azure rates added: $azureRatesAdded\n\n";

// Step 3: Create final consolidated Excel file
echo "Step 3: Creating final consolidated Excel file...\n";
$totalRates = count($allRates);
echo "  Total rates: $totalRates\n";

$outputSpreadsheet = new Spreadsheet();
$sheet = $outputSpreadsheet->getActiveSheet();
$sheet->setTitle('FCL_EXP_ALL_CARRIERS');

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

// Write data
$rowNum = 2;
foreach ($allRates as $rate) {
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
$outputFile = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/output/FINAL_ALL_CARRIERS_FCL_EXP.xlsx';
$writer = new Xlsx($outputSpreadsheet);
$writer->save($outputFile);

echo "  ✓ Saved to: $outputFile\n";
echo "  ✓ Total rows: " . ($totalRates) . " + 1 header\n\n";

// Step 4: Create summary by carrier
echo "Step 4: Summary by Carrier:\n";
echo str_repeat('-', 100) . "\n";

$carrierCounts = [];
foreach ($allRates as $rate) {
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
echo "CONSOLIDATION COMPLETE!\n";
echo "  Source Excel rates: 100\n";
echo "  Azure OCR rates: $azureRatesAdded\n";
echo "  Total final rates: $totalRates\n";
echo "  Output file: FINAL_ALL_CARRIERS_FCL_EXP.xlsx\n";
echo str_repeat('=', 100) . "\n";

// Helper function to parse table files
function parseTableFile($content, $carrier, $filename) {
    $rates = [];

    // Simple parser - extract rows that look like rate data
    $lines = explode("\n", $content);
    $currentTable = 0;

    foreach ($lines as $line) {
        // Skip table headers and separators
        if (strpos($line, 'TABLE') !== false || strpos($line, '====') !== false ||
            strpos($line, '----') !== false || empty(trim($line))) {
            continue;
        }

        // Parse row data
        if (preg_match('/^Row \d+: (.+)$/', $line, $matches)) {
            $rowData = $matches[1];
            $cells = explode(' | ', $rowData);

            // Try to extract rate information from cells
            $rate = extractRateFromCells($cells, $carrier);

            if ($rate && !empty($rate['POD'])) {
                $rates[] = $rate;
            }
        }
    }

    return $rates;
}

function extractRateFromCells($cells, $carrier) {
    // Initialize rate structure
    $rate = [
        'CARRIER' => $carrier,
        'POL' => '',
        'POD' => '',
        'CUR' => 'USD',
        "20'" => '',
        "40'" => '',
        '40 HQ' => '',
        '20 TC' => '',
        '20 RF' => '',
        '40RF' => '',
        'ETD BKK' => '',
        'ETD LCH' => '',
        'T/T' => '',
        'T/S' => '',
        'FREE TIME' => '',
        'VALIDITY' => '',
        'REMARK' => '',
        'Export' => '',
        'Who use?' => '',
        'Rate Adjust' => '',
        '1.1' => ''
    ];

    // Try to identify and extract key fields
    foreach ($cells as $index => $cell) {
        $cell = trim($cell);

        // Extract POD (usually first cell or contains port name)
        if ($index <= 2 && preg_match('/^[A-Z][a-z]+/', $cell) && strlen($cell) > 2) {
            if (empty($rate['POD'])) {
                $rate['POD'] = $cell;
            } elseif (empty($rate['POL'])) {
                $rate['POL'] = $cell;
            }
        }

        // Extract 20' rate (contains USD and number)
        if (preg_match('/\$?\s*(\d+[,\d]*)\s*\(?(INC|USD)?/i', $cell, $matches) && empty($rate["20'"])) {
            $rate["20'"] = str_replace(',', '', $matches[1]);
        }

        // Extract 40' rate (next number after 20')
        if (preg_match('/\$?\s*(\d+[,\d]*)\s*\(?(INC|USD)?/i', $cell, $matches) &&
            !empty($rate["20'"]) && empty($rate["40'"])) {
            $rate["40'"] = str_replace(',', '', $matches[1]);
        }

        // Extract transit time
        if (preg_match('/(\d+)\s*(day|Day)/i', $cell, $matches) && empty($rate['T/T'])) {
            $rate['T/T'] = $matches[0];
        }

        // Extract ETD
        if (preg_match('/(MON|TUE|WED|THU|FRI|SAT|SUN)/i', $cell) && empty($rate['ETD BKK'])) {
            $rate['ETD BKK'] = $cell;
        }
    }

    // Set 40 HQ same as 40'
    if (!empty($rate["40'"])) {
        $rate['40 HQ'] = $rate["40'"];
    }

    return $rate;
}
