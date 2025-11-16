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

    echo str_repeat('=', 100) . "\n";
    echo "FILE: $filename\n";
    echo str_repeat('=', 100) . "\n\n";

    try {
        $spreadsheet = IOFactory::load($filepath);

        foreach ($spreadsheet->getAllSheets() as $worksheet) {
            $sheetName = $worksheet->getTitle();
            echo "┌─ SHEET: $sheetName ─┐\n";

            $highestRow = $worksheet->getHighestDataRow();
            $highestColumn = $worksheet->getHighestDataColumn();

            echo "│ Size: {$highestColumn}{$highestRow}\n";
            echo "└" . str_repeat('─', 50) . "┘\n\n";

            // Read up to 50 rows with actual data
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
                        if (strlen($displayValue) > 60) {
                            $displayValue = substr($displayValue, 0, 57) . '...';
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

            echo "\n\n";
        }

    } catch (Exception $e) {
        echo "Error reading file: " . $e->getMessage() . "\n";
    }

    echo "\n";
}
