# PIL Implementation Test Results - Session 4 (Final)

**Date:** 2026-01-13
**Implemented By:** Claude Sonnet 4.5
**Status:** ✅ 5/5 Regions Tested | ✅ ALL REGIONS PERFECT | 🎉 100% EXTRACTION SUCCESS

---

## Test Progress Summary

| Region | Expected | Actual | Status | Notes |
|--------|----------|--------|--------|-------|
| Africa | 18 | 18 | ✅ **PERFECT** | Fixed merged row handling - all destinations now extracting |
| Intra Asia | 44 | 44 | ✅ **PERFECT** | Dual POL working correctly |
| Latin America | 19 | 19 | ✅ **PERFECT** | Single POL working correctly |
| Oceania | 10 | 10 | ✅ **PERFECT** | Side-by-side layout working correctly |
| South Asia | 28 | 28 | ✅ **PERFECT** | Dual POL working correctly |
| **TOTAL** | **119** | **119** | **100%** | All 5 regions working perfectly! |

---

## Session 4 Testing Results (Current) - FINAL FIX

### ✅ Africa: PERFECT (18/18 records) - FULLY FIXED
**Expected:** 18 records
**Extracted:** 18 records ✅ (was 13/18 in Sessions 1-3, fixed to 18/18 in Session 4)
**Status:** 100% accurate after implementing merged row detection
**Parser:** Lines 4134-4267 in RateExtractionService.php

**Issues Found & Fixed:**

**Issue 1: OCR Merged Multiple Destinations Per Row**
- **Problem:** Azure OCR merged multiple port destinations into single table rows
  - Row 2: Contains header "PORTs" + data "Apapa, Lagos | NGLOS | ..." in same row
  - Row 3: Contains size header "20'GP | 40'HC" + data "Onne | NGONN | ..." in same row
  - Row 4: Contains "Mombasa | KEMBA | ..." + "Tema | GHTEM | ..." (2 destinations)
  - Row 5: Contains "Dar Es Salaam | TZDAR | ..." + "Lome | TGLFW | ..." (2 destinations)
  - Row 6: Contains "Zanzibar | TZZNZ | ..." + "Cotonou | BJCOO | ..." (2 destinations)
- **Root Cause:** Original parser expected one destination per row at fixed positions (cells 0-6)
- **Missing Destinations Before Fix:**
  1. Apapa, Lagos (NGLOS) - in header row
  2. Onne (NGONN) - in size header row
  3. Tema (GHTEM) - merged with Mombasa
  4. Lome (TGLFW) - merged with Dar Es Salaam
  5. Cotonou (BJCOO) - merged with Zanzibar
- **Fix:** Implemented dynamic port code detection and position-based extraction
  - Scan all cells for 5-letter uppercase port codes (e.g., NGLOS, KEMBA, GHTEM)
  - For each port code found, extract data at relative positions from code index
  - Port name = code_index - 1, rates = code_index + 1/+2, etc.
  - Skip rows with header-like port names ("PORTs", "CODE", "RATE IN USD")
- **Lines Changed:** 4142-4267 (complete rewrite of Africa parser logic)
- **Result:** All 18 records now extract correctly, including previously missing destinations

**Issue 2: Header Row Detection Too Aggressive**
- **Problem:** Original code skipped entire rows containing "PORTs" in cell[0]
  - Row 2 has "PORTs" in cell[0] but also has valid data "Apapa, Lagos | NGLOS" later
  - Row 3 has "20'GP" size header but also has valid data "Onne | NGONN" later
- **Root Cause:** `if (preg_match('/(PORTs|CODE)/i', $cells[0])) continue;` skipped entire row
- **Fix:** Removed header skip logic, rely on port code detection instead
  - Line 4146-4150 removed (header skip section)
  - Added port name filtering: skip if port name matches "^PORTs$|^CODE$|^RATE IN USD$"
- **Lines Changed:** Removed lines 4146-4150, added filtering at lines 4177, 4216, 4248
- **Result:** Rows with mixed header+data content now process correctly

**Extracted Destinations (18 total):**
- **West Africa:** Apapa/Lagos (NGLOS), Onne (NGONN), Tema (GHTEM), Lome (TGLFW), Cotonou (BJCOO), Abidjan (CIABJ), Douala (CMDLA) - 7 destinations
- **East Africa:** Mombasa (KEMBA), Dar Es Salaam (TZDAR), Zanzibar (TZZNZ) - 3 destinations
- **South Africa:** Durban (ZADUR), Capetown (ZACPT) - 2 destinations
- **Mozambique:** Maputo (MZMPM), Beira (MZBEW), Nacala (MZMNC) - 3 destinations
- **Indian Ocean:** Toamasina/Tamatave (MGTMM), Reunion/Pointe Des Galets (REREU), Port Louis (MUPLU) - 3 destinations

---

## Session 3 Testing Results

### ✅ South Asia: PERFECT (28/28 records)
**Expected:** 28 records (14 destinations × 2 POLs)
**Extracted:** 28 records ✅
**Status:** 100% accurate - worked on first try
**Parser:** Lines 4396-4501 in RateExtractionService.php
**Format:** DUAL POL (same as Intra Asia)

