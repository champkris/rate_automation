<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$filepath = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/output/RATE SHEET_Keep update (Pricing).xlsx';

echo "Reading: RATE SHEET_Keep update (Pricing).xlsx\n";
echo str_repeat('=', 100) . "\n\n";

try {
    $spreadsheet = IOFactory::load($filepath);

    // Get all sheet names
    $sheetNames = $spreadsheet->getSheetNames();
    echo "Available sheets (" . count($sheetNames) . "):\n";
    foreach ($sheetNames as $index => $name) {
        echo "  " . ($index + 1) . ". $name\n";
    }
    echo "\n";

    // Look for FCL_EXP sheet
    $fclExpSheet = null;
    foreach ($sheetNames as $sheetName) {
        if (stripos($sheetName, 'FCL') !== false && stripos($sheetName, 'EXP') !== false) {
            $fclExpSheet = $sheetName;
            break;
        }
    }

    if ($fclExpSheet) {
        echo "Found FCL_EXP sheet: '$fclExpSheet'\n";
        echo str_repeat('=', 100) . "\n\n";

        $worksheet = $spreadsheet->getSheetByName($fclExpSheet);
        $highestRow = $worksheet->getHighestDataRow();
        $highestColumn = $worksheet->getHighestDataColumn();

        echo "Sheet size: {$highestColumn}{$highestRow}\n";
        echo "Reading first 50 rows to understand structure...\n\n";

        // Read first 50 rows
        $rowLimit = min(50, $highestRow);

        for ($row = 1; $row <= $rowLimit; $row++) {
            $rowData = $worksheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, true, true);
            $hasData = false;
            $output = "Row " . str_pad($row, 3, ' ', STR_PAD_LEFT) . ": ";

            $cells = [];
            foreach ($rowData[$row] as $colLetter => $value) {
                if ($value !== null && $value !== '') {
                    $hasData = true;
                    $displayValue = is_string($value) ? $value : json_encode($value);
                    // Truncate long values
                    if (strlen($displayValue) > 50) {
                        $displayValue = substr($displayValue, 0, 47) . '...';
                    }
                    $cells[] = "[$colLetter: $displayValue]";
                }
            }

            if ($hasData) {
                echo $output . implode(' ', $cells) . "\n";
            }
        }

        if ($highestRow > $rowLimit) {
            echo "\n... and " . ($highestRow - $rowLimit) . " more rows\n";
        }

        // Get column headers (usually row 1 or row with most data in first rows)
        echo "\n" . str_repeat('=', 100) . "\n";
        echo "Column Headers Analysis:\n";
        echo str_repeat('=', 100) . "\n\n";

        // Check first 5 rows for headers
        for ($row = 1; $row <= min(5, $highestRow); $row++) {
            $rowData = $worksheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, true, true)[$row];
            $nonEmptyCount = count(array_filter($rowData, function($v) { return $v !== null && $v !== ''; }));

            if ($nonEmptyCount > 5) {
                echo "Potential header row $row ($nonEmptyCount columns):\n";
                foreach ($rowData as $col => $value) {
                    if ($value !== null && $value !== '') {
                        echo "  $col: $value\n";
                    }
                }
                echo "\n";
            }
        }

    } else {
        echo "FCL_EXP sheet not found. Available sheets:\n";
        foreach ($sheetNames as $name) {
            echo "  - $name\n";
        }
    }

} catch (Exception $e) {
    echo "Error reading file: " . $e->getMessage() . "\n";
}
