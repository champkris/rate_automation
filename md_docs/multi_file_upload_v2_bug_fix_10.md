# Multi-File Upload V2 - Bug Fix 8, 9, 10

**Date:** 2026-02-06
**Status:** Fixed

---

## Bug Fix 8: Add "need file name" Hint to Supported Carriers Display

### Symptom
Users had no way of knowing that certain carriers (WANHAI, HEUNG A, SM LINE, TS LINE) rely on **filename-based detection only** — meaning the uploaded PDF/Excel filename must contain the carrier name for auto-detection to work.

### Fix Location

| File | Lines | Change |
|------|-------|--------|
| `resources/views/rate-extraction/index.blade.php` | 129, 132, 134, 136 | Added orange "* need file name" hint |

### What Changed

**Before:**
```html
<div class="text-sm"><span class="font-medium">HEUNG A</span></div>
<div class="text-sm"><span class="font-medium">WANHAI</span> - India Rate</div>
<div class="text-sm"><span class="font-medium">SM LINE</span></div>
<div class="text-sm"><span class="font-medium">TS LINE</span></div>
```

**After:**
```html
<div class="text-sm"><span class="font-medium">HEUNG A</span> <span class="text-orange-500 text-xs">* need file name</span></div>
<div class="text-sm"><span class="font-medium">WANHAI</span> <span class="text-orange-500 text-xs">* need file name</span></div>
<div class="text-sm"><span class="font-medium">SM LINE</span> <span class="text-orange-500 text-xs">* need file name</span></div>
<div class="text-sm"><span class="font-medium">TS LINE</span> <span class="text-orange-500 text-xs">* need file name</span></div>
```

### Why
These 4 carriers are detected primarily by filename regex patterns. Unlike SITC (which has content-based detection via "Service Route" header) or KMTC (which has logo detection), these carriers have no reliable content signature. The orange hint tells users they must include the carrier name in the filename for auto-detection to work.

---

## Bug Fix 9: Remove Tail Text from Carrier Names in Display

### Symptom
Some carriers in the Supported Carriers list had unnecessary descriptive suffixes that weren't useful to users.

### Fix Location

| File | Lines | Change |
|------|-------|--------|
| `resources/views/rate-extraction/index.blade.php` | 125-128 | Removed tail text from RCL, KMTC, SINOKOR, SINOKOR SKR |

### What Changed

| Before | After |
|--------|-------|
| `RCL - FAK Rate` | `RCL` |
| `KMTC - Updated Rate` | `KMTC` |
| `SINOKOR - Main Rate Card` | `SINOKOR` |
| `SINOKOR SKR - HK Feederage` | `SINOKOR SKR` |

### Why
The tail text was removed to keep the display clean. Descriptive text will only be added back if it provides genuinely useful information for the user.

---

## Bug Fix 10: SITC Validity Date Not Extracted from PDF Content

### Symptom
SITC PDF files contain the validity date in the format `Effective : 01-31'December 2025` (with an apostrophe before the full month name). However, the system defaulted to the current month (e.g., `FEB 2026`) instead of extracting the actual validity.

| File | Validity in PDF | Expected Output | Actual Output |
|------|----------------|-----------------|---------------|
| `PUBLIC QUOTATION 2025 DEC 25.pdf` | `Effective : 01-31'December 2025` | `1-31 DEC 2025` | `FEB 2026` |
| `PUBLIC QUOTATION 2025 Update October.pdf` | `Effective : 01-31'October 2025` | `1-31 OCT 2025` | `FEB 2026` |
| `PUBLIC QUOTATION NOV 2025 _update.pdf` | `Effective : 01-30'November 2025` | `1-30 NOV 2025` | `NOV 2025` (partial - from filename only) |
| `eieieie.pdf` (renamed DEC file) | `Effective : 01-31'December 2025` | `1-31 DEC 2025` | `FEB 2026` |

### Root Cause

**Two separate validity extraction methods exist**, and neither handled the SITC format:

#### Understanding the Two Code Paths

When a PDF is uploaded, the system takes one of two paths:

```
PDF uploaded
    │
    ├── Cached OCR results exist?
    │       YES → uses RateExtractionService::extractValidityFromJson()
    │       NO  → runs Azure OCR on-the-fly
    │                → uses AzureOcrService::extractValidityFromResult()
    │
    ▼
Validity extracted (or fallback to current month)
```

**Path 1 - Cached results** (`RateExtractionService.php` line 264-267):
```php
if (empty($validity) && file_exists($jsonFile)) {
    $validity = $this->extractValidityFromJson($jsonFile);
}
```

**Path 2 - On-the-fly OCR** (`RateExtractionService.php` line 329-331):
```php
if (empty($validity)) {
    $validity = $azureOcr->extractValidityFromResult($azureResult);
}
```

Both methods had the same set of date patterns, but **neither included SITC's format**:

| Pattern | Format | Example | Carrier |
|---------|--------|---------|---------|
| 1 | `valid until DD/MM/YYYY` | `valid until 31/12/2025` | BOXMAN |
| 2 | `Rate can be applied until DD-DD Mon'YYYY` | `Rate can be applied until 1-30 Nov'2025` | HEUNG A |
| 3 | `DD-DD Mon YY/YYYY` | `1-15 Nov. 25` | TS LINE |
| 4 | `MONTH DD-DD, YYYY` | `DECEMBER 1-31, 2025` | SM LINE |
| 5 | `validity DD-DD Mon` | `validity 1-31 Dec` | DONGJIN |
| 6 | `VALID DD-DD Mon` | `VALID 1-15 DEC` | WANHAI ME |
| **MISSING** | **`DD-DD'FullMonthName YYYY`** | **`01-31'December 2025`** | **SITC** |

The SITC format is unique:
- Uses **full month names** (`December`, `November`, `October`) instead of 3-letter abbreviations
- Has an **apostrophe** (`'`) between the day range and the month name
- Existing Pattern 3 (TS LINE) only matches 3-letter months (`Jan`, `Feb`, etc.) so `December` doesn't match

#### Why NOV File Showed Correct Month

The NOV file (`PUBLIC QUOTATION NOV 2025 _update.pdf`) appeared to work because:
1. `extractValidityFromResult()` returns empty string `''` (no pattern matched)
2. Falls to `extractValidityFromFilename()` (line 384-386)
3. Filename contains `NOV 2025` which matches the month+year pattern
4. Returns `NOV 2025` (correct month, but missing day range)

The other files had filenames without clear month patterns (`2025 DEC 25`, `2025 Update October`), so filename extraction also failed.

### Fix Locations

| File | Lines | Change |
|------|-------|--------|
| `app/Services/AzureOcrService.php` | 699-714 | New SITC pattern in `extractValidityFromResult()` (on-the-fly path) |
| `app/Services/RateExtractionService.php` | 5863-5878 | New SITC pattern in `extractValidityFromJson()` (cached path) |

### Fix 1: AzureOcrService (On-the-fly OCR path)

#### File: `app/Services/AzureOcrService.php`
#### Method: `extractValidityFromResult()` (line 699-714)

**New pattern added BEFORE Pattern 3 (TS LINE):**

```php
// Pattern: "01-31'December 2025" or "01-30'November 2025" (SITC format - apostrophe + full month name)
if (preg_match('/(\d{1,2})[-–]\s*(\d{1,2})\W?\s*(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})/i', $content, $matches)) {
    $startDay = $matches[1];
    $endDay = $matches[2];
    $monthFull = strtoupper($matches[3]);
    $year = $matches[4];

    $monthMap = [
        'JANUARY' => 'JAN', 'FEBRUARY' => 'FEB', 'MARCH' => 'MAR', 'APRIL' => 'APR',
        'MAY' => 'MAY', 'JUNE' => 'JUN', 'JULY' => 'JUL', 'AUGUST' => 'AUG',
        'SEPTEMBER' => 'SEP', 'OCTOBER' => 'OCT', 'NOVEMBER' => 'NOV', 'DECEMBER' => 'DEC'
    ];
    $month = $monthMap[$monthFull] ?? $monthFull;

    return "{$startDay}-{$endDay} {$month} {$year}";
}
```

### Fix 2: RateExtractionService (Cached results path)

#### File: `app/Services/RateExtractionService.php`
#### Method: `extractValidityFromJson()` (line 5863-5878)

**Same pattern added BEFORE Pattern 4 (TS LINE):**

```php
// Pattern: "01-31'December 2025" or "01-30'November 2025" (SITC format - apostrophe + full month name)
if (preg_match('/(\d{1,2})[-–]\s*(\d{1,2})\W?\s*(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})/i', $content, $matches)) {
    $startDay = $matches[1];
    $endDay = $matches[2];
    $monthFull = strtoupper($matches[3]);
    $year = $matches[4];

    $monthMap = [
        'JANUARY' => 'JAN', 'FEBRUARY' => 'FEB', 'MARCH' => 'MAR', 'APRIL' => 'APR',
        'MAY' => 'MAY', 'JUNE' => 'JUN', 'JULY' => 'JUL', 'AUGUST' => 'AUG',
        'SEPTEMBER' => 'SEP', 'OCTOBER' => 'OCT', 'NOVEMBER' => 'NOV', 'DECEMBER' => 'DEC'
    ];
    $month = $monthMap[$monthFull] ?? $monthFull;

    return "{$startDay}-{$endDay} {$month} {$year}";
}
```

### Regex Explained

