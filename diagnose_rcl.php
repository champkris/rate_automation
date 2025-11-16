<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

echo str_repeat('=', 100) . "\n";
echo "DIAGNOSING RCL EXCEL FILES\n";
echo str_repeat('=', 100) . "\n\n";

$rclFiles = [
    '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/attachments/FAK Rate of 1-15 NOV 25 RCL.xlsx',
    '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/attachments/FAK Rate of 1-30 NOV  on Northeast& Southeast Asia RCL.xls'
];

foreach ($rclFiles as $file) {
    echo "\nFile: " . basename($file) . "\n";
    echo str_repeat('-', 100) . "\n";

    try {
        $spreadsheet = IOFactory::load($file);
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestDataRow();
        $highestCol = $worksheet->getHighestDataColumn();

        echo "Sheet: " . $worksheet->getTitle() . "\n";
        echo "Rows: $highestRow\n";
        echo "Columns: $highestCol\n\n";

        echo "First 15 rows preview:\n";
        for ($row = 1; $row <= min(15, $highestRow); $row++) {
            echo "Row $row: ";
            for ($col = 'A'; $col <= 'H'; $col++) {
                $value = $worksheet->getCell($col . $row)->getValue();
                if (!is_null($value)) {
                    echo "$col='" . substr($value, 0, 20) . "' | ";
                }
            }
            echo "\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }

    echo "\n";
}
