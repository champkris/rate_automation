# KMTC Implementation Notes

## Overview
This document contains implementation details for the KMTC rate extraction feature, specifically the conditional remark logic. Use this as reference when implementing similar features for other carriers.

---

## Features Implemented

### 1. **KMTC Logo Detection**
- Location: `app/Services/RateExtractionService.php:105-155`
- Detects KMTC files by logo aspect ratio (3.16 ± 0.4) and position (columns D-G, rows 1-3)
- Fallback detection if filename doesn't contain "UPDATED RATE"

### 2. **KMTC Conditional Remark Extraction**
- Location: `app/Services/RateExtractionService.php:393-468`
- Extracts remarks based on notices found in the Excel file
- Priority 1: AFS charge notice for China/Japan destinations
- Priority 2: LSS notice for all other destinations

---

## Key Methods

### **parseKmtcExcel()** (Lines 393-468)
Main parsing method for KMTC Excel files.

**Key Steps:**
1. Unmerge POD Country column (B) to handle merged cells
2. Extract notice messages from below "...Notice..." row
3. Apply conditional remark logic based on country

**Columns:**
- B: POD Country
- C: POL Area
- D: POD Area
- E: 20' rate
- F: 40' rate
- G: Validity date
- J: Free time

---

### **unmergePodCountryColumn()** (Lines 477-503)
Unmerges merged cells in column B and fills down values.

**Purpose:**
Excel files have merged cells for country names (e.g., "China" merged across rows 7-15).
This method ensures ALL rows get the country value, not just the first row.

**Example:**
- Before: B7="China", B8-B15 are empty (merged)
- After: B7="China", B8="China", ..., B15="China"

---

### **extractKmtcNotices()** (Lines 520-566)
Extracts notice text from the Excel file.

**Process:**
1. Search for "...Notice..." row (checks all columns A-K)
2. Extract ALL text from the row after "Notice" until the last row
3. Return array of unique notice strings

**Example Output:**
```php
[
    "Rate is subject to origin LSS, destination LSS and local charges at both ends.",
    "There is AFS charge $30/BL for JP&CN."
]
```

---

## Excel File Structure

### **KMTC File Format:**

```
Row 1-3:  Logo and headers
Row 4-5:  Column headers
Row 6+:   Rate data
Row 43:   "...Notice..."
Row 44+:  Notice messages
```

### **Merged Cells:**
- Column B (POD Country) has merged cells for each country group
- Example: B7:B15 merged with "China", B18:B21 merged with "Japan"

---

## Conditional Remark Logic

### **Priority 1: AFS Charge (China/Japan only)**
```php
if ($hasAfsNotice) {
    $countryUpper = strtoupper($country);
    if (stripos($countryUpper, 'CHINA') !== false || stripos($countryUpper, 'JAPAN') !== false) {
        $remark = $afsNoticeText;  // "There is AFS charge $30/BL for JP&CN."
    }
}
```

### **Priority 2: LSS Notice (Fallback)**
```php
if (empty($remark) && $hasLssNotice) {
    $remark = $lssNoticeText;  // "Rate is subject to origin LSS..."
}
```

### **Default:**
If no notices found, remark remains empty string.

---

## Test Results

### **Test File:** UPDATED RATE IN DEC25.xlsx
- Total rates: 35 (including 8 TBA rates)
- China/Japan rates: 11 (with AFS charge notice)
- Other rates: 24 (with LSS notice)
- Empty remarks: 0

### **Test Command:**
```bash
php test_kmtc_remark_summary.php
```

---

## Implementation Pattern for New Carriers

When adding similar features for other carriers, follow this pattern:

### **Step 1: Understand the File Structure**
- Check if columns have merged cells
- Identify where notice/remark text is located
- Understand the conditional logic needed

### **Step 2: Create Unmerge Method (if needed)**
```php
protected function unmergeColumnX($worksheet, $highestRow): void
{
    // Get merged cells, unmerge, and fill down values
}
```

### **Step 3: Create Notice Extractor (if needed)**
```php
protected function extractCarrierNotices($worksheet): array
{
    // Find notice section and extract text
}
```

### **Step 4: Modify Main Parser**
```php
protected function parseCarrierExcel($worksheet, string $validity): array
{
    // 1. Unmerge if needed
    // 2. Extract notices if needed
    // 3. Apply conditional logic
    // 4. Return rates
}
```

---

## Common Issues & Solutions

### **Issue 1: Missing Rates (TBA rates not extracted)**
**Cause:** TBA values are not empty, so skip condition needs to check properly
**Solution:** Only skip if POD, 20', AND 40' are ALL empty

### **Issue 2: Empty Remarks**
**Cause:** Merged cells cause country field to be empty
**Solution:** Unmerge and fill down values first

### **Issue 3: Notice Text Not Found**
**Cause:** Notice text in different column than expected
**Solution:** Check ALL columns (A-K) when searching for notice row

---

## Files Modified

### **Main Code:**
- `app/Services/RateExtractionService.php`
  - Modified: `parseKmtcExcel()`
  - Added: `unmergePodCountryColumn()`
  - Added: `extractKmtcNotices()`

### **Test Files:**
- `test_kmtc_remark_summary.php` - Comprehensive test

### **Git Commit:**
- Hash: `105dcca`
- Message: "Implement KMTC conditional remark extraction with merged cell handling"

---

## Related Documentation

- `IMPLEMENTATION_COMPLETE.md` - Logo detection implementation
- `md_docs/` - Logo detection analysis

---

## Next Steps

When adding new carriers:
1. Review this document for patterns to follow
2. Check if carrier has similar merged cell issues
3. Check if carrier has notice sections with conditional logic
4. Implement similar methods (unmerge, extract notices, conditional logic)
5. Create test files to verify
6. Update this document with new carrier notes

---

**Last Updated:** 2026-01-13
**Implemented By:** Claude Sonnet 4.5
