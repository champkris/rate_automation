<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ini_set('memory_limit', '1024M');
set_time_limit(600);

// Helper function to format validity date
function formatValidity($validityRaw) {
    // Default return if invalid
    if (empty($validityRaw)) {
        return 'DEC 2025';
    }

    // Month names
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    // Check if it's a date range (e.g., "01/11-15/11" or "01/11-30/11/2025")
    if (strpos($validityRaw, '-') !== false) {
        // Split by "-" to get the end date
        $parts = explode('-', $validityRaw);
        $endDate = trim($parts[1]);

        // Parse end date: could be "15/11" or "30/11/2025"
        $dateParts = explode('/', $endDate);

        if (count($dateParts) >= 2) {
            $day = intval($dateParts[0]);
            $month = intval($dateParts[1]);
            $year = isset($dateParts[2]) ? intval($dateParts[2]) : 2025;

            // Validate month
            if ($month >= 1 && $month <= 12) {
                $monthName = $months[$month - 1];
                return sprintf('%02d %s %d', $day, $monthName, $year);
            }
        }
    }

    // If not a range or parsing failed, return original
    return $validityRaw;
}

echo str_repeat('=', 100) . "\n";
echo "PROCESSING FINAL ATTACHMENTS FROM /docs/attachmnts\n";
echo str_repeat('=', 100) . "\n\n";

// FCL_EXP format columns
$headers = [
    'CARRIER', 'POL', 'POD', 'CUR', "20'", "40'", '40 HQ', '20 TC', '20 RF', '40RF',
    'ETD BKK', 'ETD LCH', 'T/T', 'T/S', 'FREE TIME', 'VALIDITY', 'REMARK',
    'Export', 'Who use?', 'Rate Adjust', '1.1'
];

$allRates = [];
$attachmentsDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/attachments/';

// Step 1: Process Excel files
echo "Step 1: Processing Excel files...\n";
$excelFiles = array_merge(
    glob($attachmentsDir . '*.xlsx'),
    glob($attachmentsDir . '*.xls')
);

echo "  Found " . count($excelFiles) . " Excel file(s)\n\n";

