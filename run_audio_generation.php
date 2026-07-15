<?php
/**
 * Batch Audio Generation Runner (Version 1.0)
 * Generates pre-recorded audio for Against game cards
 * Usage: php run_audio_generation.php [--batch=50] [--start=0] [--stop=0] [--test]
 *
 * Options:
 *   --batch=50   : Process 50 cards at a time (default: 50)
 *   --start=0    : Start from card index (default: 0)
 *   --stop=0     : Stop at card index; 0 = process all (default: 0)
 *   --test       : Test mode (no actual API calls, just echo what would happen)
 *
 * Examples:
 *   php run_audio_generation.php --test                    (Test with 5+10 cards)
 *   php run_audio_generation.php --batch=20 --stop=20      (Generate first 20 cards only)
 *   php run_audio_generation.php --batch=50 --start=100    (Resume from card 100)
 *   php run_audio_generation.php                           (Full deck, test mode by default since no credentials)
 */

// Parse command-line arguments
$args = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $args[$parts[0]] = $parts[1] ?? true;
    }
}

$batchSize = intval($args['batch'] ?? 50);
$startIndex = intval($args['start'] ?? 0);
$stopIndex = intval($args['stop'] ?? 0);
$testMode = isset($args['test']);
$dailyLimit = isset($args['limit']) ? intval($args['limit']) : 1000;

// Load the generator
require_once __DIR__ . '/generate_audio.php';

// Initialize audio generator
// If using test mode or no credentials file specified, it will run in test mode
$credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: '';

// Fallback: Check global_config.json for google_tts_api_key if no file path provided
if (!$credentialsPath || !file_exists($credentialsPath)) {
    $configFile = __DIR__ . '/data/global_config.json';
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true);
        if ($config && !empty($config['google_tts_api_key'])) {
            $credentialsPath = $config['google_tts_api_key']; // Pass key string directly
            echo "ℹ️  Using Google Cloud API Key from global_config.json\n";
        }
    }
}

if (empty($credentialsPath)) {
    echo "⚠️  No Google Cloud credentials or API Key found. Running in TEST MODE.\n";
    echo "   Set GOOGLE_APPLICATION_CREDENTIALS env var or update global_config.json to enable audio generation.\n\n";
    $testMode = true;
}

echo "=== Against Game Audio Generation ===\n";
echo "Batch Size: $batchSize cards/batch\n";
echo "Start Index: $startIndex\n";
echo "Stop Index: " . ($stopIndex > 0 ? $stopIndex : 'ALL') . "\n";
echo "Mode: " . ($testMode ? "TEST (no API calls)" : "PRODUCTION (actual TTS)") . "\n";
echo "Voices: male, female, british\n";
echo "=================================\n\n";

// Create generator with batch parameters
$generator = new AudioGenerator(
    $testMode ? '' : $credentialsPath,
    __DIR__ . '/audio',
    [
        'male' => 'Fenrir',
        'female' => 'Aoede',
        'british' => 'Puck'
    ],
    $batchSize,
    $startIndex,
    $stopIndex,
    'gemini', // Set provider to gemini
    '',       // ElevenLabs voice ID
    $dailyLimit
);

// Parse decks
$decks = $generator->parseDecks(__DIR__ . '/decks.md');
if (!$decks) {
    echo "❌ Failed to parse decks. Exiting.\n";
    exit(1);
}

echo "📋 Decks loaded:\n";
echo "   • Black cards: " . count($decks['black'] ?? []) . "\n";
echo "   • White cards: " . count($decks['white'] ?? []) . "\n\n";

// Generate audio
if ($stopIndex > 0) {
    echo "🎵 Generating audio for cards $startIndex to $stopIndex...\n\n";
} else {
    echo "🎵 Generating audio for all cards...\n\n";
}

$processedBlack = 0;
$blackCards = $decks['black'] ?? [];
if ($stopIndex > 0) {
    $blackCards = array_slice($blackCards, $startIndex, min($stopIndex - $startIndex, count($blackCards)));
}

echo "=== BLACK CARDS ===\n";
foreach ($blackCards as $idx => $card) {
    $generator->generateSegmentsForBlackCard($card);
    $processedBlack++;
    if ($processedBlack % $batchSize === 0) {
        echo "✓ Processed $processedBlack black cards. Clearing memory...\n";
        gc_collect_cycles();
    }
}
echo "✅ Black cards complete: $processedBlack\n\n";

$processedWhite = 0;
$whiteCards = $decks['white'] ?? [];
if ($stopIndex > 0) {
    $whiteCards = array_slice($whiteCards, $startIndex, min($stopIndex - $startIndex, count($whiteCards)));
}

echo "=== WHITE CARDS ===\n";
foreach ($whiteCards as $idx => $card) {
    $generator->generateAudioForWhiteCard($card);
    $processedWhite++;
    if ($processedWhite % $batchSize === 0) {
        echo "✓ Processed $processedWhite white cards. Clearing memory...\n";
        gc_collect_cycles();
    }
}
echo "✅ White cards complete: $processedWhite\n\n";

// Summary
$generator->close();
$generator->printCharacterCountSummary();

echo "\n";
if ($testMode) {
    echo "✅ Generation complete (TEST MODE - no files created)\n";
} else {
    echo "✅ Generation complete! Check /" . __DIR__ . "/audio/ for generated MP3 files.\n";
}

if ($stopIndex > 0 && ($startIndex + ($stopIndex - $startIndex)) < max(count($decks['black'] ?? []), count($decks['white'] ?? []))) {
    $nextStart = $startIndex + ($stopIndex - $startIndex);
    echo "\n💡 To continue with the next batch:\n";
    echo "   php run_audio_generation.php --batch=$batchSize --start=$nextStart --stop=" . ($nextStart + ($stopIndex - $startIndex)) . "\n";
}

echo "\nDone!\n";
?>
