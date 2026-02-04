# Multi-File Upload System - Implementation Plan

**Date:** 2026-02-04
**Status:** ✅ Ready for Implementation
**Estimated Time:** 8-10 hours
**Approach:** Synchronous processing with client-side progress estimation

---

## Project Goal

Build a multi-file upload system that:
- Accepts up to 15 PDF/Excel rate card files per batch
- Auto-detects shipping line for each file
- Processes each file independently
- Outputs **ONE separate Excel file per input file** (1:1 mapping)
- Example: 2 PIL Africa PDFs + 1 KMTC PDF → 3 separate output Excel files

---

## Finalized Features

### 1. Max 15 Files Per Batch ✅
**Implementation:** Simple validation
```php
'rate_files' => 'required|array|max:15'
'rate_files.*' => 'file|mimes:xlsx,xls,csv,pdf|max:10240'
```

---

### 2. Client-Side Progress Bar with Time Estimation ✅
**Approach:** "Fake progress bar" using client-side calculation

**Time Estimates:**
- PDF file = 9 seconds
- Excel file = 4 seconds

**Behavior:**
- Calculate total estimated time: `(PDF_count × 9) + (Excel_count × 4)` seconds
- Display as percentage (not time)
- Progress bar increments smoothly to 95% maximum
- If processing finishes first → fast-forward to 100%
- If processing takes longer → cap at 95% until complete, then jump to 100%

**Implementation:**
```javascript
// On form submit
const totalTime = (pdfCount * 9 + excelCount * 4) * 1000; // milliseconds
let progress = 0;
const interval = setInterval(() => {
    progress += 1;
    if (progress >= 95) {
        clearInterval(interval);
        // Wait for actual completion
    }
    updateProgressBar(progress);
}, totalTime / 95);
```

**Why this approach:**
- ✅ Simple frontend-only implementation
- ✅ No AJAX polling needed
- ✅ No backend changes required
- ✅ Better UX than silent processing
- ✅ Works with synchronous processing

---

### 3. Results Summary (After Processing) ✅
**Approach:** Show summary AFTER all files processed (not preview before)

**Changed from original "Preview Mode":**
- ❌ NO preview before processing
- ✅ Show progress bar during processing (Feature 2)
- ✅ Show results summary AFTER processing completes

**Summary displays:**
```
✓ PIL_Africa_Jan_2025.pdf → PIL_AFRICA_JAN_2025.xlsx (245 rates)
✓ KMTC_Updated_Feb.xlsx → KMTC_FEB_2025.xlsx (180 rates)
✗ unknown_rate_card.pdf → FAILED (Could not detect carrier) [Re-process option]
✓ PIL_Oceania.pdf → PIL_OCEANIA_JAN_2025.xlsx (320 rates)
```

**For each file:**
- Input filename → Output Excel filename
- Validity period
- Rate count
- Success/failure status

---

### 4. Manual Pattern Selection for Failed Files ✅
**Scenario:** File auto-detection fails during processing

**Solution:**
- In results summary, show failed file with dropdown
- User manually selects carrier pattern (KMTC, PIL - Africa, SINOKOR, etc.)
- Click "Re-process" for that specific file only
- System re-processes with user-selected pattern
- Updates summary with new result

**Implementation:**
- New route: `POST /extract/reprocess`
- Request body: `{filename: 'xxx.pdf', pattern: 'kmtc'}`
- Keep original uploaded files for 30 minutes (see Storage section)

---

### 5. Hybrid Download Approach ✅
**Primary:** File System Access API (Chrome/Edge)
**Fallback:** Individual download buttons (Firefox/Safari)

**User Flow:**

**Step 1:** User clicks "Download All Files" button

**Step 2:** JavaScript checks browser support
```javascript
if ('showDirectoryPicker' in window) {
    // Chrome/Edge path
} else {
    // Firefox/Safari fallback
}
```

**Step 3a - Chrome/Edge (80%+ users):**
- Show folder picker
- User selects destination folder ONCE
- All files download to selected folder
- No multiple prompts ✅

