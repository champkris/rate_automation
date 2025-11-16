<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

echo str_repeat('=', 100) . "\n";
echo "DIAGNOSING FILES WITH 0 RATES\n";
echo str_repeat('=', 100) . "\n\n";

// 1. Check KMTC Excel file
echo "1. KMTC Excel File Analysis:\n";
echo str_repeat('-', 100) . "\n";

$kmtcFile = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/attachments/UPDATED RATE IN NOV25.xlsx';

try {
    $spreadsheet = IOFactory::load($kmtcFile);
    $worksheet = $spreadsheet->getActiveSheet();
    $highestRow = $worksheet->getHighestDataRow();
    $highestCol = $worksheet->getHighestDataColumn();

    echo "  File: UPDATED RATE IN NOV25.xlsx\n";
    echo "  Sheet: " . $worksheet->getTitle() . "\n";
    echo "  Rows: $highestRow\n";
    echo "  Columns: $highestCol\n\n";

    echo "  First 10 rows preview:\n";
    for ($row = 1; $row <= min(10, $highestRow); $row++) {
        echo "  Row $row: ";
        for ($col = 'A'; $col <= 'F'; $col++) {
            $value = $worksheet->getCell($col . $row)->getValue();
            echo "$col=" . (is_null($value) ? 'NULL' : "'{$value}'") . " | ";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "  Error: " . $e->getMessage() . "\n";
}

echo "\n";

// 2. Check SINOKOR Azure OCR results
echo "2. SINOKOR PDF Azure OCR Results:\n";
echo str_repeat('-', 100) . "\n";

$sinokorFiles = [
    '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/azure_ocr_results/GUIDE RATE FOR 1-30 NOV 2025_SINOKOR_tables.txt',
    '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/azure_ocr_results/GUIDE RATE FOR 1-30 NOV 2025_SKR - SINOKOR_tables.txt'
];

foreach ($sinokorFiles as $file) {
    if (file_exists($file)) {
        echo "\n  File: " . basename($file) . "\n";
        $content = file_get_contents($file);
        $lines = explode("\n", $content);

        echo "  Total lines: " . count($lines) . "\n";
        echo "  First 30 lines:\n";
        foreach (array_slice($lines, 0, 30) as $i => $line) {
            echo "    " . ($i + 1) . ": " . substr($line, 0, 100) . "\n";
        }
    } else {
        echo "  File not found: " . basename($file) . "\n";
    }
}

echo "\n";

// 3. Check INDIA RATE PDF Azure OCR results
echo "3. INDIA RATE (WANHAI) PDF Azure OCR Results:\n";
echo str_repeat('-', 100) . "\n";

$indiaFile = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/azure_ocr_results/INDIA RATE 1-15  NOV  DRY AND  REEFER_tables.txt';

if (file_exists($indiaFile)) {
    echo "  File: " . basename($indiaFile) . "\n";
    $content = file_get_contents($indiaFile);
    $lines = explode("\n", $content);

    echo "  Total lines: " . count($lines) . "\n";
    echo "  First 30 lines:\n";
    foreach (array_slice($lines, 0, 30) as $i => $line) {
        echo "    " . ($i + 1) . ": " . substr($line, 0, 100) . "\n";
    }
} else {
    echo "  File not found\n";
}

echo "\n" . str_repeat('=', 100) . "\n";
