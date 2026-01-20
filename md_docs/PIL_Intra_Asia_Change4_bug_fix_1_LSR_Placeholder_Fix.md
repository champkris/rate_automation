# PIL Intra Asia - Change 4 Enhancement: LSR Placeholder Filtering

## Summary

**Date**: 2026-01-20
**Change**: Added placeholder filtering to Rule 1 (LSR → REMARK conversion)
**Location**: `app/Services/RateExtractionService.php:4483`
**Status**: ✅ Implemented and tested

---

## Problem Identified

### Scenario 1: LSR = "-" (dash)

**Original Code**:
```php
if (!empty($lsr)) {
    if (strtolower($lsr) === 'include') {
        $remarkParts[] = 'LSR Include';
    } else {
        $remarkParts[] = 'LSR: ' . $lsr;
    }
}
```

**Bug**: When LSR = "-" (a common placeholder meaning "not applicable"):
- `!empty("-")` → TRUE (dash is not empty)
- Falls to `else` branch → Output: `"LSR: -"`
- **Result**: Meaningless "LSR: -" appears in REMARK field

### Other Placeholder Values

Similar issue occurs with:
- "-" (single hyphen)
- "—" (em-dash)
- "N/A", "n/a", "NA" (not applicable)
- "TBA" (to be announced)

All these should be treated as "no LSR information" (same as empty).

---

## Solution Implemented

### New Code (Line 4483)

```php
// Rule 1: Add LSR to remark (always, whether Include or numeric value)
// Filter out placeholder values like "-", "N/A", "TBA", "—", etc.
if (!empty($lsr) && !preg_match('/^(-|—|N\/?A|TBA|n\/a)$/i', $lsr)) {
    if (strtolower($lsr) === 'include') {
        $remarkParts[] = 'LSR Include';
    } else {
        $remarkParts[] = 'LSR: ' . $lsr;
    }
}
```

### Regex Pattern Explanation

**Pattern**: `/^(-|—|N\/?A|TBA|n\/a)$/i`

| Component | Matches | Examples |
|-----------|---------|----------|
| `^` and `$` | Entire string (not partial) | Ensures exact match |
| `-` | Single hyphen | "-" |
| `—` | Em-dash (Unicode) | "—" |
| `N\/?A` | N/A with optional slash | "N/A", "NA" |
| `TBA` | To Be Announced | "TBA" |
| `n\/a` | Lowercase n/a | "n/a" |
| `i` flag | Case-insensitive | "tba", "TBA", "Tba" all match |

---

## Behavior Changes

### Before Fix

| LSR Value | Output | Correct? |
|-----------|--------|----------|
| "Include" | "LSR Include" | ✅ |
| "78/156" | "LSR: 78/156" | ✅ |
| "" (empty) | *(nothing)* | ✅ |
| "-" | "LSR: -" | ❌ Bug |
| "N/A" | "LSR: N/A" | ❌ Bug |
| "TBA" | "LSR: TBA" | ❌ Bug |

### After Fix

| LSR Value | Output | Correct? |
|-----------|--------|----------|
| "Include" | "LSR Include" | ✅ |
| "78/156" | "LSR: 78/156" | ✅ |
| "" (empty) | *(nothing)* | ✅ |
| "-" | *(nothing)* | ✅ Fixed |
| "N/A" | *(nothing)* | ✅ Fixed |
| "TBA" | *(nothing)* | ✅ Fixed |

---

## Testing

### Test File Created

`test_script/test_lsr_placeholder_filtering.php`

### Test Results

```
=== LSR PLACEHOLDER FILTERING TEST ===

Testing PLACEHOLDER values (should be filtered out):
  '-' → ✅ FILTERED
  '—' → ✅ FILTERED
  'N/A' → ✅ FILTERED
  'n/a' → ✅ FILTERED
  'NA' → ✅ FILTERED
  'TBA' → ✅ FILTERED
  'tba' → ✅ FILTERED
  'Tba' → ✅ FILTERED

Testing VALID LSR values (should NOT be filtered):
  'Include' → ✅ ACCEPTED
  'INCLUDE' → ✅ ACCEPTED
  '78/156' → ✅ ACCEPTED
  '100' → ✅ ACCEPTED
  '50/100' → ✅ ACCEPTED

🎉 ALL TESTS PASSED! 🎉
```

### Regression Test

Ran existing test: `test_script/test_pil_intra_asia_final.php`

**Result**: ✅ All 4 test cases passed (44 records extracted correctly)

---

## Scenario 2: Missing LSR Column (Already Handled)

**Question**: What if the PDF has no LSR column at all?

**Answer**: Already safe due to null coalescing operator:

```php
$lsr = trim($cells[6] ?? '');  // Line 4446
```

**Logic**:
- If `$cells[6]` doesn't exist → Use `''` (empty string)
- `trim('')` → `''`
- `!empty('')` → FALSE
- Rule 1 is skipped entirely

**No fix needed** - this scenario is already handled correctly.

---

## Impact Assessment

### Risk Level
- **Before**: Medium severity bug (meaningless placeholders in output)
- **After**: ✅ Fixed

### Business Impact
- **Before**: Excel output contains confusing "LSR: -" or "LSR: N/A" entries
- **After**: Only meaningful LSR values appear in REMARK field
- **Improvement**: Cleaner, more professional output

### Likelihood
- **High**: Many data entry systems use "-" as a placeholder
- **This fix prevents future data quality issues**

---

## Documentation Updates

Updated `md_docs/PIL_Intra_Asia_change_v1.md`:

**Before**:
```markdown
**Rule 1 - LSR to REMARK**:
- If LSR = "Include" → Add "LSR Include"
- If LSR = numeric (e.g., "78/156") → Add "LSR: 78/156"
```

**After**:
```markdown
**Rule 1 - LSR to REMARK**:
- If LSR = "Include" → Add "LSR Include"
- If LSR = numeric (e.g., "78/156") → Add "LSR: 78/156"
- **Placeholder filtering**: Ignores "-", "—", "N/A", "TBA" (treated as empty)
```

---

## Files Changed

1. **app/Services/RateExtractionService.php** (Line 4483)
   - Added placeholder filtering regex

2. **md_docs/PIL_Intra_Asia_change_v1.md** (Line 256)
   - Documented placeholder filtering behavior

3. **test_script/test_lsr_placeholder_filtering.php** (NEW)
   - Comprehensive test for placeholder filtering logic

---

## Conclusion

✅ **Change 4 Enhancement Complete**

The LSR placeholder filtering ensures that only meaningful LSR values ("Include" or numeric rates) appear in the final REMARK field. Common placeholders ("-", "N/A", "TBA") are now properly filtered out, resulting in cleaner and more professional output.

**Status**: Ready for production ✅
