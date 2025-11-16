<?php

$docsDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/';
$outputDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/';

// Get all .eml files from docs directory
$emlFiles = glob($docsDir . '*.eml');

echo "Found " . count($emlFiles) . " email files\n\n";

$totalAttachments = 0;

foreach ($emlFiles as $emlFile) {
    $emailName = basename($emlFile);
    echo str_repeat('=', 80) . "\n";
    echo "Processing: $emailName\n";
    echo str_repeat('=', 80) . "\n";

    // Read the entire email file
    $content = file_get_contents($emlFile);

    // Find all attachment boundaries
    preg_match_all('/Content-Type: application\/[^\s]+;\s+name="([^"]+)".*?Content-Transfer-Encoding: base64.*?Content-Disposition: attachment;\s+filename="([^"]+)"\s+(.*?)(?=------=_NextPart_|$)/s', $content, $matches, PREG_SET_ORDER);

    echo "Found " . count($matches) . " attachments\n\n";
    $totalAttachments += count($matches);

    foreach ($matches as $index => $match) {
        $filename = $match[2]; // Use the filename from Content-Disposition
        $base64Content = $match[3];

        // Clean up the base64 content (remove newlines and whitespace)
        $base64Content = preg_replace('/\s+/', '', $base64Content);

        // Decode the base64 content
        $decodedContent = base64_decode($base64Content);

        if ($decodedContent !== false) {
            $outputPath = $outputDir . $filename;

            // Check if file already exists
            if (file_exists($outputPath)) {
                // Add a suffix to avoid overwriting
                $pathInfo = pathinfo($filename);
                $newFilename = $pathInfo['filename'] . '_' . substr(md5($emailName), 0, 6) . '.' . $pathInfo['extension'];
                $outputPath = $outputDir . $newFilename;
                echo "  ✓ Extracted: $filename -> $newFilename (" . number_format(strlen($decodedContent)) . " bytes)\n";
            } else {
                echo "  ✓ Extracted: $filename (" . number_format(strlen($decodedContent)) . " bytes)\n";
            }

            file_put_contents($outputPath, $decodedContent);
        } else {
            echo "  ✗ Failed to decode: $filename\n";
        }
    }

    echo "\n";
}

echo str_repeat('=', 80) . "\n";
echo "Total attachments extracted: $totalAttachments\n";
echo str_repeat('=', 80) . "\n";