**Step 3b - Firefox/Safari:**
- Hide "Download All" button
- Show Thai message:
  ```
  Browser ไม่ support Download All
  รบกวนกด Download ทีละไฟล์นะครับ
  ```
- Display individual download buttons for each file
- User clicks each file manually

**Implementation:**
```javascript
async function downloadAll() {
    if ('showDirectoryPicker' in window) {
        const directoryHandle = await window.showDirectoryPicker();
        for (const file of files) {
            const response = await fetch(`/extract/download/${file.filename}`);
            const blob = await response.blob();
            const fileHandle = await directoryHandle.getFileHandle(file.download_name, {create: true});
            const writable = await fileHandle.createWritable();
            await writable.write(blob);
            await writable.close();
        }
        alert('ดาวน์โหลดไฟล์ทั้งหมดเรียบร้อยแล้ว!');
    } else {
        showFallbackDownloads();
    }
}
```

**Browser Support:**
| Browser | File System Access API |
|---------|----------------------|
| Chrome | ✅ Yes (86+) |
| Edge | ✅ Yes (86+) |
| Firefox | ❌ No |
| Safari | ❌ No |

---

## System Architecture

### Current Flow (Single File)
```
Upload Form → Validate → Store Temp → Detect Pattern → Extract Rates →
Generate Excel → Store in Session → Result Page → Download → Auto-Delete
```

### New Flow (Multi-File)
```
Upload Form (select multiple files, max 15)
  ↓
Validate (file count, types, sizes)
  ↓
Store all files in temp storage with unique names
  ↓
Processing Page (show progress bar with time estimation)
  ↓
Process each file sequentially (detect → extract → generate Excel)
  ↓
Results Summary Page (show all files with status)
  ↓
Download All (File System Access API or fallback to individual buttons)
  ↓
Auto-cleanup after 30 minutes
```

---

## File Storage

### Storage Locations
**1. Original uploaded files (for re-processing):**
```
storage/app/temp_uploads/
```
From [RateExtractionController.php:54](app/Http/Controllers/RateExtractionController.php#L54)

**2. Generated Excel output files:**
```
storage/app/extracted/
```
From [RateExtractionController.php:81](app/Http/Controllers/RateExtractionController.php#L81)

### Cleanup Policy
**Original uploads:**
- Currently: Deleted immediately after processing (line 106)
- **New:** Keep for 30 minutes to allow re-processing
- Delete after 30 minutes (session-based cleanup)

**Generated Excel files:**
- Keep current behavior: Delete after download (line 152)
- `->deleteFileAfterSend(true)`

### Filename Collision Handling
**Problem:** Multiple files with same name uploaded in one batch

**Solution:** Add loop counter to filename
```php
foreach ($files as $index => $file) {
    $filename = time() . '_' . $index . '_' . $sanitizedName . '.' . $extension;
}
```

**Result:**
```
1738641600_0_PIL_Africa.pdf
1738641600_1_PIL_Africa.pdf
1738641600_2_KMTC_Rate.xlsx
```

---

## Technical Implementation

### 1. Upload Form Changes
**File:** `resources/views/rate-extraction/index.blade.php`

**Changes:**
- Line 43: Change `<input type="file" name="rate_file">` to:
  ```html
  <input type="file" name="rate_files[]" id="rate_files" multiple accept=".xlsx,.xls,.csv,.pdf">
  ```
- Update JavaScript to handle multiple files
- Show selected files list with count
- Add file type detection (PDF/Excel) for progress calculation

---

### 2. Controller Updates
**File:** `app/Http/Controllers/RateExtractionController.php`

**Changes:**

**a) Update validation (line 34):**
```php
$request->validate([
    'rate_files' => 'required|array|max:15',
    'rate_files.*' => 'file|mimes:xlsx,xls,csv,pdf|max:10240',
    'pattern' => 'required|string',
    'validity' => 'nullable|string',
]);
```

**b) Modify `process()` method to loop through files:**
```php
$files = $request->file('rate_files');
$batchFiles = [];

foreach ($files as $index => $file) {
    // Generate unique filename with index
    $filename = time() . '_' . $index . '_' . $sanitizedName . '.' . $extension;

    // Store temporarily
    $storedPath = $file->storeAs('temp_uploads', $filename, 'local');

    try {
        // Extract rates
        $rates = $this->extractionService->extractRates($fullPath, $pattern, $validity);

        // Generate Excel
        $outputFilename = 'extracted_rates_' . time() . '_' . $index . '.xlsx';
        $this->generateExcel($rates, $outputPath);

        // Add to batch results
        $batchFiles[] = [
            'original_filename' => $file->getClientOriginalName(),
            'temp_filename' => $filename,
            'output_filename' => $outputFilename,
            'download_name' => $this->generateDownloadFilename(...),
            'carrier' => $this->getCarrierNameFromPattern(...),
            'validity' => $this->getValidityPeriod($rates),
            'rate_count' => count($rates),
            'status' => 'success'
        ];
    } catch (\Exception $e) {
        // Mark as failed
        $batchFiles[] = [
            'original_filename' => $file->getClientOriginalName(),
            'temp_filename' => $filename,
            'status' => 'failed',
            'error' => $e->getMessage()
        ];
    }

    // DON'T delete temp file yet (keep for re-processing)
}

// Store batch results in session
session(['batch_files' => $batchFiles]);
```

