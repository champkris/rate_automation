# PIL Intra Asia Change 4 - Africa Style Rate Implementation

## Change Summary

**Date**: 2026-01-20
**Change Type**: Enhancement - Rate Column Content Handling
**Impact**: Medium - Changes how rates with additional charges are displayed

---

## Problem Statement

### Original Implementation (Rule 2)

The original implementation parsed rates using `parsePilRate()` function which:
1. Extracted numeric part only for rate column
2. Added additional charges to REMARK field

**Example**:
- **PDF Input**: `BKK 20' = "2600+HEA"`
- **Output**:
  - BKK 20' column: `"2600"` (numeric only)
  - REMARK: `"LSR Include, +HEA"` (charge added to remark)

### Issues

1. **Lost context**: User couldn't see full rate information in rate column
2. **Inconsistent with Africa**: Africa continent keeps full rate text `"2600+HEA"`
3. **Harder to verify**: Need to check both rate column AND remark to see complete rate

---

## Solution: Africa Style Implementation

### Changed Behavior

**New implementation** matches Africa continent approach:
1. Keep FULL rate text in rate column (e.g., `"2600+HEA"`)
2. Only remove commas for storage
3. Do NOT add rate-based charges to REMARK field

### Code Changes

**File**: `app/Services/RateExtractionService.php`
**Lines**: 4466-4490

#### BEFORE
```php
// Parse BKK rates
$bkk20 = $this->parsePilRate($bkk20Raw);
$bkk40 = $this->parsePilRate($bkk40Raw);

// Parse LCH rates
$lch20 = $this->parsePilRate($lch20Raw);
$lch40 = $this->parsePilRate($lch40Raw);

// Rule 2: Add remarks from rate parsing (additional charges like EID, HEA, etc.)
if (!empty($bkk20['remark'])) $remarkParts[] = $bkk20['remark'];
if (!empty($bkk40['remark']) && $bkk40['remark'] !== $bkk20['remark']) {
    $remarkParts[] = $bkk40['remark'];
}
```

#### AFTER
```php
// INTRA ASIA: Use AFRICA STYLE - Keep FULL rate text (like "2600+HEA")
// Just remove commas from numeric part for storage
$bkk20 = str_replace(',', '', $bkk20Raw);
$bkk40 = str_replace(',', '', $bkk40Raw);
$lch20 = str_replace(',', '', $lch20Raw);
$lch40 = str_replace(',', '', $lch40Raw);

// Rule 2: [REMOVED] Rate parsing remarks - Now using Africa style (keep full rate text in rate column)
```

---

## Behavior Comparison

### Scenario 1: Pure Numeric Rate

| Aspect | Input | BEFORE | AFTER | Change? |
|--------|-------|--------|-------|---------|
| PDF Value | `"2600"` | | | |
| BKK 20' | | `"2600"` | `"2600"` | ✅ NO CHANGE |
| REMARK | | `"LSR Include"` | `"LSR Include"` | ✅ NO CHANGE |

### Scenario 2: Rate with Comma

| Aspect | Input | BEFORE | AFTER | Change? |
|--------|-------|--------|-------|---------|
| PDF Value | `"2,600"` | | | |
| BKK 20' | | `"2600"` | `"2600"` | ✅ NO CHANGE |
| REMARK | | `"LSR Include"` | `"LSR Include"` | ✅ NO CHANGE |

### Scenario 3: Rate with Additional Charge (Single)

| Aspect | Input | BEFORE | AFTER | Change? |
|--------|-------|--------|-------|---------|
| PDF Value | `"2600+HEA"` | | | |
| BKK 20' | | `"2600"` | `"2600+HEA"` | ✅ **CHANGED** |
| REMARK | | `"LSR Include, +HEA"` | `"LSR Include"` | ✅ **CHANGED** |

### Scenario 4: Rates with Different Charges

| Aspect | Input | BEFORE | AFTER | Change? |
|--------|-------|--------|-------|---------|
| PDF BKK 20' | `"2600+HEA"` | | | |
| PDF BKK 40' | `"3600+EID"` | | | |
| BKK 20' | | `"2600"` | `"2600+HEA"` | ✅ **CHANGED** |
| BKK 40' | | `"3600"` | `"3600+EID"` | ✅ **CHANGED** |
| REMARK | | `"LSR Include, +HEA, +EID"` | `"LSR Include"` | ✅ **CHANGED** |