foreach ($excelFiles as $excelFile) {
    $filename = basename($excelFile);

    // Skip temporary Excel files (start with ~$)
    if (strpos($filename, '~$') === 0) {
        continue;
    }

    echo "  Processing: $filename\n";

    // Determine carrier from filename
    $carrier = '';
    if (preg_match('/FAK Rate of 1-30/i', $filename)) {
        $carrier = 'RCL';
    } elseif (preg_match('/FAK Rate of 1-15/i', $filename)) {
        $carrier = 'RCL';
    } elseif (preg_match('/UPDATED RATE/i', $filename)) {
        $carrier = 'KMTC';
    }

    // Load spreadsheet
    $spreadsheet = IOFactory::load($excelFile);
    $worksheet = $spreadsheet->getActiveSheet();
    $highestRow = $worksheet->getHighestDataRow();

    echo "    Carrier: $carrier, Rows: $highestRow\n";

    // Read data rows - different logic for KMTC vs RCL
    $ratesAdded = 0;

    if ($carrier === 'KMTC') {
        // KMTC format: starts at row 6
        // B=Country/POD, C=POL, D=POD Area, E=20'GP, F=40'HC, J=Freetime (DEM/DET)
        for ($row = 6; $row <= $highestRow; $row++) {
            $country = trim($worksheet->getCell('B' . $row)->getValue() ?? '');
            $pol = trim($worksheet->getCell('C' . $row)->getValue() ?? '');
            $podArea = trim($worksheet->getCell('D' . $row)->getValue() ?? '');
            $rate20 = trim($worksheet->getCell('E' . $row)->getValue() ?? '');
            $rate40 = trim($worksheet->getCell('F' . $row)->getValue() ?? '');
            $freeTime = trim($worksheet->getCell('J' . $row)->getValue() ?? '');

            // Skip empty rows
            if (empty($podArea) && empty($rate20) && empty($rate40)) {
                continue;
            }

            // Default free time if empty
            if (empty($freeTime)) {
                $freeTime = 'TBA';
            }

            // Create rate entry
            $rate = [
                'CARRIER' => $carrier,
                'POL' => $pol ?: 'BKK/LCH',
                'POD' => $podArea,
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
                'FREE TIME' => $freeTime,
                'VALIDITY' => 'DEC 2025',
                'REMARK' => $country,
                'Export' => '',
                'Who use?' => '',
                'Rate Adjust' => '',
                '1.1' => ''
            ];

            if (!empty($podArea)) {
                $allRates[] = $rate;
                $ratesAdded++;
            }
        }
    } else {
        // RCL format: starts at row 10, columns B (POD), D (POL), F (ETD LCH), G (20'), H (40'), I (T/S), J (T/T), K (FREE TIME)
        // Header is at row 9: Country | Port of Discharge | POD code | POL | Service | ETD | 20'GP | 40'HC | T/S | T/T | FREE TIME | ...
        // Note: Columns D, F, G, H, I, J, K may have merged cells that need to be handled

        // Extract VALIDITY from cell B6
        $validityRaw = trim($worksheet->getCell('B6')->getValue() ?? 'DEC 2025');

        // Convert validity to readable format (e.g., "01/11-15/11" -> "15 Nov 2025")
        $validity = formatValidity($validityRaw);

        // First, build a map of merged cell values
        $mergedCellValues = [];
        foreach ($worksheet->getMergeCells() as $mergeRange) {
            // Get the top-left cell value (the actual value of merged cells)
            $startCell = explode(':', $mergeRange)[0];
            $cellValue = $worksheet->getCell($startCell)->getCalculatedValue();

            // Parse the range to get all cells in the merge
            list($startCol, $startRow) = sscanf($startCell, '%[A-Z]%d');
            list($endCell) = array_slice(explode(':', $mergeRange), -1);
            list($endCol, $endRow) = sscanf($endCell, '%[A-Z]%d');

            // Store the value for all rows in the merged range
            for ($r = $startRow; $r <= $endRow; $r++) {
                $mergedCellValues[$startCol . $r] = $cellValue;
            }
        }

        // Helper function to get cell value considering merged cells
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
            $podCode = trim($getCellValue('C', $row) ?? '');
            $pol = trim($getCellValue('D', $row) ?? '');
            $etdColumnF = trim($getCellValue('F', $row) ?? '');  // ETD from column F
            $ts = trim($getCellValue('I', $row) ?? '');   // T/S (Transshipment)
            $tt = trim($getCellValue('J', $row) ?? '');   // T/T (Transit Time)
            $freeTime = trim($getCellValue('K', $row) ?? '');  // FREE TIME from column K
            $remarkColumnL = trim($getCellValue('L', $row) ?? '');  // REMARK from column L

            // Check if this row has black highlighting
            $isBlackRow = false;
            $cellStyle = $worksheet->getCell('B' . $row)->getStyle();
            $fill = $cellStyle->getFill();
            $fillType = $fill->getFillType();

            // Only check color if there's actually a fill (not 'none')
            if ($fillType !== \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE) {
                $fillColor = strtoupper($fill->getStartColor()->getRGB());
                // Check for pure black (000000) or dark gray (333333)
                if (in_array($fillColor, ['000000', '333333'])) {
                    $isBlackRow = true;
                }
            }

            // Add "Days" suffix to T/T if not empty
            if (!empty($tt)) {
                $tt .= ' Days';
            } else {
                $tt = 'TBA';
            }

            // Set TBA for empty T/S and Free Time
            if (empty($ts)) {
                $ts = 'TBA';
            }
            if (empty($freeTime)) {
                $freeTime = 'TBA';
            }

            $rate20 = $getCellValue('G', $row);
            $rate40 = $getCellValue('H', $row);

            // Clean up rate values (remove formulas, get calculated value)
            $rate20 = is_numeric($rate20) ? trim($rate20) : '';
            $rate40 = is_numeric($rate40) ? trim($rate40) : '';

            // Skip rows where POD is empty or rates are both empty
            if (empty($pod) || (empty($rate20) && empty($rate40))) {
                continue;
            }

            // Skip rows with formulas that result in 0 or headers
            if ($rate20 == 0 && $rate40 == 0) {
                continue;
            }

            // Process ETD dates from column F
            $etdBkk = '';
            $etdLch = '';
            $remark = $remarkColumnL;  // Start with value from column L

            if (!empty($etdColumnF)) {
                // Check for SSW - append to REMARK if exists
                if (stripos($etdColumnF, 'SSW') !== false) {
                    if (!empty($remark)) {
                        $remark .= ' / SSW';
                    } else {
                        $remark = 'SSW';
                    }
                }

                // Split by common delimiters to detect multiple dates
                $dates = preg_split('/[\n\r\/,]+/', $etdColumnF);
                $dates = array_map('trim', $dates);
                $dates = array_filter($dates); // Remove empty values

                if (count($dates) >= 2) {
                    // Multiple dates found
                    // Collect dates for BKK and LCH separately
                    // A date can belong to both if it contains both indicators
                    $bkkDates = [];
                    $lchDates = [];

                    foreach ($dates as $date) {
                        $hasBkk = preg_match('/(PAT|BKK)/i', $date);
                        $hasLch = preg_match('/LCH/i', $date);
                        $hasSSW = preg_match('/SSW/i', $date);

                        // Skip if it's SSW only (no BKK/PAT or LCH indicator)
                        if ($hasSSW && !$hasBkk && !$hasLch) {
                            continue;
                        }

                        if ($hasBkk && $hasLch) {
                            // Date has both BKK and LCH indicators (may also have SSW)
                            // Extract just the day name (e.g., "MON" from "MON (BKK PAT & LCH)")
                            if (preg_match('/^([A-Z]{3})/i', $date, $matches)) {
                                $dayName = $matches[1];
                                $bkkDates[] = $dayName;
                                $lchDates[] = $dayName;
                            } else {
                                $bkkDates[] = $date;
                                $lchDates[] = $date;
                            }
                        } elseif ($hasBkk) {
                            // Only BKK/PAT indicator (may also have SSW)
                            if (preg_match('/^([A-Z]{3})/i', $date, $matches)) {
                                $bkkDates[] = $matches[1];
                            } else {
                                $bkkDates[] = $date;
                            }
                        } elseif ($hasLch) {
                            // Only LCH indicator (may also have SSW)
                            if (preg_match('/^([A-Z]{3})/i', $date, $matches)) {
                                $lchDates[] = $matches[1];
                            } else {
                                $lchDates[] = $date;
                            }
                        } else {
                            // No specific indicator - default to LCH
                            if (preg_match('/^([A-Z]{3})/i', $date, $matches)) {
                                $lchDates[] = $matches[1];
                            } else {
                                $lchDates[] = $date;
                            }
                        }
                    }

                    // Join dates with "/"
                    $etdBkk = !empty($bkkDates) ? implode('/', $bkkDates) : '';
                    $etdLch = !empty($lchDates) ? implode('/', $lchDates) : '';
                } elseif (count($dates) === 1) {
                    // Single date found
                    $singleDate = $dates[0];
                    $hasSSW = preg_match('/SSW/i', $singleDate);

                    // If it's SSW only, skip ETD processing
                    if ($hasSSW && stripos($singleDate, 'LCH') === false && stripos($singleDate, 'BKK') === false && stripos($singleDate, 'PAT') === false) {
                        // Just SSW, no ETD dates
                    } else {
                        // Check if date has LCH or BKK indicators
                        $hasLch = preg_match('/LCH/i', $singleDate);
                        $hasBkk = preg_match('/(PAT|BKK)/i', $singleDate);

                        if ($hasLch && $hasBkk) {
                            // Has both - extract to both columns
                            if (preg_match('/^([A-Z]{3})/i', $singleDate, $matches)) {
                                $etdBkk = $matches[1];
                                $etdLch = $matches[1];
                            }
                        } elseif ($hasLch) {
                            // Has LCH only
                            if (preg_match('/^([A-Z]{3})/i', $singleDate, $matches)) {
                                $etdLch = $matches[1];
                            } else {
                                $etdLch = $singleDate;
                            }
                        } elseif ($hasBkk) {
                            // Has BKK only
                            if (preg_match('/^([A-Z]{3})/i', $singleDate, $matches)) {
                                $etdBkk = $matches[1];
                            } else {
                                $etdBkk = $singleDate;
                            }
                        } else {
                            // Check POL to determine which ETD column
                            if (stripos($pol, 'LCH') !== false || stripos($pol, 'LAEM CHABANG') !== false) {
                                $etdLch = $singleDate;
                            } elseif (stripos($pol, 'BKK') !== false || stripos($pol, 'BANGKOK') !== false) {
                                $etdBkk = $singleDate;
                            } else {
                                // Default to LCH if POL is ambiguous
                                $etdLch = $singleDate;
                            }
                        }
                    }
                }
            }

            // Create rate entry
            // If this is a black highlighted row, replace all rate values with TBA
            if ($isBlackRow) {
                $rate20 = 'TBA';
                $rate40 = 'TBA';
                $etdBkk = 'TBA';
                $etdLch = 'TBA';
                $tt = 'TBA';
                $ts = 'TBA';
                $freeTime = 'TBA';
            }

            $rate = [
                'CARRIER' => $carrier,
                'POL' => $pol ?: 'BKK/LCH',
                'POD' => $pod,
                'CUR' => 'USD',
                "20'" => $rate20,
                "40'" => $rate40,
                '40 HQ' => $rate40,
                '20 TC' => '',
                '20 RF' => '',
                '40RF' => '',
                'ETD BKK' => $etdBkk,
                'ETD LCH' => $etdLch,
                'T/T' => $tt,
                'T/S' => $ts,
                'FREE TIME' => $freeTime,
                'VALIDITY' => $validity,
                'REMARK' => $remark,
                'Export' => '',
                'Who use?' => '',
                'Rate Adjust' => '',
                '1.1' => '',
                '_isBlackRow' => $isBlackRow  // Flag for output formatting
            ];

            $allRates[] = $rate;
            $ratesAdded++;
        }
    }

    echo "    → Added $ratesAdded rates\n";
}

echo "\n  ✓ Total Excel rates: " . count($allRates) . "\n\n";

// Step 2: Process PDF files using existing Azure OCR results
echo "Step 2: Using Azure OCR results for PDFs...\n";

// Map attachment filenames to Azure result files
$azureResultsDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/azure_ocr_results/';
$pdfFiles = glob($attachmentsDir . '*.pdf');
$pdfFiles = array_merge($pdfFiles, glob($attachmentsDir . '*.PDF'));

echo "  Found " . count($pdfFiles) . " PDF file(s)\n\n";

$azureRatesAdded = 0;

