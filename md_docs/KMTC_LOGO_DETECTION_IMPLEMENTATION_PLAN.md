# KMTC Logo Detection - Implementation Plan

**Date:** December 22, 2025
**Objective:** Implement content-based KMTC detection using logo aspect ratio instead of filename patterns

---

## 📋 Executive Summary

### Problem
Current KMTC detection relies on filename pattern `/UPDATED.?RATE/i` which fails when:
- Filenames have typos (e.g., "UPDATEDI RATE" instead of "UPDATED RATE")
- Users rename files with different naming conventions
- Files are created by KMTC with non-standard names

### Solution
Detect KMTC files by analyzing the embedded logo image characteristics:
1. **Position**: Logo in header area (columns D-G, rows 1-3)
2. **Aspect Ratio**: Width/Height ≈ 3.16 (±0.4 tolerance)

### Benefits
- ✅ Works with ANY filename (user-proof)
- ✅ Handles logo size variations (±30%)
- ✅ Can detect KMTC even with multiple images in file
- ✅ No maintenance needed for filename pattern variations
- ✅ 100% tested accuracy on existing files

---

## 🔍 Detection Logic Details

### KMTC Logo Signature
```
Position: D1 (may vary to D2, E1, E2, F1, F2, G1, G2, G3)
Size: 218 x 69 pixels (actual)
Aspect Ratio: 218 / 69 = 3.16
Tolerance: ±0.4 (accepts ratios from 2.76 to 3.56)
```

### Why This Works
- **KMTC logo**: Position D1, Aspect ratio 3.16, Size 218x69px
- **RCL logo**: Position D3, Aspect ratio 2.52, Size 106x42px
- **Different position AND different aspect ratio** = perfect differentiation

### Test Results
| File | Filename Has Typo? | Old Method | New Method |
|------|-------------------|------------|------------|
| `UPDATED RATE IN NOV25.xlsx` | No | ✓ Detected | ✓ Detected |
| `UPDATEDI RATE IN JAN26.xlsx` | **Yes** | ✗ Failed | ✓ **Detected** |
| `UPDATED RATE IN DEC25.xlsx` | No | ✓ Detected | ✓ Detected |
| `FAK Rate of 1-15 DEC 25.xlsx` (RCL) | N/A | ✓ Correctly rejected | ✓ Correctly rejected |

---

## 📁 Files to Modify

### 1. Main Implementation File
**File:** `app/Services/RateExtractionService.php`

**Lines to modify:** 67-93 (detectPatternFromFilename method)

**Changes needed:**
1. Add new method: `detectPatternByLogo($filePath)`
2. Update `extractFromFile()` to call logo detection BEFORE filename detection
3. Keep filename detection as fallback

---

## 💻 Implementation Code

### Step 1: Add Logo Detection Method

**Location:** `app/Services/RateExtractionService.php` (add after line 93)

```php
/**
 * Detect KMTC pattern by analyzing embedded logo image
 *
 * @param string $filePath Full path to Excel file
 * @return string|null 'kmtc' if detected, null otherwise
 */
protected function detectPatternByLogo(string $filePath): ?string
{
    try {
        // Only process Excel files
        if (!preg_match('/\.(xlsx|xls)$/i', $filePath)) {
            return null;
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $drawings = $sheet->getDrawingCollection();

        if (count($drawings) === 0) {
            return null; // No images = not KMTC
        }

        // KMTC logo characteristics
        $kmtcAspectRatio = 218 / 69;  // ≈ 3.16 (width / height)
        $tolerance = 0.4;              // Allow ±0.4 difference

        // Check each image in the file
        foreach ($drawings as $image) {
            $position = $image->getCoordinates();
            $width = $image->getWidth();
            $height = $image->getHeight();

            // Condition 1: Position in header area (columns D-G, rows 1-3)
            $isHeaderPosition = preg_match('/^[DEFG][1-3]$/', $position);
            if (!$isHeaderPosition) {
                continue; // Skip images not in header
            }

            // Condition 2: Aspect ratio matches KMTC logo
            $aspectRatio = $width / $height;
            $ratioDifference = abs($aspectRatio - $kmtcAspectRatio);
            $ratioMatches = $ratioDifference <= $tolerance;

            // If both conditions met, this is KMTC
            if ($ratioMatches) {
                return 'kmtc';
            }
        }

        return null; // No matching logo found

    } catch (\Exception $e) {
        // If error reading file, return null (will fall back to filename detection)
        \Log::debug("Logo detection failed for {$filePath}: " . $e->getMessage());
        return null;
    }
}
```

### Step 2: Update extractFromFile Method

**Location:** `app/Services/RateExtractionService.php` (line ~40-62)

**Find this code:**
```php
// Detect pattern from filename if not provided
if ($pattern === null) {
    $pattern = $this->detectPatternFromFilename($filename);
}
```