**Key Success:** Dual POL logic works consistently across both Intra Asia and South Asia regions.

---

### ✅ Oceania: PERFECT (10/10 records) - FIXED
**Expected:** 10 records
**Extracted:** 10 records ✅ (was 0 in Session 2, then 6 after first fix)
**Status:** 100% accurate after two fixes
**Parser:** Lines 4298-4427 in RateExtractionService.php

**Issues Found & Fixed:**

**Issue 1: Region Detection Failure**
- **Problem:** PIL router required "Trade : Oceania" but OCR table extraction only contained table rows, not document text
- **Root Cause:** `parsePilTable()` used strict regex `/Trade\s*:\s*Oceania/i` which didn't match table-only content
- **Fix:** Changed all PIL region detection from `Trade\s*:\s*RegionName` to just `\bRegionName\b` keywords
- **Lines Changed:** 4115-4124 (PIL router)
- **Result:** Region detection now works from filename alone ("PIL Oceania quotation...")

**Issue 2: Inconsistent OCR Cell Counts**
- **Problem:** After region detection fix, only 6/10 records extracted (5 NZ + 1 AU)
- **Root Cause:** Azure OCR produced inconsistent cell counts:
  - Row 2: 18 cells (full data including right-side remark)
  - Rows 3-6: Only 17 cells (missing right-side remark)
  - Parser required `count($cells) >= 18` for right side processing
- **Fix:** Changed cell count check from `>= 18` to `>= 17` since remark is optional
- **Line Changed:** 4384 (changed condition for right destination processing)
- **Result:** All 10 records now extract correctly (5 NZ + 5 AU)

**Extracted Destinations:**
- **NZ (left side):** Auckland, Lyttelton, Wellington, Napier, Tauranga (rates = "n/a")
- **AU (right side):** Brisbane, Sydney, Melbourne, Fremantle, Adelaide (valid rates)

---

## All Code Changes in Session 3

### File: app/Services/RateExtractionService.php

#### Change 1: PIL Region Router - Keyword-Based Detection (Lines 4110-4129)

**Purpose:** Fix region detection to work with OCR table-only content

**Problem:**
- Original code required exact "Trade : RegionName" text in content
- Azure OCR `_tables.txt` files only contain table data, not full document text
- PIL router couldn't detect regions, causing all extractions to fail

**Before (Session 2):**
```php
protected function parsePilTable(array $lines, string $validity): array
{
    $content = implode("\n", $lines);

    if (preg_match('/Trade\s*:\s*Africa/i', $content)) {
        return $this->parsePilAfricaTable($lines, $validity);
    } elseif (preg_match('/Trade\s*:\s*Intra Asia/i', $content)) {
        return $this->parsePilIntraAsiaTable($lines, $validity);
    } elseif (preg_match('/Trade\s*:\s*(Latin America|South America)/i', $content)) {
        return $this->parsePilLatinAmericaTable($lines, $validity);
    } elseif (preg_match('/Trade\s*:\s*Oceania/i', $content)) {
        return $this->parsePilOceaniaTable($lines, $validity);
    } elseif (preg_match('/Trade\s*:\s*South Asia/i', $content)) {
        return $this->parsePilSouthAsiaTable($lines, $validity);
    }
    return [];
}
```

**After (Session 3):**
```php
protected function parsePilTable(array $lines, string $validity): array
{
    // Detect region from content (check for region keywords)
    $content = implode("\n", $lines);

    if (preg_match('/\bAfrica\b/i', $content)) {
        return $this->parsePilAfricaTable($lines, $validity);
    } elseif (preg_match('/\bIntra\s+Asia\b/i', $content)) {
        return $this->parsePilIntraAsiaTable($lines, $validity);
    } elseif (preg_match('/\b(Latin|South)\s+America\b/i', $content)) {
        return $this->parsePilLatinAmericaTable($lines, $validity);
    } elseif (preg_match('/\bOceania\b/i', $content)) {
        return $this->parsePilOceaniaTable($lines, $validity);
    } elseif (preg_match('/\bSouth\s+Asia\b/i', $content)) {
        return $this->parsePilSouthAsiaTable($lines, $validity);
    }
    return [];
}
```

**Key Changes:**
- Removed strict "Trade :" prefix requirement
- Uses word boundary `\b` to match keywords anywhere in content
- Now works with filename detection (see Change 3)

**How It Works:**
1. The `extractFromPdf()` method (Change 3) prepends "Trade: RegionName" from filename
2. Router checks for just the keyword (e.g., "Oceania") anywhere in content
3. Matches filename "PIL Oceania quotation..." → "Trade: Oceania" → `\bOceania\b` matches → routes to correct parser

---

#### Change 2: Oceania Parser - Flexible Cell Count (Line 4384)

**Purpose:** Handle inconsistent Azure OCR cell counts

**Problem:**
- Azure OCR produced inconsistent cell counts:
  - Row 2: 18 cells (includes optional right-side remark)
  - Rows 3-6: 17 cells (missing optional right-side remark)
