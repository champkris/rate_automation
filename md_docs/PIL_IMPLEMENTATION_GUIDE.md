# PIL (Pacific International Lines) Implementation Guide

## Overview

This document provides complete implementation specifications for adding PIL PDF rate extraction to the rate automation system. PIL has 5 regional PDF formats covering different trade routes.

**Related Files:**
- Analysis: `test_script/PIL_ANALYSIS.md` - Detailed structure analysis of all 5 PDF files
- Example PDFs: `example_docs/PIL/` - 5 PDF files (Africa, Intra Asia, Latin America, Oceania, South Asia)

---

## 1. File Information

### 1.1 PIL PDF Files (5 Regional Formats)

| File | Trade Region | Destinations | Validity | Special Notes |
|------|--------------|--------------|----------|---------------|
| PIL Africa quotation in 1H Jan 2026.pdf | Africa | 18 ports (5 sub-regions) | 1-14 Jan 2026 | Multi-section page, HEA charges |
| PIL Intra Asia quotation in 1H Jan 2026.pdf | Intra Asia | 22 ports | 1-14 Jan 2026 | Dual POL (BKK/LCH), cleanest format |
| PIL Latin America quotation in 1H Jan 2026.pdf | Latin America | 18 ports (WCSA/ECSA) | 1-14 Jan 2026 | Complex routing, LSR as numeric |
| PIL Oceania quotation in 1H Jan 2026_revised I.PDF | Oceania | 10 ports (AU/NZ) | 4-14 Jan 2026 | 3 container types, NZ rates n/a |
| PIL South Asia quotation in 1H Jan 2026.pdf | South Asia | 13 ports | 1-14 Jan 2026 | Dual POL, ISD inline, HEA for West Coast |

**Total Ports**: ~81 destinations across 5 continents

---

## 2. PIL File Detection Strategy

### 2.1 Detection Algorithm

Use scoring system (threshold: 70/135 points):

```php
protected function isPilFile($pdfText): bool
{
    $score = 0;

    // Primary identifiers
    if (stripos($pdfText, 'Pacific International Lines Pte Ltd') !== false) {
        $score += 40;
    }

    // Logo detection (if image processing available)
    if ($this->detectPilLogo($pdfPath)) {
        $score += 30;
    }

    // Trade header
    if (preg_match('/Trade\s*:\s*(Africa|Intra Asia|South America|Oceania|South Asia)/i', $pdfText)) {
        $score += 15;
    }

    // Standard columns
    $columnCount = 0;
    if (stripos($pdfText, 'PORTs') !== false) $columnCount++;
    if (stripos($pdfText, 'CODE') !== false) $columnCount++;
    if (stripos($pdfText, 'RATE IN USD') !== false) $columnCount++;
    if (stripos($pdfText, 'T/T') !== false) $columnCount++;
    if (stripos($pdfText, 'T/S') !== false) $columnCount++;
    if ($columnCount >= 4) $score += 15;

    // Standard remarks
    if (stripos($pdfText, 'Rates quotation are under prepaid term') !== false) {
        $score += 15;
    }

    // Validity format
    if (preg_match('/Validity\s*:\s*\d{1,2}\s*-\s*\d{1,2}\s+(Jan|January)\s+2026/i', $pdfText)) {
        $score += 10;
    }

    // Contact info
    if (stripos($pdfText, 'sales@bkk.pilship.com') !== false) {
        $score += 10;
    }

    return $score >= 70;
}
```

### 2.2 Trade Region Detection

```php
protected function detectPilTradeRegion($pdfText): string
{
    if (preg_match('/Trade\s*:\s*Africa/i', $pdfText)) return 'Africa';
    if (preg_match('/Trade\s*:\s*Intra Asia/i', $pdfText)) return 'Intra Asia';
    if (preg_match('/Trade\s*:\s*South America/i', $pdfText)) return 'Latin America';
    if (preg_match('/Trade\s*:\s*Oceania/i', $pdfText)) return 'Oceania';
    if (preg_match('/Trade\s*:\s*South Asia/i', $pdfText)) return 'South Asia';

    return 'Unknown';
}
```

---

## 3. Output Excel Column Mapping

### 3.1 Complete Column Structure (21 columns)

