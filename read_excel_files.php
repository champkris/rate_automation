<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$attachmentsDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/';

$excelFiles = [
    'UPDATED RATE IN NOV25.xlsx',
    'FAK Rate of 1-15 NOV 25.xlsx',
    'FAK Rate of 1-30 NOV  on Northeast& Southeast Asia .xls'
];

foreach ($excelFiles as $filename) {
    $filepath = $attachmentsDir . $filename;

    echo str_repeat('=', 80) . "\n";
    echo "FILE: $filename\n";
    echo str_repeat('=', 80) . "\n\n";

    try {
        $spreadsheet = IOFactory::load($filepath);

        // Get all sheet names
        $sheetNames = $spreadsheet->getSheetNames();
        echo "Number of sheets: " . count($sheetNames) . "\n";
        echo "Sheet names: " . implode(', ', $sheetNames) . "\n\n";

        // Read each sheet
        foreach ($sheetNames as $sheetName) {
            $worksheet = $spreadsheet->getSheetByName($sheetName);

            echo "--- SHEET: $sheetName ---\n";

            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();

            echo "Dimensions: {$highestColumn}{$highestRow}\n";
            echo "Highest Row: $highestRow\n";
            echo "Highest Column: $highestColumn\n\n";

            // Read first 20 rows to get structure
            $rowLimit = min(20, $highestRow);

            echo "First $rowLimit rows:\n";
            for ($row = 1; $row <= $rowLimit; $row++) {
                $rowData = [];
                for ($col = 'A'; $col <= $highestColumn; $col++) {
                    $cellValue = $worksheet->getCell($col . $row)->getValue();
                    if ($cellValue !== null && $cellValue !== '') {
                        $rowData[] = "$col: " . (is_string($cellValue) ? $cellValue : json_encode($cellValue));
                    }
                }
                if (!empty($rowData)) {
                    echo "Row $row: " . implode(' | ', $rowData) . "\n";
                }
            }

            if ($highestRow > $rowLimit) {
                echo "\n... and " . ($highestRow - $rowLimit) . " more rows\n";
            }

            echo "\n";
        }

    } catch (Exception $e) {
        echo "Error reading file: " . $e->getMessage() . "\n";
    }

    echo "\n\n";
}
