<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ini_set('memory_limit', '1024M');
set_time_limit(600);

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
                'T/T' => '',
                'T/S' => '',
                'FREE TIME' => '',
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
        $validity = trim($worksheet->getCell('B6')->getValue() ?? 'NOV 2025');

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

            // Add "Days" suffix to T/T if not empty
            if (!empty($tt)) {
                $tt .= ' Days';
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
                '1.1' => ''
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

    // Special handling for SINOKOR and INDIA RATE files
    if ($carrier === 'SINOKOR') {
        return parseSinokorTable($lines, $carrier);
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
        'T/T' => '',
        'T/S' => '',
        'FREE TIME' => '',
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
        'T/T' => '',
        'T/S' => '',
        'FREE TIME' => '',
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

        if (preg_match('/(\d+)\s*(day|Day)/i', $cell, $matches) && empty($rate['T/T'])) {
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
