# Multi-File Upload V2 - Bug Fix 12

**Date:** 2026-02-09
**Status:** Fixed

---

## Bug 12: DONGJIN SHEKOU Port Rates Hardcoded / Missing Due to Merged Cells

### Symptom

DONGJIN PDF files have the **Nansha** and **Shekou** rate cells merged in the PDF (spanning both rows). The system either output hardcoded wrong rates (20, 30) or partially empty rates for SHEKOU.

| File | Expected 20' | Expected 40' | Actual 20' | Actual 40' | Status |
|------|-------------|-------------|-----------|-----------|--------|
| Feb 2026 | 10 | 20 | 20 | 30 | **Wrong** (hardcoded) |
| Jan 2026 | 20 | 30 | 20 | 30 | Coincidentally correct |
| Dec 2025 | 20 | 30 | 20 | 30 | Coincidentally correct |
| Nov 2025 | 30 | 40 | 20 | 30 | **Wrong** (hardcoded) |
| Oct 2025 | 30 | 40 | 20 | 30 | **Wrong** (hardcoded) |

### Root Cause

**Two separate issues working together:**

#### Issue 1: Azure OCR `rowSpan` Not Handled in Line Builder

In the DONGJIN PDF, the 20' and 40' rate cells for Nansha and Shekou are **merged cells** spanning both rows:

```
                Currency   20'    40'    T/T
Nansha  NSA    ┌───────┐
                │ China │  USD  ┌────┐┌────┐  5 Days
                └───────┘       │ 10 ││ 20 │
Shekou  SHK               USD  └────┘└────┘  6 Days
```

Azure OCR correctly detects this and reports `"rowSpan": 2` on the Nansha cells:

```json
{
    "rowIndex": 5,
    "columnIndex": 4,
    "rowSpan": 2,
    "content": "10"
}
```

However, the line builder at `AzureOcrService.php` only stored cells at their starting row:

```php
// OLD CODE (line 601-610)
$row = $cell['rowIndex'] ?? 0;
$col = $cell['columnIndex'] ?? 0;
$content = $cell['content'] ?? '';
$cells[$row][$col] = $content;  // Only stores at starting row, ignores rowSpan
```

This meant SHEKOU's "Row 6:" line had **no rate columns**:

```
Row 5: Nansha | NSA | China | USD | 10 | 20 | 5 Days | Direct | FRI | Sat
Row 6: Shekou | SHK | USD | 6 Days | Direct | FRI | Sat
                              ↑ No rates! "6 Days" is T/T, not a rate
```

#### Issue 2: Hardcoded Fallback Rates in Parser

The parser at `RateExtractionService.php` had a special SHEKOU block that **hardcoded** rates:

```php
// OLD CODE (line 3714-3717)
if ($podUpper === 'SHEKOU' && (empty($rate20Clean) || strlen($rate20Clean) < 2)) {
    $rate20Clean = '20';   // Hardcoded! Based on old rate sheet
    $rate40Clean = '30';   // Hardcoded! Based on old rate sheet
```

These values (20, 30) were from an old DONGJIN rate sheet. The actual rates change every month.

#### Issue 3: Azure OCR Inconsistent `rowSpan` Detection

Testing across 5 months of DONGJIN PDFs revealed Azure OCR is **inconsistent** in detecting `rowSpan` on the 40' column:

| File | col 4 (20') rowSpan | col 5 (40') rowSpan |
|------|-------------------|-------------------|
| Feb 2026 | 2 | **2** |
| Jan 2026 | 2 | **2** |
| Oct 2025 | 2 | **2** |
| Dec 2025 | 2 | **NO** |
| Nov 2025 | 2 | **NO** |

For Nov and Dec, Azure detected the merge on 20' but NOT on 40'. This caused a **column shift** problem where "6 Days" (T/T) landed in the 40' position after the line was built.

---

## Fix Strategy: Two-Layer Approach

### Layer 1 (Option B): Handle `rowSpan` in OCR Line Builder

Fix the root cause — when Azure reports `rowSpan > 1`, copy the cell value to subsequent rows.

### Layer 2 (Option A): Smart SHEKOU Fallback in Parser

Safety net — if rowSpan expansion didn't fill all rate columns (Azure inconsistency), copy missing rates from the row above (NANSHA). Logic:

```
if SHEKOU:
    if 20' valid AND 40' valid  →  use as-is (full rowSpan or future un-merged)
    if 20' valid AND 40' invalid →  copy 40' from NANSHA (partial rowSpan detected)
    if 20' invalid               →  copy both from NANSHA (full fallback)
```

