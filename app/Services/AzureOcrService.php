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
     * Detect and merge split tables (tables that Azure split horizontally)
     * Tables on the same page with similar row counts and adjacent X positions should be merged
     */
    protected function detectAndMergeSplitTables(array $tables, array $paragraphs = []): array
    {
        if (empty($tables)) {
            return $tables;
        }

        // Analyze each table's position and properties
        $tableInfo = [];
        foreach ($tables as $index => $table) {
            $pageNumber = 1;
            $minX = PHP_INT_MAX;
            $maxX = 0;
            $minY = PHP_INT_MAX;
            $maxY = 0;

            // Get bounding box from cells
            if (isset($table['boundingRegions'][0])) {
                $pageNumber = $table['boundingRegions'][0]['pageNumber'] ?? 1;
                $polygon = $table['boundingRegions'][0]['polygon'] ?? [];
                if (!empty($polygon)) {
                    for ($i = 0; $i < count($polygon); $i += 2) {
                        $x = $polygon[$i] ?? 0;
                        $y = $polygon[$i + 1] ?? 0;
                        $minX = min($minX, $x);
                        $maxX = max($maxX, $x);
                        $minY = min($minY, $y);
                        $maxY = max($maxY, $y);
                    }
                }
            }

            $tableInfo[$index] = [
                'index' => $index,
                'table' => $table,
                'pageNumber' => $pageNumber,
                'rowCount' => $table['rowCount'] ?? 0,
                'columnCount' => $table['columnCount'] ?? 0,
                'minX' => $minX,
                'maxX' => $maxX,
                'minY' => $minY,
                'maxY' => $maxY,
                'merged' => false,
            ];
        }

        // Group tables by page
        $tablesByPage = [];
        foreach ($tableInfo as $info) {
            $tablesByPage[$info['pageNumber']][] = $info;
        }

        $mergedTables = [];
        $processedIndices = [];

        // Process each page
        foreach ($tablesByPage as $pageNumber => $pageTables) {
            // Sort tables by X position (left to right)
            usort($pageTables, function ($a, $b) {
                return $a['minX'] <=> $b['minX'];
            });

            // Try to find table pairs that should be merged (side by side with similar row counts)
            for ($i = 0; $i < count($pageTables); $i++) {
                $leftTable = $pageTables[$i];

                if (in_array($leftTable['index'], $processedIndices)) {
                    continue;
                }

                // Look for a table to the right with similar row count
                $rightTable = null;
                for ($j = $i + 1; $j < count($pageTables); $j++) {
                    $candidate = $pageTables[$j];

                    if (in_array($candidate['index'], $processedIndices)) {
                        continue;
                    }

                    // Check if candidate is to the right (X position is greater)
                    if ($candidate['minX'] <= $leftTable['minX']) {
                        continue;
                    }

                    // Check if row counts are similar (within 2 rows difference for OCR variance)
                    $rowDiff = abs($leftTable['rowCount'] - $candidate['rowCount']);
                    if ($rowDiff <= 2) {
                        $rightTable = $candidate;
                        break;
                    }
                }

                if ($rightTable) {
                    // Merge the two tables
                    $merged = $this->mergeTablePair($leftTable['table'], $rightTable['table']);
                    $merged['pageNumber'] = $pageNumber;
                    $mergedTables[] = $merged;
                    $processedIndices[] = $leftTable['index'];
                    $processedIndices[] = $rightTable['index'];
                } else {
                    // No right table found - try to merge with right-side paragraphs
                    if (!empty($paragraphs)) {
                        $merged = $this->mergeTableWithParagraphs($leftTable['table'], $paragraphs, $pageNumber);
                        $merged['pageNumber'] = $pageNumber;
                        $mergedTables[] = $merged;
                    } else {
                        $mergedTables[] = $leftTable['table'];
                    }
                    $processedIndices[] = $leftTable['index'];
                }
            }
        }

        return $mergedTables;
    }

    /**
     * Merge two tables horizontally (left table + right table)
     */
    protected function mergeTablePair(array $leftTable, array $rightTable): array
    {
        $leftCells = [];
        $rightCells = [];
        $leftColCount = $leftTable['columnCount'] ?? 0;

        // Extract left table cells
        if (isset($leftTable['cells'])) {
            foreach ($leftTable['cells'] as $cell) {
                $row = $cell['rowIndex'] ?? 0;
                $col = $cell['columnIndex'] ?? 0;
                $content = $cell['content'] ?? '';

                if (!isset($leftCells[$row])) {
                    $leftCells[$row] = [];
                }
                $leftCells[$row][$col] = $content;
            }
        }

        // Extract right table cells
        if (isset($rightTable['cells'])) {
            foreach ($rightTable['cells'] as $cell) {
                $row = $cell['rowIndex'] ?? 0;
                $col = $cell['columnIndex'] ?? 0;
                $content = $cell['content'] ?? '';

                if (!isset($rightCells[$row])) {
                    $rightCells[$row] = [];
                }
                $rightCells[$row][$col] = $content;
            }
        }

        // Merge cells: right table columns are appended after left table columns
        $mergedCells = [];
        $maxRows = max(count($leftCells), count($rightCells), $leftTable['rowCount'] ?? 0, $rightTable['rowCount'] ?? 0);

        for ($row = 0; $row < $maxRows; $row++) {
            $mergedCells[$row] = [];

            // Add left table cells
            if (isset($leftCells[$row])) {
                foreach ($leftCells[$row] as $col => $content) {
                    $mergedCells[$row][$col] = $content;
                }
            }

            // Add right table cells with offset
            if (isset($rightCells[$row])) {
                foreach ($rightCells[$row] as $col => $content) {
                    $newCol = $leftColCount + $col;
                    $mergedCells[$row][$newCol] = $content;
                }
            }
        }

        $rightColCount = $rightTable['columnCount'] ?? 0;

        return [
            'rowCount' => $maxRows,
            'columnCount' => $leftColCount + $rightColCount,
            'mergedCells' => $mergedCells,
            'cells' => [], // Empty since we use mergedCells
        ];
    }

    /**
     * Find standalone tables (no right-side pair) and try to merge with right-side paragraphs
     * This handles cases where Azure captured the right columns as paragraphs instead of table cells
     */
    protected function mergeTableWithParagraphs(array $table, array $paragraphs, int $pageNumber): array
    {
        // Get table's bounding box
        $tablePolygon = $table['boundingRegions'][0]['polygon'] ?? [];
        if (empty($tablePolygon)) {
            return $table;
        }

        $tableMinY = $tablePolygon[1] ?? 0;
        $tableMaxY = $tablePolygon[5] ?? 10; // Bottom Y
        $tableMaxX = $tablePolygon[2] ?? 5;  // Right edge of table

        // Extract table cells with their Y positions
        $tableCellsByRow = [];
        $rowYPositions = [];

        if (isset($table['cells'])) {
            foreach ($table['cells'] as $cell) {
                $row = $cell['rowIndex'] ?? 0;
                $col = $cell['columnIndex'] ?? 0;
                $content = $cell['content'] ?? '';

                if (!isset($tableCellsByRow[$row])) {
                    $tableCellsByRow[$row] = [];
                }
                $tableCellsByRow[$row][$col] = $content;

                // Track Y position for each row
                if (isset($cell['boundingRegions'][0]['polygon'][1])) {
                    $cellY = $cell['boundingRegions'][0]['polygon'][1];
                    if (!isset($rowYPositions[$row]) || $cellY < $rowYPositions[$row]) {
                        $rowYPositions[$row] = $cellY;
                    }
                }
            }
        }

        // Sort rows by Y position
        asort($rowYPositions);
        $sortedRows = array_keys($rowYPositions);

        // Find right-side paragraphs (X > table's right edge) containing metadata patterns
        // Separate paragraphs into columns based on X position:
        // - Column 1 (X ~10.4-11.5): T/T and T/S info (e.g., "20-25 T/S at HCM", "5 Direct")
        // - Column 2 (X ~12.0+): Free time info (e.g., "7/5 days (dem+detention)")
        $ttTsParagraphs = [];  // T/T and T/S column
        $freeParagraphs = [];  // Free time column

        foreach ($paragraphs as $p) {
            $pPage = $p['boundingRegions'][0]['pageNumber'] ?? 0;
            if ($pPage != $pageNumber) continue;

            $polygon = $p['boundingRegions'][0]['polygon'] ?? [];
            $pX = $polygon[0] ?? 0;
            $pY = $polygon[1] ?? 0;
            $content = $p['content'] ?? '';

            // Skip paragraphs that are not to the right of the table
            if ($pX <= 10) continue;

            // Categorize by X position
            if ($pX < 11.8) {
                // T/T and T/S column - parse to extract T/T number
                // Patterns: "20-25 T/S at HCM", "6 T/S JKT by truck", "5 Direct", "14 Direct", "20-25"
                $tt = '';
                $ts = '';
                if (preg_match('/^(\d+(?:[,-]\d+)*)\s+(T\/S.+|Direct.*)$/i', $content, $m)) {
                    $tt = $m[1];
                    $ts = $m[2];
                } elseif (preg_match('/^(\d+(?:[,-]\d+)*)$/i', $content)) {
                    // Just a number (T/T only)
                    $tt = $content;
                } elseif (preg_match('/^(T\/S.+|Direct.*)$/i', $content)) {
                    // Just T/S info
                    $ts = $content;
                }
                if ($tt || $ts) {
                    $ttTsParagraphs[] = ['y' => $pY, 'x' => $pX, 'tt' => $tt, 'ts' => $ts];
                }
            } else {
                // Free time column
                if (preg_match('/days|dem|det/i', $content)) {
                    $freeParagraphs[] = ['y' => $pY, 'x' => $pX, 'free' => $content];
                }
            }
        }

        if (empty($ttTsParagraphs) && empty($freeParagraphs)) {
            return $table;
        }

        // Match paragraphs to rows by Y position
        $leftColCount = $table['columnCount'] ?? 0;
        $mergedCells = $tableCellsByRow;
        $yTolerance = 0.18;  // Increased from 0.12 to handle larger row spacing (e.g., Jakarta)

        // Build row data: assign each paragraph to its SINGLE closest row (not row to closest paragraph)
        // This prevents the same paragraph from being matched by multiple rows
        $rowMetadata = [];
        foreach ($sortedRows as $rowIndex) {
            $rowMetadata[$rowIndex] = ['tt' => '', 'ts' => '', 'free' => ''];
        }

        // Assign each T/T+T/S paragraph to its closest row
        foreach ($ttTsParagraphs as $p) {
            $closestRow = null;
            $closestDist = PHP_INT_MAX;
            foreach ($sortedRows as $rowIndex) {
                $dist = abs($rowYPositions[$rowIndex] - $p['y']);
                if ($dist < $closestDist && $dist < $yTolerance) {
                    $closestDist = $dist;
                    $closestRow = $rowIndex;
                }
            }
            if ($closestRow !== null) {
                // Only assign if this row doesn't already have T/T data, or this paragraph is closer
                if (empty($rowMetadata[$closestRow]['tt']) || $p['tt']) {
                    if ($p['tt']) $rowMetadata[$closestRow]['tt'] = $p['tt'];
                }
                if (empty($rowMetadata[$closestRow]['ts']) || $p['ts']) {
                    if ($p['ts']) $rowMetadata[$closestRow]['ts'] = $p['ts'];
                }
            }
        }

        // Assign each Free time paragraph to its closest row
        foreach ($freeParagraphs as $p) {
            $closestRow = null;
            $closestDist = PHP_INT_MAX;
            foreach ($sortedRows as $rowIndex) {
                $dist = abs($rowYPositions[$rowIndex] - $p['y']);
                if ($dist < $closestDist && $dist < $yTolerance) {
                    $closestDist = $dist;
                    $closestRow = $rowIndex;
                }
            }
            if ($closestRow !== null && empty($rowMetadata[$closestRow]['free'])) {
                $rowMetadata[$closestRow]['free'] = $p['free'];
            }
        }

        // Second pass: inherit from previous row ONLY for continuation rows (same route)
        // Main rows (with POD in col 1) should NOT inherit from different routes
        $lastTt = '';
        $lastTs = '';
        $lastFree = '';
        $lastPod = '';
        foreach ($sortedRows as $rowIndex) {
            $meta = &$rowMetadata[$rowIndex];
            $cells = $tableCellsByRow[$rowIndex] ?? [];

            // Check if this row is a "main" row (has POD in column 1)
            $colCount = count($cells);
            $isMainRow = $colCount >= 4;

            // Get POD from column 1 (if exists)
            $currentPod = isset($cells[1]) ? trim($cells[1]) : '';

            if ($isMainRow && $currentPod && $currentPod !== $lastPod) {
                // New route - reset last values, don't inherit from previous route
                $lastTt = '';
                $lastTs = '';
                $lastFree = '';
                $lastPod = $currentPod;

                // Use this row's data as new last values
                if ($meta['tt']) $lastTt = $meta['tt'];
                if ($meta['ts']) $lastTs = $meta['ts'];
                if ($meta['free']) $lastFree = $meta['free'];
            } else {
                // Continuation row or same route - can inherit
                if (!$meta['tt'] && $lastTt) $meta['tt'] = $lastTt;
                if (!$meta['ts'] && $lastTs) $meta['ts'] = $lastTs;
                if (!$meta['free'] && $lastFree) $meta['free'] = $lastFree;

                // Update last values
                if ($meta['tt']) $lastTt = $meta['tt'];
                if ($meta['ts']) $lastTs = $meta['ts'];
                if ($meta['free']) $lastFree = $meta['free'];
            }
        }

        // Third pass: propagate T/T from continuation row UP to main row (same route pair)
        // This handles cases where the main row has no T/T but the continuation row does
        $sortedRowsList = array_values($sortedRows);
        for ($i = 0; $i < count($sortedRowsList) - 1; $i++) {
            $rowIndex = $sortedRowsList[$i];
            $nextRowIndex = $sortedRowsList[$i + 1];

            $cells = $tableCellsByRow[$rowIndex] ?? [];
            $nextCells = $tableCellsByRow[$nextRowIndex] ?? [];

            // Check if current is main row (has POD) and next is continuation (same POD or no POD)
            $currentPod = isset($cells[1]) ? trim($cells[1]) : '';
            $nextPod = isset($nextCells[1]) ? trim($nextCells[1]) : '';

            // If next row has same POD or next row has fewer columns (continuation pattern)
            $isContinuationPair = $currentPod && (count($nextCells) < count($cells) || $currentPod === $nextPod);

            if ($isContinuationPair) {
                // Propagate data UP from continuation to main if main is missing data
                if (empty($rowMetadata[$rowIndex]['tt']) && !empty($rowMetadata[$nextRowIndex]['tt'])) {
                    $rowMetadata[$rowIndex]['tt'] = $rowMetadata[$nextRowIndex]['tt'];
                }
                if (empty($rowMetadata[$rowIndex]['ts']) && !empty($rowMetadata[$nextRowIndex]['ts'])) {
                    $rowMetadata[$rowIndex]['ts'] = $rowMetadata[$nextRowIndex]['ts'];
                }
                if (empty($rowMetadata[$rowIndex]['free']) && !empty($rowMetadata[$nextRowIndex]['free'])) {
                    $rowMetadata[$rowIndex]['free'] = $rowMetadata[$nextRowIndex]['free'];
                }
            }
        }

        // Add metadata columns to merged cells
        $addedCols = 0;
        foreach ($rowMetadata as $rowIndex => $meta) {
            $colOffset = 0;
            if ($meta['tt']) {
                $mergedCells[$rowIndex][$leftColCount + $colOffset] = $meta['tt'];
                $colOffset++;
            }
            if ($meta['ts']) {
                $mergedCells[$rowIndex][$leftColCount + $colOffset] = $meta['ts'];
                $colOffset++;
            }
            if ($meta['free']) {
                $mergedCells[$rowIndex][$leftColCount + $colOffset] = $meta['free'];
                $colOffset++;
            }
            $addedCols = max($addedCols, $colOffset);
        }

        return [
            'rowCount' => $table['rowCount'] ?? count($mergedCells),
            'columnCount' => $leftColCount + $addedCols,
            'mergedCells' => $mergedCells,
            'cells' => [],
            'boundingRegions' => $table['boundingRegions'] ?? [],
        ];
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

        // First, analyze all tables to find split tables that should be merged
        $tables = $azureResult['analyzeResult']['tables'];
        $paragraphs = $azureResult['analyzeResult']['paragraphs'] ?? [];
        $mergedTables = $this->detectAndMergeSplitTables($tables, $paragraphs);

        foreach ($mergedTables as $tableIndex => $table) {
            $lines[] = "TABLE " . ($tableIndex + 1) . " (Rows: " . ($table['rowCount'] ?? 0) . ", Cols: " . ($table['columnCount'] ?? 0) . ")";
            $lines[] = str_repeat('-', 80);

            $cells = $table['mergedCells'] ?? [];

            // If not merged, extract cells normally
            if (empty($cells) && isset($table['cells'])) {
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
            } else {
                // Track pages for merged tables
                if (isset($table['pageNumber'])) {
                    $pagesWithTables[$table['pageNumber']] = true;
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

    /**
     * Extract full text content from Azure OCR result
     *
     * @param array $azureResult
     * @return string
     */
    public function extractFullTextFromResult(array $azureResult): string
    {
        return $azureResult['analyzeResult']['content'] ?? '';
    }
}