**c) Add new `reprocess()` method:**
```php
public function reprocess(Request $request)
{
    $request->validate([
        'filename' => 'required|string',
        'pattern' => 'required|string',
    ]);

    $tempFilename = $request->input('filename');
    $pattern = $request->input('pattern');

    $fullPath = Storage::disk('local')->path('temp_uploads/' . $tempFilename);

    if (!file_exists($fullPath)) {
        return back()->with('error', 'File not found. It may have been cleaned up.');
    }

    // Re-process with user-selected pattern
    // ... (similar to process() but for single file)

    // Update session batch_files
    // Return to results page
}
```

**d) Update `result()` method to handle batch:**
```php
public function result()
{
    $batchFiles = session('batch_files', []);

    if (empty($batchFiles)) {
        return redirect()->route('rate-extraction.index')
            ->with('error', 'No extraction result found.');
    }

    return view('rate-extraction.result', compact('batchFiles'));
}
```

---

### 3. New Route
**File:** `routes/web.php`

**Add:**
```php
Route::post('/extract/reprocess', [RateExtractionController::class, 'reprocess'])
    ->name('rate-extraction.reprocess');
```

---

### 4. Result View Updates
**File:** `resources/views/rate-extraction/result.blade.php`

**New structure:**

```html
<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">Extraction Results</h1>

    <!-- Summary Stats -->
    <div class="mb-6">
        <p>Total: {{ count($batchFiles) }} files</p>
        <p>Success: {{ collect($batchFiles)->where('status', 'success')->count() }}</p>
        <p>Failed: {{ collect($batchFiles)->where('status', 'failed')->count() }}</p>
    </div>

    <!-- File Results -->
    @foreach($batchFiles as $file)
    <div class="bg-white rounded-lg shadow-md p-4 mb-4">
        @if($file['status'] === 'success')
            <!-- Success -->
            <div class="flex items-center">
                <svg class="text-green-500">✓</svg>
                <div>
                    <p>{{ $file['original_filename'] }}</p>
                    <p>→ {{ $file['download_name'] }} ({{ $file['rate_count'] }} rates)</p>
                    <p>{{ $file['carrier'] }} | {{ $file['validity'] }}</p>
                </div>
            </div>
        @else
            <!-- Failed -->
            <div class="flex items-center">
                <svg class="text-red-500">✗</svg>
                <div>
                    <p>{{ $file['original_filename'] }}</p>
                    <p class="text-red-600">{{ $file['error'] }}</p>

                    <!-- Manual pattern selection -->
                    <form action="{{ route('rate-extraction.reprocess') }}" method="POST">
                        @csrf
                        <input type="hidden" name="filename" value="{{ $file['temp_filename'] }}">
                        <select name="pattern">
                            @foreach($patterns as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <button type="submit">Re-process</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
    @endforeach

    <!-- Download All Button -->
    <div class="text-center mt-8">
        <button id="downloadAllBtn" onclick="downloadAll()" class="bg-blue-600 text-white px-6 py-3 rounded-lg">
            📥 Download All Files
        </button>

        <!-- Fallback message (hidden initially) -->
        <div id="fallbackMessage" class="hidden mt-4">
            <div class="bg-orange-100 border border-orange-400 text-orange-700 px-4 py-3 rounded">
                <p class="font-bold">Browser ไม่ support Download All</p>
                <p>รบกวนกด Download ทีละไฟล์นะครับ</p>
            </div>
        </div>

        <!-- Individual downloads (hidden initially) -->
        <div id="individualDownloads" class="hidden mt-4 space-y-2">
            @foreach($batchFiles as $file)
                @if($file['status'] === 'success')
                <a href="/extract/download/{{ $file['output_filename'] }}"
                   download="{{ $file['download_name'] }}"
                   class="block bg-white border px-4 py-2 rounded hover:bg-gray-50">
                    📄 {{ $file['download_name'] }}
                </a>
                @endif
            @endforeach
        </div>
    </div>
</div>

<script>
const files = @json(collect($batchFiles)->where('status', 'success')->values());

async function downloadAll() {
    if ('showDirectoryPicker' in window) {
        // File System Access API (Chrome/Edge)
        try {
            const directoryHandle = await window.showDirectoryPicker();

            for (const file of files) {
                const response = await fetch(`/extract/download/${file.output_filename}`);
                const blob = await response.blob();

                const fileHandle = await directoryHandle.getFileHandle(file.download_name, {create: true});
                const writable = await fileHandle.createWritable();
                await writable.write(blob);
                await writable.close();
            }

            alert('ดาวน์โหลดไฟล์ทั้งหมดเรียบร้อยแล้ว!');
        } catch (err) {
            if (err.name === 'AbortError') {
                return; // User cancelled
            }
            alert('เกิดข้อผิดพลาด: ' + err.message);
        }
    } else {
        // Fallback for Firefox/Safari
        document.getElementById('downloadAllBtn').classList.add('hidden');
        document.getElementById('fallbackMessage').classList.remove('hidden');
        document.getElementById('individualDownloads').classList.remove('hidden');
    }
}
</script>
```

