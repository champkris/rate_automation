# Bug Fix 13 - SM LINE Skip Filter False Positive

## Symptom

SM LINE rate extraction produced only 2 rows (QINGDAO REEFER and SHEKOU REEFER, both LCH only) out of the expected 12 rows. All other ports — HOCHIMINH, BUSAN/KWANGYANG, INCHEON, SHANGHAI, QINGDAO, SHEKOU and their REEFER variants — were missing from the output.

## Root Cause

**Azure OCR merges side-by-side tables into one wide table.**

The SM LINE PDF has two tables printed side-by-side:
- **Left table** (cols 0-7): Outbound Rate table (COUNTRY, POD, BKK 20', BKK 40', LCH 20', LCH 40', REMARK, FREE TIME)
- **Right table** (cols 8+): Local Charges table (THC, B/L FEE, SEAL FEE, CFS, D/O FEE, DEPOSIT CHARGE, CLEANING FEE, etc.)

Azure OCR detects them as **one 17-column table**, so each row contains data from both tables. For example:

```
Row 2: VIETNAM | HOCHIMINH (CAT LAI) | $20 | $40 | $20 | $40 | Inclusive of CIC/LSF | DEM 14 + DET 7 days | B/L FEE | THB 1,400/SET
```

The parser had a skip filter that checked the **entire line** for keywords like `THC`, `B/L`, `SEAL`, `CFS`, `INBOUND`, `D/O`, `Container`, `DEPOSIT`, `CLEANING`. These keywords were meant to skip local charges rows, but they appeared in cols 8+ of every valid rate row, causing the filter to skip almost everything.

### Rows killed by the skip filter

| Row | Port | Killed by keyword | Source |
|-----|------|-------------------|--------|
| Row 2 | HOCHIMINH (CAT LAI) | `B/L` | "B/L FEE" in col 8 |
| Row 3 | HOCHIMINH (REEFER) | `SEAL` | "SEAL FEE" in col 8 |
| Row 4 | BUSAN/KWANGYANG | `B/L` | "SURRENDER B/L FEE" in col 8 |
| Row 5 | BUSAN/KWANGYANG (REEFER) | `CFS` | "CFS at UNITHAI" in col 8 |
| Row 6 | INCHEON | `INBOUND` | "INBOUND" section header in col 7 |
| Row 7 | INCHEON (REEFER) | `THC` | "THC" charge data in col 7 |
| Row 8 | SHANGHAI | `D/O` | "D/O FEE" in col 8 |
| Row 9 | QINGDAO | `Container` | "Container Premium Charge" in col 8 |
| Row 10 | SHEKOU | `DEPOSIT` | "DEPOSIT CHARGE" in col 8 |
| Row 11 | SHANGHAI (REEFER) | `CLEANING` | "CLEANING FEE" in col 8 |
| Row 12 | QINGDAO (REEFER) | **survived** | Short row, no local charges data |
| Row 13 | SHEKOU (REEFER) | **survived** | Short row, no local charges data |

Only Row 12 and 13 survived because they had fewer columns (no local charges data appended).

## Fix Strategy

**Split cells first, then check only the first 3 cells for skip keywords.**

The skip keywords (`THC`, `B/L`, `SEAL`, etc.) are meant to identify rows that are purely local charges data — where these words appear as the **row label** in cells[0-2]. In valid rate rows, these keywords only appear in cells[7+] (the local charges portion of the merged table).

By checking only cells[0] through cells[2] (the COUNTRY/POD area), we correctly:
- **Keep** rate rows where local charges keywords appear in later columns
- **Skip** actual charges-only rows where the keyword is the row label

## Code Change

### File: `app/Services/RateExtractionService.php`
### Method: `parseSmLineTable()` (line ~4024)

#### Before (old code):
```php
foreach ($lines as $line) {
    // Skip headers and non-data rows
    if (preg_match('/^TABLE \d+|^-{10,}|^Row [01]:|COUNTRY|POD.*DESTINATION|OUTBOUND|INBOUND|THC|B\/L|SEAL|CFS|D\/O|Container|DEPOSIT|CLEANING/i', $line)) {
        continue;
    }

    // Match data rows (Row 2 onwards for rate data)
    if (!preg_match('/^Row \d+:\s*(.+)/', $line, $matches)) {
        continue;
    }

    $rowData = $matches[1];
    $cells = array_map('trim', explode('|', $rowData));
```

**Problem:** The regex ran on the entire `$line`, so any skip keyword anywhere in the row (including cols 8+ from local charges) would discard the whole row.

#### After (new code):
```php
foreach ($lines as $line) {
    // Skip non-row lines (table headers, separators)
    if (preg_match('/^TABLE \d+|^-{10,}|^Row [01]:/i', $line)) {
        continue;
    }

    // Match data rows (Row 2 onwards for rate data)
    if (!preg_match('/^Row \d+:\s*(.+)/', $line, $matches)) {
        continue;
    }

    $rowData = $matches[1];
    $cells = array_map('trim', explode('|', $rowData));

    // Skip non-rate rows by checking only the first 3 cells (COUNTRY/POD area)
    // Azure OCR merges the rate table and local charges table side-by-side into one wide row,
    // so keywords like THC, B/L, SEAL etc. appear in cells[7+] for valid rate rows.
    // Only skip if these keywords appear in the first 3 cells (meaning it's a real charges row).
    $firstCells = implode(' | ', array_slice($cells, 0, 3));
    if (preg_match('/COUNTRY|POD.*DESTINATION|OUTBOUND|INBOUND|THC|B\/L|SEAL|CFS|D\/O|Container|DEPOSIT|CLEANING/i', $firstCells)) {
        continue;
    }
```

**What changed:**
1. Structural filters (`TABLE`, separator, `Row 0/1`) stay on the full line (these are safe)
2. Content-based skip keywords are split into a separate check that runs **after** cell extraction
3. Only `cells[0]` through `cells[2]` are joined and checked — this covers the COUNTRY and POD columns where a real charges row label would appear
4. Keywords in cells[7+] (local charges data) no longer trigger false skips

## Comparison with Old Git Commit (54fbdeb)

The original SM LINE parser (commit `54fbdeb933bd117e3654597c5ac213789889dee5`) had the **exact same skip filter bug**. The filter was identical in both old and new code. This bug existed from the initial SM LINE implementation — it was not a regression from later changes.

The old commit also differed in rate extraction approach (generic value collection vs. current position-based), but the skip filter issue was present in both versions.

## Result

- **Before fix:** 2 rows output (only QINGDAO REEFER and SHEKOU REEFER)
- **After fix:** All 12 rows output correctly with proper BKK/LCH handling
