<?php

$emlFile = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/docs/RATE AUTOMATION EASTERN __ FCL EXPORT.eml';
$outputDir = '/Users/apichakriskalambasuta/Sites/localhost/rate_automation/temp_attachments/';

// Read the entire email file
$content = file_get_contents($emlFile);

// Find all attachment boundaries
preg_match_all('/Content-Type: application\/[^\s]+;\s+name="([^"]+)".*?Content-Transfer-Encoding: base64.*?Content-Disposition: attachment;\s+filename="([^"]+)"\s+(.*?)(?=------=_NextPart_|$)/s', $content, $matches, PREG_SET_ORDER);

echo "Found " . count($matches) . " attachments\n\n";

foreach ($matches as $index => $match) {
    $filename = $match[2]; // Use the filename from Content-Disposition
    $base64Content = $match[3];

    // Clean up the base64 content (remove newlines and whitespace)
    $base64Content = preg_replace('/\s+/', '', $base64Content);

    // Decode the base64 content
    $decodedContent = base64_decode($base64Content);

    if ($decodedContent !== false) {
        $outputPath = $outputDir . $filename;
        file_put_contents($outputPath, $decodedContent);
        echo "Extracted: $filename (" . strlen($decodedContent) . " bytes)\n";
    } else {
        echo "Failed to decode: $filename\n";
    }
}

echo "\nExtraction complete!\n";
