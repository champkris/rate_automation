# Multi-File Upload V2 - Bug Fix 1 & 2

**Date:** 2026-02-04
**Status:** Fixed

---

## Bug 1: Delete Button Opens File Picker Dialog

### Symptom
When clicking the red [X] delete button to remove a file from the selected file list, the file gets removed correctly BUT the browser's file picker dialog opens unexpectedly.

### Root Cause
The delete buttons are nested inside the `dropZone` div. The `dropZone` has a click event listener that opens the file picker:

```javascript
// OLD CODE (line 165 of index.blade.php)
dropZone.addEventListener('click', () => fileInput.click());
```

When you click the [X] button, the click event **bubbles up** from the button to `dropZone`, triggering `fileInput.click()` and opening the file picker dialog.

**Event bubbling chain:**
```
[X] button click -> file list div -> selectedFiles div -> dropZone div -> fileInput.click() triggered!
```

### Fix Location
**File:** `resources/views/rate-extraction/index.blade.php`
**Line:** 165

### What Changed

**Before:**
```javascript
// Click to upload
dropZone.addEventListener('click', () => fileInput.click());
```

**After:**
```javascript
// Click to upload (but not when clicking delete/clear buttons)
dropZone.addEventListener('click', (e) => {
    if (e.target.closest('button')) return;
    fileInput.click();
});
```

### How It Works
- `e.target` is the actual element that was clicked (e.g., the [X] button or its SVG icon)
- `e.target.closest('button')` traverses up the DOM tree to check if the click originated from or within any `<button>` element
- If the click came from a button (delete [X] or "Clear All"), it returns early without opening the file picker
- If the click came from anywhere else in the drop zone (the upload area, text, icon), it opens the file picker normally

### Affected Buttons
This fix correctly handles all buttons inside the drop zone:
- Red [X] delete button on each file (calls `removeFile(index)`)
- "Clear All" button at the top of the file list (calls `clearAllFiles()`)

---

## Bug 2: PIL - Oceania Parsing Fails Completely

### Symptom
PIL - Oceania files always fail to extract rates, regardless of:
- Which PIL Oceania PDF file is used
- Whether auto-detect or manual "PIL - Oceania" pattern is selected
- Whether single file or multi-file upload is used

All other PIL regions (Africa, Intra Asia, Latin America) work correctly.

### Root Cause
In the V2 implementation (Fix 2), the single `'pil'` pattern was split into 4 regional variants (`pil_africa`, `pil_intra_asia`, `pil_latin_america`, `pil_oceania`). However, the `extractFromPdf()` method still checked for the old `$pattern === 'pil'` to decide whether to run PIL-specific preprocessing. Since `'pil'` no longer exists as a pattern value, this condition was **always false**, and the preprocessing was **skipped for all PIL variants**.

The other 3 PIL regions still worked because their parsers extract everything they need from the raw table content, and their region keywords naturally appear in the PDF text. Oceania uniquely depends on 3 preprocessed metadata lines that were no longer being generated.

### What Oceania Needs (That Was Skipped)

The preprocessing block does 3 things for PIL files:

1. **Prepends `Trade: Oceania` line** - The smart router (`parsePilTable()`) checks content for the word "Oceania" to know which regional parser to call. Without this line, the PDF table data alone doesn't contain the word "Oceania", so the router falls through to `return []` (empty).

2. **Prepends `Validity:` lines** - Oceania often has 2 separate validity periods (one for Australia, one for New Zealand). The parser needs these to assign correct date ranges.

3. **Prepends `POL_MAPPING:` line** - Contains Port of Loading data (which Thai ports ship to Australia vs New Zealand). The parser uses this to set the POL field on each rate entry.

### Why Other PIL Regions Still Worked

