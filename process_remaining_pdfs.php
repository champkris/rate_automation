<?php

require __DIR__ . '/extract_pdfs_with_azure.php';

// Process only the remaining PDFs that weren't processed yet
$remainingPDFs = [
    'GUIDE RATE FOR 1-30 NOV 2025_SINOKOR.PDF',
    'GUIDE RATE FOR 1-30 NOV 2025_SINOKOR_d39fe3.PDF',
    'GUIDE RATE FOR 1-30 NOV 2025_SKR - SINOKOR.PDF',
    'GUIDE RATE FOR 1-30 NOV 2025_SKR - SINOKOR_d39fe3.PDF',
    'INDIA RATE 1-15  NOV  DRY AND  REEFER.PDF',
    'QUOTATION 1-14 NOV 2025 BOXMAN.PDF',
    'QUOTATION 1-14 NOV 2025 BOXMAN_d39fe3.PDF',
];

echo str_repeat('=', 100) . "\n";
echo "Processing Remaining PDFs (SINOKOR, BOXMAN, WANHAI India)\n";
echo str_repeat('=', 100) . "\n\n";

$pdfDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/';
$processedCount = 0;
$errorCount = 0;

foreach ($remainingPDFs as $index => $filename) {
    $pdfFile = $pdfDir . $filename;

    if (!file_exists($pdfFile)) {
        echo "[" . ($index + 1) . "/" . count($remainingPDFs) . "] ✗ File not found: $filename\n";
        $errorCount++;
        continue;
    }

    echo "\n[" . ($index + 1) . "/" . count($remainingPDFs) . "] Processing: $filename\n";

    // Use the same function from extract_pdfs_with_azure.php
    $result = analyzeDocumentWithAzure($pdfFile, $endpoint, $apiKey, $model);

    if (isset($result['error'])) {
        echo "    ✗ Failed: " . $result['error'] . "\n";
        $errorCount++;
        continue;
    }

    $tables = extractTablesFromAzureResult($result);
    echo "    📊 Found " . count($tables) . " table(s)\n";

    // Save results
    $outputFile = $outputDir . pathinfo($filename, PATHINFO_FILENAME) . '_azure_result.json';
    file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT));

    $tableFile = $outputDir . pathinfo($filename, PATHINFO_FILENAME) . '_tables.txt';
    $tableOutput = "Tables extracted from: $filename\n";
    $tableOutput .= str_repeat('=', 80) . "\n\n";

    foreach ($tables as $tableIndex => $table) {
        $tableOutput .= "TABLE " . ($tableIndex + 1) . " (Rows: {$table['rowCount']}, Cols: {$table['columnCount']})\n";
        $tableOutput .= str_repeat('-', 80) . "\n";

        foreach ($table['cells'] as $rowIndex => $row) {
            ksort($row);
            $tableOutput .= "Row $rowIndex: " . implode(' | ', $row) . "\n";
        }

        $tableOutput .= "\n";
    }

    file_put_contents($tableFile, $tableOutput);

    echo "    ✓ Saved results to: " . basename($tableFile) . "\n";
    $processedCount++;
}

echo "\n" . str_repeat('=', 100) . "\n";
echo "Remaining PDFs Processing Complete!\n";
echo "  Successful: $processedCount files\n";
echo "  Failed: $errorCount files\n";
echo str_repeat('=', 100) . "\n";
