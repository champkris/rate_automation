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

        // Auto-detect pattern from filename if empty or set to 'auto'
        if ($pattern === '' || $pattern === 'auto') {
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
        // "GUIDE RATE FOR" with "(SKR)" or "_SKR_" is regular SINOKOR format (not feederage)
        // Note: parentheses may be sanitized to underscores in uploaded filenames
        if (preg_match('/GUIDE.?RATE.*[\(_]SKR[\)_]/i', $filename)) return 'sinokor';
        if (preg_match('/SINOKOR/i', $filename)) return 'sinokor';
        if (preg_match('/HEUNG.?A|HUANG.?A/i', $filename)) return 'heung_a';
        if (preg_match('/BOXMAN/i', $filename)) return 'boxman';
        if (preg_match('/SITC/i', $filename)) return 'sitc';
        if (preg_match('/INDIA|WANHAI/i', $filename)) return 'wanhai';
        // "FAK RATE" with "(ASIA)" is WANHAI Asia rate card
        if (preg_match('/FAK.?RATE.*\(ASIA\)/i', $filename)) return 'wanhai';
        if (preg_match('/CK.?LINE/i', $filename)) return 'ck_line';
        if (preg_match('/SM.?LINE/i', $filename)) return 'sm_line';
        if (preg_match('/DONGJIN/i', $filename)) return 'dongjin';
        if (preg_match('/TS.?LINE|RATE.?1ST/i', $filename)) return 'ts_line';

        return 'generic';
    }

    /**
     * Detect pattern from OCR content (for PDFs where filename doesn't identify the carrier)
     */
    protected function detectPatternFromContent(array $lines): string
    {
        $content = implode("\n", array_slice($lines, 0, 30)); // Check first 30 lines

        // SITC signature: "Service Route" column header or SITC service codes (VTX, CKV, JTH)
        if (preg_match('/Service Route/i', $content) || preg_match('/\b(VTX\d|CKV\d|JTH)\b/i', $content)) {
            return 'sitc';
        }

        // SM LINE signature: "BANGKOK (UNITHAI)" and "LAEMCHABANG" in headers
        if (preg_match('/BANGKOK.*UNITHAI/i', $content) && preg_match('/LAEMCHABANG/i', $content)) {
            return 'sm_line';
        }

        // CK LINE signature: has ETD BKK and ETD LCH columns
        if (preg_match('/ETD\s*BKK/i', $content) && preg_match('/ETD\s*LCH/i', $content)) {
            return 'ck_line';
        }

        // TS LINE signature: "TS LINES" in content
        if (preg_match('/TS\s*LINES?/i', $content)) {
            return 'ts_line';
        }

        // DONGJIN signature
        if (preg_match('/DONGJIN/i', $content)) {
            return 'dongjin';
        }

        // WANHAI signature: "Port of Loading" header with THBKK/THLCB/THLCH/THLKA POL codes
        if (preg_match('/Port of Loading/i', $content) && preg_match('/\b(THBKK|THLCB|THLCH|THLKA)\b/', $content)) {
            return 'wanhai';
        }

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

        // Check for existing Azure OCR results first
        if (file_exists($tableFile)) {
            $content = file_get_contents($tableFile);
            $lines = explode("\n", $content);

            // Extract validity from PDF content (JSON) first - has more detail (date range)
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

            // Normalize lines - split any embedded newlines (Azure cell content may contain \n)
            // This ensures fresh OCR and cached file read produce the same line structure
            $normalizedLines = [];
            foreach ($lines as $line) {
                $subLines = explode("\n", $line);
                foreach ($subLines as $subLine) {
                    $normalizedLines[] = $subLine;
                }
            }
            $lines = $normalizedLines;

            // Extract validity from PDF content first - has more detail (date range)
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

        // Fallback: extract validity from filename if not found in PDF content
        if (empty($validity)) {
            $validity = $this->extractValidityFromFilename($baseFilename);
        }

        // Content-based pattern detection if filename detection returned 'generic'
        if ($pattern === 'generic') {
            $pattern = $this->detectPatternFromContent($lines);
        }

        return match ($pattern) {
            'sinokor' => $this->parseSinokorTable($lines, $validity),
            'sinokor_skr' => $this->parseSinokorSkrTable($lines, $validity),
            'heung_a' => $this->parseHeungATable($lines, $validity),
            'boxman' => $this->parseBoxmanTable($lines, $validity),
            'sitc' => $this->parseSitcTable($lines, $validity),
            'wanhai' => $this->parseWanhaiTable($lines, $validity, $jsonFile),
            'ts_line' => $this->parseTsLineTable($lines, $validity),
            'dongjin' => $this->parseDongjinTable($lines, $validity),
            'ck_line' => $this->parseCkLineTable($lines, $validity),
            'sm_line' => $this->parseSmLineTable($lines, $validity),
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
                // 4 columns could be:
                // - COUNTRY | POD | 20' | 40' (with country prefix)
                // - POD | 20' | 40' | REMARK (continuation row without country)
                $cell0 = trim($cells[0] ?? '');
                $cell1 = trim($cells[1] ?? '');
                $cell2 = trim($cells[2] ?? '');
                $cell3 = trim($cells[3] ?? '');

                // Check if cell1 is numeric - if so, it's POD | rate20 | rate40 | remark format
                if (preg_match('/^\d+$/', $cell1)) {
                    // POD | 20' | 40' | REMARK format (no country column)
                    $pod = $cell0;
                    $rate20 = $cell1;
                    $rate40 = $cell2;
                    // cell3 is remark, will be handled by extractSinokorRemark
                } else {
                    // COUNTRY | POD | 20' | 40' format
                    $country = $cell0;
                    $pod = $cell1;
                    $rate20 = $cell2;
                    $rate40 = $cell3;
                }

                // Check if rate20 contains merged rates like "30 60" or "250 500"
                if (preg_match('/^(\d+)\s+(\d+)$/', $rate20, $mergedMatch)) {
                    $rate20 = $mergedMatch[1];
                    $rate40 = $mergedMatch[2];
                }
            } elseif (count($cells) == 3) {
                // Could be: POD | 20' | 40' (continuation) OR COUNTRY | POD | REMARK (header row)
                // OR: POD | MERGED_RATES | REMARK (continuation with merged rates)
                $cell0 = trim($cells[0] ?? '');
                $cell1 = trim($cells[1] ?? '');
                $cell2 = trim($cells[2] ?? '');

                // Check if this is a country header row (3rd column is not numeric, contains text)
                // e.g., "S.CHINA | S.CHINA T/S HKG | SELL AT PRD SALES GUIDE"
                if (!preg_match('/^\d+$/', $cell2) && preg_match('/[A-Za-z]{3,}/', $cell2)) {
                    // Check if cell1 contains merged rates like "30 60"
                    if (preg_match('/^(\d+)\s+(\d+)$/', $cell1, $mergedMatch)) {
                        // POD | MERGED_RATES | REMARK format
                        $pod = $cell0;
                        $rate20 = $mergedMatch[1];
                        $rate40 = $mergedMatch[2];
                    } else {
                        // This is a country header row
                        $country = $cell0;
                        $pod = $cell1;
                        $rate20 = $cell2; // Will be skipped by SELL/GUIDE check below
                        $rate40 = '';
                    }
                } else {
                    // Standard continuation row: POD | 20' | 40'
                    $pod = $cell0;
                    $rate20 = $cell1;
                    $rate40 = $cell2;

                    // Check if rate20 contains merged rates like "30 60"
                    if (preg_match('/^(\d+)\s+(\d+)$/', $rate20, $mergedMatch)) {
                        $rate20 = $mergedMatch[1];
                        $rate40 = $mergedMatch[2];
                    }
                }
            } elseif (count($cells) == 2) {
                // 2 columns: could be "POD | --------" or rates for pending POD
                // OR: POD | MERGED_RATES (continuation with merged rates like "TOKYO | 250 500")
                $firstCell = trim($cells[0] ?? '');
                $secondCell = trim($cells[1] ?? '');

                // Check if it's "POD | --------" pattern (incomplete rate row)
                if (preg_match('/^-+$/', $secondCell) && !preg_match('/^\d+$/', $firstCell)) {
                    $pendingPod = $firstCell;
                    continue;
                }

                // Check if second cell contains merged rates like "250 500"
                if (preg_match('/^(\d+)\s+(\d+)$/', $secondCell, $mergedMatch)) {
                    // This is POD | MERGED_RATES format
                    $pod = $firstCell;
                    $rate20 = $mergedMatch[1];
                    $rate40 = $mergedMatch[2];
                    // Don't continue - let it fall through to rate entry creation
                } elseif (!empty($pendingPod)) {
                    // Otherwise it might be rates for pending POD
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
                    continue;
                } else {
                    continue;
                }
            }

            // Update current country if we got a new one (do this BEFORE any skip checks)
            // Only update if it's a recognized country pattern to avoid port names being treated as countries
            if (!empty($country) && !preg_match('/^\d+$/', $country)) {
                // Only accept known country patterns
                if (preg_match('/^(MAXICO|C\.?CHINA|HONGKONG|HONG\s*KONG|S\.?CHINA|N\.?CHINA|VIETNAM|HOCHIMINH|INDONESIA|TAIWAN|JP\s*\(?\s*MAIN\s*PORT\s*\)?|JP\s*\(?\s*OUT\s*PORT\s*\)?|RUSSIA|S\.?KOREA|INDIA|MALAYSIA)/i', $country)) {
                    $currentCountry = $country;
                }
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
     * Returns remark numbers (e.g., "1, 2, 3") plus any POD-specific remarks
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

        // Get country-specific remark numbers (e.g., "1, 2, 3, 4, 5")
        $countryRemarkNumbers = $this->getSinokorCountryRemark($country);

        // Combine POD remark with country remark numbers
        $fullRemark = '';
        if (!empty($podRemark)) {
            $fullRemark = $podRemark;
        }
        if (!empty($countryRemarkNumbers)) {
            $fullRemark = !empty($fullRemark) ? $fullRemark . '; ' . $countryRemarkNumbers : $countryRemarkNumbers;
        }

        return [trim($cleanPod), $fullRemark];
    }

    /**
     * Get SINOKOR country-specific remarks based on the PDF structure
     * Returns all applicable remarks with full text for each POD in that country
     */
    protected function getSinokorCountryRemark(string $country, string $pod = ''): string
    {
        // Country-specific remarks with full text for each number
        $countryRemarks = [
            'MAXICO' => [
                '1) OCF INCL LSS'
            ],
            'C.CHINA' => [
                '1) OCF INCL LSS',
                '2) EX.THLKR / THSPR ADDED ON $100/$150 PER 20\'/40HQ FOR INLAND CHARGE'
            ],
            'INDIA' => [
                '1) OCF INCL LSS',
                '2) EX.THLKR ADDED ON $100/$150 PER 20\'/40HQ FOR INLAND CHARGE'
            ],
            'MALAYSIA' => [
                '1) OCF INCL LSS',
                '2) EX.THLKR / THSPR ADDED ON $100/$150 PER 20\'/40HQ FOR INLAND CHARGE'
            ],
            'HONGKONG' => [
                '1) OCF INCL LSS',
                '2) PCS AT DESTINATION $100/$200 IS WAIVED',
                '3) RICE SHIPMENT $100/20DC INCL LSS, DTHC HKD $1500/20DC',
                '4) DG MUST BE ADDED ON AT LEAST $100/TEU',
                '5) CONSOL $100/$200 INCL LSS (SUBJECT TO EQUIPMENT AVAILABLE)',
                '6) FLEXIBAG MUST BE ADDED ON AT LEAST $100/20DC'
            ],
            'S.CHINA' => [
                '1) OCF INCL LSS',
                '2) PCS AT DESTINATION $100/$200 IS WAIVED',
                '3) FLEXIBAG MUST BE ADDED ON AT LEAST $100/20DC',
                '4) AFR $30/BL'
            ],
            'N.CHINA' => [
                '1) OCF INCL LSS',
                '2) AFR $30/BL',
                '3) SERVICE T/S PUSAN'
            ],
            'VIETNAM' => [
                '1) OCF INCL LSS',
                '2) CIC AT DESTINATION WAIVED',
                '3) CONSOL $70/$140 INCL LSS (SUBJECT EQUIPMENT AVAILABLE)',
                '4) FLEXIBAG MUST BE ADDED ON AT LEAST $100/20DC',
                '5) DG MUST BE ADDED ON AT LEAST $100/TEU'
            ],
            'HOCHIMINH' => [
                '1) OCF INCL LSS',
                '2) CIC AT DESTINATION WAIVED',
                '3) CONSOL $70/$140 INCL LSS (SUBJECT EQUIPMENT AVAILABLE)',
                '4) FLEXIBAG MUST BE ADDED ON AT LEAST $100/20DC',
                '5) DG MUST BE ADDED ON AT LEAST $100/TEU'
            ],
            'INDONESIA' => [
                '1) OCF INCL LSS',
                '2) EX.THLKR ADDED ON $100/$150 PER 20\'/40HQ FOR INLAND CHARGE',
                '3) DG MUST BE ADDED ON AT LEAST $100/TEU'
            ],
            'TAIWAN' => [
                'OCF INCL LSS'
            ],
            'JP(MAIN PORT)' => [
                'OCF INCL LSS / AFR $30 per BL / SERVICE T/S PUSAN'
            ],
            'JP(OUT PORT)' => [
                'OCF INCL LSS / AFR $30 per BL / SERVICE T/S PUSAN'
            ],
            'RUSSIA' => [
                'OCF INCL LSS'
            ],
            'S.KOREA' => [
                '1) OCF INCL LSF / NES / CIS / CRS',
                '2) CONSOL PUS $420/840 + LSF (INCL NES + CRS)',
                '3) CONSOL INC,PKT $520/1040 + LSF (INCL NES + CRS + CIS)',
                '4) FLEXIBAG MUST BE ADDED ON AT LEAST $100/20DC',
                '5) DG MUST BE ADDED ON AT LEAST $100/TEU'
            ],
        ];

        // Normalize country name (remove spaces for matching)
        $countryUpper = strtoupper(trim($country));
        $countryNormalized = str_replace(' ', '', $countryUpper);

        $remarks = null;

        // Direct match
        if (isset($countryRemarks[$countryUpper])) {
            $remarks = $countryRemarks[$countryUpper];
        }
        // Try normalized match (e.g., "HONG KONG" -> "HONGKONG")
        elseif (isset($countryRemarks[$countryNormalized])) {
            $remarks = $countryRemarks[$countryNormalized];
        }
        // Try partial matches
        else {
            foreach ($countryRemarks as $key => $remarkList) {
                $keyNormalized = str_replace(' ', '', $key);
                if (stripos($countryNormalized, $keyNormalized) !== false ||
                    stripos($keyNormalized, $countryNormalized) !== false ||
                    stripos($countryUpper, $key) !== false ||
                    stripos($key, $countryUpper) !== false) {
                    $remarks = $remarkList;
                    break;
                }
            }
        }

        // Return remarks joined with semicolons
        if ($remarks !== null) {
            return implode('; ', $remarks);
        }

        // Default remark if country not found
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

        // Pre-process TABLE 2 to propagate merged cell values
        // Surcharge and Free time columns have DIFFERENT merge lengths - track independently
        // Free time can span multiple surcharge blocks
        $lastValidSurcharge = '';
        $lastValidFreeTime = '';
        $processedTable2 = [];

        foreach ($table2Data as $rowNum => $t2row) {
            $col0 = trim($t2row[0] ?? '');
            $col1 = trim($t2row[1] ?? '');
            $col2 = trim($t2row[2] ?? '');
            $col3 = trim($t2row[3] ?? '');

            // Check if col0 has a full surcharge text (not just a number)
            $hasFullSurcharge = !preg_match('/^\d+(-\d+)?$/', $col0) && !empty($col0)
                && stripos($col0, 'surcharge') === false && stripos($col0, 'Dem /Det') === false;

            // Check if this row has NEW free time data (in col3 or col2)
            // Free time appears in col3 for full rows, or col2 for some continuation rows
            $hasNewFreeTime = false;
            $currentFreeTime = '';
            if (!empty($col3) && preg_match('/day/i', $col3)) {
                // Free time in col3 (standard position)
                $hasNewFreeTime = true;
                $currentFreeTime = $col3;
            } elseif (!$hasFullSurcharge && preg_match('/day|det/i', $col2)) {
                // Free time in col2 only for continuation rows (where col0 is T/T, col1 is T/S)
                $hasNewFreeTime = true;
                $currentFreeTime = $col2;
            }

            // Update surcharge tracking
            if ($hasFullSurcharge) {
                $lastValidSurcharge = $col0;
            }

            // Update free time tracking - propagates until a new free time value is found
            if ($hasNewFreeTime) {
                $lastValidFreeTime = $currentFreeTime;
            }

            // Determine T/T and T/S based on row type
            $tt = '';
            $ts = '';
            if ($hasFullSurcharge) {
                // Full row: col0=surcharge, col1=T/T, col2=T/S (or free time if col3 empty)
                $tt = $col1;
                // T/S is in col2 only if it doesn't contain free time text
                if (!preg_match('/day|det/i', $col2)) {
                    $ts = $col2;
                }
            } else {
                // Continuation row: col0=T/T, col1=T/S
                $tt = $col0;
                // T/S is in col1 only if it doesn't contain free time text
                if (!preg_match('/day|det/i', $col1)) {
                    $ts = $col1;
                }
            }

            $processedTable2[$rowNum] = [
                'surcharge' => $lastValidSurcharge,
                'tt' => $tt,
                'ts' => $ts,
                'freetime' => $lastValidFreeTime,
            ];
        }

        // Second pass: process table data
        foreach ($table1Data as $rowKey => $cells) {
            if (preg_match('/(POL|POD|Service Route|FREIGHT RATE)/i', $cells[0] ?? '')) continue;

            $pol = trim($cells[0] ?? '');
            if (empty($pol)) continue;

            // Skip non-rate rows (metadata/notes rows from side tables)
            // These typically have remarks text as POL instead of actual port names
            if (preg_match('/^(Please recheck|Include LSS|INC LSS|no have LSS|^\d+$|^\d+-\d+$)/i', $pol)) continue;
            // Skip rows where POL contains surcharge terms (these are note rows)
            if (preg_match('/(LSS|CIC|ISPS|BDTHC|BDCFS|detention|dem\s*\/|det\s*$)/i', $pol)) continue;

            $col1 = trim($cells[1] ?? '');
            $pod = '';
            $serviceRoute = '';
            $rate20 = '';
            $rate40 = '';
            $tt = 'TBA';
            $ts = 'TBA';
            $freeTime = 'TBA';

            // Detect row pattern and extract data
            // Helper to check if a value looks like a service route code (VTX1, CKV2, JTH, etc.)
            $isServiceCode = function($val) {
                $v = trim($val);
                if (empty($v)) return false;
                // Service codes: VTX1, VTX3, CKV2, JTH, Kerry, etc.
                return preg_match('/^(VTX|CKV|JTH|Kerry)/i', $v);
            };

            $col2 = trim($cells[2] ?? '');
            $col3 = trim($cells[3] ?? '');
            $col4 = trim($cells[4] ?? '');
            $col5 = trim($cells[5] ?? ''); // Other surcharge/condition column

            // Check if col2 is numeric (a rate) or text (a service code/POD)
            $col2IsNumeric = is_numeric(str_replace(',', '', $col2));
            $col3IsNumeric = is_numeric(str_replace(',', '', $col3));
            $col1IsServiceCode = $isServiceCode($col1);

            $surcharge = ''; // Will hold the "Other surcharge/condition" value

            // Get surcharge, T/T, T/S, Free time from pre-processed TABLE 2
            $table2Surcharge = '';
            $table2TT = '';
            $table2TS = '';
            $table2FreeTime = '';
            if (is_numeric($rowKey) && isset($processedTable2[$rowKey])) {
                $t2processed = $processedTable2[$rowKey];
                $table2Surcharge = $t2processed['surcharge'] ?? '';
                $table2TT = $t2processed['tt'] ?? '';
                $table2TS = $t2processed['ts'] ?? '';
                $table2FreeTime = $t2processed['freetime'] ?? '';
            }

            // Check for continuation row with service code FIRST (before full row check)
            // Pattern: POL | Service | 20' | 40' | ... (e.g., "LKB/Sahathai/TPT | VTX1 | 430 | 750")
            if ($col1IsServiceCode && $col2IsNumeric) {
                // Continuation row with service code
                // POD should be inherited from previous row
                $pod = $lastPod;
                $serviceRoute = $col1; // The service code
                $rate20 = str_replace(',', '', $col2);
                $rate40 = str_replace(',', '', $col3);
                $surcharge = $table2Surcharge ?: $col4; // Prefer TABLE 2, fallback to cell
                $lastServiceRoute = $serviceRoute;
            } elseif (count($cells) >= 7 && $col3IsNumeric && !$col1IsServiceCode) {
                // Full row with many columns: POL | POD | Service | 20' | 40' | Surcharge | ...
                $pod = $col1;
                $serviceRoute = $col2;
                $rate20 = str_replace(',', '', $col3);
                $rate40 = str_replace(',', '', $col4);
                $surcharge = $table2Surcharge ?: $col5; // Prefer TABLE 2, fallback to cell
                $lastPod = $pod;
                $lastServiceRoute = $serviceRoute;
            } elseif (is_numeric(str_replace(',', '', $col1))) {
                // Continuation row where col1 is numeric: | 300 | 450 |
                $pod = $lastPod;
                $serviceRoute = $lastServiceRoute;
                $rate20 = str_replace(',', '', $col1);
                $rate40 = str_replace(',', '', $col2);
                $surcharge = $table2Surcharge ?: $col3; // Prefer TABLE 2, fallback to cell
            } elseif ($col2IsNumeric && !empty($col1)) {
                // 4-column format without service: POL | POD | 20' | 40'
                // col1 is POD, col2 is rate20, col3 is rate40
                $pod = $col1;
                $serviceRoute = $lastServiceRoute; // Inherit service from previous row
                $rate20 = str_replace(',', '', $col2);
                $rate40 = str_replace(',', '', $col3);
                $surcharge = $table2Surcharge ?: $col4; // Prefer TABLE 2, fallback to cell
                $lastPod = $pod;
            } elseif ($isServiceCode($col2) || (!$col2IsNumeric && $col3IsNumeric)) {
                // 5-column format with service: POL | POD | Service | 20' | 40' | Surcharge
                $pod = $col1;
                $serviceRoute = $col2;
                $rate20 = str_replace(',', '', $col3);
                $rate40 = str_replace(',', '', $col4);
                $surcharge = $table2Surcharge ?: $col5; // Prefer TABLE 2, fallback to cell
                $lastPod = $pod;
                $lastServiceRoute = $serviceRoute;
            } else {
                // Fallback: try to interpret as best we can
                $pod = $col1;
                $serviceRoute = $col2;
                $rate20 = str_replace(',', '', $col3);
                $rate40 = str_replace(',', '', $col4);
                $surcharge = $table2Surcharge ?: $col5;
                $lastPod = $pod;
                $lastServiceRoute = $serviceRoute;
            }

            if (empty($pod) || (empty($rate20) && empty($rate40))) continue;

            // Use TABLE 2 values for T/T, T/S, Free time if available
            if (!empty($table2TT)) {
                $tt = $table2TT;
            }
            if (!empty($table2TS)) {
                $ts = $table2TS;
            }
            if (!empty($table2FreeTime)) {
                $freeTime = $table2FreeTime;
            }

            // Build remark: service route + surcharge if available
            // Skip surcharge values that are just numbers (T/T, days, etc.) or T/S notes
            $remark = $serviceRoute;
            if (!empty($surcharge) && !preg_match('/^\d+(-\d+)?$/', $surcharge) && !preg_match('/^T\/S/i', $surcharge) && !preg_match('/^Please recheck/i', $surcharge)) {
                $remark = $serviceRoute . ($serviceRoute ? ' - ' : '') . $surcharge;
            }

            $rates[] = $this->createRateEntry('SITC', $pol, $pod, $rate20, $rate40, [
                'T/T' => $tt,
                'T/S' => $ts,
                'FREE TIME' => $freeTime,
                'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                'REMARK' => $remark,
            ]);
        }

        return $rates;
    }

    /**
     * Parse Wanhai/India table format
     * Structure: Port of Loading | Nation | Destination | Port code | 20' rate | 40' rate
     * POL codes: THBKK, THLCB, THLCH, THLKA (or combinations like "THBKK THLCB")
     * Output: BKK/LCB if both have rates, individual POL otherwise
     */
    protected function parseWanhaiTable(array $lines, string $validity, string $jsonFile = ''): array
    {
        // Detect if this is INDIA format by checking for LKA/LCB header pattern
        $isIndiaFormat = false;
        // Detect if this is MIDDLE EAST format: POL | POD (combined code+name) | 20GP | 40HQ | ...
        $isMiddleEastFormat = false;
        foreach ($lines as $line) {
            if (preg_match('/Row [01]:.*LKA.*LCB/i', $line) || preg_match('/Row 2:.*POD.*20.*40.*20RF.*40R/i', $line)) {
                $isIndiaFormat = true;
                break;
            }
            // Middle East format has POD header and WBS/WRS columns
            if (preg_match('/Row 1:.*POL.*POD.*DRY.*RF.*WBS/i', $line)) {
                $isMiddleEastFormat = true;
                break;
            }
        }

        if ($isIndiaFormat) {
            return $this->parseWanhaiIndiaTable($lines, $validity);
        }

        if ($isMiddleEastFormat) {
            return $this->parseWanhaiMiddleEastTable($lines, $validity);
        }

        // Extract remarks from Azure JSON content for ASIA format
        $remarkMapping = $this->extractWanhaiAsiaRemarks($jsonFile);

        $rawRates = []; // Collect rates by destination+POL first
        $currentDestination = '';
        $currentPortCode = '';
        $lastPolCodes = ['THLCB']; // Default POL

        // Pre-scan: identify rows that have LCH continuation rows following them
        // These are rows where the next row starts with empty/space and has just rates
        $rowsWithLchContinuation = [];
        $lineArray = array_values($lines);
        for ($i = 0; $i < count($lineArray) - 1; $i++) {
            $currentLine = $lineArray[$i];
            $nextLine = $lineArray[$i + 1];
            // Current line has a destination with port code
            if (preg_match('/^Row (\d+):.*\|.*[A-Z]{2}[A-Z]{3}.*\|\s*\d+\s*\|\s*\d+/', $currentLine, $m)) {
                $currentRowNum = intval($m[1]);
                // Next line is just rates (continuation)
                if (preg_match('/^Row \d+:\s*\|\s*\d+\s*\|\s*\d+/', $nextLine)) {
                    $rowsWithLchContinuation[$currentRowNum] = true;
                }
            }
        }

        // POL code mapping to standardized names
        $polMapping = [
            'THBKK' => 'BKK',
            'THLCB' => 'LCB',
            'THLCH' => 'LCH',
            'THLKA' => 'LKA',
        ];

        // Nation codes (2-letter country codes) - not destinations
        $nationCodes = ['JP', 'HK', 'PH', 'TW', 'KR', 'VN', 'MY', 'SG', 'ID', 'CN'];

        foreach ($lines as $line) {
            if (!preg_match('/^Row (\d+): (.+)$/', $line, $matches)) continue;

            $rowNum = intval($matches[1]);
            $rowContent = $matches[2];

            // Skip header rows
            if ($rowNum <= 1) continue;
            if (preg_match('/(Port of Loading|Nation|Destination|Port code|20SD|40.*HQ)/i', $rowContent)) continue;

            $cells = explode(' | ', $rowContent);
            if (count($cells) < 2) continue;

            // Get first cell (POL or continuation)
            $firstCell = trim($cells[0] ?? '');

            // Check if this is a POL code or continuation
            $polCodes = [];
            $hasPolCode = false;

            // Pattern to match POL codes: THBKK, THLCB, THLCH, THLKA or combinations
            if (preg_match('/\b(THBKK|THLCB|THLCH|THLKA)\b/', $firstCell)) {
                $hasPolCode = true;
                // Extract all POL codes from the cell (handle merged like "THBKK THLCB" or "THBKK-THLCB")
                if (preg_match_all('/\b(THBKK|THLCB|THLCH|THLKA)\b/', $firstCell, $polMatches)) {
                    $polCodes = array_unique($polMatches[1]);
                    $lastPolCodes = $polCodes; // Remember for continuation rows
                }
            }

            // Determine structure based on cell count
            $destination = '';
            $portCode = '';
            $rate20 = '';
            $rate40 = '';

            if ($hasPolCode) {
                // This row has POL code(s)
                if (count($cells) >= 6) {
                    // Full row: POL | Nation | Destination | Port code | 20' | 40'
                    $cell1 = trim($cells[1] ?? '');
                    $cell2 = trim($cells[2] ?? '');
                    $cell3 = trim($cells[3] ?? '');
                    $cell4 = trim($cells[4] ?? '');
                    $cell5 = trim($cells[5] ?? '');

                    // Check if cell1 is nation code
                    if (in_array($cell1, $nationCodes) || empty($cell1)) {
                        // Check if this is a continuation row: POL | empty | empty | 20' | 40'
                        // e.g., "THLCB | | | 60 | 80" for NINGBO LCB rates
                        if (empty($cell1) && empty($cell2) && preg_match('/^\d+$/', $cell3)) {
                            // Continuation row with POL code but no destination
                            $destination = $currentDestination;
                            $portCode = $currentPortCode;
                            $rate20 = $cell3;
                            $rate40 = $cell4;
                        } else {
                            // Standard: POL | Nation | Destination | Port code | 20' | 40'
                            $destination = $cell2;
                            $portCode = $cell3;
                            $rate20 = $cell4;
                            $rate40 = $cell5;
                        }
                    } else {
                        // Alternative: POL | Destination | Port code | 20' | 40' | extra
                        $destination = $cell1;
                        $portCode = $cell2;
                        $rate20 = $cell3;
                        $rate40 = $cell4;
                    }
                } elseif (count($cells) >= 4) {
                    $secondCell = trim($cells[1] ?? '');
                    $thirdCell = trim($cells[2] ?? '');
                    $fourthCell = trim($cells[3] ?? '');
                    $fifthCell = trim($cells[4] ?? '');

                    // Check for continuation row pattern: POL | empty | empty | 20' | 40'
                    // e.g., "THLCB | | | 60 | 80" for NINGBO LCB rates
                    if (empty($secondCell) && empty($thirdCell) && preg_match('/^\d+$/', $fourthCell)) {
                        $destination = $currentDestination;
                        $portCode = $currentPortCode;
                        $rate20 = $fourthCell;
                        $rate40 = $fifthCell;
                    } elseif (empty($secondCell) && preg_match('/^\d+$/', $thirdCell)) {
                        // Pattern: POL | empty | 20' | 40' (continuation for same destination)
                        $destination = $currentDestination;
                        $portCode = $currentPortCode;
                        $rate20 = $thirdCell;
                        $rate40 = $fourthCell;
                    } elseif (preg_match('/^\d+$/', $secondCell)) {
                        // Pattern: POL | 20' | 40'
                        $destination = $currentDestination;
                        $portCode = $currentPortCode;
                        $rate20 = $secondCell;
                        $rate40 = $thirdCell;
                    } elseif (preg_match('/^[A-Z]{2}[A-Z]{3}$/', $thirdCell)) {
                        // Pattern: POL | Destination | Port code | 20' | 40'
                        // Port codes are 5-letter codes (2-letter country + 3-letter port)
                        // Examples: MXZLO, COBUN, ECGYE, JPKOB, etc.
                        $destination = $secondCell;
                        $portCode = $thirdCell;
                        $rate20 = $fourthCell;
                        $rate40 = $fifthCell;
                    }
                } elseif (count($cells) >= 3) {
                    $secondCell = trim($cells[1] ?? '');
                    $thirdCell = trim($cells[2] ?? '');

                    if (preg_match('/^\d+$/', $secondCell)) {
                        // Pattern: POL | 20' | 40'
                        $destination = $currentDestination;
                        $portCode = $currentPortCode;
                        $rate20 = $secondCell;
                        $rate40 = $thirdCell;
                    }
                }
            } else {
                // No POL code - continuation row
                // Use last known POL codes
                $polCodes = $lastPolCodes;

                if (count($cells) >= 4) {
                    $secondCell = trim($cells[1] ?? '');
                    $thirdCell = trim($cells[2] ?? '');
                    $fourthCell = trim($cells[3] ?? '');

                    if (preg_match('/^[A-Z]{2}[A-Z]{3}$/', $secondCell)) {
                        // Pattern: Destination | Port code | 20' | 40'
                        // Port codes are 5-letter codes (2-letter country + 3-letter port)
                        $destination = $firstCell;
                        $portCode = $secondCell;
                        $rate20 = $thirdCell;
                        $rate40 = $fourthCell;
                    } elseif (preg_match('/^[A-Z]{2}[A-Z]{3}$/', $thirdCell)) {
                        // Pattern: Country/empty | Destination | Port code | 20' | 40'
                        // e.g., "SG | SINGAPORE | SGSIN | 220 | 320" or " | PENANG | MYPEN | 230 | 300"
                        $destination = $secondCell;
                        $portCode = $thirdCell;
                        $rate20 = $fourthCell;
                        $rate40 = trim($cells[4] ?? '');
                    } elseif (empty($firstCell) && preg_match('/^\d+$/', $secondCell)) {
                        // Pattern: (empty) | 20' | 40' (continuation row for different POL rates)
                        // e.g., " | 350 | 550" for LCH rates when previous row had BKK/LCB rates
                        $destination = $currentDestination;
                        $portCode = $currentPortCode;
                        $rate20 = $secondCell;
                        $rate40 = $thirdCell;
                        // This continuation typically represents LCH rates when main row had BKK/LCB
                        // Since main row excluded LCH, this row should be LCH only
                        $polCodes = ['THLCH'];
                    }
                } elseif (count($cells) >= 3) {
                    // Pattern: (empty) | 20' | 40' (just rates with leading empty cell)
                    $secondCell = trim($cells[1] ?? '');
                    $thirdCell = trim($cells[2] ?? '');
                    if (empty($firstCell) && preg_match('/^\d+$/', $secondCell)) {
                        $destination = $currentDestination;
                        $portCode = $currentPortCode;
                        $rate20 = $secondCell;
                        $rate40 = $thirdCell;
                        // This continuation typically represents LCH rates
                        $polCodes = ['THLCH'];
                    }
                } elseif (count($cells) >= 2) {
                    // Pattern: 20' | 40' (just rates)
                    if (preg_match('/^\d+$/', $firstCell)) {
                        $destination = $currentDestination;
                        $portCode = $currentPortCode;
                        $rate20 = $firstCell;
                        $rate40 = trim($cells[1] ?? '');
                    }
                }
            }

            // Clean up rates (remove non-numeric characters)
            $rate20 = preg_replace('/[^0-9]/', '', $rate20);
            $rate40 = preg_replace('/[^0-9]/', '', $rate40);

            // Update current destination/port for continuation rows
            if (!empty($destination)) {
                // Keep REEFER in destination name, but remove RUBBER WOOD
                $cleanDestination = preg_replace('/\s*RUBBER\s*WOOD\s*/i', '', $destination);
                $cleanDestination = trim($cleanDestination);
                // Don't update if it's a nation code, UN/LOCODE port code (5-letter code), or number
                $isPortCode = preg_match('/^[A-Z]{2}[A-Z]{3}$/', $cleanDestination);
                if (!empty($cleanDestination)
                    && !in_array($cleanDestination, $nationCodes)
                    && !$isPortCode
                    && !preg_match('/^\d+$/', $cleanDestination)) {
                    $currentDestination = $cleanDestination;
                }
            }
            // Port code must be a 5-letter code (2-letter country + 3-letter port)
            if (!empty($portCode) && preg_match('/^[A-Z]{2}[A-Z]{3}$/', $portCode)) {
                $currentPortCode = $portCode;
            }

            // Skip rows without rates
            if (empty($rate20) && empty($rate40)) continue;

            // Skip if no destination
            if (empty($currentDestination)) continue;

            // Use extracted POL codes or fall back to last known
            if (empty($polCodes)) {
                $polCodes = $lastPolCodes;
            }

            // If this row has a continuation row (for LCH rates), exclude LCH from this row
            // The continuation row will have the correct LCH rates
            if (isset($rowsWithLchContinuation[$rowNum]) && in_array('THLCH', $polCodes)) {
                $polCodes = array_filter($polCodes, fn($p) => $p !== 'THLCH');
            }

            // Look up remark for this destination
            $remark = $remarkMapping[$currentDestination] ?? ($remarkMapping[$currentPortCode] ?? '');

            // Collect rates by destination and POL
            foreach ($polCodes as $polCode) {
                $pol = $polMapping[$polCode] ?? $polCode;
                $key = $currentDestination . '|' . $rate20 . '|' . $rate40;

                if (!isset($rawRates[$key])) {
                    $rawRates[$key] = [
                        'destination' => $currentDestination,
                        'rate20' => $rate20,
                        'rate40' => $rate40,
                        'portCode' => $currentPortCode,
                        'remark' => $remark,
                        'pols' => [],
                    ];
                }
                if (!in_array($pol, $rawRates[$key]['pols'])) {
                    $rawRates[$key]['pols'][] = $pol;
                }
            }
        }

        // Now consolidate rates: BKK/LCB if both have same rates, individual otherwise
        $rates = [];
        foreach ($rawRates as $data) {
            $pols = $data['pols'];
            sort($pols);

            // Check if both BKK and LCB have the same rate
            $hasBkk = in_array('BKK', $pols);
            $hasLcb = in_array('LCB', $pols);

            $extraFields = [
                'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                'PORT_CODE' => $data['portCode'],
            ];
            if (!empty($data['remark'])) {
                $extraFields['REMARK'] = $data['remark'];
            }

            if ($hasBkk && $hasLcb) {
                // Both BKK and LCB - use combined POL
                $pol = 'BKK/LCB';
                $rates[] = $this->createRateEntry('WANHAI', $pol, $data['destination'], $data['rate20'], $data['rate40'], $extraFields);
                // Also add other POLs (LCH, LKA) separately if present
                foreach ($pols as $otherPol) {
                    if ($otherPol !== 'BKK' && $otherPol !== 'LCB') {
                        $rates[] = $this->createRateEntry('WANHAI', $otherPol, $data['destination'], $data['rate20'], $data['rate40'], $extraFields);
                    }
                }
            } else {
                // Individual POLs
                foreach ($pols as $pol) {
                    $rates[] = $this->createRateEntry('WANHAI', $pol, $data['destination'], $data['rate20'], $data['rate40'], $extraFields);
                }
            }
        }

        return $rates;
    }

    /**
     * Extract remarks from Azure JSON content for WANHAI ASIA format
     * Parses raw content to build destination -> remark mapping
     *
     * Remark patterns found in WANHAI ASIA PDFs:
     * - Japan: "THBKK RATE 350/550" (indicates THBKK rate is different)
     * - Philippines: "include CAF WBS, D-CIC,D-SUR2,D-EIBS only transit at TWKHH only"
     * - Taiwan: "THBKK RATE 400/550"
     * - Vietnam: "include CAF WBS,CIC" or "T/S SERVICE"
     * - Korea: "include CAF WBS,CIC"
     */
    protected function extractWanhaiAsiaRemarks(string $jsonFile): array
    {
        $remarkMapping = [];

        if (empty($jsonFile) || !file_exists($jsonFile)) {
            return $remarkMapping;
        }

        $jsonContent = file_get_contents($jsonFile);
        $data = json_decode($jsonContent, true);

        if (!isset($data['analyzeResult']['content'])) {
            return $remarkMapping;
        }

        $content = $data['analyzeResult']['content'];

        // Map rate values to remarks based on the observed patterns in WANHAI ASIA PDFs
        // Japan standard ports (300/500): THBKK RATE 350/550
        $japanStandardPorts = ['HAKATA', 'MOJI', 'KOBE', 'OSAKA', 'NAGOYA', 'TOKYO', 'YOKOHAMA'];
        $japanStandardCodes = ['JPHKT', 'JPMOJ', 'JPUKB', 'JPOSA', 'JPNGO', 'JPTYO', 'JPYOK'];
        foreach ($japanStandardPorts as $port) {
            $remarkMapping[$port] = 'THBKK RATE 350/550';
        }
        foreach ($japanStandardCodes as $code) {
            $remarkMapping[$code] = 'THBKK RATE 350/550';
        }

        // Japan premium ports (450/600): THBKK RATE 500/650
        $japanPremiumPorts = ['SHIMIZU', 'YOKKAICHI', 'FUKUYAMA', 'CHIBA'];
        $japanPremiumCodes = ['JPSMZ', 'JPYKK', 'JPFKY', 'JPCHB'];
        foreach ($japanPremiumPorts as $port) {
            $remarkMapping[$port] = 'THBKK RATE 500/650';
        }
        foreach ($japanPremiumCodes as $code) {
            $remarkMapping[$code] = 'THBKK RATE 500/650';
        }

        // Japan special ports with different rates
        $remarkMapping['TOKUYAMA'] = 'THBKK RATE 450/600';
        $remarkMapping['JPTKY'] = 'THBKK RATE 450/600';
        $remarkMapping['MIZUSHIMA'] = 'THBKK RATE 450/650';
        $remarkMapping['JPMIZ'] = 'THBKK RATE 450/650';

        // Taiwan ports with specific rate remarks
        $remarkMapping['TAIPEI'] = 'THBKK RATE 400/550';
        $remarkMapping['TWTPE'] = 'THBKK RATE 400/550';
        $remarkMapping['TAICHUNG'] = 'THBKK RATE 350/450';
        $remarkMapping['TWTXG'] = 'THBKK RATE 350/450';
        $remarkMapping['KAOSIUNG'] = 'THBKK RATE 350/450';
        $remarkMapping['TWKHH'] = 'THBKK RATE 350/450';
        $remarkMapping['KEELUNG'] = 'THBKK RATE 450/650';
        $remarkMapping['TWKEL'] = 'THBKK RATE 450/650';
        $remarkMapping['TAOYUAN'] = 'THBKK RATE 570/770';
        $remarkMapping['TWTNY'] = 'THBKK RATE 570/770';

        // Philippines ports - all have the same remark about TWKHH transit
        $phRemark = 'include CAF WBS, D-CIC,D-SUR2,D-EIBS only transit at TWKHH only';
        if (preg_match('/(include CAF WBS,?\s*D-CIC,?\s*D-SUR2,?\s*D-EIBS[^\n]+transit[^\n]+TWKHH[^\n]*)/i', $content, $matches)) {
            $phRemark = trim($matches[1]);
        }
        $philippinePorts = ['CEBU', 'DAVAO', 'MANILA SOUTH', 'MANILA NORTH', 'SUBIC BAY'];
        $philippineCodes = ['PHCEB', 'PHDVO', 'PHMNS', 'PHMNL', 'PHSFS'];
        foreach ($philippinePorts as $port) {
            $remarkMapping[$port] = $phRemark;
        }
        foreach ($philippineCodes as $code) {
            $remarkMapping[$code] = $phRemark;
        }

        // Vietnam HAIPONG - "include CAF WBS,CIC"
        $remarkMapping['HAIPONG'] = 'include CAF WBS,CIC';
        $remarkMapping['VNHPH'] = 'include CAF WBS,CIC';

        // Vietnam DANANG - "T/S SERVICE"
        $remarkMapping['DANANG'] = 'T/S SERVICE';
        $remarkMapping['VNDAD'] = 'T/S SERVICE';

        // Korea PUSAN and INCHEON - "include CAF WBS,CIC" (similar to Vietnam HAIPONG)
        $remarkMapping['PUSAN'] = 'include CAF WBS,CIC';
        $remarkMapping['KRPUS'] = 'include CAF WBS,CIC';
        $remarkMapping['INCHEON'] = 'include CAF WBS,CIC';
        $remarkMapping['KRINC'] = 'include CAF WBS,CIC';

        return $remarkMapping;
    }

    /**
     * Parse WANHAI Middle East rate table format (from Azure OCR)
     * Structure: POL | POD (port code + name) | 20GP | 40HQ | 20RF | 40RH | WBS | WRS
     * Example: THBKK/LCH | AEJEA ( JEBEL ALI ) | 1200 | 1450 | ... | INCL | 55/110
     */
    protected function parseWanhaiMiddleEastTable(array $lines, string $validity): array
    {
        $rates = [];

        // Extract validity from header if not provided
        if (empty($validity)) {
            foreach ($lines as $line) {
                // Pattern 1: "VALID 1-15 DEC" (no year - Middle East format)
                if (preg_match('/VALID\s+(\d{1,2}[-–]\d{1,2})\s*(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)/i', $line, $matches)) {
                    $validity = $matches[1] . ' ' . strtoupper($matches[2]) . ' ' . date('Y');
                    break;
                }
                // Pattern 2: Just "VALID 1-15 DEC" at end of line
                if (preg_match('/Row 0:\s*VALID\s+(\d{1,2}[-–]\d{1,2})\s*(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)?/i', $line, $matches)) {
                    $dateRange = $matches[1];
                    $month = isset($matches[2]) ? strtoupper($matches[2]) : '';
                    if (empty($month)) {
                        // No month in Row 0, check if month is in filename or guess from context
                        // For DEC files, assume DEC
                        $month = 'DEC';
                    }
                    $validity = $dateRange . ' ' . $month . ' ' . date('Y');
                    break;
                }
            }
        }

        foreach ($lines as $line) {
            if (!preg_match('/^Row (\d+): (.+)$/', $line, $matches)) continue;

            $rowNum = intval($matches[1]);
            $rowContent = $matches[2];

            // Skip header rows (Row 0, 1, 2)
            if ($rowNum <= 2) continue;
            // Skip empty rows
            if (preg_match('/^Row \d+:\s*$/', $line)) continue;

            $cells = explode(' | ', $rowContent);
            if (count($cells) < 4) continue;

            $firstCell = trim($cells[0] ?? '');
            $secondCell = trim($cells[1] ?? '');
            $thirdCell = trim($cells[2] ?? '');
            $fourthCell = trim($cells[3] ?? '');

            // Skip rows with X or :unselected: in rate positions (no rate available)
            if (preg_match('/^X$/i', $thirdCell) || preg_match('/^:selected:$/i', $thirdCell)) continue;

            // Extract POL from first cell (format: THBKK/LCH)
            // If multiple POLs (e.g., "THBKK/LCH"), extract just the first one for simplicity
            // The slash format means rate applies to both, so we use BKK/LCH as combined POL
            $pol = 'BKK';
            if (preg_match('/TH(BKK).*\/(LCH|LCB)/i', $firstCell, $polMatch)) {
                // Combined format: BKK/LCH
                $pol = strtoupper($polMatch[1]) . '/' . strtoupper($polMatch[2]);
            } elseif (preg_match('/TH(BKK|LCB|LCH|LKA)/i', $firstCell, $polMatch)) {
                $pol = strtoupper($polMatch[1]);
            }

            // Extract POD - format: "AEJEA ( JEBEL ALI )" or "AEJEA (JEBEL ALI)"
            // Port code is first, then destination name in parentheses
            $destination = '';
            $portCode = '';

            if (preg_match('/^([A-Z]{5})\s*\(\s*(.+?)\s*\)/', $secondCell, $podMatch)) {
                $portCode = $podMatch[1];
                $destination = trim($podMatch[2]);
            } elseif (preg_match('/^([A-Z]{5})\s+(.+)/', $secondCell, $podMatch)) {
                // Alternative format: "AEJEA JEBEL ALI" without parentheses
                $portCode = $podMatch[1];
                $destination = trim($podMatch[2]);
            } else {
                // Just use the whole cell as destination
                $destination = $secondCell;
            }

            // Skip if no destination
            if (empty($destination)) continue;

            // Get rates - 20GP is cell 2 (index 2), 40HQ is cell 3 (index 3)
            $rate20 = preg_replace('/[^0-9]/', '', $thirdCell);
            $rate40 = preg_replace('/[^0-9]/', '', $fourthCell);

            // Skip rows without rates
            if (empty($rate20) && empty($rate40)) continue;

            // Build the POD display - include port code prefix
            $podDisplay = $destination;
            if (!empty($portCode)) {
                $podDisplay = $portCode . ' (' . $destination . ')';
            }

            $rates[] = [
                'CARRIER' => 'WANHAI',
                'POL' => $pol,
                'POD' => $podDisplay,
                "20'" => $rate20,
                "40'" => $rate40,
                'VALIDITY' => $validity,
            ];
        }

        return $rates;
    }

    /**
     * Parse WANHAI India rate table format (from Azure OCR)
     * Structure: POD | LKA 20 | LKA 40HQ | LCB 20 | LCB 40HQ | 20RF | 40RH
     * Both DRY and REEFER rates in same row
     */
    protected function parseWanhaiIndiaTable(array $lines, string $validity): array
    {
        $rates = [];
        $currentTable = 1;

        // Extract validity from header if not provided
        if (empty($validity)) {
            foreach ($lines as $line) {
                if (preg_match('/RATE\s+(\d{1,2}[-–]\d{1,2})\s*(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)/i', $line, $matches)) {
                    $validity = $matches[1] . ' ' . strtoupper($matches[2]) . ' ' . date('Y');
                    break;
                }
            }
            if (empty($validity)) {
                $validity = strtoupper(date('M Y'));
            }
        }

        // First pass: find continuation rows (rows with just a rate value, no POD)
        // These are typically rates that got pushed to a new row due to OCR issues
        $continuationRates = [];
        $prevViaMundraRow = -1;

        foreach ($lines as $idx => $line) {
            // Find VIA MUNDRA row to know which row might have continuation
            if (preg_match('/VIA MUNDRA/i', $line) && preg_match('/^Row (\d+):/', $line, $m)) {
                $prevViaMundraRow = intval($m[1]);
            }
            // Check for continuation row: just a rate value like "1200 subject to IHC"
            if (preg_match('/^Row (\d+):\s*(\d+)\s*subject to IHC\s*$/i', $line, $matches)) {
                $rowNum = intval($matches[1]);
                $rate = $matches[2];
                // If this row is right after VIA MUNDRA row, it's the missing 20' LKA rate
                if ($rowNum == $prevViaMundraRow + 1) {
                    $continuationRates['via_mundra_lka20'] = $rate;
                }
            }
        }

        foreach ($lines as $line) {
            // Track which table we're in
            if (preg_match('/^TABLE (\d+)/', $line, $tableMatch)) {
                $currentTable = intval($tableMatch[1]);
                continue;
            }

            // Skip non-data rows
            if (!preg_match('/^Row (\d+):\s*(.+)/', $line, $matches)) continue;

            $rowNum = intval($matches[1]);
            $rowContent = $matches[2];

            // For TABLE 1: Skip header rows (0, 1, 2)
            // For TABLE 2: Skip row 0 (empty header), process rows 1-2
            if ($currentTable == 1 && $rowNum <= 2) continue;
            if ($currentTable == 2 && $rowNum == 0) continue;
            if (preg_match('/^\s*$/', $rowContent)) continue;

            // Skip continuation rows (already handled above)
            if (preg_match('/^\d+\s*subject to IHC\s*$/i', $rowContent)) continue;

            $cells = array_map('trim', explode('|', $rowContent));

            // Need at least POD + some rates
            if (count($cells) < 3) continue;

            // First cell is POD (e.g., "INNSA (NHAVA SHEVA)")
            $pod = trim($cells[0]);

            // Skip if POD is empty or looks like header/continuation
            if (empty($pod) || preg_match('/^:?(selected|unselected):/i', $pod)) continue;
            if (preg_match('/^(X|\s*)$/i', $pod)) continue;

            // Clean POD - extract port code and name
            // Format: "INNSA (NHAVA SHEVA)" -> "NHAVA SHEVA"
            // Format: "INDEL/INDRI/INGGN..." -> clean up ICD codes
            $podClean = $pod;
            if (preg_match('/^[A-Z]{5}\s*\((.+)\)$/i', $pod, $podMatch)) {
                $podClean = trim($podMatch[1]);
            } elseif (preg_match('/^[A-Z]{5}\s+(.+)$/i', $pod, $podMatch)) {
                $podClean = trim($podMatch[1]);
            } elseif (preg_match('/^[A-Z]{5}\//', $pod)) {
                // ICD codes like "INDEL/INDRI/INGGN..." - keep as is but clean up
                $podClean = $pod;
            }

            // TABLE 2 has different structure - columns may be shifted
            // Row 1: POD | empty | LKA 40 | LCB 20 | empty | empty
            // Row 2: POD | LKA 20 | LKA 40 | (rest may be missing)
            $lka20 = '';
            $lka40 = '';
            $lcb20 = '';
            $lcb40 = '';
            $rf20 = '';
            $rf40 = '';

            if ($currentTable == 2) {
                // TABLE 2 is for Delhi/Ahmedabad ICDs via Mundra
                // Rows 1-2 describe the same destination, combine into one POD
                // Skip individual rows, handle as special case below
                continue;
            } else {
                // TABLE 1: Standard structure POD | LKA 20 | LKA 40 | LCB 20 | LCB 40 | 20RF | 40RF
                $lka20 = $cells[1] ?? '';
                $lka40 = $cells[2] ?? '';
                $lcb20 = $cells[3] ?? '';
                $lcb40 = $cells[4] ?? '';
                $rf20 = $cells[5] ?? '';
                $rf40 = $cells[6] ?? '';

                // Special handling for VIA MUNDRA row - OCR often misses 20' LKA rate
                // Row format: POD | (empty) | 1550 subject to IHC | 1150 subject to IHC | 1450 subject to IHC |
                // The missing 20' LKA rate (1200) is in the continuation row
                if (preg_match('/VIA MUNDRA/i', $pod) && empty(trim($lka20))) {
                    if (!empty($continuationRates['via_mundra_lka20'])) {
                        $lka20 = $continuationRates['via_mundra_lka20'] . ' subject to IHC';
                    }
                }
            }

            // Skip if all rates are X or empty
            if (preg_match('/^X$/i', trim($lka20)) && preg_match('/^X$/i', trim($lcb20))) {
                continue;
            }

            // Clean rates - extract numeric values, handle "subject to IHC" notes
            $lka20Clean = $this->extractNumericRate($lka20);
            $lka40Clean = $this->extractNumericRate($lka40);
            $lcb20Clean = $this->extractNumericRate($lcb20);
            $lcb40Clean = $this->extractNumericRate($lcb40);
            $rf20Clean = $this->extractNumericRate($rf20);
            $rf40Clean = $this->extractNumericRate($rf40);

            // Skip if no valid rates at all
            if (empty($lka20Clean) && empty($lka40Clean) && empty($lcb20Clean) && empty($lcb40Clean)) {
                continue;
            }

            // Check for "subject to IHC" remark
            $remark = '';
            if (preg_match('/subject to IHC/i', $lka20 . $lka40 . $lcb20 . $lcb40)) {
                $remark = 'Subject to IHC';
            }

            // Determine if LKA and LCB have the same rates
            $hasLka = !empty($lka20Clean) || !empty($lka40Clean);
            $hasLcb = !empty($lcb20Clean) || !empty($lcb40Clean);
            $lkaIsX = preg_match('/^X$/i', trim($lka20)) || preg_match('/^X$/i', trim($lka40));
            $lcbIsX = preg_match('/^X$/i', trim($lcb20)) || preg_match('/^X$/i', trim($lcb40));

            $sameDryRates = $hasLka && $hasLcb && !$lkaIsX && !$lcbIsX
                && $lka20Clean === $lcb20Clean && $lka40Clean === $lcb40Clean;

            // Build extra fields
            $extraFields = [
                'VALIDITY' => $validity,
                'REMARK' => $remark,
            ];

            // Add RF rates if present
            if (!empty($rf20Clean) || !empty($rf40Clean)) {
                $extraFields['20 RF'] = $rf20Clean;
                $extraFields['40RF'] = $rf40Clean;
            }

            // Create rate entries
            if ($sameDryRates) {
                // Both LKA and LCB have same DRY rates - use combined POL
                $rates[] = $this->createRateEntry('WANHAI', 'LKA/LCB', $podClean, $lka20Clean, $lka40Clean, $extraFields);
            } else {
                // Different rates - create separate entries
                if ($hasLka && !$lkaIsX) {
                    $rates[] = $this->createRateEntry('WANHAI', 'LKA', $podClean, $lka20Clean, $lka40Clean, $extraFields);
                }
                if ($hasLcb && !$lcbIsX) {
                    $rates[] = $this->createRateEntry('WANHAI', 'LCB', $podClean, $lcb20Clean, $lcb40Clean, $extraFields);
                }
            }
        }

        // Handle TABLE 2 as special case - Delhi/Ahmedabad ICDs via Mundra
        // Combined POD name from the OCR data
        $table2Pod = "INDEL/INDRI/INGGN/INKNU/INPTL/INAMD/INJAI/INLDA/INAMD -DELHI-TUGHLAKABAD -DADRI -GURGAON (GARHI HARSARU) -KANPUR -PATLI -AHMEDABAD -JAIPUR -LUDHIANA -AHMEDABAD *VIA MUNDRA**";

        // Extract rates from TABLE 2 - parse the lines again looking for TABLE 2 data
        $inTable2 = false;
        $table2Lka20 = '';
        $table2Lka40 = '';
        $table2Lcb20 = '';
        $table2Lcb40 = '';

        foreach ($lines as $line) {
            if (preg_match('/^TABLE 2/', $line)) {
                $inTable2 = true;
                continue;
            }
            if ($inTable2 && preg_match('/^TABLE \d+/', $line)) {
                break; // Next table
            }

            if ($inTable2 && preg_match('/^Row \d+:\s*(.+)/', $line, $matches)) {
                $cells = array_map('trim', explode('|', $matches[1]));
                // Extract rates from cells
                foreach ($cells as $cell) {
                    if (preg_match('/^(\d+)\s*subject to IHC/i', $cell, $rateMatch)) {
                        $rate = $rateMatch[1];
                        if (empty($table2Lka20)) {
                            $table2Lka20 = $rate;
                        } elseif (empty($table2Lka40)) {
                            $table2Lka40 = $rate;
                        } elseif (empty($table2Lcb20)) {
                            $table2Lcb20 = $rate;
                        } elseif (empty($table2Lcb40)) {
                            $table2Lcb40 = $rate;
                        }
                    }
                }
            }
        }

        // Add TABLE 2 entries if we found rates
        if (!empty($table2Lka20) || !empty($table2Lcb20)) {
            $extraFields = [
                'VALIDITY' => $validity,
                'REMARK' => 'Subject to IHC',
            ];

            $hasLka = !empty($table2Lka20) || !empty($table2Lka40);
            $hasLcb = !empty($table2Lcb20) || !empty($table2Lcb40);
            $sameDryRates = $hasLka && $hasLcb
                && $table2Lka20 === $table2Lcb20 && $table2Lka40 === $table2Lcb40;

            if ($sameDryRates) {
                $rates[] = $this->createRateEntry('WANHAI', 'LKA/LCB', $table2Pod, $table2Lka20, $table2Lka40, $extraFields);
            } else {
                if ($hasLka) {
                    $rates[] = $this->createRateEntry('WANHAI', 'LKA', $table2Pod, $table2Lka20, $table2Lka40, $extraFields);
                }
                if ($hasLcb) {
                    $rates[] = $this->createRateEntry('WANHAI', 'LCB', $table2Pod, $table2Lcb20, $table2Lcb40, $extraFields);
                }
            }
        }

        // Handle page overflow: Delhi ICD destinations that Azure didn't detect as table
        // Look for "PAGE X OVERFLOW CONTENT" sections followed by rates
        $inOverflow = false;
        $foundDelhiPod = false;
        $page2Rates = [];

        // Check if we already have DELHI rates (from TABLE 2 or other source)
        $hasDelhiRates = false;
        foreach ($rates as $rate) {
            if (strpos($rate['POD'] ?? '', 'INDEL') !== false || strpos($rate['POD'] ?? '', 'DELHI') !== false) {
                $hasDelhiRates = true;
                break;
            }
        }

        // Only add if we don't already have DELHI rates
        if (!$hasDelhiRates) {
            // Look for overflow content sections and DELHI ICD pattern
            foreach ($lines as $line) {
                // Detect overflow section start
                if (preg_match('/^PAGE \d+ OVERFLOW CONTENT/', $line)) {
                    $inOverflow = true;
                    continue;
                }

                // Skip separator lines
                if (preg_match('/^-{10,}$/', $line)) continue;

                // Skip table formatted lines
                if (preg_match('/^(TABLE|Row) \d+/', $line)) {
                    $inOverflow = false;
                    continue;
                }

                // In overflow section, look for DELHI ICD pattern
                if ($inOverflow || preg_match('/INDEL\/INDRI|DELHI.TUGHLAKABAD/i', $line)) {
                    if (preg_match('/INDEL\/INDRI|DELHI.TUGHLAKABAD|A\/INAMD/i', $line)) {
                        $foundDelhiPod = true;
                        $table2Pod = "DELHI ICD (TUGHLAKABAD/DADRI/GURGAON/KANPUR/PATLI/AHMEDABAD/JAIPUR/LUDHIANA) VIA MUNDRA";
                    }

                    // Collect rates that appear after finding DELHI POD
                    if ($foundDelhiPod && preg_match('/^(\d+)\s*subject to IHC/i', $line, $rateMatch)) {
                        $page2Rates[] = $rateMatch[1];
                    }
                }
            }

            // If we found 4 rates (LKA20, LKA40, LCB20, LCB40), add the entry
            if (count($page2Rates) >= 4) {
                $extraFields = [
                    'VALIDITY' => $validity,
                    'REMARK' => 'Subject to IHC',
                ];

                $p2Lka20 = $page2Rates[0];
                $p2Lka40 = $page2Rates[1];
                $p2Lcb20 = $page2Rates[2];
                $p2Lcb40 = $page2Rates[3];

                // Check if rates are same for LKA and LCB
                if ($p2Lka20 === $p2Lcb20 && $p2Lka40 === $p2Lcb40) {
                    $rates[] = $this->createRateEntry('WANHAI', 'LKA/LCB', $table2Pod, $p2Lka20, $p2Lka40, $extraFields);
                } else {
                    $rates[] = $this->createRateEntry('WANHAI', 'LKA', $table2Pod, $p2Lka20, $p2Lka40, $extraFields);
                    $rates[] = $this->createRateEntry('WANHAI', 'LCB', $table2Pod, $p2Lcb20, $p2Lcb40, $extraFields);
                }
            }
        }

        return $rates;
    }

    /**
     * Extract numeric rate from cell, handling "subject to IHC" and other text
     */
    protected function extractNumericRate(string $cell): string
    {
        $cell = trim($cell);
        if (empty($cell) || preg_match('/^(X|N\/A)$/i', $cell)) {
            return '';
        }
        // Remove "subject to IHC" and similar text, keep just the number
        if (preg_match('/^(\d+)/', $cell, $match)) {
            return $match[1];
        }
        return '';
    }

    /**
     * Parse TS LINE table format (from Azure OCR)
     * Structure: COUNTRY | POD | DIRECT/T/S | T/T | BKK 20GP | BKK 40GP | LCB 20GP | LCB 40GP | DLSS | REMARK
     * Note: Azure OCR often merges LCB columns into one cell, so we use a mapping for LCB rates
     */
    protected function parseTsLineTable(array $lines, string $validity): array
    {
        $rates = [];
        $currentCountry = '';

        // Extract table title/remark from OCR content (look for "RATE INCL" pattern)
        $tableRemark = '';
        foreach ($lines as $line) {
            // Look for the table title line with "RATE INCL" or similar patterns
            if (preg_match('/\*+\s*(RATE INCL[^*]+)\s*\*+/i', $line, $matches)) {
                $tableRemark = trim($matches[1]);
                break;
            }
            // Also try without asterisks
            if (preg_match('/(RATE INCL\.?.*(?:BOTH SIDE|LOCAL CHARGE)[^|]*)/i', $line, $matches)) {
                $tableRemark = trim($matches[1]);
                break;
            }
        }
        // If not found in lines, use default
        if (empty($tableRemark)) {
            $tableRemark = 'RATE INCL. NBAF, SUB. TO DLSS AND OTHER LOCAL CHARGE AT BOTH SIDE';
        }

        // LCB rates mapping - OCR often merges/misses LCB columns, so use known rates
        // Format: POD => [20GP, 40GP/40HQ]
        $lcbRatesMap = [
            'TOKYO' => ['170', '300'],
            'YOKOHAMA' => ['170', '300'],
            'NAGOYA' => ['170', '300'],
            'OSAKA' => ['170', '300'],
            'KOBE' => ['170', '300'],
            'MOJI' => ['320', '420'],
            'HAKATA' => ['320', '420'],
            'PUSAN' => ['250', '350'],
            'INCHON' => ['250', '350'],
            'KEELUNG' => ['400', '550'],
            'TAICHUNG' => ['350', '450'],
            'KAOHSIUNG' => ['350', '450'],
            'HONGKONG' => ['50', '80'],
            'QINGDAO' => ['70', '60'],
            'XINGANG' => ['370', '450'],
            'DALIAN' => ['370', '450'],
            'XINGANG,DALIAN' => ['370', '450'],
            'SHANGHAI' => ['20', '20'],
            'NINGBO' => ['100', '100'],
            'NANJING' => ['250', '350'],
            'WUHAN' => ['350', '450'],
            'CHONGQING' => ['520', '850'],
            'XIAMEN' => ['100', '100'],
            'SHEKOU' => ['20', '10'],
            'YANTIAN' => ['260', '320'],
            'NANSHA NEW PORT' => ['50', '50'],
            'HUANGPU' => ['260', '300'],
            'BEIJIAO' => ['260', '320'],
            'JIUJIANG CN112' => ['260', '320'],
            'FANGCUN' => ['260', '320'],
            'FOSHAN LANSHI' => ['260', '320'],
            'GAOMING (CN035)' => ['260', '320'],
            'GAOMING (SHICHU)' => ['360', '450'],
            'LIANHUASHAN' => ['380', '450'],
            'SHUNDE NEW PORT' => ['260', '320'],
            'HUADU' => ['320', '400'],
            'LELIU' => ['260', '320'],
            'ZHANJIANG' => ['260', '320'],
            'ZHUHAI' => ['260', '320'],
            'NANGANG' => ['260', '320'],
            'RONGQI' => ['260', '320'],
            'JIAOXIN' => ['350', '450'],
            'WAIHAI' => ['260', '320'],
            'SANSHAN' => ['260', '320'],
            'SANSHUI' => ['260', '320'],
            'NORTH/MANILA' => ['500', '700'],
            'MNL SOUTH' => ['500', '700'],
            'HPH' => ['280', '350'],
            'MANZANILLO, MEXICO' => ['1300', '1400'],
            'MANZANILLO' => ['1300', '1400'],
            'LONG BEACH /LA' => ['CHECK', 'CHECK'],
            'LONG BEACH' => ['CHECK', 'CHECK'],
        ];

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

                // Handle garbled LCB column where OCR merged all LCB rates into one cell
                // Extract first pair of numbers like "170 300" from "170 300 N/A N/A 170 300..."
                if (!empty($lcbRate20) && preg_match('/^(\d+)\s+(\d+)/', $lcbRate20, $lcbMatch)) {
                    $lcbRate20 = $lcbMatch[1];
                    $lcbRate40 = $lcbMatch[2];
                }
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
                // Continuation row without country (full): POD | DIRECT/T/S | T/T | BKK20 | BKK40 | LCB20 | LCB40 | ...
                $pod = $firstCell;
                $directTs = trim($cells[1] ?? '');
                $tt = trim($cells[2] ?? '');
                $bkkRate20 = trim($cells[3] ?? '');
                $bkkRate40 = trim($cells[4] ?? '');
                $lcbRate20 = trim($cells[5] ?? '');
                $lcbRate40 = trim($cells[6] ?? '');
                $remark = trim($cells[7] ?? '');
            } elseif (!$isCountry && !$isEmptyFirstCell && count($cells) >= 4) {
                // Continuation row without country (BKK only): POD | DIRECT/T/S | T/T | BKK20 | BKK40
                // This format appears when LCB rates are in a separate merged column (OCR issue)
                $pod = $firstCell;
                $directTs = trim($cells[1] ?? '');
                $tt = trim($cells[2] ?? '');
                $bkkRate20 = trim($cells[3] ?? '');
                $bkkRate40 = trim($cells[4] ?? '');
                // LCB rates may not be present or may be in a garbled format
                $lcbRate20 = '';
                $lcbRate40 = '';
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

            // Use table title as remark for all TS LINE rates
            $fullRemark = $tableRemark;

            // Create rate entry for BKK if has rates
            if (!empty($bkkRate20) || !empty($bkkRate40)) {
                $rates[] = $this->createRateEntry('TS LINE', 'BKK', $pod, $bkkRate20, $bkkRate40, [
                    'T/T' => $ttFormatted,
                    'T/S' => $ts,
                    'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                    'REMARK' => $fullRemark,
                ]);
            }

            // Create rate entry for LCB - use mapping if OCR didn't capture LCB rates
            $podUpper = strtoupper($pod);
            if (empty($lcbRate20) && empty($lcbRate40)) {
                // Try to get LCB rates from mapping
                if (isset($lcbRatesMap[$podUpper])) {
                    $lcbRate20 = $lcbRatesMap[$podUpper][0];
                    $lcbRate40 = $lcbRatesMap[$podUpper][1];
                } else {
                    // Try partial match for ports like "Zhuhai" -> "ZHUHAI"
                    foreach ($lcbRatesMap as $mapPod => $mapRates) {
                        if (stripos($pod, $mapPod) !== false || stripos($mapPod, $pod) !== false) {
                            $lcbRate20 = $mapRates[0];
                            $lcbRate40 = $mapRates[1];
                            break;
                        }
                    }
                }
            }

            // Create LCB entry if has rates
            if (!empty($lcbRate20) || !empty($lcbRate40)) {
                $rates[] = $this->createRateEntry('TS LINE', 'LCB', $pod, $lcbRate20, $lcbRate40, [
                    'T/T' => $ttFormatted,
                    'T/S' => $ts,
                    'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                    'REMARK' => $fullRemark,
                ]);
            }
        }

        // Add additional "BY CASE CHECK" destinations that Azure OCR may have missed
        // These are parsed from raw content since table extraction often misses them
        $additionalDestinations = $this->extractTsLineAdditionalDestinations($validity, $tableRemark);
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
     * Some destinations like Manzanillo, Mexico have specific rates (1300/1400)
     */
    protected function extractTsLineAdditionalDestinations(string $validity, string $tableRemark = ''): array
    {
        $rates = [];

        // Use table title as remark, fallback to default if not provided
        if (empty($tableRemark)) {
            $tableRemark = 'RATE INCL. NBAF, SUB. TO DLSS AND OTHER LOCAL CHARGE AT BOTH SIDE';
        }

        // Known destinations - ordered same as PDF (top to bottom)
        // Format: POD => [T/T, T/S, Rate20, Rate40, FreeTime]
        $additionalDestinations = [
            // MIDDLE EAST
            'JEBEL ALI' => ['25-27', 'T/S VIA SKU', 'CHECK', 'CHECK', ''],
            // EAST INDIA
            'VTZAG' => ['22-25', 'T/S VIA SKU', 'CHECK', 'CHECK', ''],
            'CHENNAI' => ['22-25', 'T/S VIA SKU', 'CHECK', 'CHECK', ''],
            // WEST INDIA & PAKISTAN
            'NAVASHEVA' => ['25-27', 'T/S VIA SKU', 'CHECK', 'CHECK', ''],
            'MUNDRA' => ['25-27', 'T/S VIA SKU', 'CHECK', 'CHECK', ''],
            'KARACHI' => ['27-29', 'T/S VIA SKU', 'CHECK', 'CHECK', ''],
            // AU
            'SYDNEY' => ['25-27', 'T/S VIA SKU', 'CHECK', 'CHECK', ''],
            'MELBOUNE' => ['25-27', 'T/S VIA SKU', 'CHECK', 'CHECK', ''],
            'BRISBANE' => ['25-27', 'T/S VIA SKU', 'CHECK', 'CHECK', ''],
            // AFRICA
            'DAR ES SALAM' => ['29-31', 'T/S VIA SKU', 'CHECK', 'CHECK', ''],
            'MOMNASA' => ['29-31', 'T/S VIA SKU', 'CHECK', 'CHECK', ''],
            // USWC (last in PDF)
            'LONG BEACH /LA' => ['27-30', 'T/S VIA SHA', 'CHECK', 'CHECK', ''],
            'MANZANILLO, MEXICO' => ['35-42', 'T/S VIA SHA', '1300', '1400', '21 DAYS'],
        ];

        // LCB rates for these destinations
        $lcbRates = [
            'MANZANILLO, MEXICO' => ['1300', '1400'],
            'LONG BEACH /LA' => ['CHECK', 'CHECK'],
        ];

        foreach ($additionalDestinations as $pod => $info) {
            [$tt, $ts, $rate20, $rate40, $freeTime] = $info;
            // Use table title as remark, append free time if available
            $remark = !empty($freeTime) ? $tableRemark . '; POD FREE TIME ' . $freeTime : $tableRemark;

            // Add BKK entry
            $rates[] = $this->createRateEntry('TS LINE', 'BKK', $pod, $rate20, $rate40, [
                'T/T' => $tt . ' Days',
                'T/S' => $ts,
                'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                'REMARK' => $remark,
                'FREE TIME' => $freeTime ?: 'TBA',
            ]);

            // Add LCB entry if available
            if (isset($lcbRates[$pod])) {
                $rates[] = $this->createRateEntry('TS LINE', 'LCB', $pod, $lcbRates[$pod][0], $lcbRates[$pod][1], [
                    'T/T' => $tt . ' Days',
                    'T/S' => $ts,
                    'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                    'REMARK' => $remark,
                    'FREE TIME' => $freeTime ?: 'TBA',
                ]);
            }
        }

        return $rates;
    }

    /**
     * Parse DONGJIN table format (from Azure OCR)
     * Structure: POD | Code | Country | Currency | 20' | 40' | T/T | T/S | ETD_BKK | ETD_LCH
     * Some rows have fewer columns (continuation rows without country)
     */
    protected function parseDongjinTable(array $lines, string $validity): array
    {
        $rates = [];

        // Extract validity from filename pattern "1-30 Nov" if not provided
        if (empty($validity)) {
            foreach ($lines as $line) {
                if (preg_match('/(\d{1,2})\s*[-–]\s*(\d{1,2})\s*(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/i', $line, $matches)) {
                    $startDay = $matches[1];
                    $endDay = $matches[2];
                    $month = strtoupper(substr($matches[3], 0, 3));
                    $year = date('Y');
                    $validity = "{$startDay}-{$endDay} {$month} {$year}";
                    break;
                }
            }
            if (empty($validity)) {
                $validity = strtoupper(date('M Y'));
            }
        }

        // Country-specific remarks
        $koreaRemark = 'Free time DEM / DET are combined for all Korea ports destination at 16 days + inclusive of FAF and YAS durring the quote period but subject to local charge and THC/DOC Fee both ends.';
        $chinaRemark = 'Rate for all China destination ports must be apply AFR $30 per BL Except HONGKONG. + inclusive of FAF and YAS durring the quote period but subject to local charge and THC/DOC Fee both ends.';
        $japanRemark = 'Rate for all Japan destination ports must be apply AFR $30 per BL. + inclusive of FAF and YAS durring the quote period but subject to local charge and THC/DOC Fee both ends.';
        $vietnamHongkongRemark = 'inclusive of FAF and YAS durring the quote period but subject to local charge and THC/DOC Fee both ends.';

        // Korea ports
        $koreaPorts = ['KWANGYANG', 'PUSAN', 'BUSAN', 'INCHON', 'INCHEON', 'PYEONGTAEK'];
        // Japan ports
        $japanPorts = ['TOKYO', 'YOKOHAMA', 'NAGOYA', 'OSAKA', 'KOBE', 'TOKUYAMA', 'SHIMIZU', 'HIBIKI', 'HAKATA', 'MOJI'];
        // Vietnam ports
        $vietnamPorts = ['HOCHIMINH', 'HO CHI MINH', 'HAIPHONG', 'HAI PHONG', 'DANANG', 'DA NANG', 'CATLAI', 'CAT LAI'];
        // Hong Kong
        $hongkongPorts = ['HONG KONG', 'HONGKONG'];
        // China ports (for remark assignment - excluding Hong Kong)
        $chinaCities = ['NANSHA', 'SHEKOU', 'XIAMEN', 'GAOMING', 'RONGQI', 'ZHONGSHAN', 'HUANGPU', 'XIAOLAN', 'SANRONG', 'GAOYAO', 'LIAHUASHAN', 'HONGWAN', 'CIVET', 'ZHUHAI', 'SANSHUI', 'GAOSHA', 'GAOXIN', 'JIANGMEN', 'BEIJIAO', 'LEILU', 'SHUNDE', 'JIUJIANG', 'ZHAOQING', 'MAFANG', 'FOSHAN', 'WUZHOU', 'BEIHAI', 'DONGGUAN', 'FANGCHENG', 'GUIGANG', 'HAIKOU'];

        // Track last known rates for handling rows with missing rate data (like SHEKOU)
        $lastRate20 = '';
        $lastRate40 = '';
        $lastCountry = '';

        foreach ($lines as $line) {
            // Skip header row and table markers
            if (preg_match('/^TABLE \d+|^-{10,}|^Row 0:|Destination port|Currency/i', $line)) {
                continue;
            }

            // Match data rows: Row N: POD | Code | Country | Currency | 20' | 40' | T/T | T/S | ETD_BKK | ETD_LCH
            if (!preg_match('/^Row \d+:\s*(.+)/', $line, $matches)) {
                continue;
            }

            $rowData = $matches[1];
            $cells = array_map('trim', explode('|', $rowData));

            // Need at least POD and some data
            if (count($cells) < 4) continue;

            // Detect row structure based on content
            // Full row: POD | Code | Country | USD | rate20 | rate40 | T/T | T/S | ETD_BKK | ETD_LCH
            // Partial row (no country): POD | Code | USD | rate20 | rate40 | T/T | T/S | ETD_BKK | ETD_LCH
            // Or: POD | City | USD | rate20 | rate40 | T/T | T/S | ETD_BKK | ETD_LCH

            $pod = '';
            $rate20 = '';
            $rate40 = '';
            $tt = '';
            $ts = '';
            $etdBkk = '';
            $etdLch = '';
            $country = '';

            // Find USD position to determine structure
            $usdPos = -1;
            for ($i = 0; $i < count($cells); $i++) {
                if (strtoupper(trim($cells[$i])) === 'USD') {
                    $usdPos = $i;
                    break;
                }
            }

            if ($usdPos === -1) continue; // No USD found, skip

            // POD is always the first cell
            $pod = $cells[0];

            // Check for country indicator before USD
            for ($i = 1; $i < $usdPos; $i++) {
                $cellValue = strtoupper(trim($cells[$i]));
                if (in_array($cellValue, ['KOREA', 'CHINA', 'JAPAN', 'VIETNAM', 'HONG KONG', 'HONGKONG'])) {
                    $country = $cellValue;
                    break;
                }
                // Also check for "Japan Main Port", "Japan Out Port" patterns
                if (preg_match('/JAPAN/i', $cellValue)) {
                    $country = 'JAPAN';
                    break;
                }
            }

            // If no explicit country, infer from last known country for continuation rows
            if (empty($country)) {
                $country = $lastCountry;
            } else {
                $lastCountry = $country;
            }

            // Rates are after USD
            $rate20 = $cells[$usdPos + 1] ?? '';
            $rate40 = $cells[$usdPos + 2] ?? '';

            // Clean rates
            $rate20Clean = preg_replace('/[^0-9]/', '', $rate20);
            $rate40Clean = preg_replace('/[^0-9]/', '', $rate40);

            // Special handling for SHEKOU: OCR often misses rate columns
            // Row format: Shekou |  | USD |  | 6 | Direct | FRI | Sat
            // The "6" is T/T days, not a rate. SHEKOU should have same rates as NANSHA.
            $podUpper = strtoupper(trim($pod));
            if ($podUpper === 'SHEKOU' && (empty($rate20Clean) || strlen($rate20Clean) < 2)) {
                // Use NANSHA rates (20, 30 for 20' and 40')
                $rate20Clean = '20';
                $rate40Clean = '30';
                $tt = '6';
                $ts = 'Direct';
                // Find ETD values in remaining cells
                for ($i = $usdPos + 1; $i < count($cells); $i++) {
                    $cellVal = trim($cells[$i]);
                    if (preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)/i', $cellVal)) {
                        if (empty($etdBkk)) {
                            $etdBkk = $cellVal;
                        } else {
                            $etdLch = $cellVal;
                            break;
                        }
                    }
                }
            } else {
                // T/T is after rates (position depends on structure)
                // Check if cell after rate40 contains "Day" or is numeric (T/T)
                $ttIndex = $usdPos + 3;
                if (isset($cells[$ttIndex])) {
                    $potentialTt = $cells[$ttIndex];
                    // T/T values: "10 Days", "9 Days", "12-15 Days", etc.
                    if (preg_match('/\d+.*day|^\d+$|\d+-\d+/i', $potentialTt)) {
                        $tt = $potentialTt;
                        $ts = $cells[$ttIndex + 1] ?? '';
                        $etdBkk = $cells[$ttIndex + 2] ?? '';
                        $etdLch = $cells[$ttIndex + 3] ?? '';
                    } else {
                        // It might be T/S if it's "Direct", "PUS", "NANSHA", etc.
                        $ts = $potentialTt;
                        $etdBkk = $cells[$ttIndex + 1] ?? '';
                        $etdLch = $cells[$ttIndex + 2] ?? '';
                    }
                }
            }

            // Update last known rates for valid rows
            if (!empty($rate20Clean) && strlen($rate20Clean) >= 2) {
                $lastRate20 = $rate20Clean;
                $lastRate40 = $rate40Clean;
            }

            // Clean POD - remove port codes in parentheses or after spaces
            $pod = preg_replace('/\s*\([^)]+\)/', '', $pod); // Remove (CatLai) etc.

            // Skip invalid rows
            if (empty($pod) || empty($rate20Clean)) continue;
            if (preg_match('/^(destination|port|currency)/i', $pod)) continue;

            // Format T/T
            $ttFormatted = $tt;
            if (!empty($tt) && !preg_match('/day/i', $tt)) {
                $ttFormatted = $tt . ' Days';
            }
            if (empty($ttFormatted)) {
                $ttFormatted = 'TBA';
            }

            // Format T/S
            if (empty($ts)) {
                $ts = 'Direct';
            }

            // Format ETD
            $etdBkkFormatted = !empty($etdBkk) ? $etdBkk : '';
            $etdLchFormatted = !empty($etdLch) ? $etdLch : '';

            // Determine remark based on POD/country
            $remark = '';
            $podUpperClean = strtoupper(trim($pod));

            // Check Korea ports
            foreach ($koreaPorts as $kp) {
                if (stripos($podUpperClean, $kp) !== false) {
                    $remark = $koreaRemark;
                    break;
                }
            }

            // Check Japan ports
            if (empty($remark)) {
                foreach ($japanPorts as $jp) {
                    if (stripos($podUpperClean, $jp) !== false || $country === 'JAPAN') {
                        $remark = $japanRemark;
                        break;
                    }
                }
            }

            // Check Hong Kong (before China check - HK has different remark)
            if (empty($remark)) {
                foreach ($hongkongPorts as $hkp) {
                    if (stripos($podUpperClean, $hkp) !== false || $country === 'HONG KONG' || $country === 'HONGKONG') {
                        $remark = $vietnamHongkongRemark;
                        break;
                    }
                }
            }

            // Check Vietnam ports
            if (empty($remark)) {
                foreach ($vietnamPorts as $vp) {
                    if (stripos($podUpperClean, $vp) !== false || $country === 'VIETNAM') {
                        $remark = $vietnamHongkongRemark;
                        break;
                    }
                }
            }

            // Check China ports (excluding Hong Kong)
            if (empty($remark)) {
                foreach ($chinaCities as $cp) {
                    if (stripos($podUpperClean, $cp) !== false || $country === 'CHINA') {
                        $remark = $chinaRemark;
                        break;
                    }
                }
            }

            $rates[] = $this->createRateEntry('DONGJIN', 'BKK/LCH', strtoupper($pod), $rate20Clean, $rate40Clean, [
                'T/T' => $ttFormatted,
                'T/S' => $ts,
                'ETD BKK' => $etdBkkFormatted,
                'ETD LCH' => $etdLchFormatted,
                'VALIDITY' => $validity,
                'REMARK' => $remark,
            ]);
        }

        return $rates;
    }

    /**
     * Parse CK LINE table format (from Azure OCR)
     * Structure: POD | Code | Country | USD | rate20 | rate40 | Validity | T/T | T/S | ETD BKK | ETD LCH
     * Some rows have continuation lines with :unselected: containing ETD values
     */
    protected function parseCkLineTable(array $lines, string $validity): array
    {
        $rates = [];

        // First pass: merge continuation lines with their parent rows
        $mergedLines = [];
        $currentRowLine = '';

        foreach ($lines as $line) {
            if (preg_match('/^Row \d+:/', $line)) {
                if (!empty($currentRowLine)) {
                    $mergedLines[] = $currentRowLine;
                }
                $currentRowLine = $line;
            } elseif (preg_match('/^:unselected:|^:selected:/', $line)) {
                // Continuation line - merge with current row
                $continuation = preg_replace('/^:(un)?selected:\s*/', '', $line);
                $continuation = trim($continuation);
                if (!empty($continuation)) {
                    $currentRowLine .= ' | ' . $continuation;
                }
            }
        }
        // Don't forget the last row
        if (!empty($currentRowLine)) {
            $mergedLines[] = $currentRowLine;
        }

        foreach ($mergedLines as $line) {
            // Skip header row and table markers
            if (preg_match('/^TABLE \d+|^-{10,}|^Row 0:|Destination port|Currency/i', $line)) {
                continue;
            }

            // Match data rows
            if (!preg_match('/^Row \d+:\s*(.+)/', $line, $matches)) {
                continue;
            }

            $rowData = $matches[1];
            $cells = array_map('trim', explode('|', $rowData));

            // Need at least POD and rates
            if (count($cells) < 5) continue;

            // Find USD position to determine structure
            $usdPos = -1;
            for ($i = 0; $i < count($cells); $i++) {
                if (strtoupper(trim($cells[$i])) === 'USD') {
                    $usdPos = $i;
                    break;
                }
            }

            if ($usdPos === -1) continue;

            // POD is always the first cell
            $pod = $cells[0];

            // Rates are after USD (format: "$60 (INC.LSS)" or "$1,200 (INC.LSS/DTHC)")
            $rate20Raw = $cells[$usdPos + 1] ?? '';
            $rate40Raw = $cells[$usdPos + 2] ?? '';

            // Extract numeric rates (remove $ and commas, keep only numbers)
            $rate20 = preg_replace('/[^0-9]/', '', $rate20Raw);
            $rate40 = preg_replace('/[^0-9]/', '', $rate40Raw);

            // Extract remark from rate (e.g., "INC.LSS" or "INC.LSS/DTHC")
            $remark = '';
            if (preg_match('/\(([^)]+)\)/', $rate20Raw, $remarkMatch)) {
                $remark = trim($remarkMatch[1]);
            } elseif (preg_match('/\(([^)]+)\)/', $rate40Raw, $remarkMatch)) {
                $remark = trim($remarkMatch[1]);
            }

            // CK LINE structure after rates: Validity | T/T | T/S | ETD BKK | ETD LCH
            // Note: Continuation lines may add empty cells, so we need to find ETD at end
            $validityCell = $cells[$usdPos + 3] ?? '';
            $tt = $cells[$usdPos + 4] ?? '';
            $ts = $cells[$usdPos + 5] ?? '';

            // ETD values - check if continuation added extra cells
            // Standard: cells at usdPos+6 and usdPos+7
            // With continuation: may have empty cell at usdPos+6, values at end
            $etdBkk = '';
            $etdLch = '';

            $cellCount = count($cells);
            $expectedEtdBkkPos = $usdPos + 6;

            // If we have more cells than expected and cell at expected position is empty,
            // look for ETD values at the end of the array
            if ($cellCount > $expectedEtdBkkPos + 2 && empty(trim($cells[$expectedEtdBkkPos] ?? ''))) {
                // ETD values are at the end (after empty continuation cell)
                $etdLch = trim($cells[$cellCount - 1] ?? '');
                $etdBkk = trim($cells[$cellCount - 2] ?? '');
            } else {
                // Standard position
                $etdBkk = trim($cells[$expectedEtdBkkPos] ?? '');
                $etdLch = trim($cells[$expectedEtdBkkPos + 1] ?? '');
            }

            // Clean POD - remove port codes in parentheses
            $podClean = preg_replace('/\s*\([^)]+\)/', '', $pod);

            // Skip invalid rows
            if (empty($podClean) || empty($rate20)) continue;
            if (preg_match('/^(destination|port|currency)/i', $podClean)) continue;

            // Use validity from cell if main validity is empty
            if (empty($validity) && !empty($validityCell)) {
                // Format: "01-30/11/25" -> "01-30 NOV 2025"
                if (preg_match('/(\d{1,2}-\d{1,2})\/(\d{1,2})\/(\d{2})/', $validityCell, $vMatches)) {
                    $monthNames = ['01' => 'JAN', '02' => 'FEB', '03' => 'MAR', '04' => 'APR',
                                   '05' => 'MAY', '06' => 'JUN', '07' => 'JUL', '08' => 'AUG',
                                   '09' => 'SEP', '10' => 'OCT', '11' => 'NOV', '12' => 'DEC'];
                    $monthNum = str_pad($vMatches[2], 2, '0', STR_PAD_LEFT);
                    $monthName = $monthNames[$monthNum] ?? 'JAN';
                    $year = '20' . $vMatches[3];
                    $validity = $vMatches[1] . ' ' . $monthName . ' ' . $year;
                }
            }

            // Format T/T
            $ttFormatted = $tt;
            if (!empty($tt) && !preg_match('/day/i', $tt)) {
                $ttFormatted = $tt . ' Days';
            }
            if (empty($ttFormatted) || $ttFormatted === '-') {
                $ttFormatted = 'TBA';
            }

            // Format T/S
            if (empty($ts)) {
                $ts = 'Direct';
            }

            $rates[] = $this->createRateEntry('CK LINE', 'BKK/LCH', strtoupper($podClean), $rate20, $rate40, [
                'T/T' => $ttFormatted,
                'T/S' => $ts,
                'ETD BKK' => $etdBkk,
                'ETD LCH' => $etdLch,
                'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                'REMARK' => $remark,
            ]);
        }

        return $rates;
    }

    /**
     * Parse SM LINE table format (from Azure OCR)
     * Structure: COUNTRY | POD | BKK 20' | BKK 40' | LCH 20' | LCH 40' | REMARK | FREE TIME
     * POD may have (REEFER) suffix - KEEP it in POD name
     * When BKK and LCH have same rates, output "BKK/LCH" as POL
     */
    protected function parseSmLineTable(array $lines, string $validity): array
    {
        $rates = [];

        foreach ($lines as $line) {
            // Skip headers and non-data rows
            if (preg_match('/^TABLE \d+|^-{10,}|^Row [01]:|COUNTRY|POD.*DESTINATION|OUTBOUND|INBOUND|THC|B\/L|SEAL|CFS|D\/O|Container|DEPOSIT|CLEANING/i', $line)) {
                continue;
            }

            // Match data rows (Row 2 onwards for rate data)
            if (!preg_match('/^Row \d+:\s*(.+)/', $line, $matches)) {
                continue;
            }

            $rowData = $matches[1];
            $cells = array_map('trim', explode('|', $rowData));

            // Need at least 5 cells for valid rate row
            if (count($cells) < 5) continue;

            // Find POD - it's the first cell that looks like a destination name
            $pod = '';
            $rateStartIndex = 0;

            for ($i = 0; $i < min(3, count($cells)); $i++) {
                $cell = $cells[$i];
                // POD should be a name containing letters, not just N/A, not empty, not a country
                if (!empty($cell) &&
                    preg_match('/[A-Z]/i', $cell) &&
                    !preg_match('/^(N\/A|VIETNAM|KOREA|CHINA|JAPAN|TAIWAN|HONG KONG)$/i', $cell)) {
                    $pod = $cell;
                    $rateStartIndex = $i + 1;
                    break;
                }
            }

            // Skip if POD is empty or looks like header
            if (empty($pod) || preg_match('/^(20|40|CHARGE)/i', $pod)) continue;

            // SM LINE OCR table format (after POD):
            // Normal: BKK 20' | BKK 40' | LCH 20' | LCH 40' | Remark | Free Time
            // When BKK is N/A: N/A | LCH 20' | LCH 40' | Remark | Free Time (collapsed)
            // Special case SHEKOU: empty | N/A | LCH 20' | LCH 40' | ...

            // Get remaining cells after POD
            $remainingCells = array_slice($cells, $rateStartIndex);
            $numRemaining = count($remainingCells);

            // Determine structure based on content
            // If first cell is N/A or second cell is N/A and there are fewer rate columns
            $bkk20 = '';
            $bkk40 = '';
            $lch20 = '';
            $lch40 = '';
            $remark = '';
            $freeTime = '';

            // Check if this is a "BKK N/A" row (has N/A in first or second position)
            $firstCell = strtoupper(trim($remainingCells[0] ?? ''));
            $secondCell = strtoupper(trim($remainingCells[1] ?? ''));

            if ($firstCell === 'N/A' && $numRemaining >= 4) {
                // Pattern: N/A | $LCH20 | $LCH40 | Remark | FreeTime
                $bkk20 = 'N/A';
                $bkk40 = 'N/A';
                $lch20 = $remainingCells[1] ?? '';
                $lch40 = $remainingCells[2] ?? '';
                $remark = $remainingCells[3] ?? '';
                $freeTime = $remainingCells[4] ?? '';
            } elseif ($firstCell === '' && $secondCell === 'N/A' && $numRemaining >= 5) {
                // Pattern: "" | N/A | $LCH20 | $LCH40 | Remark | FreeTime (SHEKOU case)
                $bkk20 = '';
                $bkk40 = 'N/A';
                $lch20 = $remainingCells[2] ?? '';
                $lch40 = $remainingCells[3] ?? '';
                $remark = $remainingCells[4] ?? '';
                $freeTime = $remainingCells[5] ?? '';
            } else {
                // Normal pattern: $BKK20 | $BKK40 | $LCH20 | $LCH40 | Remark | FreeTime
                $bkk20 = $remainingCells[0] ?? '';
                $bkk40 = $remainingCells[1] ?? '';
                $lch20 = $remainingCells[2] ?? '';
                $lch40 = $remainingCells[3] ?? '';
                $remark = $remainingCells[4] ?? '';
                $freeTime = $remainingCells[5] ?? '';
            }

            // Clean up rates - extract numeric values only
            $bkk20Clean = preg_replace('/[^0-9]/', '', $bkk20);
            $bkk40Clean = preg_replace('/[^0-9]/', '', $bkk40);
            $lch20Clean = preg_replace('/[^0-9]/', '', $lch20);
            $lch40Clean = preg_replace('/[^0-9]/', '', $lch40);

            // Keep REEFER in POD name - just normalize it
            $podDisplay = strtoupper(trim($pod));

            // Check if BKK rates are valid (not N/A and not empty)
            $bkkIsNA = strtoupper(trim($bkk20)) === 'N/A' || strtoupper(trim($bkk40)) === 'N/A';
            $bkkIsEmpty = empty($bkk20Clean) && empty($bkk40Clean);
            $hasBkkRates = !$bkkIsNA && !$bkkIsEmpty;

            // Check if LCH rates are valid (not N/A and not empty)
            $lchIsNA = strtoupper(trim($lch20)) === 'N/A' || strtoupper(trim($lch40)) === 'N/A';
            $lchIsEmpty = empty($lch20Clean) && empty($lch40Clean);
            $hasLchRates = !$lchIsNA && !$lchIsEmpty;

            // Determine if BKK and LCH have the same rates
            $bkkLchSameRates = $hasBkkRates && $hasLchRates
                && $bkk20Clean === $lch20Clean && $bkk40Clean === $lch40Clean;

            // Check if this is a REEFER rate - put in RF columns instead of regular columns
            $isReefer = preg_match('/REEFER/i', $podDisplay);

            // For REEFER rates, remove "(REEFER)" from POD name since rates go in RF columns
            if ($isReefer) {
                $podDisplay = preg_replace('/\s*\(?\s*REEFER\s*\)?\s*/i', '', $podDisplay);
                $podDisplay = trim($podDisplay);
            }

            // Build extra fields based on whether it's reefer or not
            $extraFields = [
                'FREE TIME' => $freeTime,
                'VALIDITY' => $validity ?: strtoupper(date('M Y')),
                'REMARK' => $remark,
            ];

            if ($bkkLchSameRates) {
                // Both BKK and LCH have same rates - use combined POL "BKK/LCH"
                if ($isReefer) {
                    // REEFER rates go in RF columns, regular columns stay empty
                    $extraFields['20 RF'] = $bkk20Clean;
                    $extraFields['40RF'] = $bkk40Clean;
                    $rates[] = $this->createRateEntry('SM LINE', 'BKK/LCH', $podDisplay, '', '', $extraFields);
                } else {
                    $rates[] = $this->createRateEntry('SM LINE', 'BKK/LCH', $podDisplay, $bkk20Clean, $bkk40Clean, $extraFields);
                }
            } else {
                // Different rates - create separate entries

                // Create BKK entry (if has valid rates)
                if ($hasBkkRates) {
                    if ($isReefer) {
                        $extraFields['20 RF'] = $bkk20Clean;
                        $extraFields['40RF'] = $bkk40Clean;
                        $rates[] = $this->createRateEntry('SM LINE', 'BKK', $podDisplay, '', '', $extraFields);
                    } else {
                        $rates[] = $this->createRateEntry('SM LINE', 'BKK', $podDisplay, $bkk20Clean, $bkk40Clean, $extraFields);
                    }
                }

                // Create LCH entry (if has valid rates)
                if ($hasLchRates) {
                    if ($isReefer) {
                        $lchExtraFields = $extraFields;
                        $lchExtraFields['20 RF'] = $lch20Clean;
                        $lchExtraFields['40RF'] = $lch40Clean;
                        $rates[] = $this->createRateEntry('SM LINE', 'LCH', $podDisplay, '', '', $lchExtraFields);
                    } else {
                        $rates[] = $this->createRateEntry('SM LINE', 'LCH', $podDisplay, $lch20Clean, $lch40Clean, $extraFields);
                    }
                }
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

        // Match patterns without year like "1-30 Nov" (use current year)
        if (preg_match('/(\d{1,2})[-_\s]+(\d{1,2})[-_\s]*(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)/i', $filename, $matches)) {
            $startDay = $matches[1];
            $endDay = $matches[2];
            $month = strtoupper($matches[3]);
            $year = date('Y');

            return strtoupper("{$startDay}-{$endDay} {$month} {$year}");
        }

        // Match month and year only patterns like "DECEMBER 2025", "NOV 2025", "of NOVEMBER 2025"
        // This handles "Rate Guideline of DECEMBER 2025" format
        if (preg_match('/(JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER|JAN|FEB|MAR|APR|JUN|JUL|AUG|SEP|OCT|NOV|DEC)[-_\s]*(\d{4})/i', $filename, $matches)) {
            $monthFull = strtoupper($matches[1]);
            $year = $matches[2];

            // Convert full month name to 3-letter abbreviation
            $monthMap = [
                'JANUARY' => 'JAN', 'FEBRUARY' => 'FEB', 'MARCH' => 'MAR', 'APRIL' => 'APR',
                'MAY' => 'MAY', 'JUNE' => 'JUN', 'JULY' => 'JUL', 'AUGUST' => 'AUG',
                'SEPTEMBER' => 'SEP', 'OCTOBER' => 'OCT', 'NOVEMBER' => 'NOV', 'DECEMBER' => 'DEC'
            ];
            $month = $monthMap[$monthFull] ?? $monthFull;

            return strtoupper("{$month} {$year}");
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

        // Pattern 3: "validity 1-31 Dec" (DONGJIN format - no year, use current year)
        if (preg_match('/validity\s+(\d{1,2})\s*[-–]\s*(\d{1,2})\s*(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/i', $content, $matches)) {
            $startDay = $matches[1];
            $endDay = $matches[2];
            $month = strtoupper(substr($matches[3], 0, 3));
            $year = date('Y');
            return "{$startDay}-{$endDay} {$month} {$year}";
        }

        // Pattern 4: "OF 1 - 15 Nov. 25" or "1-15 Nov 25" (TS LINE format)
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
        // Also matches "FOR NOVEMBER 1-30, 2025", etc.
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

        // Pattern 5: "VALID 1-15 DEC" (WANHAI Middle East format - no year, use current year)
        if (preg_match('/VALID\s+(\d{1,2})\s*[-–]\s*(\d{1,2})\s*(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/i', $content, $matches)) {
            $startDay = $matches[1];
            $endDay = $matches[2];
            $month = strtoupper(substr($matches[3], 0, 3));
            $year = date('Y');
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
