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
        // Clean up old temp files (older than 30 minutes)
        $this->cleanupOldTempFiles();

        $patterns = $this->extractionService->getAvailablePatterns();
        return view('rate-extraction.index', compact('patterns'));
    }

    /**
     * Process the uploaded file
     */
    public function process(Request $request)
    {
        // Increase execution time limit for batch processing (5 minutes)
        set_time_limit(300);

        $request->validate([
            'rate_files' => 'required|array|max:15',
            'rate_files.*' => 'file|mimes:xlsx,xls,csv,pdf|max:10240',
            'pattern' => 'required|string',
            'validity' => 'nullable|string',
        ]);

        $files = $request->file('rate_files');
        $pattern = $request->input('pattern');
        $validity = $request->input('validity') ?? '';
        $timestamp = time();
        $batchFiles = [];

        // Ensure extracted directory exists
        $extractedDir = storage_path('app/extracted');
        if (!is_dir($extractedDir)) {
            mkdir($extractedDir, 0755, true);
        }

        // Process each file
        foreach ($files as $index => $file) {
            // Store the file temporarily - sanitize filename to remove spaces and special characters
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            // Keep only safe ASCII characters and preserve extension
            $sanitizedName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));

            // Add loop counter for uniqueness: timestamp_index_name.extension
            $filename = $timestamp . '_' . $index . '_' . $sanitizedName . '.' . $extension;

            // Use Laravel's storage system which handles Unicode paths better
            try {
                // Store using Laravel's Storage facade (handles Unicode better)
                $storedPath = $file->storeAs('temp_uploads', $filename, 'local');

                // Get the real path - Storage::path() handles the local disk properly
                $fullPath = Storage::disk('local')->path($storedPath);

                // Verify file was stored
                if (!Storage::disk('local')->exists($storedPath)) {
                    \Log::error('File upload failed: File not found after storage - ' . $storedPath);

                    $batchFiles[] = [
                        'original_filename' => $originalName,
                        'temp_filename' => $filename,
                        'status' => 'failed',
                        'error' => 'Failed to upload file'
                    ];
                    continue;
                }

                \Log::info('File uploaded successfully: ' . $fullPath);

                // Extract rates using the selected pattern
                $rates = $this->extractionService->extractRates($fullPath, $pattern, $validity);

                if (empty($rates)) {
                    $batchFiles[] = [
                        'original_filename' => $originalName,
                        'temp_filename' => $filename,
                        'status' => 'failed',
                        'error' => 'No rates could be extracted. Please check the file format or try manual pattern selection.'
                    ];
                    continue;
                }

                // Generate output Excel file
                $outputFilename = 'extracted_rates_' . $timestamp . '_' . $index . '.xlsx';
                $outputPath = storage_path('app/extracted/' . $outputFilename);

                $this->generateExcel($rates, $outputPath);

                // Get carrier name from pattern or rates for download filename
                $carrierName = $this->getCarrierNameFromPattern($pattern, $originalName, $rates);
                $validityPeriod = $this->getValidityPeriod($rates);
                $region = $this->getRegionFromRates($rates);
                $downloadFilename = $this->generateDownloadFilename($carrierName, $validityPeriod, $region);

                // Get display name from pattern label (for showing in UI, e.g., "PIL - Africa")
                $patterns = $this->extractionService->getAvailablePatterns();
                $carrierDisplayName = $patterns[$pattern] ?? $carrierName;

                // Add to batch results
                $batchFiles[] = [
                    'original_filename' => $originalName,
                    'temp_filename' => $filename,
                    'output_filename' => $outputFilename,
                    'download_name' => $downloadFilename,
                    'carrier' => $carrierDisplayName,
                    'validity' => $validityPeriod,
                    'region' => $region,
                    'rate_count' => count($rates),
                    'status' => 'success'
                ];

                \Log::info('Successfully processed file: ' . $originalName);

            } catch (\Exception $e) {
                \Log::error('Error processing file ' . $originalName . ': ' . $e->getMessage());

                // Mark as failed
                $batchFiles[] = [
                    'original_filename' => $originalName,
                    'temp_filename' => $filename,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }

            // DON'T delete temp file - keep for 30 minutes for re-processing
        }

        // Check if any files were processed
        if (empty($batchFiles)) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => 'No files could be processed. Please try again.']);
            }
            return back()->with('error', 'No files could be processed. Please try again.');
        }

        // Store batch results in session with timestamp for cleanup
        session([
            'batch_files' => $batchFiles,
            'batch_timestamp' => $timestamp,
            'batch_patterns' => $this->extractionService->getAvailablePatterns() // For re-process dropdown
        ]);

        // Return JSON for AJAX requests, redirect for normal requests
        if ($request->ajax() || $request->wantsJson()) {
            // Explicitly save session to release lock before redirect
            session()->save();

            // Force connection close to free up single-threaded dev server
            return response()->json(['redirect' => route('rate-extraction.result')])
                ->header('Connection', 'close');
        }

        return redirect()->route('rate-extraction.result');
    }

    /**
     * Show extraction result
     */
    public function result()
    {
        $batchFiles = session('batch_files', []);

        if (empty($batchFiles)) {
            return redirect()->route('rate-extraction.index')
                ->with('error', 'No extraction result found. Please upload files first.');
        }

        return view('rate-extraction.result', compact('batchFiles'));
    }

    /**
     * Re-process a failed file with user-selected pattern
     */
    public function reprocess(Request $request)
    {
        $request->validate([
            'filename' => 'required|string',
            'pattern' => 'required|string',
        ]);

        $tempFilename = $request->input('filename');
        $pattern = $request->input('pattern');
        $validity = '';

        // Get full path to temp file
        $fullPath = Storage::disk('local')->path('temp_uploads/' . $tempFilename);

        if (!file_exists($fullPath)) {
            return back()->with('error', 'File not found. It may have been cleaned up. Please upload again.');
        }

        // Get batch files from session
        $batchFiles = session('batch_files', []);
        $batchTimestamp = session('batch_timestamp', time());

        // Find the file in batch
        $fileIndex = null;
        foreach ($batchFiles as $index => $file) {
            if ($file['temp_filename'] === $tempFilename) {
                $fileIndex = $index;
                break;
            }
        }

        if ($fileIndex === null) {
            return back()->with('error', 'File not found in batch results.');
        }

        try {
            // Re-extract rates with user-selected pattern
            $rates = $this->extractionService->extractRates($fullPath, $pattern, $validity);

            if (empty($rates)) {
                return back()->with('error', 'Still could not extract rates with selected pattern. Please try another pattern.');
            }

            // Generate output Excel file
            $outputFilename = 'extracted_rates_' . $batchTimestamp . '_' . $fileIndex . '.xlsx';
            $outputPath = storage_path('app/extracted/' . $outputFilename);

            // Ensure directory exists
            if (!is_dir(dirname($outputPath))) {
                mkdir(dirname($outputPath), 0755, true);
            }

            $this->generateExcel($rates, $outputPath);

            // Get carrier info
            $originalFilename = $batchFiles[$fileIndex]['original_filename'];
            $carrierName = $this->getCarrierNameFromPattern($pattern, $originalFilename, $rates);
            $validityPeriod = $this->getValidityPeriod($rates);
            $region = $this->getRegionFromRates($rates);
            $downloadFilename = $this->generateDownloadFilename($carrierName, $validityPeriod, $region);

            // Get display name from pattern label (for showing in UI, e.g., "PIL - Africa")
            $patterns = $this->extractionService->getAvailablePatterns();
            $carrierDisplayName = $patterns[$pattern] ?? $carrierName;

            // Update batch file status to success
            $batchFiles[$fileIndex] = [
                'original_filename' => $originalFilename,
                'temp_filename' => $tempFilename,
                'output_filename' => $outputFilename,
                'download_name' => $downloadFilename,
                'carrier' => $carrierDisplayName,
                'validity' => $validityPeriod,
                'region' => $region,
                'rate_count' => count($rates),
                'status' => 'success'
            ];

            // Update session
            session(['batch_files' => $batchFiles]);

            return redirect()->route('rate-extraction.result')
                ->with('success', 'File re-processed successfully!');

        } catch (\Exception $e) {
            \Log::error('Error re-processing file: ' . $e->getMessage());
            return back()->with('error', 'Error re-processing file: ' . $e->getMessage());
        }
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

        // Get the download filename from batch_files session data
        $downloadFilename = 'extracted_rates.xlsx'; // default fallback

        // Extract index from filename (format: extracted_rates_{timestamp}_{index}.xlsx)
        if (preg_match('/extracted_rates_\d+_(\d+)\.xlsx/', $filename, $matches)) {
            $index = (int)$matches[1];
            $batchFiles = session('batch_files', []);
            if (isset($batchFiles[$index]['download_name'])) {
                $downloadFilename = $batchFiles[$index]['download_name'];
            }
        }

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
            'pil_africa' => 'PIL',
            'pil_intra_asia' => 'PIL',
            'pil_latin_america' => 'PIL',
            'pil_oceania' => 'PIL',
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
            'ial' => 'INTER_ASIA',
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
            if (preg_match('/INTER.?ASIA|IAL/i', $filename)) return 'INTER_ASIA';

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
        // First check for _validity_for_filename metadata (for documents with multiple validities)
        foreach ($rates as $rate) {
            if (isset($rate['_validity_for_filename']) && !empty($rate['_validity_for_filename'])) {
                return $rate['_validity_for_filename'];
            }
        }

        // Otherwise use the first VALIDITY found
        foreach ($rates as $rate) {
            $validity = trim($rate['VALIDITY'] ?? '');
            if (!empty($validity)) {
                return $validity;
            }
        }
        return strtoupper(date('M Y'));
    }

    /**
     * Get region from rates (for PIL carriers with region metadata)
     */
    protected function getRegionFromRates(array $rates): ?string
    {
        // Check if any rate has region metadata
        foreach ($rates as $rate) {
            if (isset($rate['_region'])) {
                return $rate['_region'];
            }
        }
        return null;
    }

    /**
     * Generate download filename from carrier, validity, and region
     * Example: "PIL_Africa_1-30_NOV_2025.xlsx" or "SINOKOR_1-30_NOV_2025.xlsx"
     */
    protected function generateDownloadFilename(string $carrier, string $validity, ?string $region = null): string
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

        // If region is provided (for PIL), include it in filename
        // Format: PIL_Africa_date_month or PIL_Intra_Asia_date_month
        if (!empty($region)) {
            return strtoupper($cleanCarrier) . '_' . $region . '_' . strtoupper($cleanValidity) . '.xlsx';
        }

        return strtoupper($cleanCarrier) . '_' . strtoupper($cleanValidity) . '.xlsx';
    }

    /**
     * Clean up temp files older than 30 minutes
     */
    protected function cleanupOldTempFiles(): void
    {
        try {
            $tempPath = Storage::disk('local')->path('temp_uploads');

            if (!is_dir($tempPath)) {
                return;
            }

            $now = time();
            $maxAge = 30 * 60; // 30 minutes in seconds

            $files = Storage::disk('local')->files('temp_uploads');

            foreach ($files as $file) {
                $fullPath = Storage::disk('local')->path($file);

                // Check if file exists and is older than 30 minutes
                if (file_exists($fullPath)) {
                    $fileAge = $now - filemtime($fullPath);

                    if ($fileAge > $maxAge) {
                        @unlink($fullPath);
                        \Log::info('Cleaned up old temp file: ' . $file . ' (age: ' . round($fileAge / 60, 1) . ' minutes)');
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error cleaning up temp files: ' . $e->getMessage());
        }
    }
}