---

### 5. Processing Page with Progress Bar
**New file:** `resources/views/rate-extraction/processing.blade.php`

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing Files...</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full bg-white rounded-lg shadow-md p-8">
        <h2 class="text-2xl font-bold text-center mb-6">Processing Files</h2>

        <!-- Progress Bar -->
        <div class="mb-4">
            <div class="bg-gray-200 rounded-full h-8">
                <div id="progressBar" class="bg-blue-600 h-8 rounded-full transition-all duration-300" style="width: 0%">
                    <span id="progressText" class="text-white font-bold px-4 leading-8">0%</span>
                </div>
            </div>
        </div>

        <p class="text-center text-gray-600">Please wait while we process your files...</p>

        <!-- Hidden form that submits after showing progress -->
        <form id="processForm" action="{{ route('rate-extraction.process') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <!-- Files and data passed from previous page -->
        </form>
    </div>

    <script>
        const fileData = @json($fileData); // {pdfCount: 2, excelCount: 3}
        const totalTime = (fileData.pdfCount * 9 + fileData.excelCount * 4) * 1000;

        let progress = 0;
        const increment = 95 / (totalTime / 100); // Update every 100ms to reach 95%

        const interval = setInterval(() => {
            progress += increment;
            if (progress >= 95) {
                progress = 95;
                clearInterval(interval);
            }

            document.getElementById('progressBar').style.width = Math.floor(progress) + '%';
            document.getElementById('progressText').textContent = Math.floor(progress) + '%';
        }, 100);

        // Submit form immediately (processing happens while progress bar shows)
        document.getElementById('processForm').submit();

        // When page redirects to result, progress will be at 95% or jump to 100%
    </script>
