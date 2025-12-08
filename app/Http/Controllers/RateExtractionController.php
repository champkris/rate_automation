<?php

namespace App\Http\Controllers;

use App\Services\RateExtractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RateExtractionController extends Controller
{
    protected RateExtractionService $extractionService;

    public function __construct(RateExtractionService $extractionService)
    {
        $this->extractionService = $extractionService;
    }

    /**
     * Show the upload form
     */
    public function index()
    {
        $patterns = $this->extractionService->getAvailablePatterns();
        return view('rate-extraction.index', compact('patterns'));
    }

    /**
     * Process the uploaded file
     */
    public function process(Request $request)
    {
        $request->validate([
            'rate_file' => 'required|file|mimes:xlsx,xls,csv,pdf|max:10240',
            'pattern' => 'required|string',
            'validity' => 'nullable|string',
        ]);

        $file = $request->file('rate_file');
        $pattern = $request->input('pattern');
        $validity = $request->input('validity') ?? '';

        // Store the file temporarily - sanitize filename to remove spaces
        $originalName = $file->getClientOriginalName();
        $sanitizedName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $filename = time() . '_' . $sanitizedName;

        // Ensure temp directory exists
        $tempDir = storage_path('app/temp_uploads');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Move file directly instead of using storeAs
        $fullPath = $tempDir . '/' . $filename;
        $file->move($tempDir, $filename);

        if (!file_exists($fullPath)) {
            return back()->with('error', 'Failed to upload file. Please try again.');
        }

        try {
            // Extract rates using the selected pattern
            $rates = $this->extractionService->extractRates($fullPath, $pattern, $validity);

            if (empty($rates)) {
                return back()->with('error', 'No rates could be extracted from the file. Please check the file format and selected pattern.');
            }

            // Generate output Excel file
            $outputFilename = 'extracted_rates_' . time() . '.xlsx';
            $outputPath = storage_path('app/extracted/' . $outputFilename);

            // Ensure directory exists
            if (!is_dir(dirname($outputPath))) {
                mkdir(dirname($outputPath), 0755, true);
            }

            $this->generateExcel($rates, $outputPath);

            // Get carrier name from pattern or rates for download filename
            // Use pattern name if specific (not auto), otherwise use carrier from rates
            $carrierName = $this->getCarrierNameFromPattern($pattern, $originalName, $rates);
            $validityPeriod = $this->getValidityPeriod($rates);
            $downloadFilename = $this->generateDownloadFilename($carrierName, $validityPeriod);

            // Store session data for download
            session([
                'extracted_file' => $outputFilename,
                'extracted_count' => count($rates),
                'carrier_summary' => $this->getCarrierSummary($rates),
                'download_filename' => $downloadFilename,
            ]);

            // Clean up temp file
            @unlink($fullPath);

            return redirect()->route('rate-extraction.result');

        } catch (\Exception $e) {
            // Clean up temp file on error
            @unlink($fullPath);

            return back()->with('error', 'Error processing file: ' . $e->getMessage());
        }
    }

    /**
     * Show extraction result
     */
    public function result()
    {
        $filename = session('extracted_file');
        $count = session('extracted_count', 0);
        $carrierSummary = session('carrier_summary', []);

        if (!$filename) {
            return redirect()->route('rate-extraction.index')
                ->with('error', 'No extraction result found. Please upload a file first.');
        }

        return view('rate-extraction.result', compact('filename', 'count', 'carrierSummary'));
    }

    /**
     * Download the extracted file
     */
    public function download($filename)
    {
        $path = storage_path('app/extracted/' . $filename);

        if (!file_exists($path)) {
            return redirect()->route('rate-extraction.index')
                ->with('error', 'File not found. Please extract again.');
        }

        // Get the download filename from session or use default
        $downloadFilename = session('download_filename', 'extracted_rates.xlsx');

        return response()->download($path, $downloadFilename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Generate Excel file from rates array
     */
    protected function generateExcel(array $rates, string $outputPath): void
    {
        $headers = [
            'CARRIER', 'POL', 'POD', 'CUR', "20'", "40'", '40 HQ', '20 TC', '20 RF', '40RF',
            'ETD BKK', 'ETD LCH', 'T/T', 'T/S', 'FREE TIME', 'VALIDITY', 'REMARK',
            'Export', 'Who use?', 'Rate Adjust', '1.1'
        ];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('FCL_EXP');

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
        foreach ($rates as $rate) {
            foreach ($headers as $index => $header) {
                $col = chr(65 + $index);
                $value = $rate[$header] ?? '';
                $sheet->setCellValue($col . $rowNum, $value);
            }

            // Apply black highlighting if flagged
            if (isset($rate['_isBlackRow']) && $rate['_isBlackRow'] === true) {
                $sheet->getStyle('A' . $rowNum . ':U' . $rowNum)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FF000000');
                $sheet->getStyle('A' . $rowNum . ':U' . $rowNum)->getFont()
                    ->getColor()->setARGB('FFFFFFFF');
            }

            $rowNum++;
        }

        // Auto-size columns
        foreach (range('A', 'U') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
    }

    /**
     * Get carrier summary from rates
     */
    protected function getCarrierSummary(array $rates): array
    {
        $summary = [];
        foreach ($rates as $rate) {
            $carrier = trim($rate['CARRIER'] ?? 'Unknown');
            if ($carrier) {
                $summary[$carrier] = ($summary[$carrier] ?? 0) + 1;
            }
        }
        arsort($summary);
        return $summary;
    }

    /**
     * Get the primary carrier from rates (most common one)
     */
    protected function getPrimaryCarrier(array $rates): string
    {
        $summary = $this->getCarrierSummary($rates);
        return array_key_first($summary) ?? 'UNKNOWN';
    }

    /**
     * Get carrier name from pattern for download filename
     * Maps pattern keys to display names (e.g., sinokor_skr -> SINOKOR_SKR)
     */
    protected function getCarrierNameFromPattern(string $pattern, string $originalFilename, array $rates): string
    {
        // Pattern name mapping
        $patternNames = [
            'rcl' => 'RCL',
            'kmtc' => 'KMTC',
            'sinokor' => 'SINOKOR',
            'sinokor_skr' => 'SINOKOR_SKR',
            'heung_a' => 'HEUNG_A',
            'boxman' => 'BOXMAN',
            'sitc' => 'SITC',
            'wanhai' => 'WANHAI',
            'ck_line' => 'CK_LINE',
            'sm_line' => 'SM_LINE',
            'dongjin' => 'DONGJIN',
            'ts_line' => 'TS_LINE',
        ];

        // If pattern is auto, detect from filename
        if ($pattern === 'auto') {
            $filename = strtoupper($originalFilename);

            // Check specific carrier names FIRST (before generic patterns like FAK RATE)
            // Order matters: more specific patterns before generic ones
            if (preg_match('/SKR.*SINOKOR|SINOKOR.*SKR/i', $filename)) {
                return 'SINOKOR_SKR';
            }
            if (preg_match('/SINOKOR/i', $filename)) return 'SINOKOR';
            if (preg_match('/WANHAI|INDIA/i', $filename)) return 'WANHAI';
            if (preg_match('/HEUNG.?A|HUANG.?A/i', $filename)) return 'HEUNG_A';
            if (preg_match('/BOXMAN/i', $filename)) return 'BOXMAN';
            if (preg_match('/SITC/i', $filename)) return 'SITC';
            if (preg_match('/T\.?S\.?\s*LINE|RATE.?1ST/i', $filename)) return 'TS_LINE';
            if (preg_match('/CK\s*LINE/i', $filename)) return 'CK_LINE';
            if (preg_match('/SM\s*LINE/i', $filename)) return 'SM_LINE';
            if (preg_match('/DONGJIN/i', $filename)) return 'DONGJIN';

            // Generic patterns (should be checked AFTER specific carrier names)
            if (preg_match('/UPDATED.?RATE/i', $filename)) return 'KMTC';
            // "FAK Rate (ASIA)" is WANHAI - check before generic FAK RATE
            if (preg_match('/FAK.?RATE.*\(?ASIA\)?/i', $filename)) return 'WANHAI';
            // "FAK Rate" without a specific carrier is RCL
            if (preg_match('/FAK.?RATE/i', $filename)) return 'RCL';

            // Fall back to carrier from rates
            return $this->getPrimaryCarrier($rates);
        }

        return $patternNames[$pattern] ?? $this->getPrimaryCarrier($rates);
    }

    /**
     * Get validity period from rates
     */
    protected function getValidityPeriod(array $rates): string
    {
        foreach ($rates as $rate) {
            $validity = trim($rate['VALIDITY'] ?? '');
            if (!empty($validity)) {
                return $validity;
            }
        }
        return strtoupper(date('M Y'));
    }

    /**
     * Generate download filename from carrier and validity
     * Example: "SINOKOR_1-30_NOV_2025.xlsx"
     */
    protected function generateDownloadFilename(string $carrier, string $validity): string
    {
        // Clean carrier name (remove special characters, keep alphanumeric and spaces)
        $cleanCarrier = preg_replace('/[^a-zA-Z0-9\s]/', '', $carrier);
        $cleanCarrier = trim($cleanCarrier);
        $cleanCarrier = str_replace(' ', '_', $cleanCarrier);

        // Clean validity (replace spaces with underscores)
        $cleanValidity = str_replace(' ', '_', $validity);
        $cleanValidity = preg_replace('/[^a-zA-Z0-9_-]/', '', $cleanValidity);

        if (empty($cleanCarrier)) {
            $cleanCarrier = 'RATES';
        }

        return strtoupper($cleanCarrier) . '_' . strtoupper($cleanValidity) . '.xlsx';
    }
}
