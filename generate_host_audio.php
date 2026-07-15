<?php
/**
 * Host Messages Audio Generator (Version 2.0)
 * Generate pre-recorded audio for host announcements
 * Version 2.0 changes:
 *   - Expanded to 80+ messages across 5 tiers
 *   - Added game-start, delay, close-game, player-join/swap, tie announcements
 *   - Integrated with api.php caching for intelligent TTS cost reduction
 *   - Players identified numerically: Player One, Two, Three
 *
 * Usage: php generate_host_audio.php [--test] [--voice=male,female] [--tier=1,2,3,4,5]
 *
 * Examples:
 *   php generate_host_audio.php --test                    (Test mode, Tier 1 only)
 *   php generate_host_audio.php --voice=female --tier=1   (Generate Tier 1 female voice only)
 *   php generate_host_audio.php --tier=1,2                (Generate Tiers 1-2 for all voices)
 *   php generate_host_audio.php                           (Full host messages, all voices)
 */

// Parse command-line arguments
$args = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $args[$parts[0]] = $parts[1] ?? true;
    }
}

$testMode = isset($args['test']);
$requestedVoices = isset($args['voice']) ? explode(',', $args['voice']) : ['male', 'female', 'british'];
$requestedTiers = isset($args['tier']) ? explode(',', $args['tier']) : ['1', '2', '3', '4', '5', '6', '7', '8'];

// Map tiers to keys
$tierMap = [
    '1' => 'tier_1_critical',
    '2' => 'tier_2_common',
    '3' => 'tier_3_conditional',
    '4' => 'tier_4_dynamic',
    '5' => 'tier_5_rare',
    '6' => 'tier_6_vocal_library',
    '7' => 'tier_7_static_pieces',
    '8' => 'tier_8_bot_names'
];

// Load host messages
$hostFile = __DIR__ . '/data/host_messages.json';
if (!file_exists($hostFile)) {
    echo "❌ host_messages.json not found at $hostFile\n";
    exit(1);
}

$hostMessages = json_decode(file_get_contents($hostFile), true);
if (!$hostMessages) {
    echo "❌ Failed to parse host_messages.json\n";
    exit(1);
}

// Load the audio generator
require_once __DIR__ . '/generate_audio.php';

// Load global config for API key and provider
$configFile = __DIR__ . '/data/global_config.json';
$globalConfig = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
$ttsProvider = $globalConfig['tts_provider'] ?? 'google';
$apiKey = '';
$elevenLabsVoiceId = '';

if ($ttsProvider === 'elevenlabs') {
    $apiKey = $globalConfig['elevenlabs_api_key'] ?? '';
    $elevenLabsVoiceId = $globalConfig['elevenlabs_voice_id'] ?? '21m00Tcm4TlvDq8ikWAM';
    if (empty($apiKey)) {
        echo "⚠️  No ElevenLabs API key found in global_config.json. Running in TEST MODE.\n";
        echo "   Add 'elevenlabs_api_key' to your global_config.json to enable audio generation.\n\n";
        $testMode = true;
    }
} else {
    $apiKey = $globalConfig['google_tts_api_key'] ?? '';
    if (empty($apiKey)) {
        echo "⚠️  No Google TTS API key found in global_config.json. Running in TEST MODE.\n";
        echo "   Add 'google_tts_api_key' to your global_config.json to enable audio generation.\n\n";
        $testMode = true;
    }
}

$voiceMap = [
    'male' => 'Fenrir',
    'female' => 'Aoede',
    'british' => 'Puck'
];

$elevenLabsVoiceMap = [
    'male' => '21m00Tcm4TlvDq8ikWAM',
    'female' => '21m00Tcm4TlvDq8ikWAM',
    'british' => '21m00Tcm4TlvDq8ikWAM'
];

// Determine provider (default to gemini if Google API key is set)
$ttsProvider = 'gemini'; 

// Filter voices and set up voice codes based on provider
$voices = [];
foreach ($voiceMap as $key => $value) {
    if (in_array($key, $requestedVoices)) {
        if ($ttsProvider === 'elevenlabs') {
            $voices[$key] = $elevenLabsVoiceMap[$key] ?? $elevenLabsVoiceId;
        } else {
            $voices[$key] = $value;
        }
    }
}

if (empty($voices)) {
    echo "❌ No valid voices selected. Available: male, female, british\n";
    exit(1);
}

echo "╔════════════════════════════════════════╗\n";
echo "║  HOST MESSAGES AUDIO GENERATOR v2.0    ║\n";
echo "╚════════════════════════════════════════╝\n\n";

