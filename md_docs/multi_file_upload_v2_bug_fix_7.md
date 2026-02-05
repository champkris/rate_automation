# Multi-File Upload V2 - Bug Fix 7

**Date:** 2026-02-05
**Status:** Fixed

---

## Bug 7: RCL Validity Date Parsing Incorrect for "DD-DD Mon YYYY" Format

### Symptom
For RCL Excel files, the validity date extraction works differently depending on the date format in the source file:

| Source Format | Example | Result | Status |
|---------------|---------|--------|--------|
| `DD/MM-DD/MM/YYYY` | `01/11-30/11/2025` | `1-30 NOV 2025` | Correct |
| `DD-DD Mon YYYY` | `01-15 Jan 2026` | `FEB 2026` (current month) | **Incorrect** |

The downloaded filename shows the current month (e.g., `RCL_FEB_2026.xlsx`) instead of the actual validity month from the file (e.g., `RCL_1-15_JAN_2026.xlsx`).

### Root Causes

**Two issues were identified:**

#### Issue 1: Cell Location Mismatch

The RCL parser was hardcoded to read validity from cell **B6** only:

```php
// OLD CODE (line 423)
$validityRaw = trim($worksheet->getCell('B6')->getValue() ?? '');
```

However, different RCL file formats have the validity date in different cells:
- Older format: Validity in **B6** (e.g., `01/11-30/11/2025`)
- Newer format: Validity in **B7** (e.g., `01-15 Jan 2026`)

When the validity was in B7, reading B6 returned empty, causing the fallback to current month.

#### Issue 2: Date Format Not Handled

The `formatValidity()` method only handled the slash-based format (`DD/MM-DD/MM/YYYY`), not the text-based format (`DD-DD Mon YYYY`):

```php
// OLD formatValidity() logic
if (strpos($validityRaw, '-') !== false) {
    $parts = explode('-', $validityRaw);
    $endDate = trim($parts[1]);
    $dateParts = explode('/', $endDate);  // Only works if '/' exists!

    if (count($dateParts) >= 2) {
        // This block only executes for DD/MM format
    }
}
return $validityRaw;  // Falls through for DD-DD Mon format
```

**Trace for `"01-15 Jan 2026"`:**
1. Contains `-` → enters if block
2. `explode('-', '01-15 Jan 2026')` → `['01', '15 Jan 2026']`
3. `$endDate = '15 Jan 2026'`
4. `explode('/', '15 Jan 2026')` → `['15 Jan 2026']` (only 1 element - no `/` separator!)
5. `count($dateParts) >= 2` → **FALSE** (skips parsing block)
6. Returns raw string unchanged

Even if the cell was read correctly, the format wasn't being parsed properly.

---

## Fix Locations

| File | Lines | Change |
|------|-------|--------|
| `app/Services/RateExtractionService.php` | 421-424 | Search multiple cells for validity |
| `app/Services/RateExtractionService.php` | 5728-5766 | New `findValidityInCells()` helper method |
| `app/Services/RateExtractionService.php` | 5698-5726 | Enhanced `formatValidity()` for multiple date formats |

---

## Fix 1: Search Multiple Cells for Validity

### File: `app/Services/RateExtractionService.php`
### Lines: 421-424

### What Changed

**Before:**
```php
// Extract VALIDITY from cell B6 if not provided
if (empty($validity)) {
    $validityRaw = trim($worksheet->getCell('B6')->getValue() ?? '');
    $validity = $this->formatValidity($validityRaw);
}
```

**After:**
```php
// Extract VALIDITY from multiple possible cells if not provided
// RCL files may have validity in B6, B7, C6, or C7 depending on the format
if (empty($validity)) {
    $validity = $this->findValidityInCells($worksheet, ['B6', 'B7', 'C6', 'C7']);
}
```

### Why This Fix Works

Instead of hardcoding a single cell (B6), we now search through multiple candidate cells:
- **B6** - Original location (older RCL format)
- **B7** - New location (newer RCL format)
- **C6, C7** - Additional fallback locations

The search stops as soon as a valid date pattern is found.

---

## Fix 2: New `findValidityInCells()` Helper Method

### File: `app/Services/RateExtractionService.php`
### Lines: 5728-5766

### New Method Added