foreach ($pdfFiles as $pdfFile) {
    $filename = basename($pdfFile);
    $baseFilename = pathinfo($filename, PATHINFO_FILENAME);

    // Find corresponding table file
    $tableFile = $azureResultsDir . $baseFilename . '_tables.txt';

    if (!file_exists($tableFile)) {
        echo "  ⚠️  No Azure results for: $filename\n";
        continue;
    }

    echo "  Processing: $filename\n";

    // Extract carrier name from filename
    $carrier = '';
    $validity = ''; // Will be extracted from PDF content
    if (preg_match('/SINOKOR/i', $filename)) {
        $carrier = 'SINOKOR';
    } elseif (preg_match('/BOXMAN/i', $filename)) {
        $carrier = 'BOXMAN';
        // Extract validity from Azure OCR JSON for BOXMAN
        $validity = extractValidityFromJson($azureResultsDir . $baseFilename . '_azure_result.json');
    } elseif (preg_match('/INDIA/i', $filename)) {
        $carrier = 'WANHAI';
    } elseif (preg_match('/WANHAI/i', $filename)) {
        $carrier = 'WANHAI';
    } elseif (preg_match('/CK LINE/i', $filename)) {
        $carrier = 'CK LINE';
    } elseif (preg_match('/HEUNG A|HUANG-A|HUANG A/i', $filename)) {
        $carrier = 'HEUNG A';
    } elseif (preg_match('/SM LINE/i', $filename)) {
        $carrier = 'SM LINE';
    } elseif (preg_match('/Dongjin/i', $filename)) {
        $carrier = 'DONGJIN';
    } elseif (preg_match('/TS LINE|Rate 1st/i', $filename)) {
        $carrier = 'TS LINE';
    } elseif (preg_match('/SITC/i', $filename)) {
        $carrier = 'SITC';
    } elseif (preg_match('/PUBLIC QUOTATION/i', $filename)) {
        $carrier = 'RCL';
    }

    // Read and parse table file
    $content = file_get_contents($tableFile);
    $ratesFromFile = parseTableFile($content, $carrier, $filename, $validity);

    $allRates = array_merge($allRates, $ratesFromFile);
    $azureRatesAdded += count($ratesFromFile);

    echo "    Carrier: $carrier, Added: " . count($ratesFromFile) . " rates\n";
}

echo "\n  ✓ Total Azure rates: $azureRatesAdded\n\n";

// Step 3: Clean and deduplicate
echo "Step 3: Cleaning and deduplicating data...\n";

$beforeCount = count($allRates);

// Remove rows with missing critical data
$allRates = array_filter($allRates, function($rate) {
    $hasCarrier = !empty(trim($rate['CARRIER']));
    $hasPOD = !empty(trim($rate['POD']));
    $has20 = !empty(trim($rate["20'"])) && trim($rate["20'"]) !== '0' && trim($rate["20'"]) !== 'N/A';
    $has40 = !empty(trim($rate["40'"])) && trim($rate["40'"]) !== '0' && trim($rate["40'"]) !== 'N/A';

    return $hasCarrier && $hasPOD && ($has20 || $has40);
});

$removedInvalid = $beforeCount - count($allRates);
echo "  → Removed $removedInvalid invalid rows\n";

// Remove duplicates
$seen = [];
$unique = [];
$duplicateCount = 0;

foreach ($allRates as $rate) {
    $key = $rate['CARRIER'] . '|' . $rate['POL'] . '|' . $rate['POD'] . '|' .
           $rate["20'"] . '|' . $rate["40'"];

    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $unique[] = $rate;
    } else {
        $duplicateCount++;
    }
}

echo "  → Removed $duplicateCount duplicates\n";
echo "  ✓ Final clean data: " . count($unique) . " rates\n\n";

$allRates = $unique;

// Sort by CARRIER to group all rates by shipping line
usort($allRates, function($a, $b) {
    return strcmp($a['CARRIER'], $b['CARRIER']);
});

echo "  → Sorted by CARRIER\n";

// Step 4: Create final Excel file
echo "Step 4: Creating final Excel file...\n";

$outputSpreadsheet = new Spreadsheet();
$sheet = $outputSpreadsheet->getActiveSheet();
$sheet->setTitle('FCL_EXP_FINAL');

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

    // Apply black highlighting if this row was marked as black in source
    if (isset($rate['_isBlackRow']) && $rate['_isBlackRow'] === true) {
        $sheet->getStyle('A' . $rowNum . ':U' . $rowNum)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF000000');
        $sheet->getStyle('A' . $rowNum . ':U' . $rowNum)->getFont()
            ->getColor()->setARGB('FFFFFFFF');  // White text on black background
    }

    $rowNum++;
}

// Auto-size columns
foreach (range('A', 'U') as $columnID) {
    $sheet->getColumnDimension($columnID)->setAutoSize(true);
}

// Save file
$outputFile = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/output/FINAL_RATES_FROM_ATTACHMENTS.xlsx';
$writer = new Xlsx($outputSpreadsheet);
$writer->save($outputFile);

echo "  ✓ Saved to: " . basename($outputFile) . "\n";
echo "  ✓ Total rows: " . count($allRates) . " + 1 header\n\n";

// Step 5: Summary by carrier
echo "Step 5: Summary by Carrier:\n";
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
echo "PROCESSING COMPLETE!\n";
echo "  Source files:         " . (count($excelFiles) + count($pdfFiles)) . " attachments\n";
echo "  Excel files:          " . count($excelFiles) . "\n";
echo "  PDF files:            " . count($pdfFiles) . "\n";
echo "  Total rates:          " . count($allRates) . "\n";
echo "  Total carriers:       " . count($carrierCounts) . "\n";
echo "  Output file:          FINAL_RATES_FROM_ATTACHMENTS.xlsx\n";
echo str_repeat('=', 100) . "\n";

// Helper function to parse table files
function parseTableFile($content, $carrier, $filename, $validity = '') {
    $rates = [];
    $lines = explode("\n", $content);

    // Special handling for SINOKOR, HEUNG A, BOXMAN, SITC, and INDIA RATE files
    if ($carrier === 'SINOKOR') {
        return parseSinokorTable($lines, $carrier);
    } elseif ($carrier === 'HEUNG A') {
        return parseHeungATable($lines, $carrier);
    } elseif ($carrier === 'BOXMAN') {
        return parseBoxmanTable($lines, $carrier, $validity);
    } elseif ($carrier === 'SITC') {
        return parseSitcTable($lines, $carrier);
    } elseif (preg_match('/INDIA/i', $filename)) {
        return parseIndiaRateTable($lines, $carrier);
    }

    // Default parsing for other carriers
    foreach ($lines as $line) {
        if (strpos($line, 'TABLE') !== false || strpos($line, '====') !== false ||
            strpos($line, '----') !== false || empty(trim($line))) {
            continue;
        }

        if (preg_match('/^Row \d+: (.+)$/', $line, $matches)) {
            $rowData = $matches[1];
            $cells = explode(' | ', $rowData);

            $rate = extractRateFromCells($cells, $carrier);

            if ($rate && !empty($rate['POD'])) {
                $rates[] = $rate;
            }
        }
    }

    return $rates;
}