| Region | Why It Works Without Preprocessing |
|--------|-----------------------------------|
| Africa | The word "Africa" and African port names (Mombasa, Durban, etc.) naturally appear in the table content. The parser extracts validity from the table itself. |
| Intra Asia | "Intra Asia" appears in table headers. No POL/Validity preprocessing needed. |
| Latin America | "Latin America" or "South America" appears in the content. No POL/Validity preprocessing needed. |
| **Oceania** | The table content uses "Australia" and "New Zealand" - NOT the word "Oceania". Requires all 3 prepended metadata lines. **FAILS.** |

### Fix Location
**File:** `app/Services/RateExtractionService.php`
**Lines:** 270 and 334 (two separate code paths)

### What Changed

**Path 1 - Cached JSON results (line 270):**

Before:
```php
// For PIL carrier: add Trade field from JSON or filename to help region detection
if ($pattern === 'pil' && file_exists($jsonFile)) {
```

After:
```php
// For PIL carrier: add Trade field from JSON or filename to help region detection
if (str_starts_with($pattern, 'pil') && file_exists($jsonFile)) {
```

**Path 2 - On-the-fly Azure OCR (line 334):**

Before:
```php
// For PIL carrier: add Trade field from OCR result to help region detection
if ($pattern === 'pil') {
```

After:
```php
// For PIL carrier: add Trade field from OCR result to help region detection
if (str_starts_with($pattern, 'pil')) {
```

### How `str_starts_with()` Works

`str_starts_with($pattern, 'pil')` returns `true` if `$pattern` begins with the characters `'pil'`. This matches all 4 PIL variants:

| Pattern Value | `=== 'pil'` (old) | `str_starts_with('pil')` (new) |
|---------------|-------------------|-------------------------------|
| `'pil_africa'` | false | **true** |
| `'pil_intra_asia'` | false | **true** |
| `'pil_latin_america'` | false | **true** |
| `'pil_oceania'` | false | **true** |
| `'sinokor'` | false | false |
| `'kmtc'` | false | false |

### Execution Flow After Fix

For a PIL Oceania file:

```
1. Pattern detected: 'pil_oceania'

2. extractFromPdf() runs:
   - str_starts_with('pil_oceania', 'pil') => TRUE
   - Preprocessing block executes:
     a. Prepends "Trade: Oceania" to $lines
     b. Prepends "Validity: 4-14 January 2026" (etc.) to $lines
     c. Prepends "POL_MAPPING:{...}" to $lines

3. Match statement:
   'pil_oceania' => parsePilTable($lines, $validity)

4. parsePilTable() smart routing:
   - Scans $lines for \bOceania\b
   - Finds "Trade: Oceania" (prepended in step 2a)
   - Calls parsePilOceaniaTable($lines, $validity)

5. parsePilOceaniaTable():
   - Reads POL_MAPPING line (from step 2c)
   - Reads Validity lines (from step 2b)
   - Parses rate table with correct POL and validity data
   - Returns rate array with '_region' = 'Oceania(Australia)'
```

### Two Code Paths Explained

The fix was applied in two places because `extractFromPdf()` has two separate branches:

| Path | When Used | Line |
|------|-----------|------|
| **Cached JSON** | When `_tables.txt` and `_azure_result.json` files already exist in `temp_attachments/azure_ocr_results/` (previously processed PDF) | 270 |
| **On-the-fly OCR** | When no cached results exist and Azure OCR must be called live | 334 |

Both paths do the same preprocessing (Trade/Validity/POL_MAPPING prepending) but from different data sources (cached JSON file vs live OCR result). Both needed the same fix.

---

## Summary

| Bug | File | Line(s) | Change |
|-----|------|---------|--------|
| Bug 1: File picker opens on delete | `index.blade.php` | 165 | Added `e.target.closest('button')` check to skip `fileInput.click()` when clicking buttons |
| Bug 2: PIL Oceania fails | `RateExtractionService.php` | 270, 334 | Changed `$pattern === 'pil'` to `str_starts_with($pattern, 'pil')` |

---

**Last Updated:** 2026-02-04
