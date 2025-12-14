<?php

require 'vendor/autoload.php';

// Bootstrap Laravel for RateExtractionService
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ini_set('memory_limit', '512M');

$attachmentsDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/attachments/';
$outputFile = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/output/EXTRACTED_RATES_FCL_EXP.xlsx';

// FCL_EXP format columns
$headers = [
    'CARRIER', 'POL', 'POD', 'CUR', "20'", "40'", '40 HQ', '20 TC', '20 RF', '40RF',
    'ETD BKK', 'ETD LCH', 'T/T', 'T/S', 'FREE TIME', 'VALIDITY', 'REMARK',
    'Export', 'Who use?', 'Rate Adjust', '1.1'
];

$allRates = [];

// Helper function to normalize currency
function normalizeCurrency($value) {
    if (empty($value) || $value === '-' || strtoupper($value) === 'TBA') {
        return 'USD';
    }
    return strtoupper(trim($value));
}

// Helper function to normalize rate value
function normalizeRate($value) {
    if ($value === null || $value === '' || $value === '-' || strtoupper($value) === 'TBA') {
        return 'TBA';
    }
    if (is_numeric($value)) {
        return (float)$value;
    }
    // Extract number from string if it contains formulas or text
    if (preg_match('/(\d+\.?\d*)/', $value, $matches)) {
        return (float)$matches[1];
    }
    return $value;
}

echo "Starting rate extraction...\n";
echo str_repeat('=', 100) . "\n\n";