// Parse SINOKOR specific table format
function parseSinokorTable($lines, $carrier) {
    $rates = [];

    foreach ($lines as $line) {
        if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) {
            continue;
        }

        $rowData = $matches[1];
        $cells = explode(' | ', $rowData);

        // Skip header rows
        if (count($cells) < 3) continue;
        if (preg_match('/(COUNTRY|POL|POD NAME|20offer)/i', $cells[0])) continue;

        // Format 1: COUNTRY | POD | 20' | 40'HQ
        // Format 2: POL | POL NAME | POD | POD NAME | T/T | Type | 20offer | 40offer

        $pod = '';
        $rate20 = '';
        $rate40 = '';

        if (count($cells) >= 8) {
            // Format 2: Has POL, POL NAME, POD, POD NAME, etc.
            $pod = trim($cells[3] ?? ''); // POD NAME
            $rate20 = trim($cells[6] ?? ''); // 20offer
            $rate40 = trim($cells[7] ?? ''); // 40offer
        } elseif (count($cells) >= 4) {
            // Format 1: COUNTRY | POD | 20' | 40'HQ
            $pod = trim($cells[1] ?? '');
            $rate20 = trim($cells[2] ?? '');
            $rate40 = trim($cells[3] ?? '');
        }

        // Clean up rate values
        $rate20 = preg_replace('/[^0-9]/', '', $rate20);
        $rate40 = preg_replace('/[^0-9]/', '', $rate40);

        if (!empty($pod) && (!empty($rate20) || !empty($rate40))) {
            $rates[] = createRateEntry($carrier, $pod, $rate20, $rate40);
        }
    }

    return $rates;
}

// Parse HEUNG A specific table format
function parseHeungATable($lines, $carrier) {
    $rates = [];

    foreach ($lines as $line) {
        if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) {
            continue;
        }

        $rowData = $matches[1];
        $cells = explode(' | ', $rowData);

        // Skip header rows and rows with insufficient data
        if (count($cells) < 4) continue;
        if (preg_match('/(POL|POD|Nov2025|20\'|40\')/i', $cells[0] ?? '')) continue;
        if (preg_match('/(20\'|40\',HQ)/i', $cells[0] ?? '')) continue;

        // Format: POL | POD | 20' | 40' | Sailing/Week | Sailing date | Direct/TS | Transit Time | Surcharge
        // BUT for "Check port" rows: POL | POD | Check port | Sailing/Week | Sailing date | Direct/TS | Transit Time
        $pol = trim($cells[0] ?? '');
        $pod = trim($cells[1] ?? '');
        $rate20 = trim($cells[2] ?? '');
        $rate40 = trim($cells[3] ?? '');

        // Check if this is a "Check port" row (merged cell causes column shift)
        $isCheckPort = (stripos($rate20, 'Check') !== false);

        if ($isCheckPort) {
            // Column shift: everything after "Check port" is shifted left by 1
            $sailingDate = trim($cells[4] ?? '');   // Shifted from 5 to 4
            $directTs = trim($cells[5] ?? '');      // Shifted from 6 to 5
            $transitTime = trim($cells[6] ?? '');   // Shifted from 7 to 6
            $surcharge = trim($cells[7] ?? '');     // Surcharge shifted to 7
        } else {
            // Normal column positions
            $sailingDate = trim($cells[5] ?? '');   // Sailing date column
            $directTs = trim($cells[6] ?? '');      // Direct/TS column
            $transitTime = trim($cells[7] ?? '');   // Transit Time column
            $surcharge = trim($cells[8] ?? '');     // Surcharge column
        }

        // Clean up POD (remove notes in parentheses like "(Light Cargos)" or "(Rice)")
        $pod = preg_replace('/\s*\([^)]*\)/', '', $pod);
        $pod = trim($pod);

        // Clean up rate values based on whether this is a "Check port" row
        if ($isCheckPort) {
            // If this is a "Check port" row, set both rates to "Check port"
            $rate20 = 'Check port';
            $rate40 = 'Check port';
        } else {
            // Clean up rate values - keep only numbers
            $rate20 = preg_replace('/[^0-9]/', '', $rate20);
            $rate40 = preg_replace('/[^0-9]/', '', $rate40);
        }

        if (!empty($pod) && (!empty($rate20) || !empty($rate40))) {
            // Determine ETD BKK and ETD LCH based on POL
            $etdBkk = '';
            $etdLch = '';

            if (!empty($sailingDate)) {
                // Check if POL contains both BKK and LCH
                if (stripos($pol, 'BKK') !== false && stripos($pol, 'LCH') !== false) {
                    // Contains both - populate both columns
                    $etdBkk = $sailingDate;
                    $etdLch = $sailingDate;
                } elseif (stripos($pol, 'BKK') !== false) {
                    // Contains only BKK
                    $etdBkk = $sailingDate;
                } elseif (stripos($pol, 'LCH') !== false || stripos($pol, 'Latkabang') !== false ||
                          stripos($pol, 'TICT') !== false || stripos($pol, 'LKR') !== false) {
                    // Contains LCH or variants (Latkabang, TICT, LKR)
                    $etdLch = $sailingDate;
                } else {
                    // Default to both if unclear
                    $etdBkk = $sailingDate;
                    $etdLch = $sailingDate;
                }
            }

            // Format Transit Time with "Days" suffix
            $tt = '';
            if (!empty($transitTime)) {
                // Add "Days" suffix if not empty
                $tt = $transitTime . ' Days';
            } else {
                $tt = 'TBA';
            }

            // Use Direct/TS value, default to TBA if empty
            $ts = !empty($directTs) ? $directTs : 'TBA';

            // Create rate entry with actual POL value (not hardcoded)
            $rates[] = [
                'CARRIER' => $carrier,
                'POL' => $pol,  // Use actual POL from source data
                'POD' => $pod,
                'CUR' => 'USD',
                "20'" => $rate20,
                "40'" => $rate40,
                '40 HQ' => $rate40,
                '20 TC' => '',
                '20 RF' => '',
                '40RF' => '',
                'ETD BKK' => $etdBkk,
                'ETD LCH' => $etdLch,
                'T/T' => $tt,
                'T/S' => $ts,
                'FREE TIME' => 'TBA',
                'VALIDITY' => 'DEC 2025',
                'REMARK' => $surcharge,  // Use surcharge value
                'Export' => '',
                'Who use?' => '',
                'Rate Adjust' => '',
                '1.1' => ''
            ];
        }
    }

    return $rates;
}