echo "Configuration:\n";
echo "  Provider: " . strtoupper($ttsProvider) . "\n";
echo "  Voices: " . implode(', ', array_keys($voices)) . "\n";
echo "  Tiers: " . implode(', ', $requestedTiers) . "\n";
echo "  Mode: " . ($testMode ? "TEST (no API calls)" : "PRODUCTION") . "\n";
echo "  Output: " . __DIR__ . "/audio/host_messages/\n\n";

// Initialize generator
$generator = new AudioGenerator(
    $testMode ? '' : $apiKey,
    __DIR__ . '/audio',
    $voices,
    50, // batchSize
    0,  // startIndex
    0,  // stopIndex
    $ttsProvider,
    $elevenLabsVoiceId
);

// Track messages
$messageCount = 0;
$totalMessages = [];
$messageCounts = [];

// Collect all requested messages
foreach ($requestedTiers as $tier) {
    $tierKey = $tierMap[$tier] ?? null;
    if (!$tierKey || !isset($hostMessages[$tierKey])) {
        // Echo warning only if tier was expressly requested
        if (in_array($tier, $requestedTiers)) {
            echo "⚠️  Tier $tier not found.\n";
        }
        continue;
    }

    $messages = $hostMessages[$tierKey]['messages'] ?? [];
    $totalMessages = array_merge($totalMessages, $messages);
    $messageCounts[$tier] = count($messages);
}

echo "📋 Messages to generate:\n";
foreach ($messageCounts as $tier => $count) {
    echo "   Tier $tier: $count messages\n";
}
$totalCount = array_sum($messageCounts);
echo "   Total: $totalCount messages\n";
echo "   Files: " . ($totalCount * count($voices)) . " (messages × voices)\n\n";

if (empty($totalMessages)) {
    echo "❌ No messages found to generate.\n";
    exit(1);
}

// Create host_messages directory in audio folder
$hostAudioDir = __DIR__ . '/audio/host_messages';
if (!is_dir($hostAudioDir)) {
    mkdir($hostAudioDir, 0777, true);
}

// Global Game Title for replacement
$gameTitle = $globalConfig['game_title'] ?? 'Everyone';

// Generate audio for each message
$processedCount = 0;
echo "🎵 Generating audio...\n\n";

foreach ($totalMessages as $msg) {
    $msgId = $msg['id'] ?? '';
    $text = $msg['text'] ?? '';
    $category = $msg['category'] ?? 'uncategorized';
    $isTemplate = $msg['template'] ?? false;

    if (empty($text)) continue;

    // Handle standard replacements
    $text = str_replace('[GAME_TITLE]', $gameTitle, $text);

    // Skip templates if they still contain placeholders (note: these need special handling)
    if (strpos($text, '[') !== false) {
        echo "ℹ️  STILL TEMPLATE: $msgId - $text (Skipping for now)\n";
        $processedCount++;
        continue;
    }

    echo "Generating: $msgId\n";
    echo "  Text: $text\n";

    // Create hash for filename (place in category subfolder)
    $hash = md5($text);

    foreach (array_keys($voices) as $voiceName) {
        $filePath = "{$hostAudioDir}/{$voiceName}/{$category}/{$hash}.mp3";

        // Create subdirectory
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // Generate audio
        $voiceCode = $voices[$voiceName];

        // Generate audio using the generator's synthesizeAndSave method
        $generator->synthesizeAndSave($text, $voiceName, $voiceCode, $filePath, "host_message");
    }

    echo "\n";
    $processedCount++;

    if ($processedCount % 10 === 0) {
        echo "Progress: $processedCount/{" . count($totalMessages) . "} processed\n";
        gc_collect_cycles();
    }
}

echo "\n";
echo "✅ Generation complete!\n";
echo "   Processed: $processedCount messages\n";
echo "   Files: " . ($processedCount * count($voices)) . " audio files\n";

$generator->close();
$generator->printCharacterCountSummary();

if (!empty($messageCounts)) {
    echo "\n📊 Summary by Tier:\n";
    $tierDesc = [
        '1' => 'CRITICAL (play FIRST)',
        '2' => 'IMPORTANT (play second)',
        '3' => 'COMMON (standard gameplay)',
        '4' => 'OCCASIONAL (conditional)',
        '5' => 'RARE (edge cases)',
        '6' => 'VOCAL NICKNAMES',
        '7' => 'STATIC PIECES',
        '8' => 'BOT NAMES'
    ];

    foreach ($requestedTiers as $tier) {
        if (isset($messageCounts[$tier])) {
            echo "   Tier $tier ({$tierDesc[$tier]}): {$messageCounts[$tier]} messages\n";
        }
    }
}

echo "\n💡 Next Steps:\n";
echo "   1. Start with --test to preview (no API charges)\n";
echo "   2. Run --tier=1 first (critical messages)\n";
echo "   3. Then expand to tier 2, 3, etc.\n";
echo "   4. After host messages complete, generate full deck (~2000 cards)\n";
echo "\nDone!\n";
?>