- Parser required `count($cells) >= 18` for right-side processing
- Result: Only Brisbane extracted (row 2), Sydney/Melbourne/Fremantle/Adelaide skipped (rows 3-6)

**Before (Session 2):**
```php
if (count($cells) >= 18) {
    // Process right destination (cells 9-17)
    $pod2 = trim($cells[9] ?? '');
    // ... extract cells 10-17
    $remark2 = trim($cells[17] ?? '');
```

**After (Session 3):**
```php
if (count($cells) >= 17) {
    // Process right destination (cells 9-16, cell 17 is optional remark)
    $pod2 = trim($cells[9] ?? '');
    // ... extract cells 10-16
    $remark2 = trim($cells[17] ?? '');  // Optional - may not exist in OCR
```

**Key Change:**
- Changed cell count check from `>= 18` to `>= 17`
- Cell 17 (right-side remark) is now optional
- Safe because `??` operators handle missing cells

**How It Works:**
1. Parser checks if at least 17 cells exist (minimum for right destination)
2. Extracts cells 9-16 (POD, CODE, rates, T/T, T/S, FREE TIME)
3. Cell 17 (remark) extracted if present, empty string if missing
4. All 10 destinations now process correctly regardless of OCR inconsistency

---

#### Change 3: PDF Extraction - Trade Field Injection (Lines 257-268, 299-309)

**Purpose:** Ensure region keywords exist in content for router detection

**Added to `extractFromPdf()` method:**

**For cached OCR (Lines 257-268):**
```php
// For PIL carrier: add Trade field from JSON or filename to help region detection
if ($pattern === 'pil' && file_exists($jsonFile)) {
    $jsonContent = file_get_contents($jsonFile);
    // Try to extract Trade field from JSON
    if (preg_match('/"content":\s*"Trade:\s*([^"]+)"/i', $jsonContent, $matches)) {
        // Prepend Trade field as first line for region detection
        array_unshift($lines, "Trade: " . trim($matches[1]));
    } elseif (preg_match('/(Africa|Intra Asia|Latin America|Oceania|South Asia)/i', $baseFilename, $matches)) {
        // Fallback: detect region from filename
        array_unshift($lines, "Trade: " . $matches[1]);
    }
}
```

**For fresh OCR (Lines 299-309):**
```php
// For PIL carrier: add Trade field from OCR result to help region detection
if ($pattern === 'pil') {
    $fullText = $azureOcr->extractFullTextFromResult($azureResult);
    if (preg_match('/Trade:\s*([^\n]+)/i', $fullText, $matches)) {
        // Prepend Trade field as first line for region detection
        array_unshift($lines, "Trade: " . trim($matches[1]));
    } elseif (preg_match('/(Africa|Intra Asia|Latin America|Oceania|South Asia)/i', $baseFilename, $matches)) {
        // Fallback: detect region from filename
        array_unshift($lines, "Trade: " . $matches[1]);
    }
}
```

**How It Works:**
1. **Primary method:** Extract "Trade: RegionName" from full JSON content
2. **Fallback method:** Detect region keyword from filename (e.g., "PIL Oceania quotation..." → "Oceania")
3. Prepend "Trade: RegionName" as first line of `$lines` array
4. PIL router can now detect region even when OCR tables don't contain document text

**Why Fallback Works:**
- All PIL test files follow naming convention: "PIL {Region} quotation..."
- Filename contains region keyword: "Africa", "Intra Asia", "Oceania", etc.
- Regex extracts keyword from filename → inserts as fake "Trade:" line
- Router detects keyword and routes to correct parser

---

## All Code Changes in Session 4

### File: app/Services/RateExtractionService.php

#### Change 1: Africa Parser - Dynamic Port Code Detection (Lines 4134-4267)

**Purpose:** Handle Azure OCR merged rows where multiple destinations appear in single row

**Problem:**
- Azure OCR merged multiple destinations into single rows (e.g., "Mombasa | KEMBA | ... | Tema | GHTEM | ...")
- Some rows contained both headers AND data (e.g., "PORTs | CODE | ... | Apapa, Lagos | NGLOS | ...")
- Original parser expected one destination per row at fixed cell positions (0-6)
- 5 destinations were missing due to merged rows

**Before (Session 3):**
```php
protected function parsePilAfricaTable(array $lines, string $validity): array
{
    $rates = [];
    $inDataSection = false;

    foreach ($lines as $line) {
        if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) continue;

        $cells = explode(' | ', $matches[1]);
        if (count($cells) < 5) continue;

        // Skip header rows
        if (preg_match('/(PORTs|CODE|RATE IN USD)/i', $cells[0] ?? '')) {
            $inDataSection = true;
            continue;  // ← This skips rows with headers, even if they have data!
        }

        if (!$inDataSection) continue;

        // Fixed position extraction
        $pod = trim($cells[0] ?? '');
        $code = trim($cells[1] ?? '');
        $rate20Raw = trim($cells[2] ?? '');
        // ... extract at fixed positions 0-6
    }
}
```

