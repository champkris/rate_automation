# Change 5: PIL Continent Name in Output Filename

**Date:** 2026-01-20
**Status:** ✅ Complete and Tested
**Files Modified:**
- `app/Services/RateExtractionService.php`
- `app/Http/Controllers/RateExtractionController.php`

---

## Summary

Added continent/region metadata to PIL extractions so that output Excel filenames automatically include the continent name. For example: `PIL_Africa_1-31_JAN_2026.xlsx` instead of just `PIL_1-31_JAN_2026.xlsx`.

---

## What Changed

### 1. RateExtractionService.php - Added Region Metadata

Modified each PIL continent parser to add a `_region` metadata field to all rate records:

**parsePilAfricaTable() - Lines 4322-4325:**
```php
// Add region metadata for filename generation
foreach ($sortedRates as &$rate) {
    $rate['_region'] = 'Africa';
}
```

**parsePilIntraAsiaTable() - Lines 4470-4473:**
```php
// Add region metadata for filename generation
foreach ($rates as &$rate) {
    $rate['_region'] = 'Intra_Asia';
}
```

**parsePilLatinAmericaTable() - Lines 4536-4539:**
```php
// Add region metadata for filename generation
foreach ($rates as &$rate) {
    $rate['_region'] = 'Latin_America';
}
```

**parsePilOceaniaTable() - Lines 4639-4642:**
```php
// Add region metadata for filename generation
foreach ($rates as &$rate) {
    $rate['_region'] = 'Oceania';
}
```

**parsePilSouthAsiaTable() - Lines 4718-4721:**
```php
// Add region metadata for filename generation
foreach ($rates as &$rate) {
    $rate['_region'] = 'South_Asia';
}
```

### 2. RateExtractionController.php - Modified Filename Generation

**Added PIL to pattern mapping (Line 247):**
```php
$patternNames = [
    'rcl' => 'RCL',
    'kmtc' => 'KMTC',
    'pil' => 'PIL',  // ← Added
    // ... other carriers
];
```

**Modified process() method (Lines 92-95):**
```php
$carrierName = $this->getCarrierNameFromPattern($pattern, $originalName, $rates);
$validityPeriod = $this->getValidityPeriod($rates);
$region = $this->getRegionFromRates($rates);  // ← Added
$downloadFilename = $this->generateDownloadFilename($carrierName, $validityPeriod, $region);  // ← Added parameter
```

**Added getRegionFromRates() method (Lines 308-320):**
```php
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
```

**Updated generateDownloadFilename() method (Lines 322-348):**
```php
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
```

---

## How It Works

### Step 1: Region Detection
When PIL parsers extract rates, they now add a `_region` metadata field to each rate record:

```php
$rate['_region'] = 'Africa';  // or 'Intra_Asia', 'Latin_America', etc.
```

This field:
- Uses underscore format for clean filenames (`Intra_Asia` not `Intra Asia`)
- Is added AFTER all extraction logic (at the end of each parser)
- Is consistent across all rate records from the same extraction

### Step 2: Region Extraction
The controller checks if any rate has the `_region` metadata:

```php
$region = $this->getRegionFromRates($rates);
```

Returns:
- `'Africa'`, `'Intra_Asia'`, `'Latin_America'`, `'Oceania'`, or `'South_Asia'` for PIL
- `null` for non-PIL carriers (KMTC, SINOKOR, etc.)

### Step 3: Filename Generation
The filename generator now includes region if available:

```php
if (!empty($region)) {
    return 'PIL_' . $region . '_1-31_JAN_2026.xlsx';
}
return 'PIL_1-31_JAN_2026.xlsx';  // Fallback if no region
```

---

## Examples