### Scenario 5: Rate with PDF Remark

| Aspect | Input | BEFORE | AFTER | Change? |
|--------|-------|--------|-------|---------|
| PDF BKK 20' | `"2600+HEA"` | | | |
| PDF Remark | `"Subject to EID (USD 100)"` | | | |
| BKK 20' | | `"2600"` | `"2600+HEA"` | ✅ **CHANGED** |
| REMARK | | `"LSR Include, +HEA, Subject to EID (USD 100)"` | `"LSR Include, Subject to EID (USD 100)"` | ✅ **CHANGED** |

---

## Updated 4-Rule System

### Rule 1: LSR → REMARK Conversion
✅ **Unchanged** - Still adds LSR information to REMARK
- LSR = "Include" → "LSR Include"
- LSR = "78/156" → "LSR: 78/156"
- Filters placeholders: "-", "N/A", "TBA", "—"

### Rule 2: Rate Column Content ⚠️ **CHANGED**
❌ **Old behavior**: Parse rate, extract numeric only, add charges to REMARK
✅ **New behavior**: Keep full rate text in rate column (Africa style)

**Implementation**:
```php
$bkk20 = str_replace(',', '', $bkk20Raw);
```

**Examples**:
- `"2600"` → `"2600"` (no change)
- `"2,600"` → `"2600"` (remove comma)
- `"2600+HEA"` → `"2600+HEA"` (keep full text)
- `"2,600+HEA"` → `"2600+HEA"` (remove comma, keep charges)

### Rule 3: PDF Remark Column
✅ **Unchanged** - Still adds PDF remark column content with spacing normalization

### Rule 4: Default Message
✅ **Unchanged** - Still adds default message if REMARK is empty

---

## Testing Results

### Regression Test
**File**: `test_script/test_pil_intra_asia_final.php`

```
✅ ALL TESTS PASSED! ✅

PIL Intra Asia extraction is working perfectly:
  ✅ Record count: 44 (22 ports × 2 POLs)
  ✅ Column mapping correct (LSR, Free time, T/T, T/S)
  ✅ LSR → REMARK conversion working
  ✅ Region header filtering (Singapore included)
  ✅ Remark spacing normalized
```

**Result**: ✅ All existing test cases pass with **zero changes needed**

**Why?** The test data contains only pure numeric rates, so the change has no effect on existing tests.

---

## Impact Assessment

### ✅ Benefits

1. **Complete Information**: Full rate text visible in rate column
2. **Consistency**: Matches Africa continent implementation
3. **Easier Verification**: Single source of truth for rate information
4. **No Breaking Changes**: Pure numeric rates unaffected

### ⚠️ Considerations

1. **Rate Column Type**: Now contains text (e.g., `"2600+HEA"`) instead of pure numeric
2. **Excel Calculations**: Users need to extract numeric part if doing calculations
3. **REMARK Changes**: Rate-based charges no longer in REMARK field

### 📊 Affected Records

**When this change matters**:
- PDF rates contain: `+HEA`, `+EID`, `+AMS`, `+ISD`, or `(... included)` text
- **Unaffected**: Pure numeric rates (majority of cases)

---

## Files Modified

1. **Implementation**:
   - `app/Services/RateExtractionService.php` (lines 4466-4490)

2. **Documentation**:
   - `md_docs/PIL_Intra_Asia_change_v1.md` (Rule 2 section)

3. **New Documentation**:
   - `md_docs/PIL_Intra_Asia_Change4_Africa_Style_Implementation.md` (this file)

---

## Production Readiness

✅ **Ready for Production**

**Verification**:
- ✅ Code changes implemented
- ✅ Regression tests passing (44 records)
- ✅ Documentation updated
- ✅ Zero breaking changes for numeric rates
- ✅ Matches Africa continent pattern

**Next Steps**:
1. Deploy to production
2. Monitor first extraction with rates containing `+HEA`, `+EID` to verify behavior
3. User feedback on full-text rate display

---

## References

- **Africa Implementation**: `RateExtractionService.php` lines 4216-4221
- **Intra Asia Implementation**: `RateExtractionService.php` lines 4466-4528
- **Change 4 Documentation**: `PIL_Intra_Asia_change_v1.md` lines 195-294