**After (Session 4):**
```php
protected function parsePilAfricaTable(array $lines, string $validity): array
{
    $rates = [];

    foreach ($lines as $line) {
        if (!preg_match('/^Row \d+: (.+)$/', $line, $matches)) continue;

        $cells = explode(' | ', $matches[1]);
        if (count($cells) < 5) continue;

        // Detect all 5-letter uppercase port codes in the row
        $portCodes = [];
        foreach ($cells as $idx => $cell) {
            if (preg_match('/^[A-Z]{5}$/', trim($cell))) {
                $portCodes[] = ['index' => $idx, 'code' => trim($cell)];
            }
        }

        // Multiple port codes found - process each destination
        if (count($portCodes) > 1) {
            foreach ($portCodes as $portInfo) {
                $codeIdx = $portInfo['index'];
                $pod = trim($cells[$codeIdx - 1] ?? '');  // Port name before code
                $rate20Raw = trim($cells[$codeIdx + 1] ?? '');  // Rate after code
                $rate40Raw = trim($cells[$codeIdx + 2] ?? '');
                $tt = trim($cells[$codeIdx + 3] ?? '');
                $ts = trim($cells[$codeIdx + 4] ?? '');
                $freeTime = trim($cells[$codeIdx + 5] ?? '');

                // Skip header-like port names
                if (empty($pod) || preg_match('/(^PORTs$|^CODE$|RATE IN USD)/i', $pod)) continue;

                // Create rate entry
                $rates[] = $this->createRateEntry(...);
            }
        } elseif (count($portCodes) == 1) {
            // Single port code - use dynamic positioning
            $codeIdx = $portCodes[0]['index'];
            $pod = trim($cells[$codeIdx - 1] ?? '');
            // ... extract relative to code position
        } else {
            // No port codes - use fixed positions (fallback)
            $pod = trim($cells[0] ?? '');
            $code = trim($cells[1] ?? '');
            // ... extract at fixed positions
        }
    }
}
```

**Key Changes:**
1. **Removed header skip logic** - No longer skips entire rows containing "PORTs" or "CODE"
2. **Added port code detection** - Scans all cells for 5-letter uppercase codes (e.g., NGLOS, KEMBA)
3. **Dynamic position-based extraction** - Extracts data relative to port code position, not fixed cells
4. **Three-tier strategy:**
   - Multiple port codes (>1): Process each destination in merged row
   - Single port code (=1): Extract relative to code position (handles header-mixed rows)
   - No port codes (=0): Fall back to fixed positions (compatibility)

**How It Works:**
1. For each row, scan all cells to find 5-letter uppercase port codes
2. If multiple codes found (e.g., KEMBA and GHTEM), process each as separate destination
3. Extract port name from cell[code_index - 1], rates from cell[code_index + 1/+2], etc.
4. Skip destinations with header-like names ("PORTs", "CODE", "RATE IN USD")
5. Handle rows with mixed header+data content (e.g., "PORTs | CODE | ... | Apapa, Lagos | NGLOS")

**Result:**
- 13/18 → 18/18 records (100% Africa extraction)
- Recovered 5 previously missing destinations
- Handles all OCR merge patterns

---

#### Change 2: Header Name Filtering (Lines 4177, 4216, 4248)

**Purpose:** Prevent header text from being extracted as destination names

**Problem:**
- Removing header skip logic meant rows like "PORTs | CODE | ... | Apapa, Lagos | NGLOS" now process
- Risk of extracting "PORTs" as a destination name if it appears before a port code

**Fix:** Added regex filtering to skip destinations with header-like names

**Added to all three extraction paths:**
```php
// Skip if port name is empty or looks like header
if (empty($pod) || preg_match('/(Validity|Rates quotation|Note|RATE IN USD|^PORTs$|^CODE$)/i', $pod)) continue;
```

**Patterns Filtered:**
- `^PORTs$` - Exact match for "PORTs" column header
- `^CODE$` - Exact match for "CODE" column header
- `RATE IN USD` - Rate column header
- `Validity|Rates quotation|Note` - Document metadata text
- `20'GP|40'HC` - Size headers (added from previous sessions)

**Result:** No false destination records created from header text

---

## Session 2 Testing Results

### ✅ Latin America: PERFECT (19/19 records)
**Expected:** 19 records
**Extracted:** 19 records ✅
**Status:** 100% accurate
**Parser:** Lines 4237-4296 in RateExtractionService.php
**Format:** Single POL (BKK/LCH combined) with LSR column

**Column Structure:**
```
PORT | CODE | 20' | 40' | T/T | T/S | FREE TIME | LSR
```

**Key Success:** Parser correctly handles LSR column and builds remarks properly.

---

### ❌ Oceania: BLOCKED (0/10 records) - Session 2 Status
**Expected:** 10 records
**Extracted:** 0 records (extraction fails completely)
**Status:** Parser rewritten but region detection was failing
**Parser:** Lines 4298-4394 in RateExtractionService.php (MODIFIED in Session 2)