---

## Fix 1: Handle `rowSpan` in OCR Line Builder

### File: `app/Services/AzureOcrService.php`
### Lines: 605-620

**Before:**
```php
foreach ($table['cells'] as $cell) {
    $row = $cell['rowIndex'] ?? 0;
    $col = $cell['columnIndex'] ?? 0;
    $content = $cell['content'] ?? '';

    if (!isset($cells[$row])) {
        $cells[$row] = [];
    }
    $cells[$row][$col] = $content;
    // ...
}
```

**After:**
```php
foreach ($table['cells'] as $cell) {
    $row = $cell['rowIndex'] ?? 0;
    $col = $cell['columnIndex'] ?? 0;
    $content = $cell['content'] ?? '';
    $rowSpan = $cell['rowSpan'] ?? 1;

    if (!isset($cells[$row])) {
        $cells[$row] = [];
    }
    $cells[$row][$col] = $content;

    // If cell spans multiple rows, copy to subsequent rows
    for ($r = 1; $r < $rowSpan; $r++) {
        if (!isset($cells[$row + $r])) {
            $cells[$row + $r] = [];
        }
        if (!isset($cells[$row + $r][$col])) {
            $cells[$row + $r][$col] = $content;
        }
    }
    // ...
}
```

### Why

Azure OCR returns `"rowSpan": 2` on merged cells. The code now reads this property and copies the cell's content to all rows it spans. The `!isset` guard prevents overwriting if a subsequent row already has its own data in that column.

### Effect on SHEKOU

When Azure reports `rowSpan: 2` on both rate columns (Feb, Jan, Oct):

```
Row 5: Nansha | NSA | China | USD | 10 | 20 | 5 Days | Direct | FRI | Sat
Row 6: Shekou | SHK | China | USD | 10 | 20 | 6 Days | Direct | FRI | Sat
                                     ↑    ↑
                                     Copied from rowSpan expansion
```

When Azure reports `rowSpan: 2` only on 20' column (Nov, Dec):

```
Row 5: Nansha | NSA | China | USD | 30 | 40 | 5 Days | Direct | FRI | Sat
Row 6: Shekou | SHK | China | USD | 30 | 6 Days | Direct | FRI | Sat
                                     ↑    ↑
                                    OK   40' still missing! (column shifted)
```

This is why Fix 2 (safety net) is needed.

### Scope

This fix benefits **all carriers**, not just DONGJIN. Any PDF with merged table cells will now have the values correctly expanded to all spanned rows.

---

## Fix 2: Smart SHEKOU Rate Fallback in Parser

### File: `app/Services/RateExtractionService.php`
### Lines: 3706-3740

**Before:**
```php
// Special handling for SHEKOU: OCR often misses rate columns
$podUpper = strtoupper(trim($pod));
if ($podUpper === 'SHEKOU' && (empty($rate20Clean) || strlen($rate20Clean) < 2)) {
    // Use NANSHA rates (20, 30 for 20' and 40')
    $rate20Clean = '20';    // Hardcoded!
    $rate40Clean = '30';    // Hardcoded!
    $tt = '6';
    $ts = 'Direct';
    // Find ETD values in remaining cells
    for ($i = $usdPos + 1; $i < count($cells); $i++) {
        $cellVal = trim($cells[$i]);
        if (preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)/i', $cellVal)) {
            if (empty($etdBkk)) {
                $etdBkk = $cellVal;
            } else {
                $etdLch = $cellVal;
                break;
            }
        }
    }
}
```

**After:**
```php
// SHEKOU handling: rates may come from rowSpan expansion (merged cells with NANSHA)
// Three cases:
//   1) Both 20' and 40' valid → use as-is (full rowSpan or future un-merged)
//   2) 20' valid but 40' invalid → partial rowSpan, copy 40' from NANSHA
//   3) 20' invalid → full fallback, copy both from NANSHA
$podUpper = strtoupper(trim($pod));
$shekou20Valid = !empty($rate20Clean) && strlen($rate20Clean) >= 2;
$shekou40Valid = !empty($rate40Clean) && strlen($rate40Clean) >= 2;

if ($podUpper === 'SHEKOU' && (!$shekou20Valid || !$shekou40Valid)) {
    // Copy missing rates from NANSHA (last known rates)
    if (!$shekou20Valid) {
        $rate20Clean = $lastRate20;
    }
    if (!$shekou40Valid) {
        $rate40Clean = $lastRate40;
    }
    // T/T and ETD parsing is unreliable when columns shifted, handle manually
    $tt = '';
    $ts = '';
    for ($i = $usdPos + 1; $i < count($cells); $i++) {
        $cellVal = trim($cells[$i]);
        if (empty($tt) && preg_match('/\d+.*day|^\d+$/i', $cellVal)) {
            $tt = $cellVal;
        } elseif (empty($ts) && preg_match('/^(Direct|NANSHA|PUS)/i', $cellVal)) {
            $ts = $cellVal;
        } elseif (preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)/i', $cellVal)) {
            if (empty($etdBkk)) {
                $etdBkk = $cellVal;
            } else {
                $etdLch = $cellVal;
                break;
            }
        }
    }
}
```

