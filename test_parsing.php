<?php
require_once __DIR__ . '/deck_parser.php';
$parsed = parseDecksShared();
echo "Black cards count: " . count($parsed['black']) . "\n";
echo "White cards count: " . count($parsed['white']) . "\n";
echo "Tags found: " . implode(', ', $parsed['tags']) . "\n";

// Show some sample black cards
echo "\nSample Black Cards:\n";
for ($i = 0; $i < 5; $i++) {
    if (isset($parsed['black'][$i])) {
        print_r($parsed['black'][$i]);
    }
}