```php
/**
 * Find validity date by searching multiple cells
 * Searches through given cell addresses and returns the first valid date found
 *
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet
 * @param array $cellAddresses Array of cell addresses to search (e.g., ['B6', 'B7', 'C6', 'C7'])
 * @return string Formatted validity or current month as fallback
 */
protected function findValidityInCells($worksheet, array $cellAddresses): string
{
    // Date patterns to match:
    // - "DD-DD Mon YYYY" (e.g., "01-15 Jan 2026", "'01-15 Jan 2026")
    // - "DD/MM-DD/MM/YYYY" (e.g., "01/11-30/11/2025")
    $datePatterns = [
        '/^\d{1,2}-\d{1,2}\s*(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s*\d{4}$/i',  // DD-DD Mon YYYY
        '/^\d{1,2}\/\d{1,2}-\d{1,2}\/\d{1,2}\/\d{4}$/',  // DD/MM-DD/MM/YYYY
    ];

    foreach ($cellAddresses as $cellAddress) {
        $cellValue = trim($worksheet->getCell($cellAddress)->getValue() ?? '');

        // Remove leading apostrophe (Excel text format prefix)
        $cleanedValue = ltrim($cellValue, "'");

        if (empty($cleanedValue)) {
            continue;
        }

        // Check if the cell contains a date pattern
        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, $cleanedValue)) {
                // Found a valid date, format and return it
                return $this->formatValidity($cleanedValue);
            }
        }
    }

    // Fallback: return current month
    return strtoupper(date('M Y'));
}
```

### How It Works

1. **Iterates through cell addresses** in order (B6 → B7 → C6 → C7)
2. **Reads each cell value** and removes Excel's text prefix (`'`) if present
3. **Pattern matching** checks if the value matches known date formats:
   - `DD-DD Mon YYYY` (e.g., `01-15 Jan 2026`)
   - `DD/MM-DD/MM/YYYY` (e.g., `01/11-30/11/2025`)
4. **Returns first match** - stops searching once a valid date is found
5. **Fallback** - returns current month only if no date found in any cell

### Pattern Regex Explained

| Pattern | Regex | Matches |
|---------|-------|---------|
| DD-DD Mon YYYY | `/^\d{1,2}-\d{1,2}\s*(Jan\|Feb\|...)\s*\d{4}$/i` | `01-15 Jan 2026`, `1-30 Dec 2025` |
| DD/MM-DD/MM/YYYY | `/^\d{1,2}\/\d{1,2}-\d{1,2}\/\d{1,2}\/\d{4}$/` | `01/11-30/11/2025` |

---

## Fix 3: Enhanced `formatValidity()` Method

### File: `app/Services/RateExtractionService.php`
### Lines: 5698-5726

### What Changed

**Before:**
```php
protected function formatValidity(string $validityRaw): string
{
    if (empty($validityRaw)) {
        return strtoupper(date('M Y'));
    }

    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    if (strpos($validityRaw, '-') !== false) {
        $parts = explode('-', $validityRaw);
        $endDate = trim($parts[1]);
        $dateParts = explode('/', $endDate);

        if (count($dateParts) >= 2) {
            $day = intval($dateParts[0]);
            $month = intval($dateParts[1]);
            $year = isset($dateParts[2]) ? intval($dateParts[2]) : date('Y');

            if ($month >= 1 && $month <= 12) {
                return sprintf('%02d %s %d', $day, $months[$month - 1], $year);
            }
        }
    }

    return $validityRaw;
}
```

**After:**
```php
/**
 * Format validity date
 * Handles multiple formats:
 * - "01/11-30/11/2025" (DD/MM-DD/MM/YYYY) → "1-30 NOV 2025"
 * - "01-15 Jan 2026" (DD-DD Mon YYYY) → "1-15 JAN 2026"
 * - "'01-15 Jan 2026" (with Excel text prefix) → "1-15 JAN 2026"
 */