// Parse BOXMAN specific table format
function parseBoxmanTable($lines, $carrier, $validity = '') {
    $rates = [];
    $lastPol = ''; // Track last POL for merged cells
    $lastEtd = ''; // Track last ETD for merged cells

    // Default validity if not provided
    if (empty($validity)) {
        $validity = 'DEC 2025';
    }

    foreach ($lines as $line) {
        if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) {
            continue;
        }

        $rowData = $matches[1];
        $cells = explode(' | ', $rowData);

        // Skip header rows and reset lastEtd for new tables
        if (count($cells) < 3) continue;
        if (preg_match('/(Port of Loading|POL|20\'DC|40\'HC)/i', $cells[0] ?? '')) {
            $lastEtd = ''; // Reset lastEtd when encountering a new table header
            continue;
        }

        // BOXMAN format: POL | POD | 20' | 40' | ETD | Transit Time | Remarks
        // Column positions:
        // 0: POL (Port of Loading) - can be "LKB", "LCH", "LKB LCH", etc.
        // 1: POD (Port of Discharge)
        // 2: 20' rate (with "USD" prefix)
        // 3: 40' rate (with "USD" prefix)
        // 4: ETD (sailing day like "WED", "SAT")
        // 5: Transit Time (like "3 days", "11 days")
        // 6: Remarks (optional)

        $pol = trim($cells[0] ?? '');
        $pod = trim($cells[1] ?? '');
        $rate20 = trim($cells[2] ?? '');
        $rate40 = trim($cells[3] ?? '');
        $etd = trim($cells[4] ?? '');
        $transitTime = trim($cells[5] ?? '');
        $remarks = trim($cells[6] ?? '');

        // Handle merged POL cells - if POL is empty or looks like a POD, use last POL
        if (empty($pol) || preg_match('/^[A-Z][a-z]/', $pol)) {
            // If POL looks like a destination name, shift columns left
            if (preg_match('/^[A-Z][a-z]/', $pol) && !preg_match('/(LKB|LCH|BKK)/i', $pol)) {
                // POL is actually POD, shift everything
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

        // Clean up rate values (remove "USD", commas, etc.)
        $rate20 = preg_replace('/[^0-9]/', '', $rate20);
        $rate40 = preg_replace('/[^0-9]/', '', $rate40);

        // Skip if no valid POD or rates
        if (empty($pod) || (empty($rate20) && empty($rate40))) {
            continue;
        }

        // First, filter out invalid ETD values (like transit time containing "days")
        // If ETD contains "days", it's likely the transit time shifted into ETD column
        if (!empty($etd) && stripos($etd, 'day') !== false) {
            // If transit time is empty, use this value as transit time
            if (empty($transitTime)) {
                $transitTime = $etd;
            }
            $etd = ''; // Clear invalid ETD that looks like transit time
        }

        // Handle merged ETD cells - if ETD is empty, use last ETD
        if (empty($etd) && !empty($lastEtd)) {
            $etd = $lastEtd;
        }

        // Update lastEtd if we have a valid ETD
        if (!empty($etd)) {
            $lastEtd = $etd;
        }

        // Map POL to ETD columns
        $etdBkk = '';
        $etdLch = '';

        if (!empty($pol) && !empty($etd)) {
            // LKB (Lat Krabang) → ETD LCH
            // LCH (Laem Chabang) → ETD LCH
            // BKK (Bangkok) → ETD BKK
            // "LKB LCH" or combinations → both columns

            $polUpper = strtoupper($pol);

            if (strpos($polUpper, 'LKB') !== false && strpos($polUpper, 'LCH') !== false) {
                // Contains both LKB and LCH → populate both columns
                $etdBkk = $etd;
                $etdLch = $etd;
            } elseif (strpos($polUpper, 'LKB') !== false || strpos($polUpper, 'LCH') !== false || strpos($polUpper, 'LKE') !== false) {
                // Contains LKB, LCH, or LKE → ETD LCH
                $etdLch = $etd;
            } elseif (strpos($polUpper, 'BKK') !== false) {
                // Contains BKK → ETD BKK
                $etdBkk = $etd;
            } else {
                // Default to ETD LCH if unclear
                $etdLch = $etd;
            }
        }

        // Format Transit Time
        $tt = !empty($transitTime) ? $transitTime : 'TBA';

        // Determine T/S from remarks
        $ts = 'TBA';
        if (!empty($remarks)) {
            if (stripos($remarks, 'DIRECT') !== false) {
                $ts = 'DIRECT';
            } elseif (preg_match('/T\/S\s+(.+)/i', $remarks, $tsMatch)) {
                $ts = 'T/S ' . trim($tsMatch[1]);
            }
        }

        // Create rate entry
        $rates[] = [
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
            'ETD BKK' => $etdBkk,
            'ETD LCH' => $etdLch,
            'T/T' => $tt,
            'T/S' => $ts,
            'FREE TIME' => 'TBA',
            'VALIDITY' => $validity,
            'REMARK' => $remarks,
            'Export' => '',
            'Who use?' => '',
            'Rate Adjust' => '',
            '1.1' => ''
        ];
    }

    return $rates;
}

// Parse INDIA RATE (WANHAI) specific table format
function parseIndiaRateTable($lines, $carrier) {
    $rates = [];

    foreach ($lines as $line) {
        if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) {
            continue;
        }

        $rowData = $matches[1];
        $cells = explode(' | ', $rowData);

        // Skip header rows and rows with insufficient data
        if (count($cells) < 4) continue;
        if (preg_match('/(POD|LKA|LCB|RATE)/i', $cells[0])) continue;

        // Format: POD | 20 (LKA) | 40HQ (LKA) | 20 (LCB) | 40HQ (LCB) | 20RF | 40RH
        $pod = trim($cells[0] ?? '');

        // Use LCB rates (columns 3 and 4) as they're usually Laem Chabang rates
        $rate20 = trim($cells[3] ?? $cells[1] ?? '');
        $rate40 = trim($cells[4] ?? $cells[2] ?? '');

        // Clean up POD (remove port codes in parentheses)
        $pod = preg_replace('/\s*\([^)]+\)/', '', $pod);
        $pod = trim($pod);

        // Clean up rate values
        $rate20 = preg_replace('/[^0-9]/', '', $rate20);
        $rate40 = preg_replace('/[^0-9]/', '', $rate40);

        if (!empty($pod) && (!empty($rate20) || !empty($rate40))) {
            $rates[] = createRateEntry($carrier, $pod, $rate20, $rate40);
        }
    }

    return $rates;
}