// ===== EXTRACT FROM: SITC PDF using RateExtractionService =====
try {
    echo "Processing: PUBLIC QUOTATION 2025 DEC 25 SITC.pdf\n";
    $rateService = new App\Services\RateExtractionService();
    $sitcRates = $rateService->extractRates($attachmentsDir . "PUBLIC QUOTATION 2025  DEC 25 SITC.pdf", "sitc", "");

    $startCount = count($allRates);
    foreach ($sitcRates as $rate) {
        $allRates[] = [
            'CARRIER' => 'SITC',
            'POL' => $rate['POL'] ?? '',
            'POD' => $rate['POD'] ?? '',
            'CUR' => 'USD',
            "20'" => $rate["20'"] ?? 'TBA',
            "40'" => $rate["40'"] ?? 'TBA',
            '40 HQ' => $rate["40'HC"] ?? $rate["40'"] ?? 'TBA',
            '20 TC' => '',
            '20 RF' => $rate["20'RF"] ?? '',
            '40RF' => $rate["40'RF"] ?? '',
            'ETD BKK' => '',
            'ETD LCH' => '',
            'T/T' => $rate['T/T'] ?? 'TBA',
            'T/S' => $rate['T/S'] ?? 'TBA',
            'FREE TIME' => $rate['FREE TIME'] ?? 'TBA',
            'VALIDITY' => $rate['VALIDITY'] ?? '',
            'REMARK' => $rate['REMARK'] ?? '',
            'Export' => '',
            'Who use?' => '',
            'Rate Adjust' => '',
            '1.1' => ''
        ];
    }
    echo "  ✓ Extracted " . (count($allRates) - $startCount) . " rates\n\n";
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// ===== EXTRACT FROM: UPDATED RATE IN DEC25.xlsx (KMTC) =====
try {
    echo "Processing: UPDATED RATE IN DEC25.xlsx\n";
    $spreadsheet = IOFactory::load($attachmentsDir . 'UPDATED RATE IN DEC25.xlsx');
    $worksheet = $spreadsheet->getActiveSheet();
    $highestRow = $worksheet->getHighestDataRow();

    // Start from row 6 based on our earlier analysis
    for ($row = 6; $row <= $highestRow; $row++) {
        $country = trim($worksheet->getCell("B{$row}")->getValue() ?? '');
        $pol = trim($worksheet->getCell("C{$row}")->getValue() ?? '');
        $podArea = trim($worksheet->getCell("D{$row}")->getValue() ?? '');
        $rate20 = $worksheet->getCell("E{$row}")->getValue();
        $rate40 = $worksheet->getCell("F{$row}")->getValue();
        $valid = trim($worksheet->getCell("G{$row}")->getValue() ?? '');
        $lssOrigin = trim($worksheet->getCell("H{$row}")->getValue() ?? '');
        $lssDest = trim($worksheet->getCell("I{$row}")->getValue() ?? '');
        $freeTime = trim($worksheet->getCell("J{$row}")->getValue() ?? '');
        $remark = trim($worksheet->getCell("K{$row}")->getValue() ?? '');

        // Skip empty rows or header rows
        if (empty($podArea) || stripos($podArea, 'POD') !== false || stripos($country, 'Notice') !== false) {
            continue;
        }

        // Determine carrier (could be SITC or KMTC based on file)
        $carrier = 'KMTC'; // Assuming KMTC based on file name pattern

        $allRates[] = [
            'CARRIER' => $carrier,
            'POL' => $pol ?: 'BKK/LCH',
            'POD' => $podArea,
            'CUR' => 'USD',
            "20'" => normalizeRate($rate20),
            "40'" => normalizeRate($rate40),
            '40 HQ' => normalizeRate($rate40), // Same as 40'
            '20 TC' => '',
            '20 RF' => '',
            '40RF' => '',
            'ETD BKK' => '',
            'ETD LCH' => '',
            'T/T' => '',
            'T/S' => '',
            'FREE TIME' => $freeTime,
            'VALIDITY' => $valid,
            'REMARK' => $remark,
            'Export' => '',
            'Who use?' => '',
            'Rate Adjust' => '',
            '1.1' => ''
        ];
    }

    echo "  ✓ Extracted " . count($allRates) . " rates\n\n";

} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// ===== EXTRACT FROM: FAK Rate of 1-15 DEC 25.xlsx (RCL) =====
try {
    echo "Processing: FAK Rate of 1-15 DEC 25.xlsx\n";
    $spreadsheet = IOFactory::load($attachmentsDir . 'FAK Rate of 1-15 DEC 25.xlsx');
    $worksheet = $spreadsheet->getSheetByName('RCL');
    $highestRow = $worksheet->getHighestDataRow();

    $carrier = 'RCL';
    $startCount = count($allRates);

    // Start from row 10 based on our earlier analysis
    for ($row = 10; $row <= $highestRow; $row++) {
        $country = trim($worksheet->getCell("A{$row}")->getValue() ?? '');
        $pod = trim($worksheet->getCell("B{$row}")->getValue() ?? '');
        $podCode = trim($worksheet->getCell("C{$row}")->getValue() ?? '');
        $pol = trim($worksheet->getCell("D{$row}")->getValue() ?? '');
        $service = trim($worksheet->getCell("E{$row}")->getValue() ?? '');
        $etd = trim($worksheet->getCell("F{$row}")->getValue() ?? '');
        $rate20 = $worksheet->getCell("G{$row}")->getValue();
        $rate40 = $worksheet->getCell("H{$row}")->getValue();
        $serviceInfo = trim($worksheet->getCell("I{$row}")->getValue() ?? '');
        $transitTime = trim($worksheet->getCell("J{$row}")->getValue() ?? '');
        $freeTime = trim($worksheet->getCell("K{$row}")->getValue() ?? '');
        $remark = trim($worksheet->getCell("L{$row}")->getValue() ?? '');

        // Skip empty or header rows
        if (empty($pod) || stripos($pod, 'Port of Discharge') !== false) {
            continue;
        }

        // Skip rows that are just POL indicators (no POD)
        if (empty($pol) || in_array(strtoupper($pol), ['LCH', 'LKR', 'BKK'])) {
            continue;
        }

        $allRates[] = [
            'CARRIER' => $carrier,
            'POL' => $pol,
            'POD' => $pod . ($podCode ? " ($podCode)" : ''),
            'CUR' => 'USD',
            "20'" => normalizeRate($rate20),
            "40'" => normalizeRate($rate40),
            '40 HQ' => normalizeRate($rate40),
            '20 TC' => '',
            '20 RF' => '',
            '40RF' => '',
            'ETD BKK' => '',
            'ETD LCH' => $etd,
            'T/T' => $transitTime,
            'T/S' => $serviceInfo,
            'FREE TIME' => $freeTime,
            'VALIDITY' => '1-15 Dec',
            'REMARK' => $remark,
            'Export' => '',
            'Who use?' => '',
            'Rate Adjust' => '',
            '1.1' => ''
        ];
    }

    echo "  ✓ Extracted " . (count($allRates) - $startCount) . " rates\n\n";

} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// ===== EXTRACT FROM: FAK Rate of 1-31 DEC on Northeast_ Southeast Asia.xls (RCL) =====
try {
    echo "Processing: FAK Rate of 1-31 DEC on Northeast_ Southeast Asia.xls\n";
    $spreadsheet = IOFactory::load($attachmentsDir . 'FAK Rate of 1-31 DEC  on Northeast_ Southeast Asia .xls');
    $worksheet = $spreadsheet->getSheetByName('RCL');
    $highestRow = $worksheet->getHighestDataRow();

    $carrier = 'RCL';
    $startCount = count($allRates);

    // Start from row 10 based on our earlier analysis
    for ($row = 10; $row <= $highestRow; $row++) {
        $country = trim($worksheet->getCell("A{$row}")->getValue() ?? '');
        $pod = trim($worksheet->getCell("B{$row}")->getValue() ?? '');
        $podCode = trim($worksheet->getCell("C{$row}")->getValue() ?? '');
        $pol = trim($worksheet->getCell("D{$row}")->getValue() ?? '');
        $service = trim($worksheet->getCell("E{$row}")->getValue() ?? '');
        $etd = trim($worksheet->getCell("F{$row}")->getValue() ?? '');
        $rate20 = $worksheet->getCell("G{$row}")->getValue();
        $rate40 = $worksheet->getCell("H{$row}")->getValue();
        $serviceInfo = trim($worksheet->getCell("I{$row}")->getValue() ?? '');
        $transitTime = trim($worksheet->getCell("J{$row}")->getValue() ?? '');
        $freeTime = trim($worksheet->getCell("K{$row}")->getValue() ?? '');
        $remark = trim($worksheet->getCell("L{$row}")->getValue() ?? '');

        // Skip empty or header rows
        if (empty($pod) || stripos($pod, 'Port of Discharge') !== false) {
            continue;
        }

        // Skip rows that are just country headers or POL indicators
        if (empty($pol) || in_array(strtoupper($pol), ['LCH', 'LKR', 'BKK'])) {
            continue;
        }

        $allRates[] = [
            'CARRIER' => $carrier,
            'POL' => $pol,
            'POD' => $pod . ($podCode ? " ($podCode)" : ''),
            'CUR' => 'USD',
            "20'" => normalizeRate($rate20),
            "40'" => normalizeRate($rate40),
            '40 HQ' => normalizeRate($rate40),
            '20 TC' => '',
            '20 RF' => '',
            '40RF' => '',
            'ETD BKK' => '',
            'ETD LCH' => $etd,
            'T/T' => $transitTime,
            'T/S' => $serviceInfo,
            'FREE TIME' => $freeTime,
            'VALIDITY' => '1-31 Dec',
            'REMARK' => $remark,
            'Export' => '',
            'Who use?' => '',
            'Rate Adjust' => '',
            '1.1' => ''
        ];
    }

    echo "  ✓ Extracted " . (count($allRates) - $startCount) . " rates\n\n";

} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// ===== CREATE OUTPUT EXCEL FILE =====
echo str_repeat('=', 100) . "\n";
echo "Creating consolidated Excel file...\n";
echo "Total rates extracted: " . count($allRates) . "\n\n";

try {
    $outputSpreadsheet = new Spreadsheet();
    $sheet = $outputSpreadsheet->getActiveSheet();
    $sheet->setTitle('FCL_EXP_EXTRACTED');

    // Write headers
    $colIndex = 0;
    foreach ($headers as $header) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
        $sheet->setCellValue($colLetter . '1', $header);
        $colIndex++;
    }

    // Style headers
    $sheet->getStyle('A1:U1')->getFont()->setBold(true);
    $sheet->getStyle('A1:U1')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFD9D9D9');

    // Write data
    $rowNum = 2;
    foreach ($allRates as $rate) {
        $colIndex = 0;
        foreach ($headers as $header) {
            $value = $rate[$header] ?? '';
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue($colLetter . $rowNum, $value);
            $colIndex++;
        }
        $rowNum++;
    }

    // Auto-size columns
    foreach (range('A', 'U') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // Save file
    $writer = new Xlsx($outputSpreadsheet);
    $writer->save($outputFile);

    echo "✓ Excel file created successfully!\n";
    echo "  Location: $outputFile\n";
    echo "  Rows: " . count($allRates) . "\n";

} catch (Exception $e) {
    echo "✗ Error creating Excel file: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 100) . "\n";
echo "Extraction complete!\n";
