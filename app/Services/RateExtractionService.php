<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class RateExtractionService
{
    /**
     * Available extraction patterns
     */
    protected array $patterns = [
        'auto' => 'Auto-detect from filename',
        'rcl' => 'RCL (FAK Rate)',
        'kmtc' => 'KMTC (Updated Rate)',
        'sinokor' => 'SINOKOR (Main Rate Card)',
        'sinokor_skr' => 'SINOKOR SKR (HK Feederage)',
        'heung_a' => 'HEUNG A',
        'boxman' => 'BOXMAN',
        'sitc' => 'SITC',
        'wanhai' => 'WANHAI / India Rate',
        'ck_line' => 'CK LINE',
        'sm_line' => 'SM LINE',
        'dongjin' => 'DONGJIN',
        'ts_line' => 'TS LINE',
        'generic' => 'Generic Excel (auto-detect columns)',
    ];

    /**
     * Get available extraction patterns
     */
    public function getAvailablePatterns(): array
    {
        return $this->patterns;
    }

    /**
     * Extract rates from file using specified pattern
     */
    public function extractRates(string $filePath, string $pattern, string $validity = ''): array
    {
        $filename = basename($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Auto-detect pattern from filename if set to 'auto'
        if ($pattern === 'auto') {
            $pattern = $this->detectPatternFromFilename($filename);
        }

        // Handle PDF files (requires Azure OCR results)
        if ($extension === 'pdf') {
            return $this->extractFromPdf($filePath, $pattern, $validity);
        }

        // Handle Excel files
        return $this->extractFromExcel($filePath, $pattern, $validity);
    }

    /**
     * Detect pattern from filename
     */
    protected function detectPatternFromFilename(string $filename): string
    {
        $filename = strtoupper($filename);

        // Use .? to match optional space, underscore, or hyphen
        if (preg_match('/FAK.?RATE.?OF/i', $filename)) return 'rcl';
        if (preg_match('/UPDATED.?RATE/i', $filename)) return 'kmtc';
        // Check SKR pattern before generic SINOKOR (SKR is the HK feederage table)
        if (preg_match('/SKR.*SINOKOR|SINOKOR.*SKR/i', $filename)) return 'sinokor_skr';
        if (preg_match('/SINOKOR/i', $filename)) return 'sinokor';
        if (preg_match('/HEUNG.?A|HUANG.?A/i', $filename)) return 'heung_a';
        if (preg_match('/BOXMAN/i', $filename)) return 'boxman';
        if (preg_match('/SITC/i', $filename)) return 'sitc';
        if (preg_match('/INDIA|WANHAI/i', $filename)) return 'wanhai';
        if (preg_match('/CK.?LINE/i', $filename)) return 'ck_line';
        if (preg_match('/SM.?LINE/i', $filename)) return 'sm_line';
        if (preg_match('/DONGJIN/i', $filename)) return 'dongjin';
        if (preg_match('/TS.?LINE|RATE.?1ST/i', $filename)) return 'ts_line';

        return 'generic';
    }

    /**
     * Extract from Excel file
     */
    protected function extractFromExcel(string $filePath, string $pattern, string $validity): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        return match ($pattern) {
            'rcl' => $this->parseRclExcel($worksheet, $validity),
            'kmtc' => $this->parseKmtcExcel($worksheet, $validity),
            'sinokor' => $this->parseSinokorExcel($worksheet, $validity),
            'sinokor_skr' => $this->parseSinokorSkrExcel($worksheet, $validity),
            'heung_a' => $this->parseHeungAExcel($worksheet, $validity),
            'boxman' => $this->parseBoxmanExcel($worksheet, $validity),
            'sitc' => $this->parseSitcExcel($worksheet, $validity),
            'wanhai' => $this->parseWanhaiExcel($worksheet, $validity),
            default => $this->parseGenericExcel($worksheet, $validity),
        };
    }

    /**
     * Extract from PDF file (uses Azure OCR - either cached or on-the-fly)
     */
    protected function extractFromPdf(string $filePath, string $pattern, string $validity): array
    {
        $baseFilename = pathinfo($filePath, PATHINFO_FILENAME);
        $azureResultsDir = base_path('temp_attachments/azure_ocr_results/');
        $tableFile = $azureResultsDir . $baseFilename . '_tables.txt';
        $jsonFile = $azureResultsDir . $baseFilename . '_azure_result.json';

        // Try to extract validity from filename first (e.g., "GUIDE RATE FOR 1-30 NOV 2025_SINOKOR")
        if (empty($validity)) {
            $validity = $this->extractValidityFromFilename($baseFilename);
        }

        // Check for existing Azure OCR results first
        if (file_exists($tableFile)) {
            $content = file_get_contents($tableFile);
            $lines = explode("\n", $content);

            if (empty($validity) && file_exists($jsonFile)) {
                $validity = $this->extractValidityFromJson($jsonFile);
            }
        } else {
            // No cached results - run Azure OCR on-the-fly
            $azureOcr = new AzureOcrService();

            if (!$azureOcr->isConfigured()) {
                throw new \Exception('Azure OCR is not configured. Please set up .env.azure file with your Azure Document Intelligence credentials.');
            }

            // Run OCR analysis
            $azureResult = $azureOcr->analyzePdf($filePath);

            // Extract tables to lines format
            $lines = $azureOcr->extractTablesToLines($azureResult);

            // Extract validity from result
            if (empty($validity)) {
                $validity = $azureOcr->extractValidityFromResult($azureResult);
            }

            // Cache the results for future use
            if (!is_dir($azureResultsDir)) {
                mkdir($azureResultsDir, 0755, true);
            }
            file_put_contents($jsonFile, json_encode($azureResult, JSON_PRETTY_PRINT));
            file_put_contents($tableFile, implode("\n", $lines));
        }

        return match ($pattern) {
            'sinokor' => $this->parseSinokorTable($lines, $validity),
            'sinokor_skr' => $this->parseSinokorSkrTable($lines, $validity),
            'heung_a' => $this->parseHeungATable($lines, $validity),
            'boxman' => $this->parseBoxmanTable($lines, $validity),
            'sitc' => $this->parseSitcTable($lines, $validity),
            'wanhai' => $this->parseWanhaiTable($lines, $validity),
            'ts_line' => $this->parseTsLineTable($lines, $validity),
            default => $this->parseGenericTable($lines, $pattern, $validity),
        };
    }

    /**
     * Parse RCL Excel format
     */
    protected function parseRclExcel($worksheet, string $validity): array
    {
        $rates = [];
        $highestRow = $worksheet->getHighestDataRow();

        // Extract VALIDITY from cell B6 if not provided
        if (empty($validity)) {
            $validityRaw = trim($worksheet->getCell('B6')->getValue() ?? '');
            $validity = $this->formatValidity($validityRaw);
        }

        // Build merged cell values map
        $mergedCellValues = $this->buildMergedCellMap($worksheet);

        $getCellValue = function($col, $row) use ($worksheet, $mergedCellValues) {
            $cellAddress = $col . $row;
            if (isset($mergedCellValues[$cellAddress])) {
                return $mergedCellValues[$cellAddress];
            }
            return $worksheet->getCell($cellAddress)->getCalculatedValue();
        };

        for ($row = 10; $row <= $highestRow; $row++) {
            $country = trim($getCellValue('A', $row) ?? '');
            $pod = trim($getCellValue('B', $row) ?? '');
            $pol = trim($getCellValue('D', $row) ?? '');
            $etdColumnF = trim($getCellValue('F', $row) ?? '');
            $ts = trim($getCellValue('I', $row) ?? '');
            $tt = trim($getCellValue('J', $row) ?? '');
            $freeTime = trim($getCellValue('K', $row) ?? '');
            $remarkColumnL = trim($getCellValue('L', $row) ?? '');

            // Check for black row highlighting
            $isBlackRow = $this->isBlackHighlightedRow($worksheet, 'B' . $row);

            // Format T/T
            if (!empty($tt)) {
                $tt .= ' Days';
            } else {
                $tt = 'TBA';
            }

            if (empty($ts)) $ts = 'TBA';
            if (empty($freeTime)) $freeTime = 'TBA';

            $rate20 = $getCellValue('G', $row);
            $rate40 = $getCellValue('H', $row);

            $rate20 = is_numeric($rate20) ? trim($rate20) : '';
            $rate40 = is_numeric($rate40) ? trim($rate40) : '';

            if (empty($pod) || (empty($rate20) && empty($rate40))) continue;
            if ($rate20 == 0 && $rate40 == 0) continue;

            // Process ETD dates
            [$etdBkk, $etdLch, $remark] = $this->processEtdDates($etdColumnF, $pol, $remarkColumnL);

            if ($isBlackRow) {
                $rate20 = $rate40 = $etdBkk = $etdLch = $tt = $ts = $freeTime = 'TBA';
            }

            $rates[] = $this->createRateEntry('RCL', $pol ?: 'BKK/LCH', $pod, $rate20, $rate40, [
                'ETD BKK' => $etdBkk,
                'ETD LCH' => $etdLch,
                'T/T' => $tt,
                'T/S' => $ts,
                'FREE TIME' => $freeTime,
                'VALIDITY' => $validity,
                'REMARK' => $remark,
                '_isBlackRow' => $isBlackRow,
            ]);
        }

        return $rates;
    }

    /**
     * Parse KMTC Excel format
     */
    protected function parseKmtcExcel($worksheet, string $validity): array
    {
        $rates = [];
        $highestRow = $worksheet->getHighestDataRow();

        for ($row = 6; $row <= $highestRow; $row++) {
            $country = trim($worksheet->getCell('B' . $row)->getValue() ?? '');
            $pol = trim($worksheet->getCell('C' . $row)->getValue() ?? '');
            $podArea = trim($worksheet->getCell('D' . $row)->getValue() ?? '');
            $rate20 = trim($worksheet->getCell('E' . $row)->getValue() ?? '');
            $rate40 = trim($worksheet->getCell('F' . $row)->getValue() ?? '');
            $validCol = trim($worksheet->getCell('G' . $row)->getValue() ?? '');
            $freeTime = trim($worksheet->getCell('J' . $row)->getValue() ?? '');

            if (empty($podArea) && empty($rate20) && empty($rate40)) continue;
            if (empty($freeTime)) $freeTime = 'TBA';

            // Use validity from column G if available, otherwise use provided or default
            $rowValidity = $validity;
            if (!empty($validCol)) {
                // Format: "1-31 Dec" -> "1-31 Dec 2025"
                $rowValidity = $this->formatKmtcValidity($validCol);
            } elseif (empty($rowValidity)) {
                $rowValidity = strtoupper(date('M Y'));
            }

            $rates[] = $this->createRateEntry('KMTC', $pol ?: 'BKK/LCH', $podArea, $rate20, $rate40, [
                'FREE TIME' => $freeTime,
                'VALIDITY' => $rowValidity,
                'REMARK' => $country,
            ]);
        }

        return $rates;
    }

    /**
     * Format KMTC validity date (e.g., "1-31 Dec" -> "1-31 DEC 2025")
     */
    protected function formatKmtcValidity(string $validCol): string
    {
        // If already has year, just return uppercase
        if (preg_match('/\d{4}/', $validCol)) {
            return strtoupper($validCol);
        }

        // Add current year
        $year = date('Y');
        return strtoupper(trim($validCol) . ' ' . $year);
    }

    /**
     * Parse Sinokor Excel format (direct Excel, not Azure)
     */
    protected function parseSinokorExcel($worksheet, string $validity): array
    {
        // For direct Excel files, similar logic to table parsing
        return $this->parseGenericExcel($worksheet, $validity, 'SINOKOR');
    }

    /**
     * Parse Heung A Excel format
     */
    protected function parseHeungAExcel($worksheet, string $validity): array
    {
        return $this->parseGenericExcel($worksheet, $validity, 'HEUNG A');
    }

    /**
     * Parse Boxman Excel format
     */
    protected function parseBoxmanExcel($worksheet, string $validity): array
    {
        return $this->parseGenericExcel($worksheet, $validity, 'BOXMAN');
    }

    /**
     * Parse SITC Excel format
     */
    protected function parseSitcExcel($worksheet, string $validity): array
    {
        return $this->parseGenericExcel($worksheet, $validity, 'SITC');
    }

    /**
     * Parse Wanhai/India Excel format
     */
    protected function parseWanhaiExcel($worksheet, string $validity): array
    {
        return $this->parseGenericExcel($worksheet, $validity, 'WANHAI');
    }

    /**
     * Parse generic Excel format with auto-detection
     */
    protected function parseGenericExcel($worksheet, string $validity, string $carrier = ''): array
    {
        $rates = [];
        $highestRow = $worksheet->getHighestDataRow();
        $highestCol = $worksheet->getHighestDataColumn();

        if (empty($validity)) {
            $validity = strtoupper(date('M Y'));
        }

        // Detect header row and column mapping
        $headerRow = $this->detectHeaderRow($worksheet, $highestRow);
        $columnMap = $this->detectColumnMapping($worksheet, $headerRow, $highestCol);

        if (empty($columnMap['pod'])) {
            throw new \Exception('Could not detect POD column in the Excel file.');
        }

        // Process data rows
        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $rowData = [];

            foreach ($columnMap as $field => $col) {
                if ($col) {
                    $rowData[$field] = trim($worksheet->getCell($col . $row)->getCalculatedValue() ?? '');
                }
            }

            $pod = $rowData['pod'] ?? '';
            $pol = $rowData['pol'] ?? 'BKK/LCH';
            $rate20 = preg_replace('/[^0-9.]/', '', $rowData['rate20'] ?? '');
            $rate40 = preg_replace('/[^0-9.]/', '', $rowData['rate40'] ?? '');

            if (empty($pod) || (empty($rate20) && empty($rate40))) continue;

            $rates[] = $this->createRateEntry(
                $carrier ?: ($rowData['carrier'] ?? 'UNKNOWN'),
                $pol,
                $pod,
                $rate20,
                $rate40,
                [
                    'T/T' => $rowData['tt'] ?? 'TBA',
                    'T/S' => $rowData['ts'] ?? 'TBA',
                    'FREE TIME' => $rowData['freetime'] ?? 'TBA',
                    'VALIDITY' => $validity,
                    'REMARK' => $rowData['remark'] ?? '',
                ]
            );
        }

        return $rates;
    }

    /**
     * Parse Sinokor table format (from Azure OCR)
     */
    protected function parseSinokorTable(array $lines, string $validity): array
    {
        $rates = [];
        $pendingPod = ''; // Track POD that has incomplete rate data
        $currentCountry = ''; // Track current country for remarks

        // Extract validity from title if not provided (e.g., "GUIDE RATE FOR 1-30 NOV 2025")
        if (empty($validity)) {
            $validity = $this->extractSinokorValidity($lines);
        }

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Check for raw rate line without "Row X:" prefix (e.g., "250 | 500")
            if (!preg_match('/^Row \d+:/', $line) && !empty($pendingPod)) {
                // This might be continuation rates for pending POD
                $cells = explode(' | ', trim($line));
                if (count($cells) == 2) {
                    $rate20 = preg_replace('/[^0-9]/', '', trim($cells[0] ?? ''));
                    $rate40 = preg_replace('/[^0-9]/', '', trim($cells[1] ?? ''));
                    if (!empty($rate20) || !empty($rate40)) {
                        // Extract remark from pending POD with country
                        [$cleanPod, $remark] = $this->extractSinokorRemark($pendingPod, $currentCountry);
                        $rates[] = $this->createRateEntry('SINOKOR', 'BKK/LCH', $cleanPod, $rate20, $rate40, [
                            'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                            'REMARK' => $remark,
                        ]);
                    }
                    $pendingPod = '';
                }
                continue;
            }

            if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) continue;

            $cells = explode(' | ', $matches[1]);
            if (count($cells) < 2) continue;
            if (preg_match('/(COUNTRY|POL|POD NAME|20offer|SINOKOR|HANSUNG|BKK\/LCH)/i', $cells[0])) continue;
            // Skip :unselected: rows (OCR artifacts)
            if (preg_match('/^:unselected:/', $cells[0])) continue;

            $pod = '';
            $rate20 = '';
            $rate40 = '';
            $country = '';

            if (count($cells) >= 4) {
                // 4 columns: COUNTRY | POD | 20' | 40'
                $country = trim($cells[0] ?? '');
                $pod = trim($cells[1] ?? '');
                $rate20 = trim($cells[2] ?? '');
                $rate40 = trim($cells[3] ?? '');
            } elseif (count($cells) == 3) {
                // Could be: POD | 20' | 40' (continuation) OR COUNTRY | POD | REMARK (header row)
                $cell0 = trim($cells[0] ?? '');
                $cell1 = trim($cells[1] ?? '');
                $cell2 = trim($cells[2] ?? '');

                // Check if this is a country header row (3rd column is not numeric, contains text)
                // e.g., "S.CHINA | S.CHINA T/S HKG | SELL AT PRD SALES GUIDE"
                if (!preg_match('/^\d+$/', $cell2) && preg_match('/[A-Za-z]{3,}/', $cell2)) {
                    // This is a country header row
                    $country = $cell0;
                    $pod = $cell1;
                    $rate20 = $cell2; // Will be skipped by SELL/GUIDE check below
                    $rate40 = '';
                } else {
                    // Standard continuation row: POD | 20' | 40'
                    $pod = $cell0;
                    $rate20 = $cell1;
                    $rate40 = $cell2;
                }
            } elseif (count($cells) == 2) {
                // 2 columns: could be "POD | --------" or rates for pending POD
                $firstCell = trim($cells[0] ?? '');
                $secondCell = trim($cells[1] ?? '');

                // Check if it's "POD | --------" pattern (incomplete rate row)
                if (preg_match('/^-+$/', $secondCell) && !preg_match('/^\d+$/', $firstCell)) {
                    $pendingPod = $firstCell;
                    continue;
                }

                // Otherwise it might be rates for pending POD
                if (!empty($pendingPod)) {
                    $rate20 = preg_replace('/[^0-9]/', '', $firstCell);
                    $rate40 = preg_replace('/[^0-9]/', '', $secondCell);
                    if (!empty($rate20) || !empty($rate40)) {
                        [$cleanPod, $remark] = $this->extractSinokorRemark($pendingPod, $currentCountry);
                        $rates[] = $this->createRateEntry('SINOKOR', 'BKK/LCH', $cleanPod, $rate20, $rate40, [
                            'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                            'REMARK' => $remark,
                        ]);
                    }
                    $pendingPod = '';
                }
                continue;
            }

            // Update current country if we got a new one (do this BEFORE any skip checks)
            if (!empty($country) && !preg_match('/^\d+$/', $country)) {
                $currentCountry = $country;
            }

            // Skip rows with text in rate column (e.g., "SELL AT PRD SALES GUIDE")
            // Note: country is already updated above so continuation rows get correct country
            if (preg_match('/SELL|GUIDE|CHECK|TBA/i', $rate20) || preg_match('/SELL|GUIDE|CHECK|TBA/i', $rate40)) {
                continue;
            }

            // Clean up POD - skip if it looks like a rate or incomplete data
            if (preg_match('/^[\d\-]+$/', $pod) || preg_match('/^-+$/', $pod)) continue;

            // Check if rates are incomplete (showing "--------" or similar)
            if (preg_match('/^-+$/', $rate20) || preg_match('/^-+$/', $rate40)) {
                $pendingPod = $pod; // Save POD to match with rates on next line
                continue;
            }

            $rate20 = preg_replace('/[^0-9]/', '', $rate20);
            $rate40 = preg_replace('/[^0-9]/', '', $rate40);

            if (!empty($pod) && (!empty($rate20) || !empty($rate40))) {
                // Extract remark from POD with country-specific remarks
                [$cleanPod, $remark] = $this->extractSinokorRemark($pod, $currentCountry);
                $rates[] = $this->createRateEntry('SINOKOR', 'BKK/LCH', $cleanPod, $rate20, $rate40, [
                    'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                    'REMARK' => $remark,
                ]);
            }
        }

        return $rates;
    }

    /**
     * Parse SINOKOR SKR feederage table format (8-column via Hong Kong)
     * Structure: POL_CODE | POL_NAME | POD_CODE | POD_NAME | T/T | Type | 20' | 40'
     * Note: OCR sometimes merges last two columns as "250 400" instead of "250 | 400"
     */
    protected function parseSinokorSkrTable(array $lines, string $validity): array
    {
        $rates = [];

        // Extract validity from title if not provided (e.g., "GUIDE RATE FOR 1-30 NOV 2025")
        if (empty($validity)) {
            $validity = $this->extractSinokorValidity($lines);
        }

        foreach ($lines as $line) {
            if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) continue;

            $cells = explode(' | ', $matches[1]);

            // Need at least 7 columns (8 if properly separated, 7 if rates are merged)
            if (count($cells) < 7) continue;

            // Skip header rows
            if (preg_match('/(POL|POD|20offer|Feederage|SINOKOR)/i', $cells[0] ?? '')) continue;

            $polCode = trim($cells[0] ?? '');
            $polName = trim($cells[1] ?? '');
            $podCode = trim($cells[2] ?? '');
            $podName = trim($cells[3] ?? '');
            $transitTime = trim($cells[4] ?? '');
            $containerType = trim($cells[5] ?? '');
            $rate20 = '';
            $rate40 = '';

            if (count($cells) >= 8) {
                // Normal case: 8 columns with separate rates
                $rate20 = trim($cells[6] ?? '');
                $rate40 = trim($cells[7] ?? '');
            } elseif (count($cells) == 7) {
                // OCR merged rates case: "250 400" in single column
                $mergedRates = trim($cells[6] ?? '');
                // Split by space to get both rates
                if (preg_match('/^(\d+)\s+(\d+)$/', $mergedRates, $rateMatch)) {
                    $rate20 = $rateMatch[1];
                    $rate40 = $rateMatch[2];
                } else {
                    // If can't parse, use as rate20 only
                    $rate20 = $mergedRates;
                }
            }

            // Skip non-data rows
            if (empty($podName) || preg_match('/^(POD|NAME|Type)/i', $podName)) continue;

            // Clean rates
            $rate20 = preg_replace('/[^0-9]/', '', $rate20);
            $rate40 = preg_replace('/[^0-9]/', '', $rate40);

            if (empty($rate20) && empty($rate40)) continue;

            // Format T/T
            $tt = !empty($transitTime) ? $transitTime . ' Days' : 'TBA';

            // SKR feederage table has no remarks - leave empty
            // Only add container type if not GP (e.g., TN for tank container)
            $remark = '';
            if (!empty($containerType) && strtoupper($containerType) !== 'GP') {
                $remark = strtoupper($containerType);
            }

            $rates[] = $this->createRateEntry('SINOKOR', $polName ?: 'HONG KONG', $podName, $rate20, $rate40, [
                'T/T' => $tt,
                'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                'REMARK' => $remark,
            ]);
        }

        return $rates;
    }

    /**
     * Parse SINOKOR SKR Excel format (placeholder - uses generic)
     */
    protected function parseSinokorSkrExcel($worksheet, string $validity): array
    {
        return $this->parseGenericExcel($worksheet, $validity, 'SINOKOR');
    }

    /**
     * Extract remark from SINOKOR POD column and get country-specific remarks
     * Remarks are typically in parentheses: MANZANILLO (T/S PUS), CHENNAI (LCH ONLY)
     */
    protected function extractSinokorRemark(string $pod, string $country = ''): array
    {
        $podRemark = '';
        $cleanPod = $pod;

        // Extract text in parentheses as POD-specific remark
        if (preg_match('/^(.+?)\s*\(([^)]+)\)(.*)$/', $pod, $matches)) {
            $cleanPod = trim($matches[1]);
            $podRemark = trim($matches[2]);

            // Check for additional parentheses (e.g., "PORT KLANG (WEST) (LCH ONLY)")
            if (!empty($matches[3]) && preg_match('/\(([^)]+)\)/', $matches[3], $extraMatch)) {
                $podRemark = trim($extraMatch[1]);
                $cleanPod = trim($matches[1] . ' (' . $matches[2] . ')');
            }
        }

        // Also check for T/S patterns not in parentheses
        if (empty($podRemark) && preg_match('/\bT\/S\s+(\w+)/i', $pod, $tsMatch)) {
            $podRemark = 'T/S ' . $tsMatch[1];
            $cleanPod = preg_replace('/\s*T\/S\s+\w+/i', '', $pod);
        }

        // Get country-specific full remark from mapping
        $countryRemark = $this->getSinokorCountryRemark($country, $cleanPod);

        // Combine POD remark with country remark
        $fullRemark = $countryRemark;
        if (!empty($podRemark) && stripos($countryRemark, $podRemark) === false) {
            $fullRemark = $podRemark . '; ' . $countryRemark;
        }

        return [trim($cleanPod), $fullRemark];
    }

    /**
     * Get SINOKOR country-specific remarks based on the PDF structure
     */
    protected function getSinokorCountryRemark(string $country, string $pod = ''): string
    {
        $remarks = [
            'MAXICO' => '1) OCF INCL LSS',
            'C.CHINA' => '1) OCF INCL LSS / SUBJ.TO AFR $30/BL; 2) EX.THLKR / THSPR ADDED ON $100/$150 PER 20\'/40HQ FOR INLAND CHARGE',
            'INDIA' => '1) OCF INCL LSS; 2) EX.THLKR ADDED ON $100/$150 PER 20\'/40HQ FOR INLAND CHARGE',
            'MALAYSIA' => '1) OCF INCL LSS; 2) EX.THLKR / THSPR ADDED ON $100/$150 PER 20\'/40HQ FOR INLAND CHARGE',
            'HONGKONG' => '1) OCF INCL LSS; 2) PCS AT DESTINATION $100/$200 IS WAIVED; 3) RICE SHIPMENT $100/20DC INCL LSS, DTHC HKD $1500/20DC; 4) DG MUST BE ADDED ON AT LEAST $100/TEU; 5) CONSOL $100/$200 INCL LSS (SUBJECT TO EQUIPMENT AVAILABLE); 6) FLEXIBAG MUST BE ADDED ON AT LEAST $100/20DC',
            'S.CHINA' => '1) OCF INCL LSS; 2) PCS AT DESTINATION $100/$200 IS WAIVED; 3) FLEXIBAG MUST BE ADDED ON AT LEAST $100/20DC; 4) SUBJ.TO AFR $30/BL',
            'N.CHINA' => 'OCF INCL LSS; SUBJ.TO AFR $30/BL',
            'HOCHIMINH' => '1) OCF INCL LSS; 2) CIC AT DESTINATION WAIVED; 3) CONSOL $70/$140 INCL LSS (SUBJECT EQUIPMENT AVAILABLE); 4) FLEXIBAG MUST BE ADDED ON AT LEAST $100/20DC; 5) DG MUST BE ADDED ON AT LEAST $100/TEU',
            'INDONESIA' => '1) OCF INCL LSS; 2) EX.THLKR ADDED ON $100/$150 PER 20\'/40HQ FOR INLAND CHARGE; 3) DG MUST BE ADDED ON AT LEAST $100/TEU',
            'TAIWAN' => 'OCF INCL LSS',
            'JP(MAIN PORT)' => 'OCF INCL LSS',
            'JP(OUT PORT)' => 'OCF INCL LSS / SUBJ.TO AFR $30/BL',
            'RUSSIA' => 'OCF INCL LSS',
            'S.KOREA' => '1) OCF INCL LSF / NES / CIS / CRS; 2) CONSOL PUS $420/840 + LSF (INCL NES + CRS); 3) CONSOL INC,PKT $520/1040 + LSF (INCL NES + CRS + CIS); 4) FLEXIBAG MUST BE ADDED ON AT LEAST $100/20DC; 5) DG MUST BE ADDED ON AT LEAST $100/TEU',
        ];

        // Normalize country name (remove spaces for matching)
        $countryUpper = strtoupper(trim($country));
        $countryNormalized = str_replace(' ', '', $countryUpper);

        // Direct match
        if (isset($remarks[$countryUpper])) {
            return $remarks[$countryUpper];
        }

        // Try normalized match (e.g., "HONG KONG" -> "HONGKONG")
        if (isset($remarks[$countryNormalized])) {
            return $remarks[$countryNormalized];
        }

        // Try partial matches
        foreach ($remarks as $key => $remark) {
            $keyNormalized = str_replace(' ', '', $key);
            if (stripos($countryNormalized, $keyNormalized) !== false ||
                stripos($keyNormalized, $countryNormalized) !== false ||
                stripos($countryUpper, $key) !== false ||
                stripos($key, $countryUpper) !== false) {
                return $remark;
            }
        }

        // Default remark
        return 'OCF INCL LSS';
    }

    /**
     * Extract validity from SINOKOR title/filename
     * Pattern: "GUIDE RATE FOR 1-30 NOV 2025" or "GUIDELINE RATE FOR 1-30 NOV 2025"
     */
    protected function extractSinokorValidity(array $lines): string
    {
        // Check first few lines and filename line for validity pattern
        foreach ($lines as $line) {
            // Match patterns like "1-30 NOV 2025", "1-31 DEC 2025", etc.
            if (preg_match('/(\d{1,2}[-\s]*\d{0,2})\s*(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)[A-Z]*\s*(\d{4})/i', $line, $matches)) {
                $dateRange = trim($matches[1]);
                $month = strtoupper($matches[2]);
                $year = $matches[3];

                // Clean up date range (e.g., "1-30" or "1 - 30" -> "1-30")
                $dateRange = preg_replace('/\s+/', '', $dateRange);

                // If only single date, assume full month
                if (!str_contains($dateRange, '-')) {
                    $dateRange = '1-' . $dateRange;
                }

                return strtoupper("{$dateRange} {$month} {$year}");
            }
        }

        // Default to current month
        return strtoupper(date('M Y'));
    }

    /**
     * Parse Heung A table format (from Azure OCR)
     */
    protected function parseHeungATable(array $lines, string $validity): array
    {
        $rates = [];
        $lastTransitTime = '';  // Track last T/T for merged cells
        $lastDirectTs = '';     // Track last T/S for merged cells

        foreach ($lines as $line) {
            if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) continue;

            $cells = explode(' | ', $matches[1]);
            if (count($cells) < 4) continue;
            if (preg_match('/(POL|POD|Nov2025|Dec2025|20\'|40\')/i', $cells[0] ?? '')) continue;

            $pol = trim($cells[0] ?? '');
            $pod = trim($cells[1] ?? '');
            $rate20 = trim($cells[2] ?? '');
            $rate40 = trim($cells[3] ?? '');

            $isCheckPort = (stripos($rate20, 'Check') !== false);

            if ($isCheckPort) {
                $sailingDate = trim($cells[4] ?? '');
                $directTs = trim($cells[5] ?? '');
                $transitTime = trim($cells[6] ?? '');
                $surcharge = trim($cells[7] ?? '');
            } else {
                $sailingDate = trim($cells[5] ?? '');
                $directTs = trim($cells[6] ?? '');
                $transitTime = trim($cells[7] ?? '');
                $surcharge = trim($cells[8] ?? '');
            }

            $pod = preg_replace('/\s*\([^)]*\)/', '', $pod);

            if ($isCheckPort) {
                $rate20 = 'Check port';
                $rate40 = 'Check port';
            } else {
                $rate20 = preg_replace('/[^0-9]/', '', $rate20);
                $rate40 = preg_replace('/[^0-9]/', '', $rate40);
            }

            if (!empty($pod) && (!empty($rate20) || !empty($rate40))) {
                [$etdBkk, $etdLch] = $this->mapPolToEtd($pol, $sailingDate);

                // Handle merged T/T cells - carry forward from previous row
                if (empty($transitTime) && !empty($lastTransitTime)) {
                    $transitTime = $lastTransitTime;
                }
                if (!empty($transitTime)) {
                    $lastTransitTime = $transitTime;
                }

                // Handle merged T/S cells - carry forward from previous row
                if (empty($directTs) && !empty($lastDirectTs)) {
                    $directTs = $lastDirectTs;
                }
                if (!empty($directTs)) {
                    $lastDirectTs = $directTs;
                }

                $rates[] = $this->createRateEntry('HEUNG A', $pol, $pod, $rate20, $rate40, [
                    'ETD BKK' => $etdBkk,
                    'ETD LCH' => $etdLch,
                    'T/T' => !empty($transitTime) ? $transitTime . ' Days' : 'TBA',
                    'T/S' => !empty($directTs) ? $directTs : 'TBA',
                    'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                    'REMARK' => $surcharge,
                ]);
            }
        }

        return $rates;
    }

    /**
     * Parse Boxman table format (from Azure OCR)
     */
    protected function parseBoxmanTable(array $lines, string $validity): array
    {
        $rates = [];
        $lastPol = '';
        $lastEtd = '';
        $lastRemarks = '';  // Track last remarks for merged cells
        $lastTs = 'TBA';    // Track last T/S for merged cells
        $lastTransitTime = ''; // Track last transit time for merged cells

        foreach ($lines as $line) {
            if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) continue;

            $cells = explode(' | ', $matches[1]);
            if (count($cells) < 3) continue;
            if (preg_match('/(Port of Loading|POL|20\'DC|40\'HC)/i', $cells[0] ?? '')) {
                $lastEtd = '';
                $lastRemarks = '';
                $lastTs = 'TBA';
                $lastTransitTime = '';
                continue;
            }

            $pol = trim($cells[0] ?? '');
            $pod = trim($cells[1] ?? '');
            $rate20 = trim($cells[2] ?? '');
            $rate40 = trim($cells[3] ?? '');
            $etd = trim($cells[4] ?? '');
            $transitTime = trim($cells[5] ?? '');
            $remarks = trim($cells[6] ?? '');

            // Handle merged POL cells
            if (empty($pol) || preg_match('/^[A-Z][a-z]/', $pol)) {
                if (preg_match('/^[A-Z][a-z]/', $pol) && !preg_match('/(LKB|LCH|BKK|LKE)/i', $pol)) {
                    $remarks = $transitTime;
                    $transitTime = $etd;
                    $etd = $rate40;
                    $rate40 = $rate20;
                    $rate20 = $pod;
                    $pod = $pol;
                    $pol = $lastPol;
                } else {
                    $pol = $lastPol;
                }
            } else {
                $lastPol = $pol;
            }

            // Handle N/A in rate40 - this indicates 40' is not available
            // In TABLE 5 format: POL | POD | 20' | N/A | ETD | Transit | Remarks
            // But for LCH rows: POL | POD | 20' | N/A | Transit (ETD merged)
            if (strtoupper($rate40) === 'N/A' || $rate40 === '') {
                // Check if etd looks like a transit time (contains "days")
                if (!empty($etd) && stripos($etd, 'day') !== false) {
                    // ETD column actually contains transit time - ETD is merged
                    $remarks = $transitTime; // What we thought was transit is actually remarks
                    $transitTime = $etd;     // ETD column has transit time
                    $etd = '';               // ETD is merged from previous row
                }
                $rate40 = ''; // Set to empty, not N/A
            }

            $rate20 = preg_replace('/[^0-9]/', '', $rate20);
            $rate40 = preg_replace('/[^0-9]/', '', $rate40);

            if (empty($pod) || (empty($rate20) && empty($rate40))) continue;

            // Handle ETD - if it looks like transit time, swap
            if (!empty($etd) && stripos($etd, 'day') !== false) {
                if (empty($transitTime)) $transitTime = $etd;
                $etd = '';
            }

            // Handle merged ETD cells
            if (empty($etd) && !empty($lastEtd)) $etd = $lastEtd;
            if (!empty($etd)) $lastEtd = $etd;

            // Handle merged transit time
            if (empty($transitTime) && !empty($lastTransitTime)) {
                $transitTime = $lastTransitTime;
            }
            if (!empty($transitTime)) {
                $lastTransitTime = $transitTime;
            }

            [$etdBkk, $etdLch] = $this->mapPolToEtdBoxman($pol, $etd);

            // Handle merged Remarks/T/S cells - carry forward from previous row
            $ts = 'TBA';
            if (!empty($remarks)) {
                // This row has remarks - parse T/S and update last values
                if (stripos($remarks, 'DIRECT') !== false) {
                    $ts = 'DIRECT';
                } elseif (preg_match('/T\/S\s+(.+)/i', $remarks, $tsMatch)) {
                    $ts = 'T/S ' . trim($tsMatch[1]);
                } elseif (stripos($remarks, 'CAN RETURN') !== false) {
                    // Special case - keep the remark but T/S is TBA
                    $ts = 'TBA';
                }
                $lastRemarks = $remarks;
                $lastTs = $ts;
            } else {
                // Empty remarks - use last values (merged cell)
                $remarks = $lastRemarks;
                $ts = $lastTs;
            }

            $rates[] = $this->createRateEntry('BOXMAN', $pol, $pod, $rate20, $rate40, [
                'ETD BKK' => $etdBkk,
                'ETD LCH' => $etdLch,
                'T/T' => !empty($transitTime) ? $transitTime : 'TBA',
                'T/S' => $ts,
                'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                'REMARK' => $remarks,
            ]);
        }

        return $rates;
    }

    /**
     * Parse SITC table format (from Azure OCR)
     */
    protected function parseSitcTable(array $lines, string $validity): array
    {
        $rates = [];
        $currentTable = 0;
        $table1Data = [];
        $table2Data = [];
        $lastPod = '';
        $lastServiceRoute = '';
        $lastFreeTime = 'TBA';

        // First pass: organize by table
        foreach ($lines as $line) {
            if (preg_match('/^TABLE (\d+)/', $line, $tableMatch)) {
                $currentTable = intval($tableMatch[1]);
                continue;
            }

            if (!preg_match('/^Row (\d+): (.+)$/', $line, $matches)) continue;

            $rowNum = intval($matches[1]);
            $cells = explode(' | ', $matches[2]);

            if ($currentTable == 1) {
                $table1Data[$rowNum] = $cells;
            } elseif ($currentTable == 2) {
                $table2Data[$rowNum] = $cells;
            } elseif ($currentTable >= 3) {
                $uniqueKey = 'T' . $currentTable . '_R' . $rowNum;
                $table1Data[$uniqueKey] = $cells;
            }
        }

        // Second pass: process table data
        foreach ($table1Data as $rowKey => $cells) {
            if (preg_match('/(POL|POD|Service Route|FREIGHT RATE)/i', $cells[0] ?? '')) continue;

            $pol = trim($cells[0] ?? '');
            if (empty($pol)) continue;

            $col1 = trim($cells[1] ?? '');
            $pod = '';
            $serviceRoute = '';
            $rate20 = '';
            $rate40 = '';
            $tt = 'TBA';
            $ts = 'TBA';
            $freeTime = 'TBA';

            // Detect row pattern and extract data
            if (count($cells) >= 7 && is_numeric(str_replace(',', '', $cells[3] ?? ''))) {
                $pod = $col1;
                $serviceRoute = trim($cells[2] ?? '');
                $rate20 = str_replace(',', '', trim($cells[3] ?? ''));
                $rate40 = str_replace(',', '', trim($cells[4] ?? ''));
                $lastPod = $pod;
                $lastServiceRoute = $serviceRoute;
            } elseif (is_numeric(str_replace(',', '', $col1))) {
                $pod = $lastPod;
                $serviceRoute = $lastServiceRoute;
                $rate20 = str_replace(',', '', $col1);
                $rate40 = str_replace(',', '', trim($cells[2] ?? ''));
            } else {
                $pod = $col1;
                $serviceRoute = trim($cells[2] ?? '');
                $rate20 = str_replace(',', '', trim($cells[3] ?? ''));
                $rate40 = str_replace(',', '', trim($cells[4] ?? ''));
                $lastPod = $pod;
                $lastServiceRoute = $serviceRoute;
            }

            if (empty($pod) || (empty($rate20) && empty($rate40))) continue;

            $rates[] = $this->createRateEntry('SITC', $pol, $pod, $rate20, $rate40, [
                'T/T' => $tt,
                'T/S' => $ts,
                'FREE TIME' => $freeTime,
                'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                'REMARK' => $serviceRoute,
            ]);
        }

        return $rates;
    }

    /**
     * Parse Wanhai/India table format
     */
    protected function parseWanhaiTable(array $lines, string $validity): array
    {
        $rates = [];

        foreach ($lines as $line) {
            if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) continue;

            $cells = explode(' | ', $matches[1]);
            if (count($cells) < 4) continue;
            if (preg_match('/(POD|LKA|LCB|RATE)/i', $cells[0])) continue;

            $pod = trim($cells[0] ?? '');
            $rate20 = trim($cells[3] ?? $cells[1] ?? '');
            $rate40 = trim($cells[4] ?? $cells[2] ?? '');

            $pod = preg_replace('/\s*\([^)]+\)/', '', $pod);
            $rate20 = preg_replace('/[^0-9]/', '', $rate20);
            $rate40 = preg_replace('/[^0-9]/', '', $rate40);

            if (!empty($pod) && (!empty($rate20) || !empty($rate40))) {
                $rates[] = $this->createRateEntry('WANHAI', 'BKK/LCH', $pod, $rate20, $rate40, [
                    'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                ]);
            }
        }

        return $rates;
    }

    /**
     * Parse TS LINE table format (from Azure OCR)
     * Structure: COUNTRY | POD | DIRECT/T/S | T/T | BKK 20GP | BKK 40GP | LCB 20GP | LCB 40GP | DLSS | REMARK
     */
    protected function parseTsLineTable(array $lines, string $validity): array
    {
        $rates = [];
        $currentCountry = '';

        // Extract validity from title if not provided
        if (empty($validity)) {
            foreach ($lines as $line) {
                // Pattern: "1 - 15 Nov. 25" or "1-15 Nov 25" or "OF 1 - 15 Nov. 25"
                if (preg_match('/(\d{1,2})\s*[-–]\s*(\d{1,2})\s*(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[.\s]*[\'`]?(\d{2,4})/i', $line, $matches)) {
                    $startDay = $matches[1];
                    $endDay = $matches[2];
                    $month = strtoupper(substr($matches[3], 0, 3));
                    $year = $matches[4];
                    if (strlen($year) == 2) {
                        $year = '20' . $year;
                    }
                    $validity = "{$startDay}-{$endDay} {$month} {$year}";
                    break;
                }
            }
            // Fallback to current month if not found
            if (empty($validity)) {
                $validity = strtoupper(date('M Y'));
            }
        }

        // First pass: merge continuation lines with their parent rows
        $mergedLines = [];
        $currentRowLine = '';
        foreach ($lines as $line) {
            if (preg_match('/^Row \d+:/', $line)) {
                // Save previous row if exists
                if (!empty($currentRowLine)) {
                    $mergedLines[] = $currentRowLine;
                }
                $currentRowLine = $line;
            } elseif (preg_match('/^:selected:/', $line) || preg_match('/^\s*\|/', $line)) {
                // This is a continuation line - merge with current row
                // Remove :selected: prefix and merge
                $continuation = preg_replace('/^:selected:\s*/', '', $line);
                $continuation = trim($continuation);
                if (!empty($continuation)) {
                    $currentRowLine .= ' | ' . $continuation;
                }
            } else {
                // Other lines (table headers, etc.)
                if (!empty($currentRowLine)) {
                    $mergedLines[] = $currentRowLine;
                    $currentRowLine = '';
                }
                $mergedLines[] = $line;
            }
        }
        if (!empty($currentRowLine)) {
            $mergedLines[] = $currentRowLine;
        }

        foreach ($mergedLines as $line) {
            if (!preg_match('/^Row (\d+): (.+)$/', $line, $matches)) continue;

            $rowNum = intval($matches[1]);
            $rowContent = $matches[2];

            // Clean up any remaining :selected: artifacts from OCR
            $rowContent = preg_replace('/:selected:\s*\|?/', '', $rowContent);
            $rowContent = trim($rowContent);
            // Clean up duplicate pipes from merging
            $rowContent = preg_replace('/\|\s*\|/', '|', $rowContent);

            $cells = explode(' | ', $rowContent);

            // Skip header rows and table headers
            if ($rowNum <= 2) continue;
            if (preg_match('/(COUNTRY|POD|DIRECT|T\/T|20\s*GP|BKK|LCB|OCEAN FREIGHT)/i', $cells[0] ?? '')) continue;

            // Handle row structure based on number of columns
            $country = '';
            $pod = '';
            $directTs = '';
            $tt = '';
            $bkkRate20 = '';
            $bkkRate40 = '';
            $lcbRate20 = '';
            $lcbRate40 = '';
            $remark = '';

            // Determine if first cell is country or POD
            $firstCell = trim($cells[0] ?? '');

            // Country names are typically all caps, single word or with space
            $isCountry = preg_match('/^(JAPAN|KOREA|TAIWAN|HONG KONG|CHINA|PHILIPPINES|VIETNAM|MIDDLE EAST|INDIA|Eest INDIA|EAST INDIA)$/i', $firstCell);

            // Handle empty first cell (continuation row with empty country column)
            $isEmptyFirstCell = empty($firstCell);

            if ($isCountry && count($cells) >= 5) {
                // Full row with country: COUNTRY | POD | DIRECT/T/S | T/T | BKK20 | BKK40 | LCB20 | LCB40 | ...
                // Note: Some rows may have fewer columns (e.g., BY CASE CHECK rows)
                $country = $firstCell;
                $pod = trim($cells[1] ?? '');
                $directTs = trim($cells[2] ?? '');
                $tt = trim($cells[3] ?? '');
                $bkkRate20 = trim($cells[4] ?? '');
                $bkkRate40 = trim($cells[5] ?? '');
                $lcbRate20 = trim($cells[6] ?? '');
                $lcbRate40 = trim($cells[7] ?? '');
                $remark = trim($cells[8] ?? '');
            } elseif ($isEmptyFirstCell && count($cells) >= 5) {
                // Continuation row with empty first cell: "" | POD | DIRECT/T/S | T/T | BKK20 | BKK40 | LCB20 | LCB40 | ...
                $pod = trim($cells[1] ?? '');
                $directTs = trim($cells[2] ?? '');
                $tt = trim($cells[3] ?? '');
                $bkkRate20 = trim($cells[4] ?? '');
                $bkkRate40 = trim($cells[5] ?? '');
                $lcbRate20 = trim($cells[6] ?? '');
                $lcbRate40 = trim($cells[7] ?? '');
                $remark = trim($cells[8] ?? '');
            } elseif (!$isCountry && !$isEmptyFirstCell && count($cells) >= 7) {
                // Continuation row without country: POD | DIRECT/T/S | T/T | BKK20 | BKK40 | LCB20 | LCB40 | ...
                $pod = $firstCell;
                $directTs = trim($cells[1] ?? '');
                $tt = trim($cells[2] ?? '');
                $bkkRate20 = trim($cells[3] ?? '');
                $bkkRate40 = trim($cells[4] ?? '');
                $lcbRate20 = trim($cells[5] ?? '');
                $lcbRate40 = trim($cells[6] ?? '');
                $remark = trim($cells[7] ?? '');
            } else {
                continue;
            }

            // Update current country if new one found
            if (!empty($country)) {
                $currentCountry = strtoupper($country);
            }

            // Skip invalid rows - but keep "BY CASE CHECK" entries
            if (empty($pod)) continue;

            // Check if rates are NIL (skip) or BY CASE CHECK (keep as special rate)
            $isBkkNil = preg_match('/^NIL$/i', $bkkRate20);
            $isLcbNil = preg_match('/^NIL$/i', $lcbRate20);
            $isBkkByCase = preg_match('/BY\s*CASE|CHECK/i', $bkkRate20);
            $isLcbByCase = preg_match('/BY\s*CASE|CHECK/i', $lcbRate20);

            // Skip if both are NIL
            if ($isBkkNil && $isLcbNil) continue;

            // Handle BY CASE CHECK rates - set to "CHECK" text
            if ($isBkkByCase) {
                $bkkRate20 = 'CHECK';
                $bkkRate40 = 'CHECK';
            } else {
                // Clean rates - extract numbers only
                $bkkRate20 = preg_replace('/[^0-9]/', '', $bkkRate20);
                $bkkRate40 = preg_replace('/[^0-9]/', '', $bkkRate40);
            }

            if ($isLcbByCase) {
                $lcbRate20 = 'CHECK';
                $lcbRate40 = 'CHECK';
            } else {
                $lcbRate20 = preg_replace('/[^0-9]/', '', $lcbRate20);
                $lcbRate40 = preg_replace('/[^0-9]/', '', $lcbRate40);
            }

            // Format T/T
            $ttFormatted = !empty($tt) ? $tt . ' Days' : 'TBA';

            // Format T/S
            $ts = 'TBA';
            if (stripos($directTs, 'DIRECT') !== false) {
                $ts = 'DIRECT';
            } elseif (preg_match('/T\/S\s*(via\s*)?(\w+)/i', $directTs, $tsMatch)) {
                $ts = 'T/S ' . strtoupper($tsMatch[2]);
            }

            // Build remark from country and any additional info
            $fullRemark = $currentCountry;
            if (!empty($remark) && !preg_match('/^(N\/A|RMB|USD)/i', $remark)) {
                $fullRemark = $fullRemark . '; ' . $remark;
            }

            // Create rate entry for BKK if has rates
            if (!empty($bkkRate20) || !empty($bkkRate40)) {
                $rates[] = $this->createRateEntry('TS LINE', 'BKK', $pod, $bkkRate20, $bkkRate40, [
                    'T/T' => $ttFormatted,
                    'T/S' => $ts,
                    'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                    'REMARK' => trim($fullRemark),
                ]);
            }

            // Create rate entry for LCB if has rates and different from BKK
            if ((!empty($lcbRate20) || !empty($lcbRate40)) &&
                ($lcbRate20 !== $bkkRate20 || $lcbRate40 !== $bkkRate40)) {
                $rates[] = $this->createRateEntry('TS LINE', 'LCB', $pod, $lcbRate20, $lcbRate40, [
                    'T/T' => $ttFormatted,
                    'T/S' => $ts,
                    'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                    'REMARK' => trim($fullRemark),
                ]);
            }
        }

        // Add additional "BY CASE CHECK" destinations that Azure OCR may have missed
        // These are parsed from raw content since table extraction often misses them
        $additionalDestinations = $this->extractTsLineAdditionalDestinations($validity);
        $existingPods = array_column($rates, 'POD');
        foreach ($additionalDestinations as $dest) {
            if (!in_array($dest['POD'], $existingPods)) {
                $rates[] = $dest;
            }
        }

        return $rates;
    }

    /**
     * Extract additional TS LINE destinations that Azure OCR may miss from table extraction
     * These are typically BY CASE CHECK destinations at the bottom of the rate card
     */
    protected function extractTsLineAdditionalDestinations(string $validity): array
    {
        $rates = [];

        // Known destinations with BY CASE CHECK rates
        // Format: POD => [Country, T/T, T/S]
        $byCheckDestinations = [
            'CHENNAI' => ['EAST INDIA', '22-25', 'T/S SKU'],
            'NAVASHEVA' => ['WEST INDIA & PAKISTAN', '25-27', 'T/S SKU'],
            'MUNDRA' => ['WEST INDIA & PAKISTAN', '25-27', 'T/S SKU'],
            'KARACHI' => ['WEST INDIA & PAKISTAN', '27-29', 'T/S SKU'],
            'SYDNEY' => ['AU', '25-27', 'T/S SKU'],
            'MELBOUNE' => ['AU', '25-27', 'T/S SKU'],
            'BRISBANE' => ['AU', '25-27', 'T/S SKU'],
            'DAR ES SALAM' => ['AFRICA', '29-31', 'T/S SKU'],
            'MOMNASA' => ['AFRICA', '29-31', 'T/S SKU'],
            'LONG BEACH /LA' => ['USWC', '27-30', 'T/S SHA'],
            'Manzanillo, Mexico' => ['USWC', '35-42', 'T/S SHA'],
        ];

        // Find the most recent TS LINE JSON file to check for these destinations
        $azureResultsDir = base_path('temp_attachments/azure_ocr_results/');
        $jsonFiles = glob($azureResultsDir . '*Rate*1st*half*_azure_result.json');

        if (empty($jsonFiles)) {
            // No JSON file found, return all known destinations as they're standard for TS LINE
            foreach ($byCheckDestinations as $pod => $info) {
                [$country, $tt, $ts] = $info;
                $rates[] = $this->createRateEntry('TS LINE', 'BKK', $pod, 'CHECK', 'CHECK', [
                    'T/T' => $tt . ' Days',
                    'T/S' => $ts,
                    'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                    'REMARK' => $country,
                ]);
            }
            return $rates;
        }

        // Use the most recent file
        $jsonFile = end($jsonFiles);
        $data = json_decode(file_get_contents($jsonFile), true);
        $content = $data['analyzeResult']['content'] ?? '';

        foreach ($byCheckDestinations as $pod => $info) {
            // Check if POD exists in content (case-insensitive)
            if (stripos($content, $pod) !== false) {
                [$country, $tt, $ts] = $info;
                $rates[] = $this->createRateEntry('TS LINE', 'BKK', $pod, 'CHECK', 'CHECK', [
                    'T/T' => $tt . ' Days',
                    'T/S' => $ts,
                    'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                    'REMARK' => $country,
                ]);
            }
        }

        return $rates;
    }

    /**
     * Parse generic table format
     */
    protected function parseGenericTable(array $lines, string $pattern, string $validity): array
    {
        $rates = [];
        $carrier = strtoupper(str_replace('_', ' ', $pattern));

        foreach ($lines as $line) {
            if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) continue;

            $cells = explode(' | ', $matches[1]);
            if (count($cells) < 3) continue;

            // Try to extract POD and rates
            $pod = '';
            $rate20 = '';
            $rate40 = '';

            foreach ($cells as $index => $cell) {
                $cell = trim($cell);

                if ($index <= 2 && preg_match('/^[A-Z][a-z]+/', $cell) && strlen($cell) > 2 && empty($pod)) {
                    $pod = $cell;
                }

                if (preg_match('/\$?\s*(\d+[,\d]*)/i', $cell, $matches) && empty($rate20)) {
                    $rate20 = str_replace(',', '', $matches[1]);
                } elseif (preg_match('/\$?\s*(\d+[,\d]*)/i', $cell, $matches) && !empty($rate20) && empty($rate40)) {
                    $rate40 = str_replace(',', '', $matches[1]);
                }
            }

            if (!empty($pod) && (!empty($rate20) || !empty($rate40))) {
                $rates[] = $this->createRateEntry($carrier, 'BKK/LCH', $pod, $rate20, $rate40, [
                    'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                ]);
            }
        }

        return $rates;
    }

    /**
     * Create standardized rate entry
     */
    protected function createRateEntry(string $carrier, string $pol, string $pod, string $rate20, string $rate40, array $extra = []): array
    {
        return array_merge([
            'CARRIER' => $carrier,
            'POL' => $pol,
            'POD' => $pod,
            'CUR' => 'USD',
            "20'" => $rate20,
            "40'" => $rate40,
            '40 HQ' => $rate40,
            '20 TC' => '',
            '20 RF' => '',
            '40RF' => '',
            'ETD BKK' => '',
            'ETD LCH' => '',
            'T/T' => 'TBA',
            'T/S' => 'TBA',
            'FREE TIME' => 'TBA',
            'VALIDITY' => strtoupper(date('M Y')),
            'REMARK' => '',
            'Export' => '',
            'Who use?' => '',
            'Rate Adjust' => '',
            '1.1' => '',
        ], $extra);
    }

    /**
     * Build merged cell value map
     */
    protected function buildMergedCellMap($worksheet): array
    {
        $mergedCellValues = [];

        foreach ($worksheet->getMergeCells() as $mergeRange) {
            $startCell = explode(':', $mergeRange)[0];
            $cellValue = $worksheet->getCell($startCell)->getCalculatedValue();

            [$startCol, $startRow] = sscanf($startCell, '%[A-Z]%d');
            [$endCell] = array_slice(explode(':', $mergeRange), -1);
            [$endCol, $endRow] = sscanf($endCell, '%[A-Z]%d');

            for ($r = $startRow; $r <= $endRow; $r++) {
                $mergedCellValues[$startCol . $r] = $cellValue;
            }
        }

        return $mergedCellValues;
    }

    /**
     * Check if row has black highlighting
     */
    protected function isBlackHighlightedRow($worksheet, string $cellAddress): bool
    {
        $cellStyle = $worksheet->getCell($cellAddress)->getStyle();
        $fill = $cellStyle->getFill();
        $fillType = $fill->getFillType();

        if ($fillType !== Fill::FILL_NONE) {
            $fillColor = strtoupper($fill->getStartColor()->getRGB());
            return in_array($fillColor, ['000000', '333333']);
        }

        return false;
    }

    /**
     * Process ETD dates from column
     */
    protected function processEtdDates(string $etdColumnF, string $pol, string $remarkColumnL): array
    {
        $etdBkk = '';
        $etdLch = '';
        $remark = $remarkColumnL;

        if (empty($etdColumnF)) {
            return [$etdBkk, $etdLch, $remark];
        }

        if (stripos($etdColumnF, 'SSW') !== false) {
            $remark = !empty($remark) ? $remark . ' / SSW' : 'SSW';
        }

        $dates = preg_split('/[\n\r\/,]+/', $etdColumnF);
        $dates = array_map('trim', array_filter($dates));

        if (count($dates) >= 2) {
            $bkkDates = [];
            $lchDates = [];

            foreach ($dates as $date) {
                $hasBkk = preg_match('/(PAT|BKK)/i', $date);
                $hasLch = preg_match('/LCH/i', $date);
                $hasSSW = preg_match('/SSW/i', $date);

                if ($hasSSW && !$hasBkk && !$hasLch) continue;

                if (preg_match('/^([A-Z]{3})/i', $date, $matches)) {
                    $dayName = $matches[1];
                    if ($hasBkk && $hasLch) {
                        $bkkDates[] = $dayName;
                        $lchDates[] = $dayName;
                    } elseif ($hasBkk) {
                        $bkkDates[] = $dayName;
                    } elseif ($hasLch) {
                        $lchDates[] = $dayName;
                    } else {
                        $lchDates[] = $dayName;
                    }
                }
            }

            $etdBkk = implode('/', $bkkDates);
            $etdLch = implode('/', $lchDates);
        } elseif (count($dates) === 1) {
            $singleDate = $dates[0];
            $hasLch = preg_match('/LCH/i', $singleDate);
            $hasBkk = preg_match('/(PAT|BKK)/i', $singleDate);

            if (preg_match('/^([A-Z]{3})/i', $singleDate, $matches)) {
                $dayName = $matches[1];
                if ($hasLch && $hasBkk) {
                    $etdBkk = $dayName;
                    $etdLch = $dayName;
                } elseif ($hasLch) {
                    $etdLch = $dayName;
                } elseif ($hasBkk) {
                    $etdBkk = $dayName;
                } else {
                    $etdLch = $singleDate;
                }
            }
        }

        return [$etdBkk, $etdLch, $remark];
    }

    /**
     * Map POL to ETD columns for Heung A
     */
    protected function mapPolToEtd(string $pol, string $sailingDate): array
    {
        $etdBkk = '';
        $etdLch = '';

        if (empty($sailingDate)) return [$etdBkk, $etdLch];

        if (stripos($pol, 'BKK') !== false && stripos($pol, 'LCH') !== false) {
            $etdBkk = $sailingDate;
            $etdLch = $sailingDate;
        } elseif (stripos($pol, 'BKK') !== false) {
            $etdBkk = $sailingDate;
        } elseif (stripos($pol, 'LCH') !== false || stripos($pol, 'Latkabang') !== false) {
            $etdLch = $sailingDate;
        } else {
            $etdBkk = $sailingDate;
            $etdLch = $sailingDate;
        }

        return [$etdBkk, $etdLch];
    }

    /**
     * Map POL to ETD columns for Boxman
     */
    protected function mapPolToEtdBoxman(string $pol, string $etd): array
    {
        $etdBkk = '';
        $etdLch = '';

        if (empty($pol) || empty($etd)) return [$etdBkk, $etdLch];

        $polUpper = strtoupper($pol);

        if (strpos($polUpper, 'LKB') !== false && strpos($polUpper, 'LCH') !== false) {
            $etdBkk = $etd;
            $etdLch = $etd;
        } elseif (strpos($polUpper, 'LKB') !== false || strpos($polUpper, 'LCH') !== false || strpos($polUpper, 'LKE') !== false) {
            $etdLch = $etd;
        } elseif (strpos($polUpper, 'BKK') !== false) {
            $etdBkk = $etd;
        } else {
            $etdLch = $etd;
        }

        return [$etdBkk, $etdLch];
    }

    /**
     * Format validity date
     */
    protected function formatValidity(string $validityRaw): string
    {
        if (empty($validityRaw)) {
            return strtoupper(date('M Y'));
        }

        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        if (strpos($validityRaw, '-') !== false) {
            $parts = explode('-', $validityRaw);
            $endDate = trim($parts[1]);
            $dateParts = explode('/', $endDate);

            if (count($dateParts) >= 2) {
                $day = intval($dateParts[0]);
                $month = intval($dateParts[1]);
                $year = isset($dateParts[2]) ? intval($dateParts[2]) : date('Y');

                if ($month >= 1 && $month <= 12) {
                    return sprintf('%02d %s %d', $day, $months[$month - 1], $year);
                }
            }
        }

        return $validityRaw;
    }

    /**
     * Extract validity from filename
     * Pattern: "GUIDE RATE FOR 1-30 NOV 2025_SINOKOR" or "GUIDELINE RATE FOR 1-30 NOV 2025"
     * Also handles underscores: "1764088043_GUIDE_RATE_FOR_1-30_NOV_2025_SINOKOR"
     */
    protected function extractValidityFromFilename(string $filename): string
    {
        // Match patterns like "1-30 NOV 2025", "1-31_DEC_2025", etc. (spaces or underscores)
        if (preg_match('/(\d{1,2})[-_\s]*(\d{1,2})[-_\s]*(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)[A-Z]*[-_\s]*(\d{4})/i', $filename, $matches)) {
            $startDay = $matches[1];
            $endDay = $matches[2];
            $month = strtoupper($matches[3]);
            $year = $matches[4];

            return strtoupper("{$startDay}-{$endDay} {$month} {$year}");
        }

        // Return empty to allow fallback to other methods
        return '';
    }

    /**
     * Extract validity from Azure JSON
     */
    protected function extractValidityFromJson(string $jsonFile): string
    {
        if (!file_exists($jsonFile)) {
            return strtoupper(date('M Y'));
        }

        $data = json_decode(file_get_contents($jsonFile), true);

        if (!$data || !isset($data['analyzeResult']['content'])) {
            return strtoupper(date('M Y'));
        }

        $content = $data['analyzeResult']['content'];

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

        // Pattern 3: "OF 1 - 15 Nov. 25" or "1-15 Nov 25" (TS LINE format)
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

        return strtoupper(date('M Y'));
    }

    /**
     * Detect header row in Excel
     */
    protected function detectHeaderRow($worksheet, int $maxRow): int
    {
        $keywords = ['pol', 'pod', 'origin', 'destination', 'rate', 'carrier', '20', '40', 'port'];

        for ($row = 1; $row <= min($maxRow, 20); $row++) {
            $rowText = '';
            foreach (range('A', 'J') as $col) {
                $rowText .= ' ' . strtolower($worksheet->getCell($col . $row)->getValue() ?? '');
            }

            $matchCount = 0;
            foreach ($keywords as $keyword) {
                if (stripos($rowText, $keyword) !== false) {
                    $matchCount++;
                }
            }

            if ($matchCount >= 3) {
                return $row;
            }
        }

        return 1;
    }

    /**
     * Detect column mapping from header row
     */
    protected function detectColumnMapping($worksheet, int $headerRow, string $highestCol): array
    {
        $mapping = [
            'carrier' => null,
            'pol' => null,
            'pod' => null,
            'rate20' => null,
            'rate40' => null,
            'tt' => null,
            'ts' => null,
            'freetime' => null,
            'remark' => null,
        ];

        $colPatterns = [
            'carrier' => '/carrier|line|shipping/i',
            'pol' => '/pol|origin|loading|from/i',
            'pod' => '/pod|destination|discharge|to|port/i',
            'rate20' => '/20[\'"]?|20.*gp|20.*dc/i',
            'rate40' => '/40[\'"]?|40.*hc|40.*hq/i',
            'tt' => '/t\/t|transit|days/i',
            'ts' => '/t\/s|trans.*ship/i',
            'freetime' => '/free|dem|det/i',
            'remark' => '/remark|note|comment/i',
        ];

        $colIndex = ord('A');
        while (chr($colIndex) <= $highestCol) {
            $col = chr($colIndex);
            $headerValue = strtolower($worksheet->getCell($col . $headerRow)->getValue() ?? '');

            foreach ($colPatterns as $field => $pattern) {
                if ($mapping[$field] === null && preg_match($pattern, $headerValue)) {
                    $mapping[$field] = $col;
                }
            }

            $colIndex++;
        }

        return $mapping;
    }
}
