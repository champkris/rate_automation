<?php

$docsDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/';
$outputDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/inline_images/';

// Create output directory
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$emlFiles = glob($docsDir . '*.eml');

echo "Extracting inline/embedded images from emails...\n";
echo str_repeat('=', 100) . "\n\n";

$totalImages = 0;

foreach ($emlFiles as $emlFile) {
    $emailName = basename($emlFile);
    echo "Processing: $emailName\n";

    $content = file_get_contents($emlFile);

    // Pattern to match embedded images with Content-ID
    // These are typically in the format:
    // Content-Type: image/jpeg
    // Content-Transfer-Encoding: base64
    // Content-ID: <image002.jpg@...>
    preg_match_all('/Content-Type: image\/([^\s]+).*?Content-Transfer-Encoding: base64.*?Content-ID: <([^>]+)>\s+(.*?)(?=------=_NextPart_|Content-Type: application|Content-Type: image|$)/s', $content, $matches, PREG_SET_ORDER);

    if (empty($matches)) {
        // Try alternative pattern without Content-ID being last
        preg_match_all('/Content-ID: <([^>]+)>.*?Content-Type: image\/([^\s]+).*?Content-Transfer-Encoding: base64.*?\s+(.*?)(?=------=_NextPart_|Content-Type:|$)/s', $content, $matches, PREG_SET_ORDER);
    }

    echo "  Found " . count($matches) . " embedded images\n";

    foreach ($matches as $index => $match) {
        $imageType = $match[1] ?? 'jpg';
        $contentId = $match[2] ?? $match[1];
        $base64Content = $match[3] ?? $match[2];

        // Extract just the filename from Content-ID
        $filename = preg_replace('/@.*$/', '', $contentId);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        if (!preg_match('/\.(jpg|jpeg|png|gif)$/i', $filename)) {
            $filename .= '.' . strtolower($imageType);
        }

        // Add email prefix to avoid overwriting
        $emailPrefix = str_replace([' ', '.eml'], ['_', ''], $emailName);
        $outputFilename = $emailPrefix . '_' . $filename;

        // Clean up base64 content
        $base64Content = preg_replace('/\s+/', '', $base64Content);

        $decodedContent = base64_decode($base64Content);

        if ($decodedContent !== false && strlen($decodedContent) > 100) {
            $outputPath = $outputDir . $outputFilename;
            file_put_contents($outputPath, $decodedContent);
            echo "    ✓ Extracted: $outputFilename (" . number_format(strlen($decodedContent)) . " bytes)\n";
            $totalImages++;
        } else {
            echo "    ✗ Failed: $filename (decode failed or too small)\n";
        }
    }

    echo "\n";
}

echo str_repeat('=', 100) . "\n";
echo "Total embedded images extracted: $totalImages\n";
echo "Location: $outputDir\n";
echo str_repeat('=', 100) . "\n";