### PIL Carriers (With Region)
| Carrier | Region | Validity | Output Filename |
|---------|--------|----------|----------------|
| PIL | Africa | 1-31 JAN 2026 | `PIL_Africa_1-31_JAN_2026.xlsx` |
| PIL | Intra_Asia | 15-31 FEB 2026 | `PIL_Intra_Asia_15-31_FEB_2026.xlsx` |
| PIL | Latin_America | 1-30 MAR 2026 | `PIL_Latin_America_1-30_MAR_2026.xlsx` |
| PIL | Oceania | 1-30 APR 2026 | `PIL_Oceania_1-30_APR_2026.xlsx` |
| PIL | South_Asia | 1-31 MAY 2026 | `PIL_South_Asia_1-31_MAY_2026.xlsx` |

### Non-PIL Carriers (Unchanged)
| Carrier | Validity | Output Filename |
|---------|----------|----------------|
| KMTC | 1-31 JAN 2026 | `KMTC_1-31_JAN_2026.xlsx` |
| SINOKOR | 1-30 NOV 2025 | `SINOKOR_1-30_NOV_2025.xlsx` |
| RCL | 15-31 DEC 2025 | `RCL_15-31_DEC_2025.xlsx` |

---

## Test Results

**Test Script:** `test_script/test_pil_filename_with_region.php`

```
=== SUMMARY ===

Total tests: 7
✅ Passed: 7
❌ Failed: 0

🎉 ALL TESTS PASSED! 🎉

The filename generation works correctly:
  ✅ PIL files include continent name (PIL_Africa_...)
  ✅ Non-PIL files remain unchanged (KMTC_...)
  ✅ All regions supported (Africa, Intra_Asia, Latin_America, Oceania, South_Asia)

Ready for production! ✅
```

### Test Cases Covered:
1. ✅ PIL Africa → `PIL_Africa_1-31_JAN_2026.xlsx`
2. ✅ PIL Intra Asia → `PIL_Intra_Asia_1-31_JAN_2026.xlsx`
3. ✅ PIL Latin America → `PIL_Latin_America_1-31_JAN_2026.xlsx`
4. ✅ PIL Oceania → `PIL_Oceania_1-31_JAN_2026.xlsx`
5. ✅ PIL South Asia → `PIL_South_Asia_1-31_JAN_2026.xlsx`
6. ✅ KMTC (non-PIL) → `KMTC_1-31_JAN_2026.xlsx`
7. ✅ SINOKOR (non-PIL) → `SINOKOR_1-30_NOV_2025.xlsx`

---

## Benefits

1. ✅ **Better file organization** - Easy to identify which PIL region the file contains
2. ✅ **No manual renaming needed** - Filename automatically includes continent
3. ✅ **Consistent naming** - All PIL files follow `PIL_<Region>_<Date>_<Month>.xlsx` format
4. ✅ **Backward compatible** - Non-PIL carriers unchanged
5. ✅ **No breaking changes** - Metadata field uses underscore prefix (`_region`) so it won't appear in Excel output

---

## Technical Notes

### Why Use `_region` with Underscore?
- Fields starting with underscore are treated as metadata
- They're included in the rate array for processing but won't be written to Excel columns
- This prevents the region from appearing as a column in the output file

### Why Use Underscores in Region Names?
- `Intra_Asia` instead of `Intra Asia` (with space)
- Ensures clean filenames without spaces
- More compatible with file systems and scripts

### Order of Operations:
1. PDF/Excel → Extract rates → Add region metadata
2. Rates with metadata → Controller receives them
3. Controller extracts region → Generates filename
4. Excel file saved with region-specific name

---

## Future Considerations

If other carriers need region-specific filenames (e.g., MSC Asia vs MSC Europe), this same pattern can be applied:

1. Add `_region` metadata in the carrier's parser
2. Region will automatically appear in filename
3. No changes needed to controller logic

---

**Implementation Status:** ✅ COMPLETE
**Tested:** ✅ YES (7 test cases, all passed)
**Ready for Production:** ✅ YES
**Breaking Changes:** ❌ NO (backward compatible)