// Parse SITC specific table format
function parseSitcTable($lines, $carrier) {
    $rates = [];
    $currentTable = 0;
    $table1Data = []; // Store TABLE 1 data
    $table2Data = []; // Store TABLE 2 data (T/T, Transit type, Free time)

    $lastPod = '';
    $lastServiceRoute = '';
    $lastFreeTime = 'TBA'; // Track last free time for merged cells

    // First pass: Organize data by table
    foreach ($lines as $line) {
        // Detect table boundaries
        if (preg_match('/^TABLE (\d+)/', $line, $tableMatch)) {
            $currentTable = intval($tableMatch[1]);
            continue;
        }

        if (!preg_match('/^Row (\d+): (.+)$/', $line, $matches)) {
            continue;
        }

        $rowNum = intval($matches[1]);
        $rowData = $matches[2];
        $cells = explode(' | ', $rowData);

        // Store data by table
        if ($currentTable == 1) {
            $table1Data[$rowNum] = $cells;
        } elseif ($currentTable == 2) {
            $table2Data[$rowNum] = $cells;
        } elseif ($currentTable >= 3) {
            // TABLE 3+ has combined format
            // Use unique keys to avoid conflicts with TABLE 1
            $uniqueKey = 'T' . $currentTable . '_R' . $rowNum;
            $table1Data[$uniqueKey] = $cells;
        }
    }

    // Second pass: Process TABLE 1 data with TABLE 2 metadata
    foreach ($table1Data as $rowKey => $cells) {
        // Extract numeric row number if this is from TABLE 3+
        $numericRowNum = is_numeric($rowKey) ? intval($rowKey) : null;

        // Skip header rows
        if (preg_match('/(POL|POD|Service Route|FREIGHT RATE|Laem Chabang.*Ningbo)/i', $cells[0] ?? '')) {
            if ($rowKey == 0 || $numericRowNum == 0) continue;
        }
        if (preg_match('/(20\'GP|40\',40\'HC|20RF|40HR)/i', $cells[0] ?? '')) continue;

        $pol = trim($cells[0] ?? '');
        if (empty($pol)) continue;

        // Skip section headers (merged rows spanning all columns)
        if (stripos($pol, 'China T/S') !== false || stripos($pol, 'T/S at') !== false) {
            continue;
        }

        // Also check if col1 contains section header text
        $col1Check = trim($cells[1] ?? '');
        if (stripos($col1Check, 'China T/S') !== false || stripos($col1Check, 'T/S at') !== false) {
            continue;
        }

        $rate20 = '';
        $rate40 = '';
        $pod = '';
        $serviceRoute = '';
        $tt = 'TBA';
        $ts = 'TBA';
        $freeTime = 'TBA';

        $col1 = trim($cells[1] ?? '');

        // Helper to detect if a value is a service route (e.g., VTX1, CKV2, JTH)
        $isServiceRoute = function($value) {
            return preg_match('/^(VTX|CKV|JTH|SSW)/i', $value);
        };

        // Check if this row has combined format (TABLE 3+) with more columns
        // TABLE 3 rows have at least 7 columns: POL | POD | Service | 20' | 40' | T/T | Transit
        // But NOT if col1 is a service route (that's a continuation row)
        if (count($cells) >= 7 &&
            is_numeric(str_replace(',', '', $cells[3] ?? '')) &&
            !$isServiceRoute($col1)) {
            // Combined format: POL | POD | Service Route | 20' | 40' | ... | T/T | Transit type | Free time
            $pod = $col1;
            $serviceRoute = trim($cells[2] ?? '');
            $rate20 = str_replace(',', '', trim($cells[3] ?? ''));
            $rate40 = str_replace(',', '', trim($cells[4] ?? ''));

            // Extract T/T, Transit type, Free time from later columns
            // Column positions vary based on presence of reefer notes and surcharge columns
            // We need to intelligently detect T/T position by looking for numeric values
            // T/T is typically a number (with or without "Days"), not long text like surcharges

            $tt = 'TBA';
            $ts = 'TBA';
            $freeTime = '';

            // Scan columns after rates (starting from col 5) to find T/T
            // T/T indicators: numeric, contains "day", or short text (< 20 chars)
            // Surcharge indicators: very long text (> 30 chars), contains "INC", "LSS", "Exclude", "collect", "Include"
            for ($i = 5; $i < count($cells); $i++) {
                $cellValue = trim($cells[$i] ?? '');
                if (empty($cellValue)) continue;

                // Check if this looks like a surcharge (very long text or contains surcharge keywords)
                $isSurcharge = strlen($cellValue) > 30 ||
                              stripos($cellValue, 'INC LSS') !== false ||
                              stripos($cellValue, 'Include LSS') !== false ||
                              stripos($cellValue, 'Exclude') !== false ||
                              stripos($cellValue, 'collect at destination') !== false ||
                              stripos($cellValue, 'ECRS') !== false ||
                              stripos($cellValue, 'PSS') !== false;

                if ($isSurcharge) {
                    continue; // Skip surcharge column
                }

                // Check if this looks like T/T (numeric, or contains "day" but NOT "Include" or "LSS")
                // T/T should be simple like "14 Days", "15-20 Days", not "Include LSS Days"
                $looksLikeTT = (is_numeric(str_replace(['-', ' '], '', $cellValue)) ||
                               (stripos($cellValue, 'day') !== false &&
                                stripos($cellValue, 'Include') === false &&
                                stripos($cellValue, 'LSS') === false)) ||
                               (strlen($cellValue) < 20 && !empty($cellValue));

                if ($looksLikeTT && $tt === 'TBA') {
                    $tt = $cellValue;
                    // Next column should be T/S
                    if (isset($cells[$i + 1])) {
                        $ts = trim($cells[$i + 1] ?? 'TBA');
                    }
                    // Column after that might be Free Time
                    if (isset($cells[$i + 2])) {
                        $freeTime = trim($cells[$i + 2] ?? '');
                    }
                    break; // Found T/T, stop scanning
                }
            }

            // If free time is empty, use last free time (merged cell)
            if (empty($freeTime) || $freeTime === 'TBA') {
                $freeTime = $lastFreeTime;
            } else {
                $lastFreeTime = $freeTime; // Update last free time
            }

            if (!empty($tt) && $tt !== 'TBA' && !stripos($tt, 'day')) {
                $tt .= ' Days';
            }

            $lastPod = $pod;
            $lastServiceRoute = $serviceRoute;
        } elseif (is_numeric(str_replace(',', '', $col1)) || empty($col1) || $isServiceRoute($col1)) {
            // Pattern 2: Continuation rows with merged POD (rates or service route in col 1)
            // Type A: POL | 20' | 40' | Surcharge | T/T | Transit type (POD+ServiceRoute merged, rates in col 1&2)
            // Type C: POL | Service Route | ... | 20' | 40' | ... (POD merged, service route in col 1)
            $pod = $lastPod;

            // Check if this is Type A: col1 is numeric AND col2 is numeric
            // AND (col3 is NOT numeric OR col4 is NOT numeric)
            // Handles: POL | 20' | 40' | Surcharge | T/T | Transit
            //      OR: POL | 20' | 40' | T/T | Transit
            $col2 = trim($cells[2] ?? '');
            $col3 = trim($cells[3] ?? '');
            $col4 = trim($cells[4] ?? '');
            $isTypeA = is_numeric(str_replace(',', '', $col1)) &&
                       is_numeric(str_replace(',', '', $col2)) &&
                       (!empty($col3)) &&
                       (!is_numeric(str_replace(',', '', $col3)) ||
                        (!empty($col4) && !is_numeric(str_replace(',', '', $col4))));

            if ($isTypeA) {
                // Type A: Two possible patterns
                // 6 cols: POL | 20' | 40' | Surcharge | T/T | Transit
                // 5 cols: POL | 20' | 40' | T/T | Transit
                $serviceRoute = $lastServiceRoute;
                $rate20 = str_replace(',', '', $col1);
                $rate40 = str_replace(',', '', $col2);

                // Determine column positions based on count
                $numCols = count($cells);
                if ($numCols >= 6) {
                    // 6+ columns: has Surcharge column
                    $tt = trim($cells[4] ?? 'TBA');
                    $ts = trim($cells[5] ?? 'TBA');
                    $freeTime = trim($cells[6] ?? '');
                } else {
                    // 5 columns: no Surcharge, T/T starts at col 3
                    $tt = trim($cells[3] ?? 'TBA');
                    $ts = trim($cells[4] ?? 'TBA');
                    $freeTime = trim($cells[5] ?? '');
                }

                // If free time is empty, use last free time (merged cell)
                if (empty($freeTime) || $freeTime === 'TBA') {
                    $freeTime = $lastFreeTime;
                } else {
                    $lastFreeTime = $freeTime;
                }

                if (!empty($tt) && $tt !== 'TBA' && !stripos($tt, 'day')) {
                    $tt .= ' Days';
                }
            } elseif ($isServiceRoute($col1)) {
                // Type C: Service Route | ... | 40' | 20' | ... | T/T | Transit type
                // Note: Column positions vary depending on table format
                $serviceRoute = $col1;

                // Dynamically find rate columns - look for first two numeric values after service route
                $rate40 = '';
                $rate20 = '';
                $rateColumns = [];

                // Scan cells to find numeric rate values
                for ($i = 1; $i < count($cells); $i++) {
                    $cellValue = str_replace(',', '', trim($cells[$i] ?? ''));
                    if (is_numeric($cellValue) && !empty($cellValue)) {
                        $rateColumns[] = ['index' => $i, 'value' => $cellValue];
                        if (count($rateColumns) >= 2) {
                            break; // Found both rates
                        }
                    }
                }

                // Assign rates - in Type C, 40' typically comes before 20'
                if (count($rateColumns) >= 2) {
                    $rate40 = $rateColumns[0]['value'];
                    $rate20 = $rateColumns[1]['value'];

                    // Determine positions for T/T, T/S, FREE TIME based on number of columns
                    $numCols = count($cells);
                    $lastRateIdx = $rateColumns[1]['index'];

                    // T/T and T/S typically appear after rates
                    // Position varies: could be right after rates or with gap for surcharge
                    if ($numCols >= $lastRateIdx + 4) {
                        // Enough columns for: rates | surcharge | T/T | T/S | Free time
                        $tt = trim($cells[$lastRateIdx + 2] ?? 'TBA');
                        $ts = trim($cells[$lastRateIdx + 3] ?? 'TBA');
                        $freeTime = trim($cells[$lastRateIdx + 4] ?? '');
                    } elseif ($numCols >= $lastRateIdx + 3) {
                        // Format: rates | T/T | T/S | Free time
                        $tt = trim($cells[$lastRateIdx + 1] ?? 'TBA');
                        $ts = trim($cells[$lastRateIdx + 2] ?? 'TBA');
                        $freeTime = trim($cells[$lastRateIdx + 3] ?? '');
                    } else {
                        // Minimal columns: rates | T/T | T/S
                        $tt = trim($cells[$lastRateIdx + 1] ?? 'TBA');
                        $ts = trim($cells[$lastRateIdx + 2] ?? 'TBA');
                        $freeTime = '';
                    }
                } else {
                    // Fallback: not enough numeric values found
                    $rate40 = '';
                    $rate20 = '';
                    $tt = 'TBA';
                    $ts = 'TBA';
                    $freeTime = '';
                }

                // If free time is empty, use last free time (merged cell)
                if (empty($freeTime) || $freeTime === 'TBA') {
                    $freeTime = $lastFreeTime;
                } else {
                    $lastFreeTime = $freeTime;
                }

                if (!empty($tt) && $tt !== 'TBA' && !stripos($tt, 'day')) {
                    $tt .= ' Days';
                }
            } else {
                // Normal continuation: POL | 20' | 40'
                $serviceRoute = $lastServiceRoute;
                $rate20 = str_replace(',', '', $col1);
                $rate40 = str_replace(',', '', trim($cells[2] ?? ''));

                // Get T/T, Transit type, Free time from TABLE 2 if available
                if ($numericRowNum !== null && isset($table2Data[$numericRowNum])) {
                $table2Cells = $table2Data[$numericRowNum];

                // Check if this is a continuation row (POD merged)
                // Continuation row: col[0] is T/T (numeric), col[1] is Transit type
                // Full row: col[0] is Surcharge, col[1] is T/T, col[2] is Transit type, col[3] is Free time
                $firstCol = trim($table2Cells[0] ?? '');
                $isContinuation = is_numeric(str_replace('-', '', $firstCol)) ||
                                  preg_match('/^\d+(-\d+)?$/', $firstCol) ||
                                  empty($firstCol);

                if ($isContinuation) {
                    // Continuation row: columns are shifted
                    $tt = trim($table2Cells[0] ?? 'TBA');
                    $ts = trim($table2Cells[1] ?? 'TBA');
                    $freeTime = trim($table2Cells[2] ?? '');
                } else {
                    // Full row: normal column positions
                    $tt = trim($table2Cells[1] ?? 'TBA');
                    $ts = trim($table2Cells[2] ?? 'TBA');
                    $freeTime = trim($table2Cells[3] ?? '');
                }

                // If free time is empty, use last free time (merged cell)
                if (empty($freeTime) || $freeTime === 'TBA') {
                    $freeTime = $lastFreeTime;
                } else {
                    $lastFreeTime = $freeTime; // Update last free time
                }

                if (!empty($tt) && $tt !== 'TBA' && !stripos($tt, 'day')) {
                    $tt .= ' Days';
                }
                }
            }
        } else {
            // Pattern 1: Full rows with POD in col1
            // Two sub-patterns:
            // Pattern 1a (5+ cols): POL | POD | Service Route | 20' | 40' | ...
            // Pattern 1b (4 cols): POL | POD | 20' | 40' (no service route)
            $pod = $col1;

            $col2Val = trim($cells[2] ?? '');
            $col3Val = trim($cells[3] ?? '');
            $col4Val = trim($cells[4] ?? '');

            // Check if col2 is numeric (Pattern 1b: no service route column)
            $col2IsNumeric = is_numeric(str_replace(',', '', $col2Val));
            $col3IsNumeric = is_numeric(str_replace(',', '', $col3Val));

            if ($col2IsNumeric && $col3IsNumeric) {
                // Pattern 1b: POL | POD | 20' | 40' (4 columns, no service route)
                $serviceRoute = $lastServiceRoute; // Inherit from previous row
                $rate20 = str_replace(',', '', $col2Val);
                $rate40 = str_replace(',', '', $col3Val);
            } elseif ($col2IsNumeric && !$col3IsNumeric) {
                // Pattern 1b variant: POL | POD | 20' | 40' where 40' is empty or has text
                $serviceRoute = $lastServiceRoute;
                $rate20 = str_replace(',', '', $col2Val);
                $rate40 = str_replace(',', '', $col3Val);
            } elseif ($isServiceRoute($col2Val) || ($col3IsNumeric && !$col2IsNumeric)) {
                // Pattern 1a: POL | POD | Service Route | 20' | 40'
                $serviceRoute = $col2Val;
                $rate20 = str_replace(',', '', $col3Val);
                $rate40 = str_replace(',', '', $col4Val);
            } else {
                // Default fallback: assume Pattern 1a
                $serviceRoute = $col2Val;
                $rate20 = str_replace(',', '', $col3Val);
                $rate40 = str_replace(',', '', $col4Val);
            }

            // Get T/T, Transit type, Free time from TABLE 2 if available
            if ($numericRowNum !== null && isset($table2Data[$numericRowNum])) {
                $table2Cells = $table2Data[$numericRowNum];

                // Check if this is a continuation row (POD merged)
                // Continuation row: col[0] is T/T (numeric), col[1] is Transit type
                // Full row: col[0] is Surcharge, col[1] is T/T, col[2] is Transit type, col[3] is Free time
                $firstCol = trim($table2Cells[0] ?? '');
                $isContinuation = is_numeric(str_replace('-', '', $firstCol)) ||
                                  preg_match('/^\d+(-\d+)?$/', $firstCol) ||
                                  empty($firstCol);

                if ($isContinuation) {
                    // Continuation row: columns are shifted
                    $tt = trim($table2Cells[0] ?? 'TBA');
                    $ts = trim($table2Cells[1] ?? 'TBA');
                    $freeTime = trim($table2Cells[2] ?? '');
                } else {
                    // Full row: normal column positions
                    $tt = trim($table2Cells[1] ?? 'TBA');
                    $ts = trim($table2Cells[2] ?? 'TBA');
                    $freeTime = trim($table2Cells[3] ?? '');
                }

                // If free time is empty, use last free time (merged cell)
                if (empty($freeTime) || $freeTime === 'TBA') {
                    $freeTime = $lastFreeTime;
                } else {
                    $lastFreeTime = $freeTime; // Update last free time
                }

                if (!empty($tt) && $tt !== 'TBA' && !stripos($tt, 'day')) {
                    $tt .= ' Days';
                }
            }

            $lastPod = $pod;
            $lastServiceRoute = $serviceRoute;
        }

        // Special handling for TABLE 5 - apply transit data by destination name
        // This is more robust than row index matching
        if (strpos($rowKey, 'T5_R') === 0 && !empty($pod)) {
            // TABLE 5 transit data mapping by destination (DEC 2025)
            // Update this mapping each month when the new rate card is released
            $table5TransitByDestination = [
                'Kuching/Sarawak (Malay)' => ['tt' => '20-25', 'ts' => 'T/S at HCM', 'freetime' => '7/5 days (dem+detention)'],
                'Bintulu' => ['tt' => '20-25', 'ts' => 'T/S at HCM', 'freetime' => '7/5 days (dem+detention)'],
                'Jakarta (NPCT1 Terminal)' => ['tt' => '5', 'ts' => 'Direct', 'freetime' => '7 days combine dem/det'],
                'Cikarang (CKD)' => ['tt' => '6', 'ts' => 'T/S JKT by truck', 'freetime' => '7 days combine dem/det'],
                'Batam/Indo (CY/CY)' => ['tt' => '20-25', 'ts' => 'T/S at HCM', 'freetime' => '7 days combine dem/det'],
                'Batam/Indo (CY/DOOR)' => ['tt' => '20-25', 'ts' => 'T/S at HCM', 'freetime' => '7 days combine dem/det'],
                'Balikpapan' => ['tt' => '20-25', 'ts' => 'T/S at HCM', 'freetime' => '7 days combine dem/det'],
                'Semarang' => ['tt' => '20-25', 'ts' => 'T/S HCM', 'freetime' => '7 days combine dem/det'],
                'Makassar' => ['tt' => '25', 'ts' => 'T/S Xiamen', 'freetime' => '7 days combine dem/det'],
                'Surabaya' => ['tt' => '29', 'ts' => 'T/S Xiamen', 'freetime' => '7 days combine dem/det'],
                'BUSAN' => ['tt' => '14', 'ts' => 'Direct', 'freetime' => '10 dem/ 5 det'],
                'INCHON' => ['tt' => '12', 'ts' => 'Direct', 'freetime' => '10 dem/ 5 det'],
                'OSAKA/KOBE' => ['tt' => '12', 'ts' => 'Direct', 'freetime' => '7 dem/ 5 det'],
                'KAWASAKI' => ['tt' => '12', 'ts' => 'Direct', 'freetime' => '7 dem/ 5 det'],
                'NGO/TOKYO/YOKO' => ['tt' => '12,13,14', 'ts' => 'Direct', 'freetime' => '7 dem/ 5 det'],
                'HAKATA' => ['tt' => '9', 'ts' => 'Direct', 'freetime' => '7 dem/ 5 det'],
                'Osaka/Kobe' => ['tt' => '10,11', 'ts' => 'Direct', 'freetime' => '7dem/ 5 det'],
                'Tokyo/Yoko/Nagoya' => ['tt' => '11,12,13', 'ts' => 'Direct', 'freetime' => '7dem/ 5 det'],
            ];

            // Match by destination name (case-insensitive, partial match)
            foreach ($table5TransitByDestination as $destName => $transitData) {
                if (stripos($pod, $destName) !== false) {
                    $tt = $transitData['tt'];
                    $ts = $transitData['ts'];
                    $freeTime = $transitData['freetime'];

                    if (!empty($tt) && $tt !== 'TBA' && !stripos($tt, 'day') && !stripos($tt, 'Direct') && !str_contains($tt, ',')) {
                        $tt .= ' Days';
                    }
                    break; // Found match, stop searching
                }
            }
        }

        // Skip if no valid data
        if (empty($pol) || empty($pod) || (empty($rate20) && empty($rate40))) {
            continue;
        }

        // Skip header-like values
        if (stripos($pol, 'Dem /Det') !== false || stripos($pol, 'Other surcharge') !== false) {
            continue;
        }

        // Keep POD with all text including brackets/parentheses
        $pod = trim($pod);

        // Create rate entry
        $rates[] = [
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
            'T/T' => $tt,
            'T/S' => $ts,
            'FREE TIME' => $freeTime,
            'VALIDITY' => 'DEC 2025',
            'REMARK' => $serviceRoute,
            'Export' => '',
            'Who use?' => '',
            'Rate Adjust' => '',
            '1.1' => ''
        ];
    }

    return $rates;
}

