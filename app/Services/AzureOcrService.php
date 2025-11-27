<?php

namespace App\Services;

class AzureOcrService
{
    protected string $endpoint;
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        $config = $this->loadAzureConfig();
        $this->endpoint = $config['AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT'] ?? '';
        $this->apiKey = $config['AZURE_DOCUMENT_INTELLIGENCE_KEY'] ?? '';
        $this->model = $config['AZURE_DOCUMENT_MODEL'] ?? 'prebuilt-layout';
    }

    /**
     * Check if Azure OCR is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->endpoint) && !empty($this->apiKey);
    }

    /**
     * Load Azure configuration from .env.azure
     */
    protected function loadAzureConfig(): array
    {
        $envFile = base_path('.env.azure');
        if (!file_exists($envFile)) {
            return [];
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

    /**
     * Analyze a PDF document and return extracted tables
     */
    public function analyzePdf(string $filePath): array
    {
        if (!$this->isConfigured()) {
            throw new \Exception('Azure OCR is not configured. Please set up .env.azure file.');
        }

        // Read file content
        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) {
            throw new \Exception('Failed to read PDF file.');
        }

        // Step 1: Submit document for analysis
        $analyzeUrl = rtrim($this->endpoint, '/') . "/formrecognizer/documentModels/{$this->model}:analyze?api-version=2023-07-31";

        $ch = curl_init($analyzeUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fileContent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/pdf',
                'Ocp-Apim-Subscription-Key: ' . $this->apiKey
            ],
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 120
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        curl_close($ch);

        if ($httpCode !== 202) {
            throw new \Exception("Azure API error (HTTP $httpCode): " . substr($body, 0, 200));
        }

        // Extract operation location from headers
        if (!preg_match('/Operation-Location: (.+)/i', $headers, $matches)) {
            throw new \Exception('No Operation-Location header found in Azure response.');
        }

        $operationUrl = trim($matches[1]);

        // Step 2: Poll for results
        $maxAttempts = 60; // Up to 2 minutes
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            sleep(2); // Wait 2 seconds between polls

            $ch = curl_init($operationUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Ocp-Apim-Subscription-Key: ' . $this->apiKey
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new \Exception("Error polling Azure results: HTTP $httpCode");
            }

            $resultData = json_decode($result, true);

            if ($resultData['status'] === 'succeeded') {
                return $resultData;
            } elseif ($resultData['status'] === 'failed') {
                throw new \Exception('Azure analysis failed: ' . json_encode($resultData));
            }

            $attempt++;
        }

        throw new \Exception('Timeout waiting for Azure OCR results.');
    }

    /**
     * Extract tables from Azure response and return as lines format
     */
    public function extractTablesToLines(array $azureResult): array
    {
        $lines = [];

        if (!isset($azureResult['analyzeResult']['tables'])) {
            return $lines;
        }

        // Track which pages have tables
        $pagesWithTables = [];

        foreach ($azureResult['analyzeResult']['tables'] as $tableIndex => $table) {
            $lines[] = "TABLE " . ($tableIndex + 1) . " (Rows: " . ($table['rowCount'] ?? 0) . ", Cols: " . ($table['columnCount'] ?? 0) . ")";
            $lines[] = str_repeat('-', 80);

            $cells = [];
            if (isset($table['cells'])) {
                foreach ($table['cells'] as $cell) {
                    $row = $cell['rowIndex'] ?? 0;
                    $col = $cell['columnIndex'] ?? 0;
                    $content = $cell['content'] ?? '';

                    if (!isset($cells[$row])) {
                        $cells[$row] = [];
                    }
                    $cells[$row][$col] = $content;

                    // Track which pages have table cells
                    if (isset($cell['boundingRegions'])) {
                        foreach ($cell['boundingRegions'] as $region) {
                            $pagesWithTables[$region['pageNumber'] ?? 1] = true;
                        }
                    }
                }
            }

            foreach ($cells as $rowIndex => $row) {
                ksort($row);
                $lines[] = "Row $rowIndex: " . implode(' | ', $row);
            }

            $lines[] = "";
        }

        // Check for paragraphs on pages that don't have tables (continuation pages)
        // This handles cases where page 2 has table continuation that Azure didn't detect as table
        $pages = $azureResult['analyzeResult']['pages'] ?? [];
        $paragraphs = $azureResult['analyzeResult']['paragraphs'] ?? [];

        // Find paragraphs on pages without tables (likely table continuations)
        $overflowContent = [];
        foreach ($paragraphs as $paragraph) {
            $pageNum = 1;
            if (isset($paragraph['boundingRegions'][0]['pageNumber'])) {
                $pageNum = $paragraph['boundingRegions'][0]['pageNumber'];
            }

            // If this paragraph is on a page that doesn't have table cells, it might be overflow content
            if ($pageNum > 1 && !isset($pagesWithTables[$pageNum])) {
                $content = $paragraph['content'] ?? '';
                if (!empty($content)) {
                    $overflowContent[$pageNum][] = $content;
                }
            }
        }

        // Add overflow content as additional lines for parsers to handle
        foreach ($overflowContent as $pageNum => $contents) {
            $lines[] = "PAGE $pageNum OVERFLOW CONTENT";
            $lines[] = str_repeat('-', 80);
            foreach ($contents as $content) {
                $lines[] = $content;
            }
            $lines[] = "";
        }

        return $lines;
    }

    /**
     * Extract validity date from Azure result content
     */
    public function extractValidityFromResult(array $azureResult): string
    {
        if (!isset($azureResult['analyzeResult']['content'])) {
            return '';
        }

        $content = $azureResult['analyzeResult']['content'];

        // Pattern 1: "valid until DD/MM/YYYY" (BOXMAN format)
        if (preg_match('/valid\s+until\s+(\d{1,2})[\/\\\\](\d{1,2})[\/\\\\](\d{4})/i', $content, $matches)) {
            $monthNames = [
                '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
                '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug',
                '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'
            ];

            $monthName = $monthNames[str_pad($matches[2], 2, '0', STR_PAD_LEFT)] ?? 'Jan';
            return $matches[1] . ' ' . $monthName . ' ' . $matches[3];
        }

        // Pattern 2: "Rate can be applied until 1-30 Nov'2025" (HEUNG A format - 2nd remark)
        if (preg_match('/Rate can be applied until\s+(\d{1,2}[-–]\d{1,2})\s*(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[\'`]?(\d{4})/i', $content, $matches)) {
            $dateRange = $matches[1];  // e.g., "1-30"
            $month = strtoupper($matches[2]);  // e.g., "NOV"
            $year = $matches[3];  // e.g., "2025"
            return $dateRange . ' ' . $month . ' ' . $year;
        }

        // Pattern 3: "1-15 Nov 25" or "1 - 15 Nov. 25" (TS LINE format)
        if (preg_match('/(\d{1,2})\s*[-–]\s*(\d{1,2})\s*(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[.\s]*[\'`]?(\d{2,4})/i', $content, $matches)) {
            $startDay = $matches[1];
            $endDay = $matches[2];
            $month = strtoupper(substr($matches[3], 0, 3));
            $year = $matches[4];
            if (strlen($year) == 2) {
                $year = '20' . $year;
            }
            return "{$startDay}-{$endDay} {$month} {$year}";
        }

        // Pattern 4: "RATE GUIDELINE FOR DECEMBER 1-31, 2025" (SM LINE format - month first)
        if (preg_match('/(JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER)\s+(\d{1,2})\s*[-–]\s*(\d{1,2}),?\s*(\d{4})/i', $content, $matches)) {
            $monthFull = strtoupper($matches[1]);
            $startDay = $matches[2];
            $endDay = $matches[3];
            $year = $matches[4];

            // Convert full month name to 3-letter abbreviation
            $monthMap = [
                'JANUARY' => 'JAN', 'FEBRUARY' => 'FEB', 'MARCH' => 'MAR', 'APRIL' => 'APR',
                'MAY' => 'MAY', 'JUNE' => 'JUN', 'JULY' => 'JUL', 'AUGUST' => 'AUG',
                'SEPTEMBER' => 'SEP', 'OCTOBER' => 'OCT', 'NOVEMBER' => 'NOV', 'DECEMBER' => 'DEC'
            ];
            $month = $monthMap[$monthFull] ?? $monthFull;

            return "{$startDay}-{$endDay} {$month} {$year}";
        }

        // Pattern 5: "validity 1-31 Dec" (DONGJIN format - no year, use current year)
        if (preg_match('/validity\s+(\d{1,2})\s*[-–]\s*(\d{1,2})\s*(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/i', $content, $matches)) {
            $startDay = $matches[1];
            $endDay = $matches[2];
            $month = strtoupper(substr($matches[3], 0, 3));
            $year = date('Y');
            return "{$startDay}-{$endDay} {$month} {$year}";
        }

        // Pattern 6: "VALID 1-15 DEC" (WANHAI Middle East format - no year, use current year)
        if (preg_match('/VALID\s+(\d{1,2})\s*[-–]\s*(\d{1,2})\s*(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/i', $content, $matches)) {
            $startDay = $matches[1];
            $endDay = $matches[2];
            $month = strtoupper(substr($matches[3], 0, 3));
            $year = date('Y');
            return "{$startDay}-{$endDay} {$month} {$year}";
        }

        return '';
    }
}