### Key Changes

| Change | Before | After |
|--------|--------|-------|
| **Condition** | Only checks 20' rate | Checks **both** 20' AND 40' rates |
| **20' fallback** | Hardcoded `'20'` | Dynamic `$lastRate20` (from NANSHA) |
| **40' fallback** | Hardcoded `'30'` | Dynamic `$lastRate40` (from NANSHA) |
| **Rate filling** | Both rates always overwritten | Each rate independently: only copy if invalid |
| **T/T parsing** | Hardcoded `'6'` | Pattern-scans all cells for day values |
| **T/S parsing** | Hardcoded `'Direct'` | Pattern-scans for T/S values |

### Why Each Rate is Checked Independently

The `$lastRate20` / `$lastRate40` variables track the most recently parsed row with valid rates. Since NANSHA is always processed immediately before SHEKOU (PDF row order), these contain NANSHA's rates.

```php
// Already existed at line 3762
if (!empty($rate20Clean) && strlen($rate20Clean) >= 2) {
    $lastRate20 = $rate20Clean;
    $lastRate40 = $rate40Clean;
}
```

For NANSHA with rates 30/40: `strlen('30') >= 2` passes, so `$lastRate20 = '30'`, `$lastRate40 = '40'`.

### Why T/T Parsing Uses Pattern Scanning