```
/(\d{1,2})[-–]\s*(\d{1,2})\W?\s*(January|...|December)\s+(\d{4})/i
 ├────────┤├──┤├──┤├────────┤├─┤├──┤├──────────────────────┤├──┤├───────┤
 │         │    │   │         │  │   │                       │   │
 │         │    │   │         │  │   │Full month name        │   │4-digit year
 │         │    │   │         │  │   │(case insensitive)     │   │
 │         │    │   │         │  │   │                       │1+ whitespace
 │         │    │   │         │  │0+ whitespace
 │         │    │   │         │any non-word char (apostrophe, etc.) - optional
 │         │    │   │1-2 digit end day
 │         │    │0+ whitespace
 │         │dash or en-dash
 │1-2 digit start day
```

**Key design choice: `\W?` instead of specific apostrophe characters**

Earlier attempts used specific character classes for the apostrophe (`['\x{2018}\x{2019}]`) which caused PHP string escaping issues. Using `\W?` (any non-word character, optional) is cleaner and handles:
- ASCII apostrophe `'` (U+0027)
- Unicode left/right single quotes (U+2018, U+2019)
- Backtick `` ` ``
- No character at all (direct `31December` if OCR skips the apostrophe)

### Pattern Placement (Why Before TS LINE Pattern)

The new pattern is placed **before** the TS LINE pattern (Pattern 3/4) because:

1. TS LINE pattern matches **3-letter** month abbreviations: `(Jan|Feb|...|Dec)`
2. SITC pattern matches **full** month names: `(January|February|...|December)`
3. If TS LINE ran first, it could NOT match `December` (only `Dec`), so SITC content would fall through to default
4. If SITC ran first, it would correctly match `01-31'December 2025` and return immediately
5. TS LINE content like `1-15 Nov. 25` would NOT match SITC pattern (because `Nov` is not a full month name), so it safely falls to TS LINE pattern

There is **no conflict** between the two patterns.

---

## Execution Flow After Fix

### For SITC PDF: `PUBLIC QUOTATION 2025 DEC 25.pdf`

```
┌─────────────────────────────────────────────────────┐
│ Upload: PUBLIC QUOTATION 2025 DEC 25.pdf            │
│ No cached OCR → on-the-fly Azure OCR                │
└─────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────┐
│ AzureOcrService::extractValidityFromResult()        │
│                                                     │
│ analyzeResult.content contains:                     │
│   "...Effective : 01-31'December 2025\nS/C no..."   │
│                                                     │
│ Pattern 1 (BOXMAN): no match                        │
│ Pattern 2 (HEUNG A): no match                       │
│ NEW SITC Pattern: MATCH!                            │
│   "01-31'December 2025"                             │
│   → startDay=01, endDay=31, month=DEC, year=2025    │
│   → returns "01-31 DEC 2025"                        │
└─────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────┐
│ Download filename: SITC_01-31_DEC_2025.xlsx         │
└─────────────────────────────────────────────────────┘
```

---

## Before vs After Comparison

| File | Before Fix | After Fix |
|------|-----------|-----------|
| `PUBLIC QUOTATION 2025 DEC 25.pdf` | `SITC_FEB_2026.xlsx` | `SITC_01-31_DEC_2025.xlsx` |
| `PUBLIC QUOTATION 2025 Update October.pdf` | `SITC_FEB_2026.xlsx` | `SITC_01-31_OCT_2025.xlsx` |
| `PUBLIC QUOTATION NOV 2025 _update.pdf` | `SITC_NOV_2025.xlsx` (from filename) | `SITC_01-30_NOV_2025.xlsx` (from content - more precise) |
| `eieieie.pdf` (renamed DEC file) | `SITC_FEB_2026.xlsx` | `SITC_01-31_DEC_2025.xlsx` |

---

## Summary of All Fixes (8, 9, 10)

| Fix | Component | Change |
|-----|-----------|--------|
| **Fix 8** | `index.blade.php` | Added orange "* need file name" hint for WANHAI, HEUNG A, SM LINE, TS LINE |
| **Fix 9** | `index.blade.php` | Removed tail text from RCL, KMTC, SINOKOR, SINOKOR SKR |
| **Fix 10** | `AzureOcrService.php` | New SITC validity pattern in `extractValidityFromResult()` (on-the-fly OCR path) |
| **Fix 10** | `RateExtractionService.php` | New SITC validity pattern in `extractValidityFromJson()` (cached OCR path) |

---

## Key Lesson Learned (Fix 10)

The system has **two parallel code paths** for validity extraction from PDFs:

| Path | Method | File | When Used |
|------|--------|------|-----------|
| Cached | `extractValidityFromJson()` | `RateExtractionService.php` | When `_azure_result.json` exists from previous upload |
| On-the-fly | `extractValidityFromResult()` | `AzureOcrService.php` | When Azure OCR runs fresh for new upload |

**Any new validity pattern must be added to BOTH methods** to work consistently regardless of whether cached or fresh OCR data is used.

---

**Last Updated:** 2026-02-06