// Helper to create standardized rate entry
function createRateEntry($carrier, $pod, $rate20, $rate40) {
    return [
        'CARRIER' => $carrier,
        'POL' => 'BKK/LCH',
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
        'VALIDITY' => 'NOV 2025',
        'REMARK' => '',
        'Export' => '',
        'Who use?' => '',
        'Rate Adjust' => '',
        '1.1' => ''
    ];
}

function extractRateFromCells($cells, $carrier) {
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
        'T/T' => 'TBA',
        'T/S' => 'TBA',
        'FREE TIME' => 'TBA',
        'VALIDITY' => '',
        'REMARK' => '',
        'Export' => '',
        'Who use?' => '',
        'Rate Adjust' => '',
        '1.1' => ''
    ];

    foreach ($cells as $index => $cell) {
        $cell = trim($cell);

        if ($index <= 2 && preg_match('/^[A-Z][a-z]+/', $cell) && strlen($cell) > 2) {
            if (empty($rate['POD'])) {
                $rate['POD'] = $cell;
            } elseif (empty($rate['POL'])) {
                $rate['POL'] = $cell;
            }
        }

        if (preg_match('/\$?\s*(\d+[,\d]*)\s*\(?(INC|USD)?/i', $cell, $matches) && empty($rate["20'"])) {
            $rate["20'"] = str_replace(',', '', $matches[1]);
        }

        if (preg_match('/\$?\s*(\d+[,\d]*)\s*\(?(INC|USD)?/i', $cell, $matches) &&
            !empty($rate["20'"]) && empty($rate["40'"])) {
            $rate["40'"] = str_replace(',', '', $matches[1]);
        }

        if (preg_match('/(\d+)\s*(day|Day)/i', $cell, $matches) && ($rate['T/T'] === 'TBA' || empty($rate['T/T']))) {
            $rate['T/T'] = $matches[0];
        }

        if (preg_match('/(MON|TUE|WED|THU|FRI|SAT|SUN)/i', $cell) && empty($rate['ETD BKK'])) {
            $rate['ETD BKK'] = $cell;
        }
    }

    if (!empty($rate["40'"])) {
        $rate['40 HQ'] = $rate["40'"];
    }

    return $rate;
}