</body>
</html>
```

---

### 6. Session Data Structure

```php
session([
    'batch_files' => [
        [
            'original_filename' => 'PIL_Africa_Jan_2025.pdf',
            'temp_filename' => '1738641600_0_PIL_Africa_Jan_2025.pdf',
            'output_filename' => 'extracted_rates_1738641600_0.xlsx',
            'download_name' => 'PIL_AFRICA_JAN_2025.xlsx',
            'carrier' => 'PIL - Africa',
            'validity' => 'JAN 2025',
            'rate_count' => 245,
            'status' => 'success'
        ],
        [
            'original_filename' => 'unknown_rate.pdf',
            'temp_filename' => '1738641600_1_unknown_rate.pdf',
            'status' => 'failed',
            'error' => 'Could not detect carrier pattern'
        ]
    ]
]);
```

---

## Key Components

- **Controller:** `app/Http/Controllers/RateExtractionController.php`
- **Service:** `app/Services/RateExtractionService.php` (no changes needed)
- **Upload View:** `resources/views/rate-extraction/index.blade.php`
- **Processing View:** `resources/views/rate-extraction/processing.blade.php` (new)
- **Result View:** `resources/views/rate-extraction/result.blade.php`
- **Routes:** `routes/web.php`

---

## Testing Plan

### Test Scenarios:
1. ✅ Upload 1 file (backward compatibility)
2. ✅ Upload 5 files, all same carrier
3. ✅ Upload 10 files, mixed carriers (PDF + Excel)
4. ✅ Upload 15 files (max limit)
5. ✅ Upload 16 files (should fail validation)
6. ✅ Upload 2 files with same name (test filename collision handling)
7. ✅ Upload file with undetectable carrier (test manual pattern selection)
8. ✅ Test re-process functionality after failed detection
9. ✅ Test download all in Chrome (File System Access API)
10. ✅ Test download all in Firefox (fallback to individual buttons)
11. ✅ Test progress bar with different file mixes
12. ✅ Test 30-minute cleanup (upload, wait, verify deletion)
13. ✅ Test with PIL files (multiple regions)
14. ✅ Test session data persistence across requests

---

## Performance Considerations

### Processing Time Estimates:
- Excel file: ~4 seconds
- PDF file: ~9 seconds

### Batch Estimates:
- 5 Excel files: ~20 seconds
- 10 mixed files: ~60 seconds
- 15 PDF files (worst case): ~135 seconds (~2.3 minutes)

### PHP Configuration:
```ini
max_execution_time = 300  ; 5 minutes
memory_limit = 512M
post_max_size = 160M      ; 15 files × 10MB each + overhead
upload_max_filesize = 10M
```

---

## Implementation Phases

### Phase 1: Form & Validation (1-2 hours)
- Update upload form for multiple files
- Add file count display
- Update validation rules
- Test file selection UI

### Phase 2: Processing Logic (3-4 hours)
- Update controller to loop through files
- Implement unique filename generation
- Handle batch results in session
- Add error handling for individual files

### Phase 3: Results & Re-processing (2-3 hours)
- Update result view for batch display
- Add manual pattern selection UI
- Implement re-process functionality
- Test failed file handling

### Phase 4: Download Functionality (2-3 hours)
- Implement File System Access API
- Add fallback for unsupported browsers
- Test in Chrome, Edge, Firefox, Safari

### Phase 5: Progress Bar (1 hour)
- Add client-side progress estimation
- Test with different file combinations

### Phase 6: Cleanup & Testing (1-2 hours)
- Implement 30-minute file cleanup
- Comprehensive testing
- Bug fixes

**Total: 10-15 hours**

---

## Related Documentation

- **PIL Oceania Implementation:** `md_docs/PIL_Oceania(Australia)_v1.md`
- **Current Controller:** `app/Http/Controllers/RateExtractionController.php`
- **Current Service:** `app/Services/RateExtractionService.php`
- **Supported Carriers:** 16 patterns (RCL, KMTC, PIL regions, SINOKOR, etc.)

---

**Status:** ✅ All decisions finalized - Ready for implementation

**Last Updated:** 2026-02-04 (Session 2 - Final Planning)
