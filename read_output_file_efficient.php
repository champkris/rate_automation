<?php

ini_set('memory_limit', '512M'); // Increase memory limit

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

$filepath = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/output/RATE SHEET_Keep update (Pricing).xlsx';

echo "Reading: RATE SHEET_Keep update (Pricing).xlsx\n";
echo str_repeat('=', 100) . "\n\n";

try {
    // Use a reader with read filter to only load specific rows
    $reader = new Xlsx();
    $reader->setReadDataOnly(true);

    // First, just get sheet names
    $worksheetNames = $reader->listWorksheetNames($filepath);

    echo "Available sheets (" . count($worksheetNames) . "):\n";
    foreach ($worksheetNames as $index => $name) {
        echo "  " . ($index + 1) . ". $name\n";
    }
    echo "\n";

    // Look for FCL_EXP sheet
    $fclExpSheet = null;
    foreach ($worksheetNames as $sheetName) {
        if (stripos($sheetName, 'FCL') !== false && stripos($sheetName, 'EXP') !== false) {
            $fclExpSheet = $sheetName;
            break;
        }
    }

    if ($fclExpSheet) {
        echo "Found FCL_EXP sheet: '$fclExpSheet'\n";
        echo str_repeat('=', 100) . "\n\n";

        // Load only the specific sheet
        $reader->setLoadSheetsOnly([$fclExpSheet]);
        $spreadsheet = $reader->load($filepath);

        $worksheet = $spreadsheet->getSheetByName($fclExpSheet);
        $highestRow = $worksheet->getHighestDataRow();
        $highestColumn = $worksheet->getHighestDataColumn();

        echo "Sheet size: {$highestColumn}{$highestRow}\n";
        echo "Reading first 30 rows...\n\n";

        // Read first 30 rows
        $rowLimit = min(30, $highestRow);

        for ($row = 1; $row <= $rowLimit; $row++) {
            $rowData = $worksheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false, false);
            $hasData = false;
            $cells = [];

            foreach ($rowData[0] as $colIndex => $value) {
                if ($value !== null && $value !== '') {
                    $hasData = true;
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
                    $displayValue = is_string($value) ? $value : json_encode($value);
                    if (strlen($displayValue) > 50) {
                        $displayValue = substr($displayValue, 0, 47) . '...';
                    }
                    $cells[] = "[$colLetter: $displayValue]";
                }
            }

            if ($hasData) {
                echo "Row " . str_pad($row, 3, ' ', STR_PAD_LEFT) . ": " . implode(' ', $cells) . "\n";
            }
        }

        if ($highestRow > $rowLimit) {
            echo "\n... and " . ($highestRow - $rowLimit) . " more rows\n";
        }

    } else {
        echo "FCL_EXP sheet not found. Available sheets:\n";
        foreach ($worksheetNames as $name) {
            echo "  - $name\n";
        }
        echo "\nTrying to read first sheet...\n\n";

        // Load first sheet
        $reader->setLoadSheetsOnly([$worksheetNames[0]]);
        $spreadsheet = $reader->load($filepath);
        $worksheet = $spreadsheet->getActiveSheet();

        echo "First sheet: " . $worksheetNames[0] . "\n";
        echo "Reading first 20 rows...\n\n";

        for ($row = 1; $row <= 20; $row++) {
            $rowData = $worksheet->rangeToArray("A{$row}:Z{$row}", null, true, false, false);
            $hasData = false;
            $cells = [];

            foreach ($rowData[0] as $colIndex => $value) {
                if ($value !== null && $value !== '') {
                    $hasData = true;
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
                    $displayValue = is_string($value) ? $value : json_encode($value);
                    if (strlen($displayValue) > 50) {
                        $displayValue = substr($displayValue, 0, 47) . '...';
                    }
                    $cells[] = "[$colLetter: $displayValue]";
                }
            }

            if ($hasData) {
                echo "Row " . str_pad($row, 3, ' ', STR_PAD_LEFT) . ": " . implode(' ', $cells) . "\n";
            }
        }
    }

} catch (Exception $e) {
    echo "Error reading file: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
