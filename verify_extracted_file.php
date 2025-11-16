<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

ini_set('memory_limit', '512M');

$filepath = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/output/EXTRACTED_RATES_FCL_EXP.xlsx';

echo "Verifying extracted file...\n";
echo str_repeat('=', 100) . "\n\n";

try {
    $spreadsheet = IOFactory::load($filepath);
    $worksheet = $spreadsheet->getActiveSheet();
    $highestRow = $worksheet->getHighestDataRow();
    $highestColumn = $worksheet->getHighestDataColumn();

    echo "Sheet: " . $worksheet->getTitle() . "\n";
    echo "Size: {$highestColumn}{$highestRow}\n";
    echo "Total rows (including header): $highestRow\n";
    echo "Total rate entries: " . ($highestRow - 1) . "\n\n";

    // Show first 20 rows
    echo "Sample data (first 20 rows):\n";
    echo str_repeat('-', 100) . "\n";

    for ($row = 1; $row <= min(20, $highestRow); $row++) {
        $rowData = $worksheet->rangeToArray("A{$row}:U{$row}", null, true, false, false);

        if ($row == 1) {
            // Header row
            echo "Row $row (HEADER):\n";
            foreach ($rowData[0] as $index => $value) {
                if ($value) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                    echo "  $colLetter: $value\n";
                }
            }
        } else {
            // Data rows
            $carrier = $rowData[0][0] ?? '';
            $pol = $rowData[0][1] ?? '';
            $pod = $rowData[0][2] ?? '';
            $rate20 = $rowData[0][4] ?? '';
            $rate40 = $rowData[0][5] ?? '';
            $validity = $rowData[0][15] ?? '';

            if ($carrier) {
                echo "Row " . str_pad($row, 3, ' ', STR_PAD_LEFT) . ": ";
                echo "$carrier | $pol -> " . substr($pod, 0, 30) . "... | ";
                echo "20'=$rate20 40'=$rate40 | Valid: $validity\n";
            }
        }
    }

    if ($highestRow > 20) {
        echo "\n... and " . ($highestRow - 20) . " more rows\n";
    }

    echo "\n" . str_repeat('-', 100) . "\n";

    // Group by carrier
    echo "\nBreakdown by Carrier:\n";
    $carrierCounts = [];
    for ($row = 2; $row <= $highestRow; $row++) {
        $carrier = trim($worksheet->getCell("A{$row}")->getValue() ?? '');
        if ($carrier) {
            $carrierCounts[$carrier] = ($carrierCounts[$carrier] ?? 0) + 1;
        }
    }

    foreach ($carrierCounts as $carrier => $count) {
        echo "  $carrier: $count rates\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 100) . "\n";