protected function formatValidity(string $validityRaw): string
{
    if (empty($validityRaw)) {
        return strtoupper(date('M Y'));
    }

    // Remove leading apostrophe if present (Excel text format prefix)
    $cleanedRaw = ltrim($validityRaw, "'");

    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    // Format 1: "DD-DD Mon YYYY" (e.g., "01-15 Jan 2026", "1-15 Jan 2026")
    if (preg_match('/^(\d{1,2})-(\d{1,2})\s*(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s*(\d{4})$/i', $cleanedRaw, $matches)) {
        $startDay = intval($matches[1]);
        $endDay = intval($matches[2]);
        $month = strtoupper($matches[3]);
        $year = $matches[4];
        return "{$startDay}-{$endDay} {$month} {$year}";
    }

    // Format 2: "DD/MM-DD/MM/YYYY" (e.g., "01/11-30/11/2025")
    if (strpos($cleanedRaw, '-') !== false) {
        $parts = explode('-', $cleanedRaw);

        // Check if both parts have slashes (DD/MM format)
        if (count($parts) >= 2 && strpos($parts[0], '/') !== false && strpos($parts[1], '/') !== false) {
            $startParts = explode('/', trim($parts[0]));
            $endParts = explode('/', trim($parts[1]));

            if (count($startParts) >= 2 && count($endParts) >= 2) {
                $startDay = intval($startParts[0]);
                $endDay = intval($endParts[0]);
                $month = intval($endParts[1]);
                $year = isset($endParts[2]) ? intval($endParts[2]) : date('Y');

                if ($month >= 1 && $month <= 12) {
                    return "{$startDay}-{$endDay} " . strtoupper($months[$month - 1]) . " {$year}";
                }
            }
        }
    }

    // Return cleaned raw value (uppercase for consistency)
    return strtoupper($cleanedRaw);
}
```

### Key Improvements

| Improvement | Description |
|-------------|-------------|
| **Excel apostrophe handling** | Removes leading `'` from text-formatted cells |
| **Regex for DD-DD Mon YYYY** | New pattern to parse `01-15 Jan 2026` format |
| **Consistent output format** | Both formats now return `startDay-endDay MONTH YEAR` |
| **Uppercase output** | All output is uppercase for filename consistency |

### Format Conversion Examples

| Input | Output |
|-------|--------|
| `01-15 Jan 2026` | `1-15 JAN 2026` |
| `'01-15 Jan 2026` | `1-15 JAN 2026` |
| `01/11-30/11/2025` | `1-30 NOV 2025` |
| `1-30 Dec 2025` | `1-30 DEC 2025` |

---

## Execution Flow After Fix

```
┌─────────────────────────────────────────────────────────────────────┐
│ parseRclExcel() called with RCL file                                │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│ findValidityInCells(['B6', 'B7', 'C6', 'C7'])                       │
│                                                                     │
│   Check B6: empty or no date pattern? → continue                    │
│   Check B7: "01-15 Jan 2026" matches DD-DD Mon YYYY → FOUND!        │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│ formatValidity("01-15 Jan 2026")                                    │
│                                                                     │
│   1. Remove apostrophe if present → "01-15 Jan 2026"                │
│   2. Match regex: /^(\d+)-(\d+)\s*(Mon)\s*(\d{4})$/i                │
│   3. Extract: startDay=1, endDay=15, month=JAN, year=2026           │
│   4. Return: "1-15 JAN 2026"                                        │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Rate entries created with VALIDITY = "1-15 JAN 2026"                │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│ generateDownloadFilename("RCL", "1-15 JAN 2026", null)              │
│                                                                     │
│   Clean validity: "1-15_JAN_2026"                                   │
│   Result: "RCL_1-15_JAN_2026.xlsx"                                  │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Before vs After Comparison

### Scenario: RCL file with validity "01-15 Jan 2026" in cell B7

| Step | Before Fix | After Fix |
|------|-----------|-----------|
| Cell searched | B6 only | B6, B7, C6, C7 |
| B6 value | (empty) | (empty) - continue searching |
| B7 value | (not checked) | "01-15 Jan 2026" - found! |
| formatValidity input | "" (empty) | "01-15 Jan 2026" |
| formatValidity output | "FEB 2026" (fallback) | "1-15 JAN 2026" |
| Download filename | `RCL_FEB_2026.xlsx` | `RCL_1-15_JAN_2026.xlsx` |

---

## Summary

| Component | Change |
|-----------|--------|
| `parseRclExcel()` | Now calls `findValidityInCells()` to search B6, B7, C6, C7 |
| `findValidityInCells()` | New method that searches multiple cells with pattern matching |
| `formatValidity()` | Enhanced to handle both `DD/MM-DD/MM/YYYY` and `DD-DD Mon YYYY` formats |

---

**Last Updated:** 2026-02-05