When column 5 (40') is missing from the OCR output, subsequent columns shift left in the "Row N:" line. Fixed-position T/T parsing (`$usdPos + 3`) would read the wrong cell. Pattern-based scanning finds T/T, T/S, and ETD values regardless of their position.

---

## How `$lastRate20` / `$lastRate40` Works

The `parseDongjinTable()` method tracks the last valid rates as it iterates through rows:

```
Processing order (from PDF):
Row 1: Kwangyang   → $lastRate20 = '200', $lastRate40 = '400'
Row 2: Pusan       → $lastRate20 = '200', $lastRate40 = '400'
Row 3: Inchon      → $lastRate20 = '250', $lastRate40 = '500'
Row 4: Pyeongtaek  → $lastRate20 = '300', $lastRate40 = '600'
Row 5: Nansha      → $lastRate20 = '30',  $lastRate40 = '40'   ← Updated!
Row 6: Shekou      → uses $lastRate20 / $lastRate40 if needed
```

NANSHA is always the row immediately before SHEKOU, so `$lastRate20` / `$lastRate40` always contain NANSHA's rates when SHEKOU is processed.

---

## Decision Table: All SHEKOU Scenarios

| Scenario | 20' from OCR | 40' from OCR | Condition | Action |
|----------|-------------|-------------|-----------|--------|
| Full rowSpan (Feb, Jan, Oct) | valid (e.g., `'10'`) | valid (e.g., `'20'`) | Both valid → skips block | Uses OCR values directly |
| Partial rowSpan (Nov, Dec) | valid (e.g., `'30'`) | invalid (`'6'` from shifted T/T) | 40' invalid → enters block | Keeps 20', copies 40' from NANSHA |
| No rowSpan (OCR failure) | invalid (empty or `'6'`) | invalid | Both invalid → enters block | Copies both from NANSHA |
| Future un-merged cells | valid (own rate) | valid (own rate) | Both valid → skips block | Uses OCR values directly |

### Validity Check

A rate is considered **valid** if:
- Not empty (`!empty($rate)`)
- At least 2 digits (`strlen($rate) >= 2`)

This filters out T/T values like `'6'` (from "6 Days") that may land in rate positions due to column shifts.

---

## Execution Flow After Fix

### Case 1: Full rowSpan (Feb 2026 - rates 10, 20)

```
┌─────────────────────────────────────────────────────┐
│ AzureOcrService: Build lines from table cells       │
│                                                     │
│ Nansha cell col 4: "10" (rowSpan: 2)                │
│   → $cells[5][4] = '10'                             │
│   → $cells[6][4] = '10'  ← NEW: rowSpan expansion  │
│                                                     │
│ Nansha cell col 5: "20" (rowSpan: 2)                │
│   → $cells[5][5] = '20'                             │
│   → $cells[6][5] = '20'  ← NEW: rowSpan expansion  │
│                                                     │
│ Row 6: Shekou | SHK | China | USD | 10 | 20 | ...  │
└─────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────┐
│ parseDongjinTable: Process SHEKOU                   │
│                                                     │
│ rate20Clean = '10', rate40Clean = '20'              │
│ shekou20Valid = true, shekou40Valid = true           │
│ Both valid → skips SHEKOU block → normal parsing    │
│                                                     │
│ Result: SHEKOU 20'=10, 40'=20  ✓                    │
└─────────────────────────────────────────────────────┘
```

### Case 2: Partial rowSpan (Nov 2025 - rates 30, 40)

```
┌─────────────────────────────────────────────────────┐
│ AzureOcrService: Build lines from table cells       │
│                                                     │
│ Nansha cell col 4: "30" (rowSpan: 2)                │
│   → $cells[5][4] = '30'                             │
│   → $cells[6][4] = '30'  ← rowSpan expansion       │
│                                                     │
│ Nansha cell col 5: "40" (NO rowSpan)                │
│   → $cells[5][5] = '40'                             │
│   → $cells[6][5] = NOT SET  ← col 5 missing!       │
│                                                     │
│ Row 6: Shekou | SHK | China | USD | 30 | 6 Days... │
│                                          ↑ shifted  │
└─────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────┐
│ parseDongjinTable: Process SHEKOU                   │
│                                                     │
│ rate20Clean = '30', rate40Clean = '6' (from T/T)    │
│ shekou20Valid = true, shekou40Valid = false           │
│ → enters SHEKOU block                               │
│ → keeps rate20Clean = '30'                           │
│ → copies rate40Clean = $lastRate40 = '40'            │
│ → scans cells for T/T: finds "6 Days" → tt = "6 Days"|
│ → scans for T/S: finds "Direct" → ts = "Direct"     │
│                                                     │
│ Result: SHEKOU 20'=30, 40'=40  ✓                    │
└─────────────────────────────────────────────────────┘
```

---

## Before vs After Comparison (All 5 Months)

| File | Before Fix (20'/40') | After Fix (20'/40') | Correct? |
|------|--------------------|-------------------|----------|
| Feb 2026 | 20 / 30 (hardcoded) | 10 / 20 (from rowSpan) | Yes |
| Jan 2026 | 20 / 30 (hardcoded) | 20 / 30 (from rowSpan) | Yes |
| Dec 2025 | 20 / 30 (hardcoded) | 20 / 30 (20' rowSpan + 40' fallback) | Yes |
| Nov 2025 | 20 / 30 (hardcoded) | 30 / 40 (20' rowSpan + 40' fallback) | Yes |
| Oct 2025 | 20 / 30 (hardcoded) | 30 / 40 (from rowSpan) | Yes |

---

## Summary of All Changes

| Fix | File | Lines | Change |
|-----|------|-------|--------|
| **Fix 1** (rowSpan) | `app/Services/AzureOcrService.php` | 605-620 | Read `rowSpan` from Azure OCR cells and copy content to subsequent rows |
| **Fix 2** (SHEKOU fallback) | `app/Services/RateExtractionService.php` | 3706-3740 | Replace hardcoded rates with dynamic fallback from NANSHA; check both 20' and 40' independently; pattern-scan for T/T and ETD |

---

## Key Lessons Learned

1. **Azure OCR detects merged cells** via `rowSpan` and `columnSpan` properties — but the line builder was ignoring them.

2. **Azure OCR is inconsistent** — for the same PDF layout, it may detect `rowSpan` on one column but not the adjacent one. Any fix must handle partial detection.

3. **Hardcoded fallback values are fragile** — rates change every month. Dynamic fallback using the last-parsed row is more robust.

4. **Column shifts from missing cells** break fixed-position parsing — when a column is missing from the "Row N:" line, all subsequent columns shift left. Pattern-based scanning is more reliable than position-based indexing.

---

**Last Updated:** 2026-02-09