// Extract validity date from Azure OCR JSON file
// Pattern: "valid until DD/MM/YYYY" → format as "DD Mon YYYY"
function extractValidityFromJson($jsonFile) {
    if (!file_exists($jsonFile)) {
        return 'DEC 2025'; // Default fallback
    }

    $jsonContent = file_get_contents($jsonFile);
    $data = json_decode($jsonContent, true);

    if (!$data || !isset($data['analyzeResult']['content'])) {
        return 'DEC 2025'; // Default fallback
    }

    $content = $data['analyzeResult']['content'];

    // Search for pattern: "valid until DD/MM/YYYY" or "valid until DD\/MM\/YYYY"
    if (preg_match('/valid\s+until\s+(\d{1,2})[\/\\\\](\d{1,2})[\/\\\\](\d{4})/i', $content, $matches)) {
        $day = $matches[1];
        $month = $matches[2];
        $year = $matches[3];

        // Convert month number to month name
        $monthNames = [
            '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
            '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug',
            '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'
        ];

        $monthName = $monthNames[str_pad($month, 2, '0', STR_PAD_LEFT)] ?? 'Jan';

        // Format: "14 Nov 2025"
        return $day . ' ' . $monthName . ' ' . $year;
    }

    return 'DEC 2025'; // Default fallback if pattern not found
}
