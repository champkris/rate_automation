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
        return 'NOV 2025';
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
        // KMTC format: starts at row 6, columns B-F
        // B=Country/POD, C=POL, D=POD Area, E=20'GP, F=40'HC
        for ($row = 6; $row <= $highestRow; $row++) {
            $country = trim($worksheet->getCell('B' . $row)->getValue() ?? '');
            $pol = trim($worksheet->getCell('C' . $row)->getValue() ?? '');
            $podArea = trim($worksheet->getCell('D' . $row)->getValue() ?? '');
            $rate20 = trim($worksheet->getCell('E' . $row)->getValue() ?? '');
            $rate40 = trim($worksheet->getCell('F' . $row)->getValue() ?? '');

            // Skip empty rows
            if (empty($podArea) && empty($rate20) && empty($rate40)) {
                continue;
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
                'FREE TIME' => 'TBA',
                'VALIDITY' => 'NOV 2025',
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
        $validityRaw = trim($worksheet->getCell('B6')->getValue() ?? 'NOV 2025');

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
            $fillColor = $cellStyle->getFill()->getStartColor()->getRGB();
            if ($fillColor === '000000' || strtoupper($fillColor) === '000000') {
                $isBlackRow = true;
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
    if (preg_match('/SINOKOR/i', $filename)) {
        $carrier = 'SINOKOR';
    } elseif (preg_match('/BOXMAN/i', $filename)) {
        $carrier = 'BOXMAN';
    } elseif (preg_match('/INDIA/i', $filename)) {
        $carrier = 'WANHAI';
    } elseif (preg_match('/WANHAI/i', $filename)) {
        $carrier = 'WANHAI';
    } elseif (preg_match('/CK LINE/i', $filename)) {
        $carrier = 'CK LINE';
    } elseif (preg_match('/HEUNG A/i', $filename)) {
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
    $ratesFromFile = parseTableFile($content, $carrier, $filename);

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
function parseTableFile($content, $carrier, $filename) {
    $rates = [];
    $lines = explode("\n", $content);

    // Special handling for SINOKOR, HEUNG A, and INDIA RATE files
    if ($carrier === 'SINOKOR') {
        return parseSinokorTable($lines, $carrier);
    } elseif ($carrier === 'HEUNG A') {
        return parseHeungATable($lines, $carrier);
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
                'VALIDITY' => 'NOV 2025',
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
