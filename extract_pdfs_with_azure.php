<?php

/**
 * Extract rate cards from PDFs using Azure Document Intelligence (OCR)
 *
 * Setup Instructions:
 * 1. Create Azure Document Intelligence resource in Azure Portal
 * 2. Copy endpoint and key to .env.azure file
 * 3. Run this script to process all PDF files
 */

ini_set('memory_limit', '512M');
set_time_limit(600); // 10 minutes

// Load Azure credentials from .env.azure
function loadAzureConfig() {
    $envFile = __DIR__ . '/.env.azure';
    if (!file_exists($envFile)) {
        die("Error: .env.azure file not found. Please configure Azure credentials.\n");
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];

    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;

        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $config[trim($key)] = trim($value);
        }
    }

    return $config;
}

// Analyze document using Azure Document Intelligence
function analyzeDocumentWithAzure($filePath, $endpoint, $apiKey, $model = 'prebuilt-layout') {
    echo "  Analyzing: " . basename($filePath) . "\n";

    // Read file content
    $fileContent = file_get_contents($filePath);
    if ($fileContent === false) {
        return ['error' => 'Failed to read file'];
    }

    // Step 1: Submit document for analysis
    $analyzeUrl = rtrim($endpoint, '/') . "/formrecognizer/documentModels/{$model}:analyze?api-version=2023-07-31";

    $ch = curl_init($analyzeUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fileContent,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/pdf',
            'Ocp-Apim-Subscription-Key: ' . $apiKey
        ],
        CURLOPT_HEADER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    curl_close($ch);

    if ($httpCode !== 202) {
        echo "    ✗ Error: HTTP $httpCode\n";
        echo "    Response: " . substr($body, 0, 200) . "...\n";
        return ['error' => "HTTP $httpCode: $body"];
    }

    // Extract operation location from headers
    if (!preg_match('/Operation-Location: (.+)/i', $headers, $matches)) {
        return ['error' => 'No Operation-Location header found'];
    }

    $operationUrl = trim($matches[1]);
    echo "    ⏳ Processing... ";

    // Step 2: Poll for results
    $maxAttempts = 30;
    $attempt = 0;

    while ($attempt < $maxAttempts) {
        sleep(2); // Wait 2 seconds between polls

        $ch = curl_init($operationUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Ocp-Apim-Subscription-Key: ' . $apiKey
            ]
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo "\n    ✗ Error polling results: HTTP $httpCode\n";
            return ['error' => "Polling error: HTTP $httpCode"];
        }

        $resultData = json_decode($result, true);

        if ($resultData['status'] === 'succeeded') {
            echo "✓ Complete!\n";
            return $resultData;
        } elseif ($resultData['status'] === 'failed') {
            echo "\n    ✗ Analysis failed\n";
            return ['error' => 'Analysis failed: ' . json_encode($resultData)];
        }

        echo ".";
        $attempt++;
    }

    echo "\n    ✗ Timeout waiting for results\n";
    return ['error' => 'Timeout'];
}

// Extract tables from Azure response
function extractTablesFromAzureResult($azureResult) {
    if (isset($azureResult['error'])) {
        return [];
    }

    $tables = [];

    if (isset($azureResult['analyzeResult']['tables'])) {
        foreach ($azureResult['analyzeResult']['tables'] as $tableIndex => $table) {
            $tableData = [
                'rowCount' => $table['rowCount'] ?? 0,
                'columnCount' => $table['columnCount'] ?? 0,
                'cells' => []
            ];

            // Extract cells
            if (isset($table['cells'])) {
                foreach ($table['cells'] as $cell) {
                    $row = $cell['rowIndex'] ?? 0;
                    $col = $cell['columnIndex'] ?? 0;
                    $content = $cell['content'] ?? '';

                    if (!isset($tableData['cells'][$row])) {
                        $tableData['cells'][$row] = [];
                    }

                    $tableData['cells'][$row][$col] = $content;
                }
            }

            $tables[] = $tableData;
        }
    }

    return $tables;
}

// Main execution
echo str_repeat('=', 100) . "\n";
echo "PDF Rate Card Extraction using Azure Document Intelligence\n";
echo str_repeat('=', 100) . "\n\n";

// Load configuration
$config = loadAzureConfig();
$endpoint = $config['AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT'] ?? '';
$apiKey = $config['AZURE_DOCUMENT_INTELLIGENCE_KEY'] ?? '';
$model = $config['AZURE_DOCUMENT_MODEL'] ?? 'prebuilt-layout';

if (empty($endpoint) || empty($apiKey)) {
    die("Error: Please configure AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT and AZURE_DOCUMENT_INTELLIGENCE_KEY in .env.azure\n\n" .
        "Setup Instructions:\n" .
        "1. Go to https://portal.azure.com\n" .
        "2. Create a 'Document Intelligence' resource (or 'Form Recognizer')\n" .
        "3. Go to 'Keys and Endpoint' section\n" .
        "4. Copy the endpoint and key to .env.azure file\n\n");
}

echo "Azure Configuration:\n";
echo "  Endpoint: $endpoint\n";
echo "  API Key: " . substr($apiKey, 0, 8) . "..." . substr($apiKey, -4) . "\n";
echo "  Model: $model\n\n";

// Get all PDF files (both .pdf and .PDF)
$pdfDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/';
$pdfFiles = array_merge(
    glob($pdfDir . '*.pdf'),
    glob($pdfDir . '*.PDF')
);

echo "Found " . count($pdfFiles) . " PDF files\n\n";

$outputDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/azure_ocr_results/';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Process each PDF
$processedCount = 0;
$errorCount = 0;

foreach ($pdfFiles as $index => $pdfFile) {
    $filename = basename($pdfFile);
    echo "\n[" . ($index + 1) . "/" . count($pdfFiles) . "] Processing: $filename\n";

    // Analyze document
    $result = analyzeDocumentWithAzure($pdfFile, $endpoint, $apiKey, $model);

    if (isset($result['error'])) {
        echo "    ✗ Failed: " . $result['error'] . "\n";
        $errorCount++;
        continue;
    }

    // Extract tables
    $tables = extractTablesFromAzureResult($result);
    echo "    📊 Found " . count($tables) . " table(s)\n";

    // Save results
    $outputFile = $outputDir . pathinfo($filename, PATHINFO_FILENAME) . '_azure_result.json';
    file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT));

    // Save tables in readable format
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
echo "Processing Complete!\n";
echo "  Successful: $processedCount files\n";
echo "  Failed: $errorCount files\n";
echo "  Results saved to: $outputDir\n";
echo str_repeat('=', 100) . "\n";