Based on `createRateEntry()` method in RateExtractionService.php:

| Col | Field | PIL Value | Notes |
|-----|-------|-----------|-------|
| A | CARRIER | "PIL" | Fixed value |
| B | POL | Variable | "BKK", "LCH", "BKK/LCH", "LKR/LCH", etc. |
| C | POD | Variable | Port name + code, e.g., "Singapore (SGSIN)" |
| D | CUR | "USD" | Fixed currency |
| E | 20' | Variable | Numeric rate (e.g., "1,050", "2,600") |
| F | 40' | Variable | Numeric rate (e.g., "2,000", "3,200") |
| G | 40 HQ | Variable | **Duplicate from column F** |
| H | 20 TC | "" | Empty (PIL doesn't provide) |
| I | 20 RF | "" | Empty (PIL doesn't provide) |
| J | 40RF | "" | Empty (PIL doesn't provide) |
| K | ETD BKK | "" | Empty (PIL doesn't provide) |
| L | ETD LCH | "" | Empty (PIL doesn't provide) |
| M | T/T | Variable | Transit time (e.g., "3", "18 days", "19-22 days") |
| N | T/S | Variable | Transshipment (e.g., "SIN", "DIRECT", "Singapore") |
| O | FREE TIME | Variable | Free time (e.g., "7 days", "6 days combine", "4 dem/ 4 det") |
| P | VALIDITY | Variable | Format: "1-14 JAN 2026" or "4-14 JAN 2026" |
| Q | REMARK | Variable | Charges, requirements, notes |
| R | Export | "" | Empty (internal use) |
| S | Who use? | "" | Empty (internal use) |
| T | Rate Adjust | "" | Empty (internal use) |
| U | 1.1 | "" | Empty (internal use) |

### 3.2 Example Output Records

**Africa - Apapa, Lagos:**
```
PIL | BKK/LCH | Apapa, Lagos (NGLOS) | USD | 2,600 | 3,200 | 3,200 | ... | 40 days | SIN | 7 days | 1-14 JAN 2026 | +HEA (LSR & ISD included), Require Form M
```

**Intra Asia - Singapore (BKK):**
```
PIL | BKK | Singapore (SGSIN) | USD | 200 | 300 | 300 | ... | 3 | DIRECT | 6 days combine | 1-14 JAN 2026 | -
```

**Intra Asia - Singapore (LCH):** *(separate record for dual POL)*
```
PIL | LCH | Singapore (SGSIN) | USD | 150 | 250 | 250 | ... | 3 | DIRECT | 6 days combine | 1-14 JAN 2026 | -
```

---

## 4. Regional Format Specifications

### 4.1 Africa

**Structure:**
- Single page with 5 sub-regions (West, East, South, Mozambique, Indian Ocean)
- Each section has regional header

**Columns:**
- PORTs | CODE | RATE IN USD (20'GP | 40'HC) | T/T (DAY) | T/S | POD F/T | Remark

**POL:** Single combined "Ex BKK/LCH"

**Key Parsing Rules:**
1. Extract section name (e.g., "West Africa", "East Africa")
2. Parse rates: "2,600+HEA ( LSR & ISD included )" → base=2600, remark="+HEA (LSR & ISD included)"
3. Extract transit time: "40 days" or "19-22 days"
4. Extract free time from POD F/T column: "7 days", "10 days"
5. Extract requirements from Remark: "Require Form M"

**Output:**
- Create 1 record per destination
- POL: "BKK/LCH"
- VALIDITY: "1-14 JAN 2026"

---

### 4.2 Intra Asia

**Structure:**
- Single page, clean layout
- Organized by country groups

**Columns:**
- PORTs | CODE | POL: BKK (20'GP|40'HC) | POL: LCH (20'GP|40'HC) | LSR | Free time | T/T (DAY) | T/S | Remark

**POL:** **Dual pricing** - separate columns for BKK and LCH

**Key Parsing Rules:**
1. Parse dual POL rates separately
2. Extract free time: "6 days combine" or "4 dem/ 4 det"
3. Extract transit time: simple numeric "3", "8", "12"
4. Extract T/S: "DIRECT", "Singapore"
5. Extract EID charges from Remark: "Include EID (USD 450 per teu)"

**Output:**
- **Create 2 records per destination** (one for BKK, one for LCH)
- POL: "BKK" or "LCH" (separate records)
- VALIDITY: "1-14 JAN 2026"

**Example:**
```
PIL | BKK | Singapore (SGSIN) | USD | 200 | 300 | 300 | ... | 3 | DIRECT | 6 days combine | 1-14 JAN 2026 | -
PIL | LCH | Singapore (SGSIN) | USD | 150 | 250 | 250 | ... | 3 | DIRECT | 6 days combine | 1-14 JAN 2026 | -
```

---

### 4.3 Latin America

**Structure:**
- Single page with 2 sections (WCSA, ECSA)

**Columns:**
- PORTs | CODE | RATE IN USD (20'GP | 40'HC) | LSR | T/T (DAY) | T/S | POD F/T | Remark

**POL:** Single combined "Ex BKK / LCH"

**Key Parsing Rules:**
1. Parse rates: "1,700 ( LSR included )" or "1,900 ( LSR included ) + AMS"
2. Extract LSR value: "78/156" or "108/216" (store in remark as "LSR: 78/156")
3. Extract transit time: "60-70 days", "35-40 days"
4. Extract T/S: "SGSIN/CNTAO", "CNSHK", "SIN"
5. Extract ISD from Remark: "Subj. ISD USD45/BOX (Cnee a/c)"

**Output:**
- Create 1 record per destination
- POL: "BKK/LCH"
- VALIDITY: "1-14 JAN 2026"
- REMARK: Include LSR numeric value if present

---

### 4.4 Oceania

**Structure:**
- Single page with 2 sections (Australia, New Zealand)

**Columns:**
- PORTs | CODE | RATE IN USD (20'GP|40'GP|40'HC) | T/T (DAY) | T/S | F/T @ Dest | Remark

**POL:** Variable by destination
- Brisbane/Sydney/Melbourne: "Ex LKR/LCH"
- Fremantle/Adelaide: "Ex BKK/LKR/LCH"

**Container Types:** **3 types** (20'GP, 40'GP, 40'HC) - but 40'GP = 40'HC for all destinations

**Key Parsing Rules:**
1. Parse 3 container rates (but 40'GP and 40'HC are identical)
2. Extract transit time: "18 days", "24 days"
3. Extract T/S: "DIRECT", "SIN"
4. Extract free time from "F/T @ Dest": "14 days"
5. Handle "n/a" rates for New Zealand

**Output:**
- Create 1 record per destination
- POL: "LKR/LCH" or "BKK/LKR/LCH" (depends on destination)
- VALIDITY: **"4-14 JAN 2026"** (different from other regions!)
- 40' and 40 HQ: Use 40'HC value (ignore 40'GP as it's identical)
- Skip destinations with "n/a" rates

---

### 4.5 South Asia

**Structure:**
- Single page with 3 trade sections (Bangladesh, India East, India West & Sri Lanka & Pakistan)

**Columns:**
- PORTs | CODE | POL: BKK (20'GP|40'HC) | POL: LCH (20'GP|40'HC) | LSR | Free time | T/T (DAY) | T/S | Remark

**POL:** **Dual pricing** - separate columns for BKK and LCH

**Key Parsing Rules:**
1. Parse dual POL rates separately
2. Parse rates with ISD: "1150+ ISD12" → base=1150, remark="+ISD USD12"
3. Extract free time: "4 days combine", "7 days combine"
4. Extract transit time: "15-20", "15-18", "9"
5. Extract weight restrictions: "Rate for G.W. not over 14.00/20' only"

**Output:**
- **Create 2 records per destination** (one for BKK, one for LCH)
- POL: "BKK" or "LCH" (separate records)
- VALIDITY: "1-14 JAN 2026"

**Example:**
```
PIL | BKK | Chennai (INMAA) | USD | 1,150 | 1,450 | 1,450 | ... | 15-18 | Singapore | 4 days combine | 1-14 JAN 2026 | LSR included, +ISD USD12
PIL | LCH | Chennai (INMAA) | USD | 1,050 | 1,300 | 1,300 | ... | 15-18 | Singapore | 4 days combine | 1-14 JAN 2026 | LSR included, +ISD USD12
```

---

## 5. Field Extraction Rules

### 5.1 POD (Port of Discharge)

**Format:** Port name + UNLOCODE in parentheses

**Examples:**
- "Singapore (SGSIN)"
- "Apapa, Lagos (NGLOS)"
- "Santos, Brazil (BRSSZ)"

**Extraction:**
```php
// From columns: PORTs and CODE
$portName = trim($portsColumn);
$portCode = trim($codeColumn);
$pod = $portName . ' (' . $portCode . ')';
```

### 5.2 Rates (20' and 40')

**Formats:**
1. Simple: "1,050"
2. With inclusions: "2,600 ( LSR & ISD included )"
3. With surcharges: "2,600+HEA ( LSR & ISD included )"
4. With inline charge: "1150+ ISD12"
5. With AMS: "1,900 ( LSR included ) + AMS"

**Extraction Logic:**
```php
protected function parsePilRate($rateString): array
{
    // Extract base numeric rate (remove commas)
    preg_match('/([\d,]+)/', $rateString, $matches);
    $baseRate = str_replace(',', '', $matches[1]);

    // Extract additional charges for REMARK
    $remarkParts = [];

    if (preg_match('/\+HEA/', $rateString)) {
        $remarkParts[] = '+HEA';
    }

    if (preg_match('/\+AMS/', $rateString)) {
        $remarkParts[] = '+AMS';
    }

    if (preg_match('/\+\s*ISD\s*(\d+)/', $rateString, $m)) {
        $remarkParts[] = '+ISD USD' . $m[1];
    }

    if (preg_match('/\((.*?included.*?)\)/i', $rateString, $m)) {
        $remarkParts[] = $m[1];
    }

    return [
        'rate' => $baseRate,
        'remark' => implode(', ', $remarkParts)
    ];
}
```

### 5.3 Transit Time (T/T)

**Formats:**
1. Simple numeric: "3", "8", "15"
2. With unit: "18 days", "24 days", "40 days"
3. Range: "19-22 days", "35-40 days", "15-20"

**Extraction:**
```php
// Store as-is, no parsing needed
$transitTime = trim($ttColumn);
```

**Examples:**
- "3" → "3"
- "18 days" → "18 days"
- "19-22 days" → "19-22 days"

### 5.4 Transshipment (T/S)

**Formats:**
1. Port code: "SIN"
2. Full name: "Singapore"
3. Direct: "DIRECT"
4. Complex routing: "SGSIN/CNTAO", "SGSIN/CNTAO/COBUN"

**Extraction:**
```php
// Store as-is
$transshipment = trim($tsColumn);
```

### 5.5 Free Time

**Formats:**
1. Simple: "7 days", "10 days", "14 days"
2. Combined: "6 days combine", "4 days combine"
3. Separated: "4 dem/ 4 det", "5 dem/ 3 det"

**Extraction:**
```php
// Store as-is from "POD F/T" or "Free time" or "F/T @ Dest" column
$freeTime = trim($freeTimeColumn);
```

### 5.6 Validity

**Format:** "DD-DD MMM YYYY"

**Extraction:**
```php
protected function extractPilValidity($pdfText): string
{
    // Match: "Validity: 1-14 Jan 2026" or "Validity : 04-14 January 2026"
    if (preg_match('/Validity\s*:\s*(\d{1,2})\s*-\s*(\d{1,2})\s+(Jan|January)\s+(\d{4})/i', $pdfText, $m)) {
        $start = $m[1];
        $end = $m[2];
        $month = strtoupper(substr($m[3], 0, 3)); // "JAN"
        $year = $m[4];

        return "$start-$end $month $year";
    }

    return '';
}
```

**Examples:**
- "1-14 JAN 2026" (Africa, Intra Asia, Latin America, South Asia)
- "4-14 JAN 2026" (Oceania - different start date!)

### 5.7 Remark

**Components to include:**
1. Rate inclusions: "(LSR & ISD included)", "(LSR included)"
2. Additional charges: "+HEA", "+AMS", "+ISD USD12"
3. ISD/EID from remark column: "Subj. ISD USD9/BOX (Cnee a/c)", "Include EID USD 450/TEU"
4. Documentation: "Require Form M", "Require CTN"
5. Weight restrictions: "Rate for G.W. not over 14.00/20' only"
6. LSR numeric (Latin America): "LSR: 78/156"
7. Service notes: "Ex Lat Krabang and Laem Chabang", "ETD 19 and 28 Jan 2026"

**Build logic:**
```php
protected function buildPilRemark($rateRemark, $columnRemark, $lsrValue = ''): string
{
    $parts = [];

    // Add rate remark (charges, inclusions)
    if (!empty($rateRemark)) {
        $parts[] = $rateRemark;
    }

    // Add LSR numeric value (Latin America only)
    if (!empty($lsrValue) && $lsrValue !== 'Include') {
        $parts[] = 'LSR: ' . $lsrValue;
    }

    // Add column remark (ISD, requirements, notes)
    if (!empty($columnRemark) && $columnRemark !== '-') {
        $parts[] = $columnRemark;
    }

    $remark = implode(', ', $parts);

    // Clean up
    $remark = trim($remark);
    if ($remark === '-' || $remark === '') {
        return '-';
    }

    return $remark;
}
```

---

## 6. Implementation Steps

### 6.1 Phase 1: PDF Detection and Parsing

**Files to modify:**
- `app/Services/RateExtractionService.php`

**Methods to add:**

```php
/**
 * Detect if PDF is PIL format
 */
protected function isPilFile($pdfText): bool
{
    // Implement scoring algorithm (see section 2.1)
}

/**
 * Detect PIL trade region
 */
protected function detectPilTradeRegion($pdfText): string
{
    // Implement region detection (see section 2.2)
}

/**
 * Extract PIL rates from PDF
 */
protected function extractPilPdf($pdfPath, $pdfText, string $validity): array
{
    if (!$this->isPilFile($pdfText)) {
        return [];
    }

    $region = $this->detectPilTradeRegion($pdfText);

    // Route to region-specific parser
    switch ($region) {
        case 'Africa':
            return $this->parsePilAfrica($pdfText, $validity);
        case 'Intra Asia':
            return $this->parsePilIntraAsia($pdfText, $validity);
        case 'Latin America':
            return $this->parsePilLatinAmerica($pdfText, $validity);
        case 'Oceania':
            return $this->parsePilOceania($pdfText, $validity);
        case 'South Asia':
            return $this->parsePilSouthAsia($pdfText, $validity);
        default:
            return [];
    }
}
```

### 6.2 Phase 2: Region-Specific Parsers

**Create 5 parser methods:**

1. `parsePilAfrica()` - Single POL, 5 sub-sections
2. `parsePilIntraAsia()` - **Dual POL** (create 2 records per destination)
3. `parsePilLatinAmerica()` - Single POL, LSR numeric
4. `parsePilOceania()` - Single POL, 3 container types, different validity
5. `parsePilSouthAsia()` - **Dual POL** (create 2 records per destination)

**Key differences:**
- **Dual POL regions** (Intra Asia, South Asia): Create 2 records per destination
- **Single POL regions** (Africa, Latin America, Oceania): Create 1 record per destination
- **Oceania validity**: "4-14 JAN 2026" (different from others)

### 6.3 Phase 3: Helper Methods

```php
/**
 * Parse PIL rate string
 */
protected function parsePilRate($rateString): array
{
    // See section 5.2
}

/**
 * Extract PIL validity
 */
protected function extractPilValidity($pdfText): string
{
    // See section 5.6
}

/**
 * Build PIL remark
 */
protected function buildPilRemark($rateRemark, $columnRemark, $lsrValue = ''): string
{
    // See section 5.7
}
```

### 6.4 Phase 4: Integration

**Update main extraction flow:**

```php
protected function extractFromPdf($filePath, $pattern, $validity): array
{
    // ... existing code ...

    // Add PIL detection
    if ($this->isPilFile($pdfText)) {
        return $this->extractPilPdf($filePath, $pdfText, $validity);
    }

    // ... existing code ...
}
```

**Update auto-detection:**

```php
protected function detectPatternFromFilename(string $filename): string
{
    // ... existing patterns ...

    if (preg_match('/PIL.*quotation/i', $filename)) return 'pil';

    // ... existing patterns ...
}
```

---

## 7. Testing Strategy

### 7.1 Test Files

All 5 PIL PDF files are in `example_docs/PIL/`:
1. PIL Africa quotation in 1H Jan 2026.pdf
2. PIL Intra Asia quotation in 1H Jan 2026.pdf
3. PIL Latin America quotation in 1H Jan 2026.pdf
4. PIL Oceania quotation in 1H Jan 2026_revised I.PDF
5. PIL South Asia quotation in 1H Jan 2026.pdf

### 7.2 Test Script Template

Create: `test_script/test_pil_extraction.php`

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\RateExtractionService;

$service = new RateExtractionService();

$testFiles = [
    'Africa' => 'example_docs/PIL/PIL Africa quotation in 1H Jan 2026.pdf',
    'Intra Asia' => 'example_docs/PIL/PIL Intra Asia quotation in 1H Jan 2026.pdf',
    'Latin America' => 'example_docs/PIL/PIL Latin America quotation in 1H Jan 2026.pdf',
    'Oceania' => 'example_docs/PIL/PIL Oceania quotation in 1H Jan 2026_revised I.PDF',
    'South Asia' => 'example_docs/PIL/PIL South Asia quotation in 1H Jan 2026.pdf',
];

foreach ($testFiles as $region => $file) {
    echo "\n=== Testing $region ===\n";

    $rates = $service->extractRates($file, 'auto', '');

    echo "Total rates: " . count($rates) . "\n";

    // Show first 3 records
    for ($i = 0; $i < min(3, count($rates)); $i++) {
        $rate = $rates[$i];
        echo sprintf(
            "%s | %s | %s | %s | %s | %s | %s | %s\n",
            $rate['CARRIER'],
            $rate['POL'],
            $rate['POD'],
            $rate["20'"],
            $rate["40'"],
            $rate['T/T'],
            $rate['T/S'],
            $rate['VALIDITY']
        );
    }
}
```

### 7.3 Expected Results

| Region | Expected Records | Notes |
|--------|------------------|-------|
| Africa | ~18 | 1 per destination |
| Intra Asia | ~44 | 22 destinations × 2 POLs (BKK/LCH) |
| Latin America | ~18 | 1 per destination |
| Oceania | ~5 | 5 Australia (NZ rates are n/a) |
| South Asia | ~26 | 13 destinations × 2 POLs (BKK/LCH) |
| **TOTAL** | ~111 | |

### 7.4 Validation Checklist

- [ ] All 5 files detected as PIL correctly
- [ ] Trade region identified correctly for each file
- [ ] Dual POL regions create 2 records per destination
- [ ] Single POL regions create 1 record per destination
- [ ] 40 HQ column duplicates 40' column
- [ ] Validity format is "DD-DD MMM YYYY"
- [ ] Oceania validity is "4-14 JAN 2026" (different from others)
- [ ] Rates parsed correctly (base numeric extracted)
- [ ] Additional charges moved to REMARK
- [ ] T/T and T/S in separate columns
- [ ] FREE TIME formats preserved
- [ ] Port codes in parentheses after port names
- [ ] Empty columns (TC, RF, ETD) remain empty

---

## 8. Common Challenges and Solutions

### 8.1 Challenge: PDF Table Extraction

**Problem:** PDF tables don't have clear structure tags

**Solution:** Use coordinate-based extraction or Azure OCR with table detection
- Identify column boundaries by text alignment
- Use header keywords to anchor columns
- Validate extracted structure against expected fields

### 8.2 Challenge: Dual POL Pricing

**Problem:** Intra Asia and South Asia have separate BKK/LCH columns

**Solution:**
```php
// Detect dual POL structure
if (stripos($pdfText, 'POL: BKK') !== false && stripos($pdfText, 'POL: LCH') !== false) {
    // Create 2 records per destination
    $rates[] = $this->createRateEntry('PIL', 'BKK', $pod, $rate20_bkk, $rate40_bkk, ...);
    $rates[] = $this->createRateEntry('PIL', 'LCH', $pod, $rate20_lch, $rate40_lch, ...);
}
```

### 8.3 Challenge: Rate Format Variations

**Problem:** Rates displayed in multiple formats

**Solution:** Use regex to extract base rate, then build remark separately
```php
$parsed = $this->parsePilRate("2,600+HEA ( LSR & ISD included )");
// $parsed['rate'] = "2600"
// $parsed['remark'] = "+HEA, LSR & ISD included"
```

### 8.4 Challenge: Multi-Section Pages

**Problem:** Africa has 5 sub-regions on one page

**Solution:**
```php
// Identify section headers
$sections = ['West Africa', 'East Africa', 'South Africa', 'Mozambique', 'Indian Ocean Islands'];

foreach ($sections as $section) {
    // Find section start position
    // Extract destinations until next section
    // Associate section name with destinations
}
```

### 8.5 Challenge: Different Validity Formats

**Problem:** Oceania starts on 4th, others start on 1st

**Solution:**
```php
// Extract validity from each file individually
$validity = $this->extractPilValidity($pdfText);

// Oceania will return "4-14 JAN 2026"
// Others will return "1-14 JAN 2026"
```

---

## 9. Implementation Pattern (Following KMTC)

### 9.1 Reference KMTC Implementation

**File:** `KMTC_IMPLEMENTATION_NOTES.md`

**Key similarities:**
1. Logo/text detection for file identification
2. Region-specific parsing methods
3. Rate parsing with additional charges
4. Helper methods for common operations
5. Test scripts for validation

**Key differences:**
1. PIL: 5 regional formats vs KMTC: 1 format
2. PIL: PDF extraction vs KMTC: Excel extraction
3. PIL: Dual POL for some regions vs KMTC: single POL
4. PIL: More complex rate formats

### 9.2 Code Organization

```
app/Services/RateExtractionService.php
├── PIL Detection
│   ├── isPilFile()
│   └── detectPilTradeRegion()
├── PIL Extraction
│   ├── extractPilPdf()
│   └── Main routing method
├── Regional Parsers
│   ├── parsePilAfrica()
│   ├── parsePilIntraAsia()
│   ├── parsePilLatinAmerica()
│   ├── parsePilOceania()
│   └── parsePilSouthAsia()
└── Helper Methods
    ├── parsePilRate()
    ├── extractPilValidity()
    └── buildPilRemark()
```

---

## 10. Next Steps After Implementation

### 10.1 Documentation

1. Create `PIL_IMPLEMENTATION_NOTES.md` (similar to KMTC)
2. Document parser logic for each region
3. Add examples and test results
4. Update main README with PIL support

### 10.2 Testing

1. Run test script on all 5 files
2. Verify record counts match expected
3. Check dual POL records created correctly
4. Validate output Excel format
5. Test with web interface

### 10.3 Deployment

1. Commit changes with clear message
2. Update pattern list in web interface
3. Add PIL to auto-detection flow
4. Monitor first production extractions

---

## 11. Quick Reference

### 11.1 Key Differences by Region

| Region | POL Type | Records/Dest | Container Types | Validity | Special |
|--------|----------|--------------|-----------------|----------|---------|
| Africa | Single | 1 | 2 (20'/40'HC) | 1-14 JAN 2026 | HEA charges, 5 sections |
| Intra Asia | **Dual** | **2** | 2 (20'/40'HC) | 1-14 JAN 2026 | EID charges |
| Latin America | Single | 1 | 2 (20'/40'HC) | 1-14 JAN 2026 | LSR numeric, AMS |
| Oceania | Single | 1 | 3 (20'/40'/40HC) | **4-14 JAN 2026** | 3 types, NZ n/a |
| South Asia | **Dual** | **2** | 2 (20'/40'HC) | 1-14 JAN 2026 | ISD inline, HEA West |

### 11.2 Column Filling Summary

| Always Fill | Sometimes Fill | Always Empty |
|-------------|----------------|--------------|
| CARRIER: "PIL" | POL: varies by region | 20 TC, 20 RF, 40RF |
| POD: "Port (CODE)" | T/T: varies | ETD BKK, ETD LCH |
| CUR: "USD" | T/S: varies | Export, Who use? |
| 20': numeric | FREE TIME: varies | Rate Adjust, 1.1 |
| 40': numeric | VALIDITY: varies | |
| 40 HQ: = 40' | REMARK: varies | |

---

**Last Updated:** 2026-01-13
**For Implementation By:** Next Claude session
**Related Files:** PIL_ANALYSIS.md, KMTC_IMPLEMENTATION_NOTES.md
**Test Files:** example_docs/PIL/ (5 PDF files)
