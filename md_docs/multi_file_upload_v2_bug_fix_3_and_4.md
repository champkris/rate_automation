# Multi-File Upload V2 - Bug Fix 3 & 4

**Date:** 2026-02-05
**Status:** Fixed

---

## Bug 3: Downloaded File Name Incorrect

### Symptom
The GUI displays the correct filename (e.g., `PIL_Africa_1-14_JAN_2026.xlsx`), but when clicking the Download button, the actual downloaded file is always named `extracted_rates.xlsx`.

### Root Cause
The `download()` method in `RateExtractionController.php` was reading from a **single** session key `'download_filename'` (old single-file approach):

```php
// OLD CODE (line 288)
$downloadFilename = session('download_filename', 'extracted_rates.xlsx');
```

However, with batch/multi-file upload, each file has its own `download_name` stored in the `batch_files` session array:
```php
session('batch_files')[$index]['download_name']
```

The old code always fell back to `'extracted_rates.xlsx'` because `'download_filename'` key was never set.

### Fix Location
**File:** `app/Http/Controllers/RateExtractionController.php`
**Lines:** 279-289

### What Changed

**Before:**
```php
// Get the download filename from session or use default
$downloadFilename = session('download_filename', 'extracted_rates.xlsx');
```

**After:**
```php
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
```

### How It Works

1. The server-side filename follows the pattern: `extracted_rates_{timestamp}_{index}.xlsx`
   - Example: `extracted_rates_1738728000_0.xlsx` (index 0)
   - Example: `extracted_rates_1738728000_1.xlsx` (index 1)

2. The regex `preg_match('/extracted_rates_\d+_(\d+)\.xlsx/', ...)` extracts the index number

3. Uses the index to look up `batch_files[$index]['download_name']` from session

4. Returns the meaningful filename to the browser via `Content-Disposition` header

### Data Flow

```
User clicks Download on file #2 (index 1)
    ↓
GET /extract/download/extracted_rates_1738728000_1.xlsx
    ↓
Controller extracts index: 1
    ↓
Looks up: session('batch_files')[1]['download_name']
    ↓
Returns: "PIL_Intra_Asia_01-14_JAN_2026.xlsx"
    ↓
Browser saves file with correct name
```

---

## Bug 4: Progress Bar Smooth Animation on Early Completion

### Requirement
When file processing finishes before the progress bar reaches 90%, the bar should smoothly animate to completion instead of immediately redirecting:
- Jump to 90% → wait 0.5 seconds
- Move to 95% → wait 0.7 seconds
- Move to 100% → wait 0.3 seconds
- Redirect to results page

### Implementation Approach
Changed from **normal form submission** to **AJAX (fetch)** to gain control over when the redirect happens.

### Fix Location
**File:** `resources/views/rate-extraction/index.blade.php`
**Lines:** 348-404

**File:** `app/Http/Controllers/RateExtractionController.php`
**Lines:** 163-172

### Frontend Changes (index.blade.php)

**Before:** Normal form submission
```javascript
// Old approach - immediate redirect when server responds
return true; // Allow form to submit normally
```

**After:** AJAX submission with smooth animation
```javascript
// Use AJAX submission for smoother progress animation
const formData = new FormData(uploadForm);

fetch(uploadForm.action, {
    method: 'POST',
    body: formData,
    headers: {
        'X-Requested-With': 'XMLHttpRequest'
    }
})
.then(response => response.json())
.then(data => {
    // Stop the progress animation
    clearInterval(progressInterval);

    // Smooth finish animation
    const smoothFinish = async () => {
        // If progress < 90, jump to 90 first
        if (progress < 90) {
            progressBar.style.width = '90%';
            progressText.textContent = '90%';
            await new Promise(r => setTimeout(r, 500));
        }

        // Move to 95%
        progressBar.style.width = '95%';
        progressText.textContent = '95%';
        await new Promise(r => setTimeout(r, 700));

        // Move to 100%
        progressBar.style.width = '100%';
        progressText.textContent = '100%';
        await new Promise(r => setTimeout(r, 300));

        // Redirect to results page
        await new Promise(r => setTimeout(r, 100)); // Let server connection close
        if (data.redirect) {
            window.location.href = data.redirect;
        } else {
            window.location.href = '/extract/result';
        }
    };

    smoothFinish();
})
.catch(error => {
    console.error('Error:', error);
    clearInterval(progressInterval);
    window.location.href = '/extract/result';
});

// Prevent normal form submission
return false;
```

### Backend Changes (RateExtractionController.php)

**Added:** JSON response for AJAX requests
```php
// Return JSON for AJAX requests, redirect for normal requests
if ($request->ajax() || $request->wantsJson()) {
    // Explicitly save session to release lock before redirect
    session()->save();

    // Force connection close to free up single-threaded dev server
    return response()->json(['redirect' => route('rate-extraction.result')])
        ->header('Connection', 'close');
}

return redirect()->route('rate-extraction.result');
```

### Execution Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│ User clicks "Extract Rates"                                          │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Frontend:                                                            │
│ 1. Start progress bar animation (0% → 90% over estimated time)      │
│ 2. Send AJAX POST with form data                                     │
│ 3. Continue animating while waiting                                  │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Backend:                                                             │
│ 1. Process files (OCR, parse, generate Excel)                       │
│ 2. Store results in session                                          │
│ 3. Return JSON: { "redirect": "/extract/result" }                   │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Frontend receives JSON response:                                     │
│                                                                      │
│ IF progress < 90%:                                                   │
│    → Set to 90% → wait 500ms                                        │
│    → Set to 95% → wait 700ms                                        │
│    → Set to 100% → wait 300ms                                       │
│    → Redirect                                                        │
│                                                                      │
│ IF progress >= 90%:                                                  │
│    → Set to 95% → wait 700ms                                        │
│    → Set to 100% → wait 300ms                                       │
│    → Redirect                                                        │
└─────────────────────────────────────────────────────────────────────┘
```

### Animation Timing

| Step | Duration | Cumulative |
|------|----------|------------|
| Jump to 90% | 500ms | 500ms |
| Move to 95% | 700ms | 1200ms |
| Move to 100% | 300ms | 1500ms |
| Connection close delay | 100ms | 1600ms |
| **Total** | **1.6 seconds** | |

### Known Issue: Slow Redirect on `php artisan serve`

**Problem:** When using `php artisan serve` (single-threaded development server), there may be a 30-second delay between the redirect being triggered and the result page loading.

**Cause:** The built-in PHP development server can only handle one request at a time. Even after the JSON response is sent, the PHP process may not fully release before the redirect request comes in.

**Mitigations Applied:**
1. `session()->save()` - Explicitly release session lock
2. `Connection: close` header - Force connection to close
3. 100ms delay before redirect - Allow server to fully complete

**Recommended Solution:** Use a proper web server for testing:
- **XAMPP** (Apache + PHP)
- **Laragon** (Nginx + PHP)
- **Docker** with PHP-FPM + Nginx

These servers can handle multiple concurrent requests and won't have the blocking issue.

---

## Summary

| Bug | File | Line(s) | Change |
|-----|------|---------|--------|
| Bug 3: Download filename | `RateExtractionController.php` | 279-289 | Extract index from filename, lookup `batch_files[$index]['download_name']` |
| Bug 4: Smooth progress bar | `index.blade.php` | 348-404 | Changed to AJAX submission with async smooth animation |
| Bug 4: Smooth progress bar | `RateExtractionController.php` | 163-172 | Return JSON for AJAX requests |

---

**Last Updated:** 2026-02-05
