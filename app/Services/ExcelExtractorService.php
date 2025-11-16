<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\RateCard;
use App\Models\ProcessingLog;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Support\Facades\Storage;
use Exception;

class ExcelExtractorService
{
    /**
     * Extract rate cards from an Excel attachment
     */
    public function extract(Attachment $attachment): array
    {
        try {
            $attachment->markAsProcessing();

            $filePath = Storage::path($attachment->file_path);

            if (!file_exists($filePath)) {
                throw new Exception("File not found: {$filePath}");
            }

            $spreadsheet = IOFactory::load($filePath);
            $rateCards = [];

            // Process each sheet (could represent different rate types)
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheetName = $sheet->getTitle();
                $rateType = $this->determineRateType($sheetName, $attachment->email->subject ?? '');

                $extractedRates = $this->extractFromSheet($sheet, $attachment->email_id, $rateType);
                $rateCards = array_merge($rateCards, $extractedRates);
            }

            $attachment->markAsCompleted();

            ProcessingLog::logSuccess(
                'excel_extract',
                "Extracted " . count($rateCards) . " rate cards from Excel file: {$attachment->filename}",
                $attachment->email_id,
                ['attachment_id' => $attachment->id, 'count' => count($rateCards)]
            );

            return $rateCards;
        } catch (Exception $e) {
            $attachment->markAsFailed($e->getMessage());

            ProcessingLog::logFailure(
                'excel_extract',
                "Failed to extract data from Excel file: {$attachment->filename}",
                $e,
                $attachment->email_id,
                ['attachment_id' => $attachment->id]
            );

            throw $e;
        }
    }

    /**
     * Extract rate data from a single sheet
     */
    private function extractFromSheet($sheet, int $emailId, ?string $rateType): array
    {
        $data = $sheet->toArray();
        $rateCards = [];

        // Find header row (look for key columns like "POL", "POD", "Rate", etc.)
        $headerRow = $this->findHeaderRow($data);

        if ($headerRow === null) {
            ProcessingLog::logFailure(
                'excel_extract',
                "Could not find header row in sheet: {$sheet->getTitle()}",
                null,
                $emailId
            );
            return [];
        }

        $columnMap = $this->mapColumns($data[$headerRow]);

        // Extract data rows
        for ($i = $headerRow + 1; $i < count($data); $i++) {
            $row = $data[$i];

            // Skip empty rows
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $rateCard = $this->parseRow($row, $columnMap, $emailId, $rateType);

            if ($rateCard) {
                $rateCards[] = RateCard::create($rateCard);
            }
        }

        return $rateCards;
    }

    /**
     * Determine rate type from sheet name or email subject
     */
    private function determineRateType(string $sheetName, string $emailSubject): ?string
    {
        $text = strtoupper($sheetName . ' ' . $emailSubject);

        if (str_contains($text, 'FCL') && str_contains($text, 'IMPORT') || str_contains($text, 'FCL IM')) {
            return 'FCL_IMPORT';
        } elseif (str_contains($text, 'FCL') && str_contains($text, 'EXPORT') || str_contains($text, 'FCL EXP')) {
            return 'FCL_EXPORT';
        } elseif (str_contains($text, 'LCL') && str_contains($text, 'IMPORT') || str_contains($text, 'LCL IM')) {
            return 'LCL_IMPORT';
        } elseif (str_contains($text, 'LCL') && str_contains($text, 'EXPORT') || str_contains($text, 'LCL EXP')) {
            return 'LCL_EXPORT';
        }

        return null;
    }

    /**
     * Find the header row in the data
     */
    private function findHeaderRow(array $data): ?int
    {
        $headerKeywords = [
            'pol', 'pod', 'origin', 'destination', 'port', 'rate', 'price',
            'carrier', 'shipping', 'line', 'freight', 'container', '20', '40'
        ];

        foreach ($data as $index => $row) {
            $rowText = strtolower(implode(' ', array_filter($row, 'is_string')));

            $matchCount = 0;
            foreach ($headerKeywords as $keyword) {
                if (str_contains($rowText, $keyword)) {
                    $matchCount++;
                }
            }

            // If we find at least 3 header keywords, consider it the header row
            if ($matchCount >= 3) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Map column names to indices
     */
    private function mapColumns(array $headerRow): array
    {
        $map = [
            'carrier' => null,
            'origin_port' => null,
            'destination_port' => null,
            'origin_city' => null,
            'destination_city' => null,
            'origin_country' => null,
            'destination_country' => null,
            'rate' => null,
            'currency' => null,
            'container_type' => null,
            'service_type' => null,
            'effective_date' => null,
            'expiry_date' => null,
            'remarks' => null,
        ];

        foreach ($headerRow as $index => $header) {
            if (!$header) {
                continue;
            }

            $headerLower = strtolower(trim($header));

            // Match common column patterns
            if (preg_match('/carrier|shipping\s*line|line/i', $headerLower)) {
                $map['carrier'] = $index;
            } elseif (preg_match('/pol|port.*origin|origin.*port|from/i', $headerLower)) {
                $map['origin_port'] = $index;
            } elseif (preg_match('/pod|port.*destination|destination.*port|to/i', $headerLower)) {
                $map['destination_port'] = $index;
            } elseif (preg_match('/rate|price|freight|cost/i', $headerLower) && !str_contains($headerLower, 'valid')) {
                $map['rate'] = $index;
            } elseif (preg_match('/curr|usd|eur/i', $headerLower)) {
                $map['currency'] = $index;
            } elseif (preg_match('/20|40|container|cntr/i', $headerLower)) {
                $map['container_type'] = $index;
            } elseif (preg_match('/service|type/i', $headerLower)) {
                $map['service_type'] = $index;
            } elseif (preg_match('/effective|valid\s*from|start/i', $headerLower)) {
                $map['effective_date'] = $index;
            } elseif (preg_match('/expir|valid\s*to|valid\s*until|end/i', $headerLower)) {
                $map['expiry_date'] = $index;
            } elseif (preg_match('/remark|note|comment/i', $headerLower)) {
                $map['remarks'] = $index;
            }
        }

        return $map;
    }

    /**
     * Parse a data row into rate card data
     */
    private function parseRow(array $row, array $columnMap, int $emailId, ?string $rateType): ?array
    {
        // Extract basic rate card data
        $rateCard = [
            'email_id' => $emailId,
            'carrier' => $this->getValue($row, $columnMap['carrier']),
            'origin_port' => $this->getValue($row, $columnMap['origin_port']),
            'destination_port' => $this->getValue($row, $columnMap['destination_port']),
            'rate' => $this->parseNumeric($this->getValue($row, $columnMap['rate'])),
            'currency' => $this->getValue($row, $columnMap['currency']) ?? 'USD',
            'container_type' => $this->getValue($row, $columnMap['container_type']),
            'service_type' => $rateType,
            'effective_date' => $this->parseDate($this->getValue($row, $columnMap['effective_date'])),
            'expiry_date' => $this->parseDate($this->getValue($row, $columnMap['expiry_date'])),
            'remarks' => $this->getValue($row, $columnMap['remarks']),
            'raw_data' => $row, // Store original row data
        ];

        // Validate required fields
        if (empty($rateCard['origin_port']) && empty($rateCard['destination_port'])) {
            return null;
        }

        return $rateCard;
    }

    /**
     * Get value from row by column index
     */
    private function getValue(array $row, ?int $index): ?string
    {
        if ($index === null || !isset($row[$index])) {
            return null;
        }

        $value = $row[$index];
        return is_string($value) || is_numeric($value) ? trim((string)$value) : null;
    }

    /**
     * Parse numeric value (remove currency symbols, commas, etc.)
     */
    private function parseNumeric(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Remove currency symbols and commas
        $cleaned = preg_replace('/[^\d.-]/', '', $value);
        return is_numeric($cleaned) ? (float)$cleaned : null;
    }

    /**
     * Parse date value
     */
    private function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $timestamp = strtotime($value);
            return $timestamp ? date('Y-m-d', $timestamp) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Check if row is empty
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }
        return true;
    }
}