**Replace with:**
```php
// Detect pattern from file content (logo) first, then filename
if ($pattern === null) {
    // Priority 1: Logo detection (content-based, most reliable)
    $pattern = $this->detectPatternByLogo($filePath);

    // Priority 2: Filename pattern (fallback for non-Excel or files without logo)
    if ($pattern === null) {
        $pattern = $this->detectPatternFromFilename($filename);
    }
}
```

---

## 🧪 Testing Plan

### Test Cases

1. **KMTC with correct filename**
   - File: `example_docs/UPDATED RATE IN NOV25.xlsx`
   - Expected: Detected as KMTC via logo
   - Verify: Pattern = 'kmtc'

2. **KMTC with typo filename** (Critical test)
   - File: `example_docs/(ฉบับแก้ไข remark )Copy of UPDATEDI RATE IN JAN26.xlsx`
   - Expected: Detected as KMTC via logo (filename detection would fail)
   - Verify: Pattern = 'kmtc'

3. **KMTC with standard filename**
   - File: `docs/attachments/UPDATED RATE IN DEC25.xlsx`
   - Expected: Detected as KMTC via logo
   - Verify: Pattern = 'kmtc'

4. **RCL file** (Negative test)
   - File: `docs/attachments/FAK Rate of 1-15 DEC 25.xlsx`
   - Expected: NOT detected as KMTC (different aspect ratio + position)
   - Verify: Pattern = 'rcl' (via filename)

5. **PDF file** (Fallback test)
   - Any PDF file
   - Expected: Logo detection skipped, falls back to filename detection
   - Verify: No errors, filename detection works

### Test Script

Create `tests/Feature/KmtcLogoDetectionTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\RateExtractionService;

class KmtcLogoDetectionTest extends TestCase
{
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RateExtractionService();
    }

    /** @test */
    public function it_detects_kmtc_with_correct_filename()
    {
        $file = base_path('example_docs/UPDATED RATE IN NOV25.xlsx');
        $pattern = $this->service->detectPattern($file);

        $this->assertEquals('kmtc', $pattern);
    }

    /** @test */
    public function it_detects_kmtc_with_typo_filename()
    {
        $file = base_path('example_docs/(ฉบับแก้ไข remark )Copy of UPDATEDI RATE IN JAN26.xlsx');
        $pattern = $this->service->detectPattern($file);

        $this->assertEquals('kmtc', $pattern, 'Should detect KMTC via logo despite filename typo');
    }

    /** @test */
    public function it_does_not_detect_rcl_as_kmtc()
    {
        $file = base_path('docs/attachments/FAK Rate of 1-15 DEC 25.xlsx');
        $pattern = $this->service->detectPattern($file);

        $this->assertNotEquals('kmtc', $pattern, 'RCL file should not be detected as KMTC');
    }
}
```

Run tests:
```bash
php artisan test --filter KmtcLogoDetectionTest
```

---

## 🚀 Deployment Steps

### 1. Backup
```bash
# Backup the file before modification
cp app/Services/RateExtractionService.php app/Services/RateExtractionService.php.backup
```

### 2. Implementation
1. Open `app/Services/RateExtractionService.php`
2. Add `detectPatternByLogo()` method after line 93
3. Update `extractFromFile()` method to use logo detection first
4. Save file

### 3. Verify Syntax
```bash
php -l app/Services/RateExtractionService.php
```

### 4. Run Tests
```bash
# Run the specific test
php artisan test --filter KmtcLogoDetectionTest

# Run all extraction tests
php artisan test tests/Feature/
```

### 5. Manual Testing
```bash
# Test with actual files
php artisan tinker

>>> $service = new App\Services\RateExtractionService();
>>> $service->extractFromFile('../example_docs/UPDATEDI RATE IN JAN26.xlsx');
// Should return KMTC pattern and extracted data
```

### 6. Monitor Logs
```bash
# Check for any logo detection errors
tail -f storage/logs/laravel.log
```

---

## 📊 Performance Considerations

### Speed Impact
- **Logo detection**: ~50-100ms per file (PhpSpreadsheet loading)
- **Filename detection**: <1ms per file (regex only)
- **Overall**: Minimal impact since logo detection only runs on Excel files

### Optimization Strategies
1. **Cache logo detection results** by file hash (optional, for future)
2. **Skip logo detection for PDFs** (already implemented - checks file extension)
3. **Early return** on first matching logo (already implemented)

### Memory Usage
- PhpSpreadsheet loads full Excel file: ~5-20MB per file
- Acceptable for background processing
- For bulk processing, consider processing in batches

---

## 🔧 Maintenance & Future Enhancements

### Extending to Other Carriers

The same approach can be applied to other carriers:

```php
// Example: RCL detection
protected function detectRclByLogo($filePath): ?string
{
    // RCL logo: Position D3, Aspect ratio ~2.52, Size 106x42px
    $rclAspectRatio = 106 / 42; // ≈ 2.52

    foreach ($drawings as $image) {
        $position = $image->getCoordinates();
        if ($position === 'D3') {
            $aspectRatio = $image->getWidth() / $image->getHeight();
            if (abs($aspectRatio - $rclAspectRatio) <= 0.3) {
                return 'rcl';
            }
        }
    }
    return null;
}
```