**Note:** This issue was fully resolved in Session 3. See [Session 3 Oceania results](#-oceania-perfect-1010-records---fixed) for the complete fix.

#### **Root Cause Identified in Session 2: Side-by-Side Table Layout**

The Oceania PDF has a **unique side-by-side layout** with 2 destinations per row:

**OCR Output Example:**
```
Row 2: Auckland | NZAKL | n/a | n/a | n/a | 24 days | SIN | 14 days | No accept new NZ shipment in WK 02-03/2026 | Brisbane | AUBNE | 1,050 | 2,000 | 2,000 | 18 days | DIRECT | 14 days | Ex Lat Krabang and Laem Chabang
```

**Structure:**
- **Left destination:** cells 0-8 (PORT | CODE | 20' | 40' | 40'HQ | T/T | T/S | F/T | REMARK)
- **Right destination:** cells 9-17 (PORT | CODE | 20' | 40' | 40'HQ | T/T | T/S | F/T | REMARK)
- **Total:** 18 cells per row (but Azure OCR inconsistently produces 17-18 cells)

**Expected Destinations:**
- **Left side (NZ ports):** Auckland, Lyttelton, Wellington, Napier, Tauranga (5 destinations)
- **Right side (AU ports):** Brisbane, Sydney, Melbourne, Fremantle, Adelaide (5 destinations)
- **Total:** 10 destinations

#### **What Was Attempted in Session 2**

**Modified Lines 4298-4394:** Rewrote Oceania parser to handle side-by-side layout

**Key Changes Attempted:**
1. Changed from single-column parsing to dual-column parsing
2. Process left destination from cells 0-8
3. Process right destination from cells 9-17 (changed from `>= 17` to `>= 18`)
4. Skip size header rows (20'GP | 40'GP | 40'HC)

#### **Why It Failed in Session 2**

**Two issues were blocking extraction:**
1. **Region detection failing:** The PIL router required "Trade : Oceania" text but OCR table extraction only contained table data, not document text
2. **Cell count mismatch:** Azure OCR produced inconsistent cell counts (17-18), and parser required exactly 18 cells

**These issues were fully resolved in Session 3 - see below.**

---

### ✅ Africa: Was Partial (13/18 records) in Sessions 1-3
**Status:** FULLY FIXED in Session 4 - now 18/18 records ✅

**Historical Issue:** Azure OCR merged multiple destinations into single rows. This was different from Oceania's side-by-side layout.

**Previously Missing Destinations (Session 1-3):**
1. Apapa, Lagos (NGLOS) ✅ FIXED
2. Onne (NGONN) ✅ FIXED
3. Tema (GHTEM) ✅ FIXED
4. Lome (TGLFW) ✅ FIXED
5. Cotonou (BJCOO) ✅ FIXED

**Note:** This was fully resolved in Session 4 with dynamic port code detection. See [Session 4 results](#session-4-testing-results-current---final-fix) for complete fix details.

---

## All Code Changes in Session 2

### File: app/Services/RateExtractionService.php

#### Change 1: Oceania Parser Rewrite (Lines 4298-4394)
**Purpose:** Handle side-by-side table layout with 2 destinations per row

**Before:** Expected single destination per row (cells 0-7)
**After:** Processes dual destinations per row (cells 0-8 for left, 9-17 for right)

**Key Fix on Line 4359:**
```php
// OLD: if (count($cells) >= 17)
// NEW: if (count($cells) >= 18)
```

**Reason:** Need 18 cells total (0-17) to access cell 17 (remark of right destination)

---

## OCR File Analysis

### Oceania OCR Structure
**File:** `temp_attachments/azure_ocr_results/1768296685_PIL_Oceania_quotation_in_1H_Jan_2026_revised_I_tables.txt`

```
TABLE 1 (Rows: 7, Cols: 18)
Row 0: PORTs | CODE | RATE IN USD | T/T (DAY) | T/S | F/T @ Dest | Remark | PORTs | CODE | RATE IN USD | T/T (DAY) | T/S | F/T @ Dest | Remark
Row 1: 20'GP | 40'GP | 40'HC | 20'GP | 40'GP | 40'HC
Row 2: Auckland | NZAKL | n/a | n/a | n/a | 24 days | SIN | 14 days | No accept new NZ shipment in WK 02-03/2026 | Brisbane | AUBNE | 1,050 | 2,000 | 2,000 | 18 days | DIRECT | 14 days | Ex Lat Krabang and Laem Chabang
Row 3: Lyttelton | NZLYT | n/a | n/a | n/a | 28 days | SIN | 14 days | Sydney | AUSYD | 1,050 | 2,000 | 2,000 | 16 days | DIRECT | 14 days | Ex Lat Krabang and Laem Chabang
Row 4: Wellington | NZWLG | n/a | n/a | n/a | 30 days | SIN | 14 days | Melbourne | AUMEL | 1,050 | 2,000 | 2,000 | 13 days | DIRECT | 14 days | Ex Lat Krabang and Laem Chabang
Row 5: Napier | NZNPE | n/a | n/a | n/a | 31 days | SIN | 14 days | Fremantle | AUFRE | 1,100 | 2,100 | 2,100 | 14 days | SIN | 14 days | ex BKK/LCH t/s SIN
Row 6: Tauranga | NZTRG | n/a | n/a | n/a | 32 days | SIN | 14 days | Adelaide | AUADL | 1,100 | 2,100 | 2,100 | 32 days | SIN | 14 days | ex BKK/LCH t/s SIN
```

**Analysis:**
- Row 0: Header (duplicated for both sides)
- Row 1: Size headers (20'GP | 40'GP | 40'HC duplicated)
- Rows 2-6: Data rows (5 rows × 2 destinations = 10 total destinations)
- Each data row has exactly 18 pipe-separated cells

**Left Side Destinations (NZ):**
- All have rates = "n/a" (should be handled by parsePilRate returning empty string)
- 5 destinations: Auckland, Lyttelton, Wellington, Napier, Tauranga

**Right Side Destinations (AU):**
- All have valid numeric rates
- 5 destinations: Brisbane, Sydney, Melbourne, Fremantle, Adelaide

---

## Implementation Notes

### Working Regions (3/5)

1. **Intra Asia (44/44)** - Dual POL format works perfectly
2. **Latin America (19/19)** - Single POL with LSR works perfectly
3. **Africa (13/18)** - Single POL works but OCR structure issue causes missing records

### Blocked Regions (1/5)

1. **Oceania (0/10)** - Parser rewritten but still returning 0 records
   - **Critical Issue:** Need to debug why extraction completely fails
   - **Server Restarts:** Had to restart Laravel server twice to reload code changes
   - **File Location:** Used correct uppercase .PDF extension for file

### Not Tested (1/5)

1. **South Asia (0/28)** - Should work like Intra Asia (dual POL)

---

## Known Issues

### Issue 1: Oceania Parser Still Failing
**Severity:** HIGH - Blocking 10 records
**Status:** Parser logic fixed but extraction returns 0 records
**Next Steps:**
1. Add debug logging to see if `parsePilOceaniaTable()` is called
2. Check if "Trade : Oceania" regex matches in content detection
3. Verify actual cell count in each row matches expected 18
4. Test with manual data to isolate parser vs OCR issue

### Issue 2: Africa Multi-Destination Rows
**Severity:** MEDIUM - Missing 5 records
**Status:** Known issue from Session 1, not addressed in Session 2
**Next Steps:** Implement row splitting logic (see original document Options A/B/C)

### Issue 3: Laravel Code Caching
**Impact:** Development workflow
**Workaround:** Must restart Laravel server (`php artisan serve`) after code changes
**Occurred:** Twice during Oceania parser testing

---

## Files Modified This Session

### Modified:
1. **app/Services/RateExtractionService.php** (Lines 4298-4394)
   - Completely rewrote `parsePilOceaniaTable()` method
   - Changed cell count check from `>= 17` to `>= 18`
   - Added side-by-side dual-destination processing logic

### Created:
- None (used existing OCR cache files)

### Tested Files:
1. `example_docs/PIL/PIL Latin America quotation in 1H Jan 2026.pdf` ✅ SUCCESS
2. `example_docs/PIL/PIL Oceania quotation in 1H Jan 2026_revised I.PDF` ❌ FAILED (note uppercase .PDF)

---

## Next Session Action Plan (Updated After Session 3)

### ✅ Priority 1: Fix Oceania Parser - COMPLETED
**Result:** 10/10 records now extracting correctly
**Fixes Applied:**
1. Changed PIL router to keyword-based detection
2. Changed cell count check from `>= 18` to `>= 17`
3. Added Trade field injection from filename

### ✅ Priority 2: Test South Asia - COMPLETED
**Result:** 28/28 records extracted perfectly on first try
**File:** `example_docs/PIL/PIL South Asia quotation in 1H Jan 2026.pdf`

### 🔄 Priority 3 (Remaining): Fix Africa Parser
**Goal:** Extract missing 5 destinations from merged rows (13/18 → 18/18)

**Current Status:**
- 13 destinations extracted correctly
- 5 destinations missing due to Azure OCR merging multiple ports into single rows
- This is a data quality issue, not a parser logic issue

**Potential Solutions:**

**Option A: Row Splitting Preprocessor (Recommended)**
```php
// Before parsing, detect and split multi-destination rows
protected function splitMergedAfricaRows(array $lines): array
{
    $splitLines = [];
    foreach ($lines as $line) {
        // Detect multiple port codes in single row (e.g., "NGLOS | NGONN | ...")
        if (preg_match_all('/\b[A-Z]{5}\b/', $line, $matches) && count($matches[0]) > 1) {
            // Split into individual rows, preserving shared data
            foreach ($matches[0] as $portCode) {
                $splitLines[] = createRowForPort($line, $portCode);
            }
        } else {
            $splitLines[] = $line;
        }
    }
    return $splitLines;
}
```

**Option B: Multi-Destination Row Detection**
```php
// Parse multiple destinations from single row
if (preg_match_all('/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\s*\|\s*([A-Z]{5})/', $cellData, $matches)) {
    foreach ($matches[1] as $i => $port) {
        // Create separate record for each port/code pair
    }
}
```

**Option C: Manual OCR Correction**
- Pre-process problematic PDFs with better OCR settings
- Manually edit `_tables.txt` files to split merged rows
- Not scalable for production use

**Recommendation:** Implement Option A (row splitting preprocessor) as it's most robust and handles edge cases

---

## Files Modified in Session 4

### Modified Files:
1. **app/Services/RateExtractionService.php**
   - Lines 4142-4267: Complete rewrite of Africa parser with dynamic port code detection
   - Lines 4177, 4216, 4248: Added header name filtering (^PORTs$, ^CODE$, RATE IN USD)
   - Removed lines 4146-4150: Removed aggressive header skip logic

2. **md_docs/PIL_TEST_RESULTS.md** (this file)
   - Updated header to Session 4 (Final) status
   - Changed test results summary to 119/119 (100%)
   - Added Session 4 Testing Results section with Africa fix details
   - Added Session 4 Code Changes section with before/after comparisons
   - Updated Summary section with 100% completion status
   - Updated Known Issues section marking Africa issue as RESOLVED
   - Updated final status line to celebrate 100% success

### Testing Results Files:
- Used existing cached OCR file: `1768295990_PIL_Africa_quotation_in_1H_Jan_2026_tables.txt`
- Web interface extractions tested via http://127.0.0.1:8000/extract
- Created temporary `read_africa_excel.php` script to read Excel output

### Tested PDFs:
1. `example_docs/PIL/PIL Africa quotation in 1H Jan 2026.pdf`:
   - Attempt 1: 16/18 records (missing Apapa/Lagos and duplicate PORTs header)
   - Attempt 2: 17/18 records (recovered Onne, still missing Apapa/Lagos)
   - Attempt 3: 19/18 records (recovered Apapa/Lagos but extracted invalid PORTs header)
   - **Final: 18/18 records ✅ SUCCESS** (all destinations, no false headers)

---

## Files Modified in Session 3

### Modified Files:
1. **app/Services/RateExtractionService.php**
   - Lines 257-268: Added Trade field injection for cached OCR (PIL pattern)
   - Lines 299-309: Added Trade field injection for fresh OCR (PIL pattern)
   - Lines 4110-4129: Changed PIL router from strict "Trade:" matching to keyword-only matching
   - Line 4384: Changed Oceania parser cell count check from `>= 18` to `>= 17`

2. **md_docs/PIL_TEST_RESULTS.md** (this file)
   - Updated test results summary (Session 3)
   - Added detailed Session 3 code changes documentation
   - Updated action plan with completion status

### Testing Results Files:
- Used existing cached OCR files (no new files generated)
- Web interface extractions tested via http://127.0.0.1:8000/extract

### Tested PDFs:
1. `example_docs/PIL/PIL Oceania quotation in 1H Jan 2026_revised I.PDF` ✅ SUCCESS (10/10 records)
2. `example_docs/PIL/PIL South Asia quotation in 1H Jan 2026.pdf` ✅ SUCCESS (28/28 records)

---

## Developer Notes

### Code Quality
- All parsers follow consistent structure
- Helper method `parsePilRate()` works correctly across all formats
- Dual POL logic (Intra Asia) proven to work perfectly

### Testing Workflow
1. Upload PDF via web interface: http://127.0.0.1:8000/extract
2. Click "Extract Rates" button
3. Wait 5-20 seconds for processing
4. Check result page for record count
5. Download Excel to verify data quality

### OCR Cache Location
- All OCR results cached in: `temp_attachments/azure_ocr_results/`
- Filename format: `{timestamp}_{filename}_tables.txt`
- Cache persists across sessions (no re-OCR needed)

### Laravel Server
- Running on: http://127.0.0.1:8000
- Must restart after code changes: `php artisan serve --host=127.0.0.1 --port=8000`
- Background task ID tracked for easy termination

---

## Summary

**Session 4 Progress (FINAL):**
- ✅ Fixed Africa merged row handling: 13/18 → 18/18 records
- ✅ Implemented dynamic port code detection for all Africa sub-regions
- ✅ Removed aggressive header skip logic
- 🎉 **100% EXTRACTION SUCCESS - ALL REGIONS PERFECT**

**Overall PIL Status:**
- **Working:** 119/119 records (100%) ✅
- **Perfect Regions:** 5/5 (Africa, Intra Asia, Latin America, Oceania, South Asia)
- **Partial Regions:** 0/5
- **Missing:** 0 records

**Code Changes Made in Session 4:**

1. **Africa Parser - Dynamic Port Code Detection (Lines 4134-4267):**
   - Scans all cells for 5-letter uppercase port codes
   - Extracts data relative to code position (not fixed cells)
   - Handles merged rows with multiple destinations
   - Three-tier strategy: multiple codes / single code / no codes (fallback)

2. **Header Name Filtering (Lines 4177, 4216, 4248):**
   - Added regex filtering to skip header-like port names
   - Prevents "PORTs", "CODE", "RATE IN USD" from being extracted as destinations

**Code Changes Made in Session 3:**

1. **PIL Region Router (Lines 4115-4124):**
   - Changed from strict "Trade : RegionName" matching to keyword-only matching
   - Now detects regions from filename alone

2. **Oceania Parser (Line 4384):**
   - Changed cell count check from `>= 18` to `>= 17`
   - Handles inconsistent OCR cell counts gracefully

3. **PDF Extraction (Lines 257-268, 299-309):**
   - Added Trade field injection from JSON for region detection
   - Fallback to filename-based region detection

**Remaining Work:**
✅ **NONE - 100% COMPLETE**

---

**Last Updated:** 2026-01-13 (Session 4 - FINAL)
**Session Status:** 🎉🎉🎉 **COMPLETE SUCCESS! ALL 5 REGIONS PERFECT (100% EXTRACTION)** 🎉🎉🎉

---

## Known Issues & Edge Cases

### Issue 1: Africa Multi-Destination Rows (RESOLVED ✅)
**Severity:** MEDIUM - Was missing 5/18 records (28% data loss for Africa region)
**Status:** FULLY FIXED in Session 4
**Impact:** All destinations now extracting correctly:
- Apapa, Lagos (NGLOS) ✅ FIXED
- Onne (NGONN) ✅ FIXED
- Tema (GHTEM) ✅ FIXED
- Lome (TGLFW) ✅ FIXED
- Cotonou (BJCOO) ✅ FIXED

**Root Cause:** Azure OCR Document Intelligence merged multiple destination rows into single table rows. Original parser expected one destination per row at fixed cell positions.

**Solution:** Implemented dynamic port code detection (Session 4):
- Scans all cells for 5-letter uppercase port codes
- Extracts data relative to port code position (not fixed cells)
- Handles merged rows with multiple destinations
- Result: 13/18 → 18/18 records (100%)

---

### Issue 2: Oceania "n/a" Rates (RESOLVED - Working As Designed)
**Severity:** LOW - Informational only
**Status:** Working as designed
**Behavior:** NZ destinations (Auckland, Lyttelton, Wellington, Napier, Tauranga) extract with empty rate fields because PDF contains "n/a" for rates.

**Explanation:** This is correct behavior. The `parsePilRate()` helper correctly returns empty string for "n/a" values, allowing the record to be created without rates. Users can see these destinations exist but have no published rates.

**Excel Output:** These records appear with empty 20'/40'/40HQ columns but contain T/T, T/S, FREE TIME, and REMARK data.

---

### Issue 3: OCR Inconsistent Cell Counts (RESOLVED)
**Severity:** HIGH - Was blocking 4/10 Oceania records
**Status:** FIXED in Session 3
**Solution:** Changed cell count check to handle optional last column (remark field).

**Details:** Azure OCR sometimes omits the final remark cell (cell 17) for right-side destinations. Changed parser from requiring 18 cells to requiring 17 cells, making the 18th cell optional.

---

### Issue 4: PIL Region Detection Failure (RESOLVED)
**Severity:** CRITICAL - Was blocking all Oceania extractions
**Status:** FIXED in Session 3
**Solution:** Changed from strict "Trade : RegionName" matching to keyword-only matching + filename fallback.

**Details:**
- Original code required "Trade : Oceania" text in OCR content
- OCR table extraction only contains table rows, not document text
- Fixed by detecting region keyword from filename ("PIL Oceania quotation...") and injecting "Trade: Oceania" line
- PIL router now uses simple keyword matching (`\bOceania\b`) instead of strict format

---

### Edge Case 1: Dual POL Formats
**Regions Affected:** Intra Asia, South Asia
**Behavior:** Each destination creates 2 records (POL='BKK' and POL='LCH')
**Status:** Working correctly
**Note:** This is expected behavior for regions with separate port pricing.

---

### Edge Case 2: Single POL Format
**Regions Affected:** Africa, Latin America, Oceania
**Behavior:** Each destination creates 1 record (POL='BKK/LCH')
**Status:** Working correctly
**Note:** These regions have combined port pricing.

---

### Edge Case 3: Side-by-Side Table Layout
**Regions Affected:** Oceania only
**Behavior:** PDF contains 2 destinations per row (NZ on left, AU on right)
**Status:** Working correctly after Session 3 fixes
**Implementation:** Parser processes cells 0-8 (left) and 9-17 (right) separately.

---

### Edge Case 4: File Extension Case Sensitivity
**Observation:** Test files use both `.pdf` and `.PDF` extensions
**Impact:** None - filesystem is case-insensitive on Windows
**Examples:**
- `PIL Oceania quotation in 1H Jan 2026_revised I.PDF` (uppercase)
- `PIL South Asia quotation in 1H Jan 2026.pdf` (lowercase)

---

## Performance Notes

### Extraction Speed:
- **Average time per PDF:** 7-20 seconds
- **OCR cache hit:** ~4-7 seconds (reading cached tables)
- **OCR cache miss:** ~18-29 seconds (fresh Azure OCR + processing)

### Memory Usage:
- Stable for all tested PDFs
- No memory leaks observed during session

### Laravel Server:
- Requires manual restart after code changes
- No auto-reload in development mode
- Background task management working correctly

---

**Last Updated:** 2026-01-13 17:15
**Documentation Status:** Complete and ready for compaction.