### Configuration File (Future)

Create `config/carrier_detection.php`:

```php
return [
    'kmtc' => [
        'logo_aspect_ratio' => 3.16,
        'tolerance' => 0.4,
        'header_positions' => ['D1', 'D2', 'E1', 'E2', 'F1', 'F2', 'G1', 'G2', 'G3'],
    ],
    'rcl' => [
        'logo_aspect_ratio' => 2.52,
        'tolerance' => 0.3,
        'header_positions' => ['D3'],
    ],
    // Add more carriers as needed
];
```

---

## 📚 Reference Files

### Test Scripts Created
1. `test_kmtc_image_detection.php` - Basic logo detection test
2. `compare_carriers.php` - Compare KMTC vs RCL logos
3. `test_aspect_ratio_detection.php` - Full aspect ratio testing
4. `proposed_kmtc_detection.php` - Prototype implementation
5. `final_kmtc_detection.php` - Production-ready standalone code

### Documentation
- This implementation plan: `md_docs/KMTC_LOGO_DETECTION_IMPLEMENTATION_PLAN.md`

---

## ✅ Implementation Checklist

### Pre-Implementation
- [ ] Read this implementation plan
- [ ] Review test scripts in rate_automation folder
- [ ] Backup `RateExtractionService.php`

### Implementation
- [ ] Add `detectPatternByLogo()` method to `RateExtractionService.php`
- [ ] Update `extractFromFile()` to call logo detection first
- [ ] Verify syntax with `php -l`

### Testing
- [ ] Create `KmtcLogoDetectionTest.php`
- [ ] Run automated tests
- [ ] Test with KMTC file (correct filename)
- [ ] Test with KMTC file (typo filename) - **Critical**
- [ ] Test with RCL file (negative test)
- [ ] Test with PDF file (fallback test)

### Deployment
- [ ] Review code changes
- [ ] Commit changes to git
- [ ] Deploy to staging environment
- [ ] Monitor logs for errors
- [ ] Deploy to production

### Post-Deployment
- [ ] Process existing KMTC files to verify
- [ ] Monitor error logs for 24 hours
- [ ] Update documentation if needed

---

## 🎯 Success Criteria

1. ✅ KMTC files with typo filenames are correctly detected
2. ✅ All existing KMTC files still detected correctly
3. ✅ RCL and other carriers NOT falsely detected as KMTC
4. ✅ No performance degradation (<100ms per file)
5. ✅ No errors in production logs
6. ✅ All automated tests pass

---

## 🆘 Troubleshooting

### Issue: Logo detection not working

**Check:**
1. PhpSpreadsheet is installed: `composer show phpoffice/phpspreadsheet`
2. File permissions: Can PHP read the Excel file?
3. File format: Is it .xlsx or .xls?
4. Log output: Check `storage/logs/laravel.log` for errors

**Solution:**
```php
// Add debug logging in detectPatternByLogo()
\Log::debug("Logo detection for: {$filePath}");
\Log::debug("Found " . count($drawings) . " images");
foreach ($drawings as $image) {
    \Log::debug("Image at {$image->getCoordinates()}: {$image->getWidth()}x{$image->getHeight()}");
}
```

### Issue: False positives (other carriers detected as KMTC)

**Check:**
1. Aspect ratio tolerance too loose?
2. Header position range too wide?

**Solution:**
- Reduce tolerance from 0.4 to 0.3
- Narrow header positions to only D1, D2, E1

### Issue: Performance slow

**Check:**
1. How many Excel files processed per second?
2. File sizes?

**Solution:**
- Add early return after first match
- Cache detection results by file hash
- Process files in background queue

---

## 📝 Notes for Next Claude Session

### Context
- User wants to detect KMTC files by logo instead of filename
- Current filename pattern `/UPDATED.?RATE/i` fails with typos
- Logo-based detection tested and working 100%
- Implementation plan created, ready to code

### Key Decisions Made
1. **Detection method**: Aspect ratio (3.16 ± 0.4) + header position
2. **Priority order**: Logo detection → Filename detection (fallback)
3. **No breaking changes**: Existing filename detection still works
4. **No "exactly 1 image" requirement**: Can handle multiple images

### Files to Modify
- `app/Services/RateExtractionService.php` (main implementation)
- `tests/Feature/KmtcLogoDetectionTest.php` (new test file)

### Test Files Available
- All test scripts are in `rate_automation/` folder
- Example files in `example_docs/` and `docs/attachments/`
- `final_kmtc_detection.php` contains production-ready standalone code

### Next Steps
1. Implement `detectPatternByLogo()` method
2. Update `extractFromFile()` to use logo detection
3. Create and run tests
4. Deploy and monitor

---

**Implementation Status:** 📋 **PLANNING COMPLETE - READY FOR IMPLEMENTATION**

**Estimated Implementation Time:** 30-45 minutes
**Estimated Testing Time:** 15-20 minutes
**Total Time:** ~1 hour

---

*End of Implementation Plan*
