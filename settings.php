<?php

/**
 * Version: 4.10 - Fix Card Edit text visibility and styling
 * Changes:
 *   - Upgraded version number to 4.10.
 *   - Added explicit inline styling to card edit inputs (avoiding white-on-white text input visibility issues).
 *   - Redesigned card edit checkmark/cross buttons into distinct text buttons ("Save" and "Cancel") for clarity.
 * Previously (4.9): Disallow Google TTS key fallback for Gemini AI calls.
 */
session_start();

require_once __DIR__ . '/deck_parser.php';

// --- CONFIG LOADING ---
$globalConfigFile = __DIR__ . '/data/global_config.json';
function load_global_config()
{
    global $globalConfigFile;
    $defaults = [
        'stale_days' => 2,
        'idle_days_to_clear_names' => 7,
        'default_theme' => 'default',
        'intro_media_url' => 'ocah.png',
        'intro_media_type' => 'image',
        'intro_audio_url' => '',
        'win_audio_url' => '',
        'tts_enabled' => false,
        'tts_provider' => 'browser', // browser, google, elevenlabs
        'tts_voice' => 'female',
        'google_tts_api_key' => '',
        'elevenlabs_api_key' => '',
        'elevenlabs_voice_id' => '',
        'enable_vdo' => false, // Optional - user can enable in settings
        'vdo_room_id' => bin2hex(random_bytes(8)),
        'vdo_api_key' => '',
        'banned_ips' => [],
        'blocked_users' => [],
        'admin_password' => 'orange',
        'wp_publish_url' => 'https://netbound.ca',
        'wp_publish_username' => '',
        'wp_publish_password' => '',
        'reserved_users' => []
    ];
    if (file_exists($globalConfigFile)) {
        $data = json_decode(file_get_contents($globalConfigFile), true);
        return array_merge($defaults, $data ?: []);
    }
    return $defaults;
}
/**
 * @param array<string, mixed> $config
 */
function save_global_config(array $config): void
{
    global $globalConfigFile;
    file_put_contents($globalConfigFile, json_encode($config, JSON_PRETTY_PRINT));
}

/**
 * @param string $text
 * @param bool $isBlack
 * @return string
 */
function cleanCardTextPHP(string $text, bool $isBlack): string {
    $cleaned = trim($text);
    if ($cleaned === '') return '';

    if ($isBlack) {
        $cleaned = preg_replace('/_{2,}/', '______', $cleaned);
        $cleaned = preg_replace('/(\w)\s*______/', '$1 ______', $cleaned);
        $cleaned = preg_replace('/______\s*(\w)/', '______ $1', $cleaned);
        $cleaned = preg_replace('/______\s+([.,;:?!])/', '______$1', $cleaned);
        $cleaned = preg_replace('/ {2,}/', ' ', $cleaned);
    } else {
        $cleaned = preg_replace('/_+/', '', $cleaned);
        $cleaned = preg_replace('/ {2,}/', ' ', $cleaned);
    }
    return $cleaned;
}

/**
 * @param string $content
 * @return array{valid: bool, errors: string[], blackCardCount: int, whiteCardCount: int}
 */
function validateDeckImport(string $content): array {
    $lines = preg_split('/\R/', $content);
    $errors = [];
    $hasBlackHeader = false;
    $hasWhiteHeader = false;
    $hasBlackCards = false;
    $hasWhiteCards = false;
    $currentType = null;
    $blackCardCount = 0;
    $whiteCardCount = 0;

    // Validate structure
    foreach ($lines as $lineNum => $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;

        // Check for headers
        if (preg_match('/^(?:The\s+)?(.+?)\s+(Black|White)\s+Cards(?: List)?\s*$/i', $trimmed, $m)) {
            $headerType = strtolower(trim($m[2]));
            if ($headerType === 'black') {
                $hasBlackHeader = true;
                $currentType = 'black';
            } else {
                $hasWhiteHeader = true;
                $currentType = 'white';
            }
            continue;
        }

        // Standalone header check
        if (preg_match('/^\s*(Black|White)\s+Cards\s*$/i', $trimmed, $m)) {
            $headerType = strtolower(trim($m[1]));
            if ($headerType === 'black') {
                $hasBlackHeader = true;
                $currentType = 'black';
            } else {
                $hasWhiteHeader = true;
                $currentType = 'white';
            }
            continue;
        }

        // Skip other subheaders
        if (stripos($trimmed, 'Cards List') !== false) continue;

        // Validate card content
        if ($currentType === 'black') {
            $hasBlackCards = true;
            $blackCardCount++;

            // Black cards MUST have blanks (2 or more underscores, strip backslashes for check)
            $checkLine = str_replace('\\', '', $trimmed);
            if (preg_match('/_{2,}/', $checkLine) === 0) {
                $errors[] = "Line " . ($lineNum + 1) . ": Black card missing blanks (e.g., ______): \"" . substr($trimmed, 0, 50) . "...\"";
            }
        } elseif ($currentType === 'white') {
            $hasWhiteCards = true;
            $whiteCardCount++;

            // White cards should NOT have blanks
            $checkLine = str_replace('\\', '', $trimmed);
            if (preg_match('/_{2,}/', $checkLine) === 1) {
                $errors[] = "Line " . ($lineNum + 1) . ": White card should not have blanks (e.g., ______): \"" . substr($trimmed, 0, 50) . "...\"";
            }
        }
    }

    // Final validation checks
    if (!$hasBlackHeader && !$hasWhiteHeader) {
        $errors[] = "Missing required section headers. Must include 'Black Cards' and/or 'White Cards' headers.";
    }

    if (!$hasBlackCards && !$hasWhiteCards) {
        $errors[] = "No valid cards found. The deck must contain at least one card.";
    }

    if ($hasBlackHeader && !$hasBlackCards) {
        $errors[] = "Black Cards section header found, but no black cards present.";
    }

    if ($hasWhiteHeader && !$hasWhiteCards) {
        $errors[] = "White Cards section header found, but no white cards present.";
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'blackCardCount' => $blackCardCount,
        'whiteCardCount' => $whiteCardCount
    ];
}

/**
 * @param string $content
 * @return string
 */
function sanitizeDeckImport(string $content): string {
    $lines = preg_split('/\R/', $content);
    $blackCards = [];
    $whiteCards = [];
    $currentType = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;

        $trimmed = str_replace('\\', '', $trimmed);

        if (preg_match('/^(?:The\s+)?(.+?)\s+(Black|White)\s+Cards(?: List)?\s*$/i', $trimmed, $m)) {
            $currentType = strtolower(trim($m[2]));
            continue;
        }
        if (preg_match('/^\s*(Black|White)\s+Cards\s*$/i', $trimmed, $m)) {
            $currentType = strtolower(trim($m[1]));
            continue;
        }
        if (stripos($trimmed, 'Cards List') !== false) continue;

        if ($currentType === null) {
            if (preg_match('/_{2,}/', $trimmed) === 1 || substr($trimmed, -1) === '?') {
                $type = 'black';
            } else {
                $type = 'white';
            }
        } else {
            $type = $currentType;
        }

        if ($type === 'black') {
            if (preg_match('/_{2,}/', $trimmed) === 0) {
                $trimmed .= ' ______';
            }
            $trimmed = preg_replace('/_{2,}/', '______', $trimmed);
            $blackCards[] = $trimmed;
        } else {
            $trimmed = preg_replace('/_{2,}/', '', $trimmed);
            $trimmed = trim(preg_replace('/\s+/', ' ', $trimmed));
            if ($trimmed !== '') {
                $whiteCards[] = $trimmed;
            }
        }
    }

    $out = "";
    if (!empty($blackCards)) {
        $out .= "Black Cards\n";
        foreach ($blackCards as $c) {
            $out .= $c . "\n";
        }
        $out .= "\n";
    }
    if (!empty($whiteCards)) {
        $out .= "White Cards\n";
        foreach ($whiteCards as $c) {
            $out .= $c . "\n";
        }
    }
    return trim($out);
}

/**
 * @param string $text
 * @param string $type
 */
function generateCardAudio(string $text, string $type): void {
    global $globalConfig;
    
    if (empty($globalConfig['tts_enabled']) || empty($globalConfig['google_tts_api_key'])) {
        return;
    }

    $apiKey = $globalConfig['google_tts_api_key'];
    require_once __DIR__ . '/generate_audio.php';

    $generator = new AudioGenerator(
        $apiKey,
        __DIR__ . '/audio',
        [
            'male' => 'Fenrir',
            'female' => 'Aoede',
            'british' => 'Puck'
        ],
        50, // batchSize
        0,  // startIndex
        0,  // stopIndex
        'gemini', // provider
        '', // ElevenLabs voice ID
        1000 // dailyLimit
    );

    if ($type === 'black') {
        $generator->generateSegmentsForBlackCard($text);
    } else {
        $generator->generateAudioForWhiteCard($text);
    }
    
    $generator->close();
}

$globalConfig = load_global_config();

$voiceScriptsFile = __DIR__ . '/data/voice_scripts.json';
$voiceScripts = [];
if (file_exists($voiceScriptsFile)) {
    $voiceScripts = json_decode(file_get_contents($voiceScriptsFile), true) ?: [];
}

// Valid Admin
if (isset($_POST['admin_pass'])) {
    $adminPass = $globalConfig['admin_password'] ?? 'orange';
    if ($_POST['admin_pass'] === $adminPass) $_SESSION['is_admin'] = true;
    else $error = "Access Denied";
}
$isAdmin = $_SESSION['is_admin'] ?? false;

// --- THEMES LOADING ---
$themesFile = __DIR__ . '/data/themes.json';
$themes = file_exists($themesFile) ? (json_decode(file_get_contents($themesFile), true) ?: []) : [];

// Ensure a minimal Default theme exists
if (!isset($themes['default'])) {
    $themes['default'] = [
        'label' => 'Default',
        'game_name_suffix' => 'Everyone',
        'room_names' => ['The Lounge', 'Main Hall', 'Game Room', 'The Pit', 'Arcade'],
        'character_names' => ['Player One', 'Player Two', 'Player Three', 'Player Four', 'Player Five'],
        'banner_media' => '',
        'intro_audio_url' => '',
        'win_audio_url' => ''
    ];
    file_put_contents($themesFile, json_encode($themes, JSON_PRETTY_PRINT));
}

// Ensure Star Trek theme exists
if (!isset($themes['star_trek'])) {
    $themes['star_trek'] = [
        'label' => 'Star Trek',
        'game_name_suffix' => 'Starfleet',
        'room_names' => ['Vulcan', 'Qo\'noS', 'Romulus', 'Bajor', 'Cardassia Prime', 'Ferenginar', 'Earth', 'Andoria', 'Risa'],
        'character_names' => ['James T. Kirk', 'Spock', 'Leonard McCoy', 'Jean-Luc Picard', 'William Riker', 'Worf', 'Seven of Nine', 'Kathryn Janeway'],
        'banner_media' => '',
        'intro_audio_url' => '',
        'win_audio_url' => ''
    ];
    file_put_contents($themesFile, json_encode($themes, JSON_PRETTY_PRINT));
}

// Normalize existing themes with new fields
foreach ($themes as $key => $data) {
    $changed = false;
    if (!isset($themes[$key]['game_name_suffix'])) {
        // Migrate from game_title if exists
        if (isset($themes[$key]['game_title'])) {
            $title = $themes[$key]['game_title'];
            $themes[$key]['game_name_suffix'] = str_ireplace('Cards Against ', '', $title);
        } else {
            $themes[$key]['game_name_suffix'] = 'Everyone';
        }
        $changed = true;
    }
    if (!isset($themes[$key]['win_audio_url'])) {
        $themes[$key]['win_audio_url'] = '';
        $changed = true;
    }
    if (!isset($themes[$key]['intro_audio_url'])) {
        $themes[$key]['intro_audio_url'] = $themes[$key]['audio_url'] ?? '';
        $changed = true;
    }
    if ($changed) {
        file_put_contents($themesFile, json_encode($themes, JSON_PRETTY_PRINT));
    }
}

// --- DECK MANAGEMENT ---
$deckFile = __DIR__ . '/decks.md';
$deckAvailabilityFile = __DIR__ . '/data/decks_available.json';

if ($isAdmin && isset($_POST['action'])) {
    $ajax = isset($_POST['ajax']);
    $msg = "";

    // CHECK DUPLICATE CHECK
    if ($_POST['action'] === 'check_duplicates') {
        $parsed = parseDecksShared(true); // include deleted
        $blackSeen = [];
        $whiteSeen = [];

        // Group cards by normalized text
        foreach ($parsed['black'] as $card) {
            $normalized = trim(strtolower($card['text']));
            if (!isset($blackSeen[$normalized])) {
                $blackSeen[$normalized] = ['text' => $card['text'], 'locations' => []];
            }
            $blackSeen[$normalized]['locations'][] = ['deck' => $card['deck']];
        }
        foreach ($parsed['white'] as $card) {
            $normalized = trim(strtolower($card['text']));
            if (!isset($whiteSeen[$normalized])) {
                $whiteSeen[$normalized] = ['text' => $card['text'], 'locations' => []];
            }
            $whiteSeen[$normalized]['locations'][] = ['deck' => $card['deck']];
        }

        // Filter for duplicates
        $blackDups = array_values(array_filter($blackSeen, function($c) { return count($c['locations']) > 1; }));
        $whiteDups = array_values(array_filter($whiteSeen, function($c) { return count($c['locations']) > 1; }));

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'black_duplicates' => $blackDups,
            'white_duplicates' => $whiteDups
        ]);
        exit;
    }

    // AUTO REMOVE DUPLICATES FROM decks.md
    if ($_POST['action'] === 'remove_duplicates') {
        if (!file_exists($deckFile)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'decks.md not found']);
            exit;
        }

        $content = file_get_contents($deckFile);
        $lines = preg_split('/\R/', $content);
        $cleanedLines = [];

        $blackSeen = [];
        $whiteSeen = [];
        $type = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            if (preg_match('/^(?:The\s+)?(.+?)\s+(Black|White)\s+Cards(?:s)?(?: List)?\s*$/i', $trimmed, $m)) {
                $type = strtolower(trim($m[2]));
                $cleanedLines[] = $line;
                continue;
            }
            if (preg_match('/^\s*(Black|White)\s+Cards\s*(?:List)?\s*$/i', $trimmed, $m)) {
                $type = strtolower(trim($m[1]));
                $cleanedLines[] = $line;
                continue;
            }

            if ($trimmed === '' || stripos($trimmed, 'Cards List') !== false) {
                $cleanedLines[] = $line;
                continue;
            }

            $normalized = trim($trimmed);
            $normalized = str_replace('\\', '', $normalized);
            $normalized = preg_replace('/_{2,}/', '______', $normalized);
            $normalized = trim(strtolower($normalized));

            if ($type === 'black') {
                if (isset($blackSeen[$normalized])) {
                    continue;
                }
                $blackSeen[$normalized] = true;
            } elseif ($type === 'white') {
                if (isset($whiteSeen[$normalized])) {
                    continue;
                }
                $whiteSeen[$normalized] = true;
            }

            $cleanedLines[] = $line;
        }

        file_put_contents($deckFile, implode("\n", $cleanedLines));

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    // TEST GEMINI API CONNECTION
    if ($_POST['action'] === 'test_gemini') {
        $apiKey = $_POST['gemini_api_key'] ?? '';
        if (empty($apiKey)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Please enter a Gemini API Key first.']);
            exit;
        }

        $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=" . $apiKey;
        $payload = [
            "contents" => [
                ["role" => "user", "parts" => [["text" => "Test connection. Respond with only the word: 'OK'."]]]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        header('Content-Type: application/json');
        if ($response === false) {
            echo json_encode(['success' => false, 'error' => 'cURL Error: ' . $err]);
            exit;
        }

        $data = json_decode($response, true);
        if ($httpCode === 200) {
            $text = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
            if ($text !== '') {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Gemini API returned an empty response.']);
            }
        } else {
            $errorMsg = $data['error']['message'] ?? ('HTTP Error ' . $httpCode);
            
            // Diagnostics: List models to see what is available for this API Key
            $listUrl = "https://generativelanguage.googleapis.com/v1/models?key=" . $apiKey;
            $ch2 = curl_init($listUrl);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
            $listResponse = curl_exec($ch2);
            curl_close($ch2);
            
            $availableModels = [];
            if ($listResponse !== false) {
                $listData = json_decode($listResponse, true);
                if (isset($listData['models'])) {
                    foreach ($listData['models'] as $m) {
                        if (isset($m['name'])) {
                            $parts = explode('/', $m['name']);
                            $availableModels[] = end($parts);
                        }
                    }
                }
            }
            
            echo json_encode([
                'success' => false,
                'error' => $errorMsg,
                'available_models' => $availableModels
            ]);
        }
        exit;
    }

    // TEST WORDPRESS CONNECTION
    if ($_POST['action'] === 'test_wp') {
        $url = ($_POST['wp_publish_url'] ?? '') . '/wp-json/wp/v2/posts';
        $username = $_POST['wp_publish_username'] ?? '';
        $password = $_POST['wp_publish_password'] ?? '';

        if (empty($username) || empty($password) || empty($_POST['wp_publish_url'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Please fill in all WordPress credentials first.']);
            exit;
        }

        $payload = [
            'title' => 'Cards Against Test Connection',
            'content' => '<p>WordPress REST API connection test successful! This post can be safely deleted.</p>',
            'status' => 'draft'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($username . ':' . $password)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        header('Content-Type: application/json');
        if ($httpCode === 201) {
            $data = json_decode($response, true);
            echo json_encode(['success' => true, 'url' => $data['link'] ?? '']);
        } else {
            $errorMsg = "HTTP Error {$httpCode}";
            $data = json_decode($response, true);
            if (isset($data['message'])) {
                $errorMsg = $data['message'];
            }
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
        exit;
    }

    // SAVE VOICE SCRIPTS
    if ($_POST['action'] === 'save_voice_scripts') {
        $vs_data = [];
        if (isset($_POST['vs']) && is_array($_POST['vs'])) {
            foreach ($_POST['vs'] as $category => $phrases) {
                if (is_array($phrases)) {
                    $vs_data[$category] = array_values(array_filter(array_map('trim', $phrases)));
                } else {
                    $vs_data[$category] = [];
                }
            }
        }
        $voiceScriptsFile = __DIR__ . '/data/voice_scripts.json';
        file_put_contents($voiceScriptsFile, json_encode($vs_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $msg = "Voice scripts successfully saved.";
        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'msg' => $msg]);
            exit;
        }
    }

    // SAVE GLOBAL CONFIG
    if ($_POST['action'] === 'save_global') {
        $allowed = [
            'stale_days',
            'idle_days_to_clear_names',
            'default_theme',
            'intro_media_url',
            'intro_media_type',
            'intro_audio_url',
            'win_audio_url',
            'tts_enabled',
            'tts_provider',
            'tts_voice',
            'google_tts_api_key',
            'google_tts_voice_name',
            'elevenlabs_api_key',
            'elevenlabs_voice_id',
            'enable_vdo',
            'vdo_room_id',
            'vdo_api_key',
            'admin_password',
            'wp_publish_url',
            'wp_publish_username',
            'wp_publish_password',
            'gemini_api_key',
            'reserved_users'
        ];
        foreach ($allowed as $k) {
            if (isset($_POST[$k])) {
                if ($k === 'tts_enabled' || $k === 'enable_vdo') {
                    $globalConfig[$k] = ($_POST[$k] === '1');
                } else {
                    $globalConfig[$k] = $_POST[$k];
                }
            } else if ($k === 'reserved_users') {
                // Handle array from form
                $ru = [];
                if (isset($_POST['ru_username'])) {
                    for ($i = 0; $i < count($_POST['ru_username']); $i++) {
                        if (!empty($_POST['ru_username'][$i])) {
                            $ru[] = ['username' => $_POST['ru_username'][$i], 'password' => $_POST['ru_password'][$i], 'avatar' => $_POST['ru_avatar'][$i]];
                        }
                    }
                }
                $globalConfig['reserved_users'] = $ru;
            } else {
                if ($k === 'tts_enabled' || $k === 'enable_vdo') {
                    $globalConfig[$k] = false;
                }
            }
        }
        if (isset($_POST['banned_ips'])) {
            $globalConfig['banned_ips'] = array_filter(array_map('trim', explode("\n", $_POST['banned_ips'])));
        }
        if (isset($_POST['blocked_users'])) {
            $globalConfig['blocked_users'] = array_filter(array_map('trim', explode("\n", $_POST['blocked_users'])));
        }
        save_global_config($globalConfig);
        $msg = "Configuration saved.";

        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'msg' => $msg]);
            exit;
        }
    }

    // DELETE ROOMS
    if ($_POST['action'] === 'delete_room' && !empty($_POST['room_id'])) {
        $f = __DIR__ . '/data/room_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['room_id']) . '.json';
        if (file_exists($f)) @unlink($f);
        $msg = "Room deleted.";
    }
    if ($_POST['action'] === 'delete_all_rooms') {
        $files = glob(__DIR__ . '/data/room_*.json');
        foreach ($files as $f) @unlink($f);
        $msg = "All rooms deleted.";
    }

    // MEDIA UPLOAD
    if ($_POST['action'] === 'upload_media') {
        $targetDir = __DIR__ . '/assets/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        $uploads = [
            'graphic_main' => 'intro_media_url',
            'audio_intro' => 'intro_audio_url',
            'audio_win' => 'win_audio_url'
        ];
        foreach ($uploads as $key => $configKey) {
            if (!empty($_FILES[$key]['name'])) {
                $ext = strtolower(pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION));
                $name = $key . '.' . $ext;
                $relative = 'assets/' . $name;
                $globalConfig[$configKey] = $relative;
                if ($key === 'graphic_main') {
                    $globalConfig['intro_media_type'] = in_array($ext, ['mp4', 'webm', 'mov']) ? 'video' : 'image';
                }
                move_uploaded_file($_FILES[$key]['tmp_name'], $targetDir . $name);
            }
        }
        save_global_config($globalConfig);
        $msg = "Media uploaded.";
    }

    // DECK AVAILABILITY
    if ($_POST['action'] === 'save_decks') {
        $avail = [];
        $allTags = array_keys(get_deck_stats());
        $selected = isset($_POST['available_decks']) && is_array($_POST['available_decks']) ? $_POST['available_decks'] : [];
        foreach ($allTags as $tag) {
            $avail[$tag] = in_array($tag, $selected, true);
        }
        file_put_contents($deckAvailabilityFile, json_encode($avail, JSON_PRETTY_PRINT));
        $msg = "Deck availability saved.";
        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'msg' => $msg]);
            exit;
        }
    }

    // DELETE DECK
    if ($_POST['action'] === 'delete_deck' && !empty($_POST['deck_slug'])) {
        $deckSlug = $_POST['deck_slug'];
        $deletedFile = __DIR__ . '/data/deleted_decks.json';
        $deletedDecks = file_exists($deletedFile) ? (json_decode(file_get_contents($deletedFile), true) ?: []) : [];
        if (!in_array($deckSlug, $deletedDecks, true)) {
            $deletedDecks[] = $deckSlug;
            file_put_contents($deletedFile, json_encode($deletedDecks, JSON_PRETTY_PRINT));
        }
        $msg = "Deck deleted successfully.";
        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'msg' => $msg]);
            exit;
        }
    }

    // RESTORE DECK
    if ($_POST['action'] === 'restore_deck' && !empty($_POST['deck_slug'])) {
        $deckSlug = $_POST['deck_slug'];
        $deletedFile = __DIR__ . '/data/deleted_decks.json';
        $deletedDecks = file_exists($deletedFile) ? (json_decode(file_get_contents($deletedFile), true) ?: []) : [];
        $deletedDecks = array_values(array_filter($deletedDecks, function($d) use ($deckSlug) { return $d !== $deckSlug; }));
        file_put_contents($deletedFile, json_encode($deletedDecks, JSON_PRETTY_PRINT));
        $msg = "Deck restored successfully.";
        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'msg' => $msg]);
            exit;
        }
    }

    // SAVE THEME (create or update)
    if ($_POST['action'] === 'save_theme') {
        $label = trim($_POST['label'] ?? '');
        if ($label === '') {
            $msg = "Theme name is required.";
            if ($ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'msg' => $msg]);
                exit;
            }
        } else {
            // Generate key from label
            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label));
            $key = trim($key, '_');
            if ($key === '') $key = 'theme_' . time();

            // Check if editing existing theme
            $editKey = trim($_POST['edit_key'] ?? '');
            if ($editKey !== '' && isset($themes[$editKey])) {
                // Editing existing theme - use the original key
                $key = $editKey;
            }

            // Get existing values if editing
            $existingTheme = $themes[$key] ?? [];

            // Handle file uploads for theme media
            $targetDir = __DIR__ . '/assets/themes/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

            $bannerMedia = trim($_POST['banner_media'] ?? $existingTheme['banner_media'] ?? '');
            $introAudio = trim($_POST['theme_intro_audio'] ?? $existingTheme['intro_audio_url'] ?? '');
            $winAudio = trim($_POST['theme_win_audio'] ?? $existingTheme['win_audio_url'] ?? '');

            // Upload banner media
            if (!empty($_FILES['theme_banner_file']['name'])) {
                $ext = strtolower(pathinfo($_FILES['theme_banner_file']['name'], PATHINFO_EXTENSION));
                $filename = $key . '_banner.' . $ext;
                if (move_uploaded_file($_FILES['theme_banner_file']['tmp_name'], $targetDir . $filename)) {
                    $bannerMedia = 'assets/themes/' . $filename;
                }
            }

            // Upload intro audio
            if (!empty($_FILES['theme_intro_file']['name'])) {
                $ext = strtolower(pathinfo($_FILES['theme_intro_file']['name'], PATHINFO_EXTENSION));
                $filename = $key . '_intro.' . $ext;
                if (move_uploaded_file($_FILES['theme_intro_file']['tmp_name'], $targetDir . $filename)) {
                    $introAudio = 'assets/themes/' . $filename;
                }
            }

            // Upload win audio
            if (!empty($_FILES['theme_win_file']['name'])) {
                $ext = strtolower(pathinfo($_FILES['theme_win_file']['name'], PATHINFO_EXTENSION));
                $filename = $key . '_win.' . $ext;
                if (move_uploaded_file($_FILES['theme_win_file']['tmp_name'], $targetDir . $filename)) {
                    $winAudio = 'assets/themes/' . $filename;
                }
            }

            // Parse default decks
            $themeDecks = [];
            if (isset($_POST['default_decks'])) {
                if (is_string($_POST['default_decks'])) {
                    $themeDecks = json_decode($_POST['default_decks'], true) ?: [];
                } else if (is_array($_POST['default_decks'])) {
                    $themeDecks = $_POST['default_decks'];
                }
            }

            $themes[$key] = [
                'label' => $label,
                'game_name_suffix' => trim($_POST['game_name_suffix'] ?? 'Everyone'),
                'room_names' => array_filter(array_map('trim', preg_split('/\r?\n/', $_POST['room_names'] ?? ''))),
                'character_names' => array_filter(array_map('trim', preg_split('/\r?\n/', $_POST['character_names'] ?? ''))),
                'banner_media' => $bannerMedia,
                'intro_audio_url' => $introAudio,
                'win_audio_url' => $winAudio,
                'mandatory_deck' => trim($_POST['mandatory_deck'] ?? ''),
                'win_limit' => isset($_POST['win_limit']) ? intval($_POST['win_limit']) : 5,
                'timer' => isset($_POST['timer']) ? intval($_POST['timer']) : 60,
                'hand_size' => isset($_POST['hand_size']) ? intval($_POST['hand_size']) : 7,
                'self_vote' => ($_POST['self_vote'] === 'true' || $_POST['self_vote'] === '1' || $_POST['self_vote'] === 'on' || $_POST['self_vote'] === true),
                'enable_tts' => ($_POST['enable_tts'] === 'true' || $_POST['enable_tts'] === '1' || $_POST['enable_tts'] === 'on' || $_POST['enable_tts'] === true),
                'default_decks' => array_map('strtolower', array_map('trim', $themeDecks))
            ];

            file_put_contents($themesFile, json_encode($themes, JSON_PRETTY_PRINT));
            $msg = "Theme saved.";

            if ($ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'msg' => $msg, 'key' => $key]);
                exit;
            }
        }
    }

    // SET DEFAULT THEME
    if ($_POST['action'] === 'set_default_theme') {
        $key = trim($_POST['theme_key'] ?? '');
        if ($key !== '' && isset($themes[$key])) {
            $globalConfig['default_theme'] = $key;
            save_global_config($globalConfig);
            $msg = "Default theme set to: " . ($themes[$key]['label'] ?? $key);
            if ($ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'msg' => $msg]);
                exit;
            }
        }
    }

    // DELETE THEME
    if ($_POST['action'] === 'delete_theme') {
        $key = trim($_POST['theme_key'] ?? '');
        if ($key !== '' && $key !== 'default' && isset($themes[$key])) {
            unset($themes[$key]);
            file_put_contents($themesFile, json_encode($themes, JSON_PRETTY_PRINT));
            if ($globalConfig['default_theme'] === $key) {
                $globalConfig['default_theme'] = 'default';
                save_global_config($globalConfig);
            }
            $msg = "Theme deleted.";
            if ($ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'msg' => $msg]);
                exit;
            }
        }
    }

    // IMPORT DECK
    if ($_POST['action'] === 'import_deck') {
        $content = $_POST['deck_content'] ?? '';
        $sanitize = isset($_POST['sanitize_import']) && $_POST['sanitize_import'] === '1';
        if (trim($content) !== '') {
            // Validate deck syntax first
            $validation = validateDeckImport($content);

            if (!$validation['valid'] && $sanitize) {
                $content = sanitizeDeckImport($content);
                $validation = validateDeckImport($content);
            }

            if (!$validation['valid']) {
                $msg = "Error: Deck import validation failed:\n\n" . implode("\n", $validation['errors']);
                if ($ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'msg' => $msg]);
                    exit;
                }
            } else {
                // Proceed with import - parse and clean the content
                $lines = preg_split('/\R/', $content);
                $cleanedLines = [];
                $type = 'black'; // default assumption

                $hasHeader = false;
                $hasCards = false;

                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        $cleanedLines[] = '';
                        continue;
                    }

                    // More flexible header detection (matches parseDecks)
                    if (preg_match('/^(?:The\s+)?(.+?)\s+(Black|White)\s+Cards(?: List)?\s*$/i', $trimmed, $m)) {
                        $type = strtolower(trim($m[2]));
                        $cleanedLines[] = $trimmed;
                        $hasHeader = true;
                        continue;
                    }
                    if (preg_match('/^\s*Black Cards\s*$/i', $trimmed)) {
                        $type = 'black';
                        $cleanedLines[] = $trimmed;
                        $hasHeader = true;
                        continue;
                    }
                    if (preg_match('/^\s*White Cards\s*$/i', $trimmed)) {
                        $type = 'white';
                        $cleanedLines[] = $trimmed;
                        $hasHeader = true;
                        continue;
                    }
                    if (stripos($trimmed, 'Cards List') !== false) {
                        $cleanedLines[] = $trimmed;
                        continue;
                    }

                    // It's a card line. Clean it!
                    $isBlack = ($type === 'black');
                    $cleanedCard = cleanCardTextPHP($trimmed, $isBlack);
                    if ($cleanedCard !== '') {
                        $cleanedLines[] = $cleanedCard;
                        $hasCards = true;
                    }
                }

                $cleanedContent = implode("\n", $cleanedLines);
                $importedFile = __DIR__ . '/data/imported_decks.md';

                if (!is_dir(dirname($importedFile))) {
                    mkdir(dirname($importedFile), 0777, true);
                }

                $current = file_exists($importedFile) ? file_get_contents($importedFile) : "Black Cards\n\nWhite Cards\n";
                file_put_contents($importedFile, $current . "\n\n" . $cleanedContent);
                $msg = "✓ Deck imported successfully! (" . $validation['blackCardCount'] . " black, " . $validation['whiteCardCount'] . " white cards)";

                if ($ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'msg' => $msg, 'blackCount' => $validation['blackCardCount'], 'whiteCount' => $validation['whiteCardCount']]);
                    exit;
                }
            }
        }
    }

    // ADD CARD (force into Orange Deck via user_additions.json)
    if ($_POST['action'] === 'add_card') {
        $type = ($_POST['card_type'] === 'Black') ? 'black' : 'white';
        $text = trim($_POST['card_text']);

        if ($text) {
            $text = cleanCardTextPHP($text, ($type === 'black'));

            $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
            $additions = file_exists($USER_ADDITIONS_FILE) ? (json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: []) : [];

            // Check duplicates
            $exists = false;
            foreach ($additions as $existing) {
                if (strcasecmp($existing['text'], $text) === 0) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $pick = ($type === 'black') ? max(1, substr_count($text, '______')) : 0;
                $additions[] = [
                    'text' => $text,
                    'type' => $type,
                    'pick' => $pick,
                    'ts' => time()
                ];

                if (!is_dir(dirname($USER_ADDITIONS_FILE))) mkdir(dirname($USER_ADDITIONS_FILE), 0777, true);
                file_put_contents($USER_ADDITIONS_FILE, json_encode($additions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                // Generate TTS audio for the new card!
                generateCardAudio($text, $type);

                $msg = "Card added to Orange Deck (Audio Generated).";
            } else {
                $msg = "Card already exists in Orange Deck.";
            }
        }
    }

    // DELETE SELECTED CARDS
    if ($_POST['action'] === 'delete_cards') {
        $deckTag = slug_deck_label($_POST['deck_tag'] ?? '');
        $toDeleteBlack = $_POST['delete_black'] ?? [];
        $toDeleteWhite = $_POST['delete_white'] ?? [];
        $deckMap = parse_deck_cards_for_admin();
        if (isset($deckMap[$deckTag])) {
            if (!empty($toDeleteBlack)) {
                $deckMap[$deckTag]['black'] = array_values(array_filter($deckMap[$deckTag]['black'], function ($text) use ($toDeleteBlack) {
                    return !in_array($text, $toDeleteBlack, true);
                }));
            }
            if (!empty($toDeleteWhite)) {
                $deckMap[$deckTag]['white'] = array_values(array_filter($deckMap[$deckTag]['white'], function ($text) use ($toDeleteWhite) {
                    return !in_array($text, $toDeleteWhite, true);
                }));
            }
            rebuild_deck_file($deckMap);
            $msg = "Selected cards deleted.";
        }
    }
}


// --- DATA PREP FOR VIEW ---
// Active Rooms
$activeRooms = [];
$files = glob(__DIR__ . '/data/room_*.json');
if ($files) {
    foreach ($files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if ($d) {
            $d['file'] = $f;
            $activeRooms[] = $d;
        }
    }
}

// Deck Stats
function get_deck_stats()
{
    $parsed = parseDecksShared(true);
    $stats = [];
    foreach ($parsed['tags'] as $tag) {
        $stats[$tag] = ['black' => 0, 'white' => 0];
    }
    foreach ($parsed['black'] as $c) {
        if (isset($stats[$c['deck']])) $stats[$c['deck']]['black']++;
    }
    foreach ($parsed['white'] as $c) {
        if (isset($stats[$c['deck']])) $stats[$c['deck']]['white']++;
    }
    return $stats;
}

/**
 * @param string $label
 * @return string
 */
function slug_deck_label(string $label): string
{
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', $label)));
    return $slug ?: 'base_deck';
}

function parse_deck_cards_for_admin()
{
    global $deckFile;
    if (!file_exists($deckFile)) return [];
    $lines = file($deckFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $decks = [];
    $currentLabel = 'Base Deck';
    $currentSlug = 'base_deck';
    $type = null;

    $ensureDeck = function ($slug, $label) use (&$decks) {
        if (!isset($decks[$slug])) {
            $decks[$slug] = [
                'label' => $label,
                'black' => [],
                'white' => []
            ];
        }
    };

    foreach ($lines as $l) {
        $line = trim($l);
        if ($line === '') continue;
        if (preg_match('/^\s*Black Cards\s*$/i', $line)) {
            $type = 'black';
            $currentLabel = 'Base Deck';
            $currentSlug = 'base_deck';
            $ensureDeck($currentSlug, $currentLabel);
            continue;
        }
        if (preg_match('/^\s*White Cards\s*$/i', $line)) {
            $type = 'white';
            $currentLabel = 'Base Deck';
            $currentSlug = 'base_deck';
            $ensureDeck($currentSlug, $currentLabel);
            continue;
        }

        if (preg_match('/^(The\s+)?(.+?)\s+(Black|White)\s+Cards\s+List\s*$/i', $line, $m)) {
            $currentLabel = trim($m[2]);
            $currentSlug = slug_deck_label($currentLabel);
            $type = strtolower(trim($m[3])) === 'black' ? 'black' : 'white';
            $ensureDeck($currentSlug, $currentLabel);
            continue;
        }

        if ($type === 'white' && stripos($line, 'Cards Against') === 0) continue;

        // Inline tag e.g., "Card text (orange)"
        $deckLabel = $currentLabel;
        $deckSlug = $currentSlug;
        if (preg_match('/\(([^)]+)\)\s*$/', $line, $m)) {
            $deckLabel = trim($m[1]);
            $deckSlug = slug_deck_label($deckLabel);
            $line = trim(preg_replace('/\s*\([^)]+\)\s*$/', '', $line));
        }

        $ensureDeck($deckSlug, $deckLabel);
        if ($type === 'black') $decks[$deckSlug]['black'][] = $line;
        elseif ($type === 'white') $decks[$deckSlug]['white'][] = $line;
    }

    return $decks;
}

/**
 * @param array<string, array<string, mixed>> $deckMap
 * @return bool
 */
function rebuild_deck_file(array $deckMap): bool
{
    global $deckFile;
    if (empty($deckMap)) return false;

    // Preserve order: base_deck first, then alphabetical
    $slugs = array_keys($deckMap);
    usort($slugs, function ($a, $b) {
        if ($a === 'base_deck') return ($b === 'base_deck') ? 0 : -1;
        if ($b === 'base_deck') return 1;
        return strcmp($a, $b);
    });

    $lines = [];
    $lines[] = 'Black Cards';
    foreach ($slugs as $slug) {
        $deck = $deckMap[$slug];
        $label = $deck['label'] ?? ucfirst(str_replace('_', ' ', $slug));
        if (!empty($deck['black'])) {
            if ($slug !== 'base_deck') $lines[] = "The {$label} Black Cards List";
            foreach ($deck['black'] as $text) {
                $lines[] = $text;
            }
            $lines[] = '';
        }
    }

    $lines[] = 'White Cards';
    foreach ($slugs as $slug) {
        $deck = $deckMap[$slug];
        $label = $deck['label'] ?? ucfirst(str_replace('_', ' ', $slug));
        if (!empty($deck['white'])) {
            if ($slug !== 'base_deck') $lines[] = "The {$label} White Cards List";
            foreach ($deck['white'] as $text) {
                $lines[] = $text;
            }
            $lines[] = '';
        }
    }

    $content = rtrim(implode("\n", $lines)) . "\n";
    return file_put_contents($deckFile, $content) !== false;
}

$deckStats = get_deck_stats();
$availDecks = [];
if (file_exists($deckAvailabilityFile)) {
    $availDecks = json_decode(file_get_contents($deckAvailabilityFile), true);
} else {
    $availDecks = array_fill_keys(array_keys($deckStats), true);
}
$deckCards = parse_deck_cards_for_admin();

// Asset helpers
$findAsset = function ($configKey, $prefix) use ($globalConfig) {
    $fromConfig = $globalConfig[$configKey] ?? '';
    if ($fromConfig && file_exists(__DIR__ . '/' . $fromConfig)) {
        return $fromConfig;
    }
    $matches = glob(__DIR__ . '/assets/' . $prefix . '.*');
    if (!empty($matches)) {
        return 'assets/' . basename($matches[0]);
    }
    return '';
};
$introMediaPath = $globalConfig['intro_media_url'] ?? '';
$introMediaType = $globalConfig['intro_media_type'] ?? 'image';
$introAudioPath = $findAsset('intro_audio_url', 'audio_intro');
$winAudioPath = $findAsset('win_audio_url', 'audio_win');

// Current default theme
$defaultThemeKey = $globalConfig['default_theme'] ?? 'default';
$defaultTheme = $themes[$defaultThemeKey] ?? $themes['default'];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Game Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <style>
        body {
            background-color: #1a1b1e;
            color: white;
            font-family: sans-serif;
        }

        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }

        .accordion-content.open {
            max-height: 3000px;
            transition: max-height 0.5s ease-in;
        }

        .rotate-180 {
            transform: rotate(180deg);
        }
    </style>
</head>

<body class="flex flex-col h-screen bg-gradient-to-b from-[#1a1b1e] to-[#141517] overflow-hidden">

    <!-- BRANDING BANNER -->
    <div class="bg-[#141517] border-b border-gray-800 py-2 flex-none">
        <div class="max-w-4xl mx-auto px-4 flex items-center justify-center gap-2">
            <i class="fas fa-tshirt text-orange-500 text-xl sm:text-2xl"></i>
            <h1 class="text-lg sm:text-xl font-black uppercase tracking-[0.15em] text-gray-200">
                Cards Against <span class="text-orange-500"><?php echo htmlspecialchars($defaultTheme['game_name_suffix'] ?? 'Everyone'); ?></span>
            </h1>
        </div>
    </div>

    <!-- SETTINGS HEADER -->
    <div class="bg-[#141517] border-b border-gray-800 sticky top-0 z-50 shadow-md flex-none">
        <div class="max-w-4xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="text-sm font-bold text-gray-200 uppercase tracking-wider">Settings</div>
            <a href="index.php" class="hover:text-white transition-colors p-1" title="Lobby">
                <i class="fas fa-home text-lg"></i>
            </a>
        </div>
    </div>

    <?php if (!$isAdmin): ?>
        <!-- ADMIN LOGIN -->
        <div class="flex-1 flex items-center justify-center p-4">
            <div class="bg-[#25262b] p-8 rounded-xl shadow-2xl border border-gray-700 max-w-sm w-full text-center">
                <i class="fas fa-lock text-4xl text-orange-500 mb-4"></i>
                <h2 class="text-xl font-bold uppercase tracking-widest mb-6">Restricted Access</h2>
                <?php if (isset($error)) echo "<p class='text-red-500 text-xs mb-4'>$error</p>"; ?>
                <form method="POST">
                    <input type="password" name="admin_pass" placeholder="Password" class="w-full bg-gray-800 border border-gray-600 rounded p-3 text-white mb-4 focus:border-orange-500 outline-none">
                    <button type="submit" class="w-full bg-orange-500 text-white font-bold py-3 rounded uppercase tracking-wider hover:bg-orange-600">Unlock</button>
                </form>
            </div>
        </div>
    <?php else: ?>

        <main class="p-4 max-w-4xl mx-auto w-full space-y-4 flex-1 overflow-y-auto">
            <?php if (isset($msg)) echo "<div class='bg-green-600/20 border border-green-600 text-green-400 p-3 rounded text-sm font-bold'>$msg</div>"; ?>

            <!-- 1. THEME EDITOR -->
            <style>
                /* Hide 'No file chosen' text and style file inputs */
                input[type="file"]::file-selector-button {
                    padding: 4px 8px;
                    border-radius: 4px;
                    background: #374151;
                    color: white;
                    border: 1px solid #4B5563;
                    cursor: pointer;
                    margin-right: 8px;
                }
                input[type="file"]::file-selector-button:hover {
                    background: #4B5563;
                }
                /* Hide the filename text */
                input[type="file"] {
                    color: transparent;
                    width: 110px;
                }
                /* Position clear buttons */
                .media-clear-btn {
                    position: relative;
                    right: 20px;
                }
            </style>
            <?php renderAccordion('theme', 'Theme Editor', 'palette', function () use ($themes, $defaultThemeKey, $deckStats) { 
                $allDeckSlugs = array_keys($deckStats);
                sort($allDeckSlugs);
            ?>
                <div class="space-y-4">
                    <!-- Theme Selector Row -->
                    <div class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-end">
                        <div class="flex-1">
                            <label class="block text-xs font-bold text-gray-200 uppercase mb-2">Select Theme</label>
                            <select id="theme_select" class="w-full bg-gray-800 border border-gray-600 rounded p-3 text-white" onchange="loadTheme()">
                                <?php foreach ($themes as $key => $t):
                                    $isDefault = ($key === $defaultThemeKey);
                                    $label = htmlspecialchars($t['label'] ?? ucfirst($key));
                                    if ($isDefault) $label .= ' ★';
                                ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $isDefault ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" onclick="setAsDefault()" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold px-4 py-3 rounded whitespace-nowrap">
                                <i class="fas fa-star mr-1"></i> Set as Default
                            </button>
                            <button type="button" onclick="editTheme()" class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold px-4 py-3 rounded">
                                <i class="fas fa-edit mr-1"></i> Edit
                            </button>
                            <button type="button" onclick="newTheme()" class="bg-green-600 hover:bg-green-500 text-white text-xs font-bold px-4 py-3 rounded">
                                <i class="fas fa-plus mr-1"></i> New
                            </button>
                            <button type="button" id="delete_theme_btn_main" onclick="deleteTheme()" class="bg-red-600 hover:bg-red-500 text-white text-xs font-bold px-4 py-3 rounded">
                                <i class="fas fa-trash mr-1"></i> Delete
                            </button>
                        </div>
                    </div>

                    <!-- Theme Editor Form (hidden by default) -->
                    <div id="theme_form" class="hidden space-y-4 p-4 bg-gray-800/50 rounded-lg border border-gray-700">
                        <input type="hidden" id="theme_edit_key" value="">

                        <div class="flex justify-between items-center">
                            <h3 id="theme_form_title" class="text-sm font-bold text-orange-400 uppercase">New Theme</h3>
                            <button type="button" onclick="cancelThemeEdit()" class="text-gray-200 hover:text-white">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-200 uppercase mb-1">Theme Name</label>
                                <input id="theme_label" class="w-full bg-gray-900 border border-gray-700 rounded p-2 text-white" placeholder="My Theme">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-200 uppercase mb-1">Cards Against ______</label>
                                <input id="theme_suffix" class="w-full bg-gray-900 border border-gray-700 rounded p-2 text-white mb-1" placeholder="Everyone">
                                <button type="button" onclick="insertBlank('theme_suffix')" class="bg-gray-700 hover:bg-gray-600 text-white text-[9px] font-bold py-1 px-2 rounded">
                                    <i class="fas fa-underscore mr-1"></i> Insert Blank (______)
                                </button>
                                <p class="text-[10px] text-gray-300 mt-1">The game will be called "Cards Against [this value]"</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-200 uppercase mb-1">Game Room Names</label>
                                <textarea id="theme_rooms" class="w-full bg-gray-900 border border-gray-700 rounded p-2 text-white h-32" placeholder="The Lounge&#10;Main Hall&#10;Game Room"></textarea>
                                <p class="text-[10px] text-gray-300 mt-1">One per line. Used when creating new rooms.</p>
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-xs font-bold text-gray-200 uppercase">Character / Bot Names</label>
                                    <div class="flex gap-1">
                                        <button type="button" onclick="loadDefaultCharNames()" class="text-[10px] bg-gray-700 hover:bg-gray-600 text-white px-2 py-1 rounded" title="Load defaults">
                                            <i class="fas fa-undo"></i> Defaults
                                        </button>
                                        <button type="button" onclick="diceCharName()" class="text-[10px] bg-gray-700 hover:bg-gray-600 text-white px-2 py-1 rounded" title="Add random name">
                                            <i class="fas fa-dice"></i>
                                        </button>
                                    </div>
                                </div>
                                <textarea id="theme_chars" class="w-full bg-gray-900 border border-gray-700 rounded p-2 text-white h-32" placeholder="Player One&#10;Player Two&#10;Player Three"></textarea>
                                <p class="text-[10px] text-gray-300 mt-1">One per line. Used for bot players.</p>
                            </div>
                        </div>

                        <!-- Media Upload Section -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <!-- Banner Image/Video -->
                            <div class="p-3 bg-gray-900/50 rounded border border-gray-700 space-y-2">
                                <label class="block text-xs font-bold text-gray-200 uppercase">Banner Image/Video</label>
                                <div class="flex gap-2 items-center">
                                    <input type="file" id="theme_banner_file" accept="image/*,video/*" class="text-xs text-gray-200" onchange="previewThemeMedia(this, 'banner')">
                                    <button type="button" id="theme_banner_clear" onclick="clearThemeMedia('banner')" class="hidden text-red-400 hover:text-red-300 text-xs px-2 py-1 border border-red-400/50 rounded media-clear-btn" title="Clear banner"><i class="fas fa-times"></i></button>
                                </div>
                                <input type="hidden" id="theme_banner" value="">
                                <div id="theme_banner_preview" class="hidden">
                                    <div class="text-[10px] text-gray-300 mb-1">Current: <span id="theme_banner_name"></span></div>
                                    <div id="theme_banner_container"></div>
                                </div>
                            </div>

                            <!-- Intro Audio -->
                            <div class="p-3 bg-gray-900/50 rounded border border-gray-700 space-y-2">
                                <label class="block text-xs font-bold text-gray-200 uppercase">Intro Audio</label>
                                <div class="flex gap-2 items-center">
                                    <input type="file" id="theme_intro_file" accept="audio/*" class="text-xs text-gray-200" onchange="previewThemeMedia(this, 'intro')">
                                    <button type="button" id="theme_intro_clear" onclick="clearThemeMedia('intro')" class="hidden text-red-400 hover:text-red-300 text-xs px-2 py-1 border border-red-400/50 rounded media-clear-btn" title="Clear intro audio"><i class="fas fa-times"></i></button>
                                </div>
                                <input type="hidden" id="theme_intro_audio" value="">
                                <div id="theme_intro_preview" class="hidden">
                                    <div class="text-[10px] text-gray-300 mb-1">Current: <span id="theme_intro_name"></span></div>
                                    <audio id="theme_intro_player" controls class="w-full"></audio>
                                </div>
                            </div>

                            <!-- Win Audio -->
                            <div class="p-3 bg-gray-900/50 rounded border border-gray-700 space-y-2">
                                <label class="block text-xs font-bold text-gray-200 uppercase">Win Audio</label>
                                <div class="flex gap-2 items-center">
                                    <input type="file" id="theme_win_file" accept="audio/*" class="text-xs text-gray-200" onchange="previewThemeMedia(this, 'win')">
                                    <button type="button" id="theme_win_clear" onclick="clearThemeMedia('win')" class="hidden text-red-400 hover:text-red-300 text-xs px-2 py-1 border border-red-400/50 rounded media-clear-btn" title="Clear win audio"><i class="fas fa-times"></i></button>
                                </div>
                                <input type="hidden" id="theme_win_audio" value="">
                                <div id="theme_win_preview" class="hidden">
                                    <div class="text-[10px] text-gray-300 mb-1">Current: <span id="theme_win_name"></span></div>
                                    <audio id="theme_win_player" controls class="w-full"></audio>
                                </div>
                            </div>
                        </div>

                        <!-- Theme Default Settings -->
                        <div class="p-3 bg-gray-900/50 rounded border border-gray-700 space-y-4">
                            <label class="block text-xs font-bold text-gray-200 uppercase">Default Room/Game Settings</label>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-200 uppercase mb-1">Default Rounds to Win</label>
                                    <input type="number" id="theme_win_limit" class="w-full bg-gray-800 border border-gray-600 rounded p-2 text-sm text-white" min="1" max="50" value="5">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-200 uppercase mb-1">Default Hand Size</label>
                                    <input type="number" id="theme_hand_size" class="w-full bg-gray-800 border border-gray-600 rounded p-2 text-sm text-white" min="4" max="10" value="7">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-200 uppercase mb-1">Default Turn Timer</label>
                                    <select id="theme_timer" class="w-full bg-gray-800 border border-gray-600 rounded p-2 text-sm text-white">
                                        <option value="0">No Timer</option>
                                        <option value="30">30 Seconds</option>
                                        <option value="60">60 Seconds</option>
                                        <option value="90">90 Seconds</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 mt-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-200 uppercase mb-1">Mandatory Deck</label>
                                    <div class="flex gap-2">
                                        <input type="text" id="theme_deck_filter" placeholder="Filter decks..." oninput="filterMandatoryDecks(this.value)" class="flex-1 bg-gray-800 border border-gray-600 rounded p-2 text-xs text-white">
                                        <button type="button" onclick="clearMandatoryDeck()" class="bg-gray-700 hover:bg-gray-600 text-white text-[10px] font-bold px-2 rounded" title="Clear mandatory deck selection"><i class="fas fa-times"></i></button>
                                    </div>
                                    <select id="theme_mandatory_deck" class="w-full bg-gray-800 border border-gray-600 rounded p-2 text-xs text-white mt-1">
                                        <option value="">No Mandatory Deck</option>
                                        <?php foreach ($allDeckSlugs as $slug):
                                            if ($slug === 'base' || ($slug === 'base_deck' && ($deckStats[$slug]['black'] === 0 && $deckStats[$slug]['white'] === 0))) continue;
                                            $displayLabel = ucwords(str_replace('_', ' ', $slug));
                                        ?>
                                        <option value="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($displayLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="flex items-center space-x-4">
                                <label class="flex items-center text-xs font-bold text-gray-300 cursor-pointer">
                                    <input type="checkbox" id="theme_self_vote" checked class="w-4 h-4 accent-orange-500 rounded mr-2">
                                    Allow voting for yourself
                                </label>
                                <label class="flex items-center text-xs font-bold text-gray-300 cursor-pointer">
                                    <input type="checkbox" id="theme_enable_tts" checked class="w-4 h-4 accent-orange-500 rounded mr-2">
                                    Digital Host (Voice)
                                </label>
                            </div>
                        </div>

                        <!-- Theme Deck Selection -->
                        <div class="p-3 bg-gray-900/50 rounded border border-gray-700 space-y-2">
                            <label class="block text-xs font-bold text-gray-200 uppercase">Default Decks for Theme</label>
                            <p class="text-[10px] text-gray-300">These decks will be pre-selected when a user chooses this theme on the "New Game" page.</p>
                            <div id="theme-decks-container" class="max-h-40 overflow-y-auto custom-scrollbar space-y-1 pr-1">
                                <?php
                                foreach ($allDeckSlugs as $slug):
                                    if ($slug === 'base' || ($slug === 'base_deck' && ($deckStats[$slug]['black'] === 0 && $deckStats[$slug]['white'] === 0))) continue;
                                    $displayLabel = ucwords(str_replace('_', ' ', $slug));
                                    $stat = $deckStats[$slug];
                                ?>
                                <label class="flex items-center space-x-3 cursor-pointer p-1.5 rounded hover:bg-gray-700/50">
                                    <input type="checkbox" value="<?php echo htmlspecialchars($slug); ?>" class="theme-deck-checkbox w-4 h-4 accent-orange-500 rounded mr-2">
                                    <span class="text-sm font-bold text-gray-200"><?php echo htmlspecialchars($displayLabel); ?></span>
                                    <span class="text-[10px] text-gray-400">(<?php echo $stat['black']; ?>B / <?php echo $stat['white']; ?>W)</span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>


                        <div class="flex gap-2">
                            <button type="button" onclick="saveThemeWithFiles()" class="bg-green-600 hover:bg-green-500 text-white text-xs font-bold px-4 py-2 rounded">
                                <i class="fas fa-save mr-1"></i> Save Theme
                            </button>
                        </div>
                    </div>
                </div>

                <script>
                    const THEMES = <?php echo json_encode($themes, JSON_UNESCAPED_SLASHES); ?>;
                    const DEFAULT_THEME = <?php echo json_encode($defaultThemeKey); ?>;

                    function loadTheme() {
                        const key = document.getElementById('theme_select').value;
                        const t = THEMES[key] || {};
                        document.getElementById('theme_edit_key').value = key;
                        document.getElementById('theme_label').value = t.label || '';
                        document.getElementById('theme_suffix').value = t.game_name_suffix || 'Everyone';
                        document.getElementById('theme_rooms').value = (t.room_names || []).join('\n');
                        document.getElementById('theme_chars').value = (t.character_names || []).join('\n');
                        
                        const mandatoryDeckEl = document.getElementById('theme_mandatory_deck');
                        if (mandatoryDeckEl) mandatoryDeckEl.value = t.mandatory_deck || '';
                        const deckFilterEl = document.getElementById('theme_deck_filter');
                        if (deckFilterEl) deckFilterEl.value = '';
                        if (typeof filterMandatoryDecks === 'function') {
                            try { filterMandatoryDecks(''); } catch(e){}
                        }
                        document.getElementById('theme_banner').value = t.banner_media || '';
                        document.getElementById('theme_intro_audio').value = t.intro_audio_url || '';
                        document.getElementById('theme_win_audio').value = t.win_audio_url || '';
                        
                        document.getElementById('theme_win_limit').value = t.win_limit !== undefined ? t.win_limit : 5;
                        const timerEl = document.getElementById('theme_timer');
                        if (timerEl) timerEl.value = t.timer !== undefined ? t.timer : 60;
                        document.getElementById('theme_hand_size').value = t.hand_size !== undefined ? t.hand_size : 7;
                        document.getElementById('theme_self_vote').checked = t.self_vote !== undefined ? t.self_vote : true;
                        document.getElementById('theme_enable_tts').checked = t.enable_tts !== undefined ? t.enable_tts : true;
                        
                        const defaultDecks = Array.isArray(t.default_decks) && t.default_decks.length > 0 ? t.default_decks : ['base_deck'];
                        document.querySelectorAll('.theme-deck-checkbox').forEach(cb => {
                            cb.checked = defaultDecks.includes(cb.value);
                        });
                    }

                    function editTheme() {
                        loadTheme();
                        document.getElementById('theme_form').classList.remove('hidden');
                        document.getElementById('theme_form_title').textContent = 'Edit Theme';
                        const key = document.getElementById('theme_select').value;
                        // The main delete button is always visible, just enable/disable it.
                        document.getElementById('delete_theme_btn_main').disabled = (key === 'default');
                    }

                    function newTheme() {
                        document.getElementById('delete_theme_btn_main').disabled = true;
                        const defaultTheme = THEMES['default'] || {};
                        document.getElementById('theme_edit_key').value = '';
                        document.getElementById('theme_label').value = '';
                        document.getElementById('theme_suffix').value = 'Everyone';
                        document.getElementById('theme_rooms').value = '';
                        document.getElementById('theme_chars').value = '';
                        // Copy default theme media
                        document.getElementById('theme_banner').value = defaultTheme.banner_media || '';
                        document.getElementById('theme_intro_audio').value = defaultTheme.intro_audio_url || '';
                        document.getElementById('theme_win_audio').value = defaultTheme.win_audio_url || '';
                        
                        document.getElementById('theme_win_limit').value = 5;
                        const timerEl = document.getElementById('theme_timer');
                        if (timerEl) timerEl.value = 60;
                        document.getElementById('theme_hand_size').value = 7;
                        document.getElementById('theme_self_vote').checked = true;
                        document.getElementById('theme_enable_tts').checked = true;
                        document.querySelectorAll('.theme-deck-checkbox').forEach(cb => {
                            cb.checked = (cb.value === 'base_deck');
                        });

                        document.getElementById('theme_form').classList.remove('hidden');
                        document.getElementById('theme_form_title').textContent = 'New Theme';
                        document.getElementById('theme_label').focus();
                    }

                    function cancelThemeEdit() {
                        document.getElementById('theme_form').classList.add('hidden');
                    }

                    function filterMandatoryDecks(query) {
                        const select = document.getElementById('theme_mandatory_deck');
                        const lowerQuery = query.toLowerCase();
                        Array.from(select.options).forEach(opt => {
                            if (opt.value === '') {
                                opt.style.display = ''; // Always show "no mandatory deck"
                            } else {
                                opt.style.display = opt.text.toLowerCase().includes(lowerQuery) ? '' : 'none';
                            }
                        });
                    }

                    function clearMandatoryDeck() {
                        document.getElementById('theme_mandatory_deck').value = '';
                        document.getElementById('theme_deck_filter').value = '';
                        filterMandatoryDecks('');
                    }

                    async function setAsDefault() {
                        const key = document.getElementById('theme_select').value;
                        const fd = new FormData();
                        fd.append('action', 'set_default_theme');
                        fd.append('ajax', '1');
                        fd.append('theme_key', key);

                        const res = await fetch('settings.php', {
                            method: 'POST',
                            body: fd
                        });
                        if (res.ok) {
                            location.reload();
                        }
                    }

                    async function deleteTheme() {
                        const key = document.getElementById('theme_select').value;
                        if (key === 'default') {
                            alert('Cannot delete the default theme');
                            return;
                        }
                        if (!confirm('Delete this theme?')) return;

                        const fd = new FormData();
                        fd.append('action', 'delete_theme');
                        fd.append('theme_key', key);

                        const res = await fetch('settings.php', {
                            method: 'POST',
                            body: fd
                        });
                        if (res.ok) {
                            location.reload();
                        }
                    }

                    // Clear theme media
                    function clearThemeMedia(type) {
                        if (type === 'banner') {
                            document.getElementById('theme_banner_file').value = '';
                            document.getElementById('theme_banner').value = '';
                            document.getElementById('theme_banner_preview').classList.add('hidden');
                            document.getElementById('theme_banner_container').innerHTML = '';
                            document.getElementById('theme_banner_clear').classList.add('hidden');
                        } else if (type === 'intro') {
                            document.getElementById('theme_intro_file').value = '';
                            document.getElementById('theme_intro_audio').value = '';
                            document.getElementById('theme_intro_preview').classList.add('hidden');
                            document.getElementById('theme_intro_player').src = '';
                            document.getElementById('theme_intro_clear').classList.add('hidden');
                        } else if (type === 'win') {
                            document.getElementById('theme_win_file').value = '';
                            document.getElementById('theme_win_audio').value = '';
                            document.getElementById('theme_win_preview').classList.add('hidden');
                            document.getElementById('theme_win_player').src = '';
                            document.getElementById('theme_win_clear').classList.add('hidden');
                        }
                    }

                    // Preview theme media when file selected
                    function previewThemeMedia(input, type) {
                        const file = input.files[0];
                        if (!file) return;

                        if (type === 'banner') {
                            const container = document.getElementById('theme_banner_container');
                            const preview = document.getElementById('theme_banner_preview');
                            const nameEl = document.getElementById('theme_banner_name');
                            nameEl.textContent = file.name;
                            container.innerHTML = '';

                            const url = URL.createObjectURL(file);
                            if (file.type.startsWith('video/')) {
                                const video = document.createElement('video');
                                video.src = url;
                                video.controls = true;
                                video.muted = true;
                                video.className = 'w-full rounded';
                                container.appendChild(video);
                            } else {
                                const img = document.createElement('img');
                                img.src = url;
                                img.className = 'w-full rounded border border-gray-700';
                                container.appendChild(img);
                            }
                            preview.classList.remove('hidden');
                            document.getElementById('theme_banner_clear').classList.remove('hidden');
                        } else if (type === 'intro') {
                            const preview = document.getElementById('theme_intro_preview');
                            const nameEl = document.getElementById('theme_intro_name');
                            const player = document.getElementById('theme_intro_player');
                            nameEl.textContent = file.name;
                            player.src = URL.createObjectURL(file);
                            preview.classList.remove('hidden');
                            document.getElementById('theme_intro_clear').classList.remove('hidden');
                        } else if (type === 'win') {
                            const preview = document.getElementById('theme_win_preview');
                            const nameEl = document.getElementById('theme_win_name');
                            const player = document.getElementById('theme_win_player');
                            nameEl.textContent = file.name;
                            player.src = URL.createObjectURL(file);
                            preview.classList.remove('hidden');
                            document.getElementById('theme_win_clear').classList.remove('hidden');
                        }
                    }

                    // Show existing media when editing theme
                    function showExistingMedia(t) {
                        // Banner
                        if (t.banner_media) {
                            const container = document.getElementById('theme_banner_container');
                            const preview = document.getElementById('theme_banner_preview');
                            const nameEl = document.getElementById('theme_banner_name');
                            nameEl.textContent = t.banner_media.split('/').pop();
                            container.innerHTML = '';

                            const ext = t.banner_media.split('.').pop().toLowerCase();
                            if (['mp4', 'webm', 'mov'].includes(ext)) {
                                const video = document.createElement('video');
                                video.src = t.banner_media;
                                video.controls = true;
                                video.muted = true;
                                video.className = 'w-full rounded';
                                container.appendChild(video);
                            } else {
                                const img = document.createElement('img');
                                img.src = t.banner_media;
                                img.className = 'w-full rounded border border-gray-700';
                                container.appendChild(img);
                            }
                            preview.classList.remove('hidden');
                            document.getElementById('theme_banner_clear').classList.remove('hidden');
                        } else {
                            document.getElementById('theme_banner_preview').classList.add('hidden');
                            document.getElementById('theme_banner_clear').classList.add('hidden');
                        }

                        // Intro audio
                        if (t.intro_audio_url) {
                            const preview = document.getElementById('theme_intro_preview');
                            const nameEl = document.getElementById('theme_intro_name');
                            const player = document.getElementById('theme_intro_player');
                            nameEl.textContent = t.intro_audio_url.split('/').pop();
                            player.src = t.intro_audio_url;
                            preview.classList.remove('hidden');
                            document.getElementById('theme_intro_clear').classList.remove('hidden');
                        } else {
                            document.getElementById('theme_intro_preview').classList.add('hidden');
                            document.getElementById('theme_intro_clear').classList.add('hidden');
                        }

                        // Win audio
                        if (t.win_audio_url) {
                            const preview = document.getElementById('theme_win_preview');
                            const nameEl = document.getElementById('theme_win_name');
                            const player = document.getElementById('theme_win_player');
                            nameEl.textContent = t.win_audio_url.split('/').pop();
                            player.src = t.win_audio_url;
                            preview.classList.remove('hidden');
                            document.getElementById('theme_win_clear').classList.remove('hidden');
                        } else {
                            document.getElementById('theme_win_preview').classList.add('hidden');
                            document.getElementById('theme_win_clear').classList.add('hidden');
                        }
                    }

                    // Clear file inputs and previews
                    function clearMediaInputs() {
                        document.getElementById('theme_banner_file').value = '';
                        document.getElementById('theme_intro_file').value = '';
                        document.getElementById('theme_win_file').value = '';
                        document.getElementById('theme_banner_preview').classList.add('hidden');
                        document.getElementById('theme_intro_preview').classList.add('hidden');
                        document.getElementById('theme_win_preview').classList.add('hidden');
                        document.getElementById('theme_banner_clear').classList.add('hidden');
                        document.getElementById('theme_intro_clear').classList.add('hidden');
                        document.getElementById('theme_win_clear').classList.add('hidden');
                    }

                    // Override loadTheme to show existing media
                    const originalLoadTheme = loadTheme;
                    loadTheme = function() {
                        originalLoadTheme();
                        const key = document.getElementById('theme_select').value;
                        const t = THEMES[key] || {};
                        clearMediaInputs();
                        showExistingMedia(t);
                    };

                    // Override editTheme to show existing media
                    const originalEditTheme = editTheme;
                    editTheme = function() {
                        originalEditTheme();
                        const key = document.getElementById('theme_select').value;
                        const t = THEMES[key] || {};
                        showExistingMedia(t);
                    };

                    // Override newTheme to clear media
                    const originalNewTheme = newTheme;
                    newTheme = function() {
                        originalNewTheme();
                        clearMediaInputs();
                    };

                    // Load default character names
                    function loadDefaultCharNames() {
                        const defaults = [
                            'Player One', 'Player Two', 'Player Three', 'Player Four', 'Player Five',
                            'Player Six', 'Player Seven', 'Player Eight', 'Player Nine', 'Player Ten'
                        ];
                        document.getElementById('theme_chars').value = defaults.join('\n');
                    }

                    // Add a random character name
                    function diceCharName() {
                        const names = ['Alex', 'Sam', 'Jordan', 'Taylor', 'Morgan', 'Casey', 'Riley', 'Avery', 'Quinn', 'Dakota',
                                       'Skyler', 'Charlie', 'Parker', 'Reese', 'Finley', 'Rowan', 'Phoenix', 'River', 'Sage', 'Blake'];
                        const randomName = names[Math.floor(Math.random() * names.length)];
                        const textarea = document.getElementById('theme_chars');
                        const current = textarea.value.trim();
                        textarea.value = current ? current + '\n' + randomName : randomName;
                    }

                    // Save theme with file uploads
                    async function saveThemeWithFiles() {
                        const label = document.getElementById('theme_label').value.trim();
                        if (!label) {
                            alert('Theme name is required');
                            return;
                        }

                        const fd = new FormData();
                        fd.append('action', 'save_theme');
                        fd.append('edit_key', document.getElementById('theme_edit_key').value);
                        fd.append('label', label);
                        fd.append('ajax', '1');
                        fd.append('game_name_suffix', document.getElementById('theme_suffix').value || 'Everyone');
                        fd.append('room_names', document.getElementById('theme_rooms').value);
                        fd.append('character_names', document.getElementById('theme_chars').value);
                        fd.append('banner_media', document.getElementById('theme_banner').value);
                        fd.append('theme_intro_audio', document.getElementById('theme_intro_audio').value);
                        fd.append('theme_win_audio', document.getElementById('theme_win_audio').value);
                        fd.append('mandatory_deck', document.getElementById('theme_mandatory_deck') ? document.getElementById('theme_mandatory_deck').value : '');
                        fd.append('win_limit', document.getElementById('theme_win_limit').value);
                        fd.append('timer', document.getElementById('theme_timer') ? document.getElementById('theme_timer').value : '60');
                        fd.append('hand_size', document.getElementById('theme_hand_size').value);
                        fd.append('self_vote', document.getElementById('theme_self_vote').checked ? 'true' : 'false');
                        fd.append('enable_tts', document.getElementById('theme_enable_tts').checked ? 'true' : 'false');

                        const selectedDecks = [];
                        document.querySelectorAll('.theme-deck-checkbox:checked').forEach(cb => {
                            selectedDecks.push(cb.value);
                        });
                        fd.append('default_decks', JSON.stringify(selectedDecks));

                        // Add files if selected
                        const bannerFile = document.getElementById('theme_banner_file').files[0];
                        const introFile = document.getElementById('theme_intro_file').files[0];
                        const winFile = document.getElementById('theme_win_file').files[0];

                        if (bannerFile) fd.append('theme_banner_file', bannerFile);
                        if (introFile) fd.append('theme_intro_file', introFile);
                        if (winFile) fd.append('theme_win_file', winFile);

                        const btn = document.querySelector('#theme_form button[onclick="saveThemeWithFiles()"]');
                        const originalText = btn.innerHTML;
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';

                        try {
                            const res = await fetch('settings.php', {
                                method: 'POST',
                                body: fd
                            });
                            if (res.ok) {
                                location.reload();
                            } else {
                                alert('Failed to save theme');
                                btn.innerHTML = originalText;
                                btn.disabled = false;
                            }
                        } catch (err) {
                            console.error(err);
                            alert('Error saving theme');
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        }
                    }
                </script>
            <?php }); ?>

            <!-- 4. ADMIN SECURITY -->
            <?php renderAccordion('security', 'Admin Security', 'shield-alt', function () use ($globalConfig) { ?>
                <form method="POST" class="space-y-4" onsubmit="saveForm(event)">
                    <input type="hidden" name="action" value="save_global">
                    <input type="hidden" name="ajax" value="1">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-200 uppercase mb-2">Admin Password</label>
                            <input type="password" name="admin_password" value="<?php echo htmlspecialchars($globalConfig['admin_password'] ?? 'orange'); ?>" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white">
                            <p class="text-[10px] text-gray-300 mt-1">Change the password required to access this Settings panel.</p>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-200 uppercase mb-2">Gemini API Key</label>
                            <input type="password" name="gemini_api_key" value="<?php echo htmlspecialchars($globalConfig['gemini_api_key'] ?? ''); ?>" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white" placeholder="AIzaSy...">
                            <p class="text-[10px] text-gray-300 mt-1">API key for Gemini AI roasts, smart bot players, and chat responsiveness.</p>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-2 px-4 rounded">
                            Save Security Settings
                        </button>
                        <button type="button" onclick="testGeminiConnection()" class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold py-2 px-4 rounded">
                            Test Gemini API
                        </button>
                    </div>
                    <div id="gemini-test-result" class="text-xs mt-2 hidden" style="white-space: pre-wrap;"></div>
                </form>
            <?php }); ?>

            <!-- WordPress Integration -->
            <?php renderAccordion('wordpress', 'WordPress Integration', 'wordpress', function () use ($globalConfig) { ?>
                <form method="POST" class="space-y-4" onsubmit="saveForm(event)">
                    <input type="hidden" name="action" value="save_global">
                    <input type="hidden" name="ajax" value="1">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-200 uppercase mb-2">WordPress Site URL</label>
                            <input type="url" name="wp_publish_url" value="<?php echo htmlspecialchars($globalConfig['wp_publish_url'] ?? 'https://netbound.ca'); ?>" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white" placeholder="https://netbound.ca">
                            <p class="text-[10px] text-gray-300 mt-1">URL of the site where game reports will be published.</p>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-200 uppercase mb-2">WordPress Username</label>
                            <input type="text" name="wp_publish_username" value="<?php echo htmlspecialchars($globalConfig['wp_publish_username'] ?? ''); ?>" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white" placeholder="admin">
                            <p class="text-[10px] text-gray-300 mt-1">Username used for REST API authentication.</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-200 uppercase mb-2">Application Password</label>
                        <input type="password" name="wp_publish_password" value="<?php echo htmlspecialchars($globalConfig['wp_publish_password'] ?? ''); ?>" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white" placeholder="xxxx xxxx xxxx xxxx">
                        <p class="text-[10px] text-gray-300 mt-1">Hidden Application Password generated under WordPress User Profile.</p>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-2 px-4 rounded">
                            Save WordPress Settings
                        </button>
                        <button type="button" onclick="testWordPressConnection()" class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold py-2 px-4 rounded">
                            Test Connection
                        </button>
                    </div>
                    <div id="wp-test-result" class="text-xs mt-2 hidden"></div>
                </form>
            <?php }); ?>

            <!-- Voice Scripts -->
            <?php renderAccordion('voice_scripts_sec', 'Voice Scripts', 'comment-dots', function () use ($voiceScripts) { ?>
                <form method="POST" class="space-y-6" onsubmit="saveForm(event)">
                    <input type="hidden" name="action" value="save_voice_scripts">
                    <input type="hidden" name="ajax" value="1">

                    <p class="text-xs text-gray-400 mb-4">
                        Customise dynamic voice comments announced by the host during various stages of the game. Use placeholders like <code>[name]</code>, <code>[winner]</code>, <code>[sentence]</code>, <code>[round]</code>, <code>[text]</code>, <code>[GAME_TITLE]</code>, or <code>[champ]</code> where applicable.
                    </p>

                    <div class="space-y-4">
                        <?php
                        $categories = [
                            'welcome' => ['label' => 'Start of Game', 'desc' => 'Announced when a new game starts. Placeholders: [GAME_TITLE]'],
                            'voting' => ['label' => 'Time to Vote', 'desc' => 'Announced when cards are revealed and players begin voting.'],
                            'afk_warning' => ['label' => 'Waiting On... (AFK Warning)', 'desc' => 'Played when waiting for a slow player. Placeholders: [name]'],
                            'afk_sub' => ['label' => 'Players AFK (Bot Substituted)', 'desc' => 'Announced when a bot replaces an AFK player. Placeholders: [name], [subbingFor]'],
                            'paused' => ['label' => 'Pause', 'desc' => 'Announced when a player pauses the game. Placeholders: [pauser]'],
                            'unpaused' => ['label' => 'Unpause', 'desc' => 'Announced when the game is resumed.'],
                            'join' => ['label' => 'Player Joined', 'desc' => 'Announced when a new player joins the lobby. Placeholders: [name]'],
                            'leave' => ['label' => 'Player Left', 'desc' => 'Announced when a player leaves the lobby or game. Placeholders: [name]'],
                            'new_round' => ['label' => 'New Round Started', 'desc' => 'Announced at the beginning of each round. Placeholders: [round], [text]'],
                            'winner' => ['label' => 'Round Winner Announced', 'desc' => 'Announced when a round is won. Placeholders: [winner], [sentence]'],
                            'tie' => ['label' => 'Round Tied', 'desc' => 'Announced when a voting result is tied.'],
                            'bot_leading' => ['label' => 'Bot Nearing Win', 'desc' => 'Announced when a bot is match point away from winning.'],
                            'timer_low' => ['label' => 'Turn Timer Low', 'desc' => 'Announced when only 15 seconds remain in the turn.'],
                            'game_over' => ['label' => 'Game Over', 'desc' => 'Announced when the game concludes. Placeholders: [champ]'],
                        ];

                        foreach ($categories as $catKey => $catInfo):
                            $phrases = $voiceScripts[$catKey] ?? [];
                        ?>
                            <div class="bg-gray-900/60 p-4 rounded-lg border border-gray-700/80 space-y-3">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h4 class="text-xs font-black text-orange-400 uppercase tracking-widest"><?php echo htmlspecialchars($catInfo['label']); ?></h4>
                                        <p class="text-[10px] text-gray-400 mt-1"><?php echo htmlspecialchars($catInfo['desc']); ?></p>
                                    </div>
                                    <button type="button" onclick="addVoicePhraseRow('<?php echo htmlspecialchars($catKey); ?>')" class="text-xs bg-gray-800 hover:bg-gray-750 text-orange-400 font-bold px-2 py-1 rounded inline-flex items-center gap-1 border border-gray-700">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>

                                <div id="phrases-container-<?php echo htmlspecialchars($catKey); ?>" class="space-y-2">
                                    <?php if (empty($phrases)): ?>
                                        <div class="no-phrases-notice text-[10px] text-gray-500 italic py-1">No custom scripts added. Add one or the game will default to system phrases.</div>
                                    <?php else: ?>
                                        <?php foreach ($phrases as $i => $phrase): ?>
                                            <div class="flex items-center gap-2 phrase-row-item">
                                                <textarea name="vs[<?php echo htmlspecialchars($catKey); ?>][]" class="flex-1 bg-gray-800 border border-gray-700 rounded p-1.5 text-xs text-white resize-none" rows="2" placeholder="Announce phrase..."><?php echo htmlspecialchars($phrase); ?></textarea>
                                                <button type="button" onclick="this.closest('.phrase-row-item').remove()" class="text-red-500 hover:text-red-400 hover:bg-red-950/20 p-2 rounded" title="Delete phrase"><i class="fas fa-trash"></i></button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-6 pt-4 border-t border-gray-800 flex justify-end">
                        <button type="submit" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-2.5 px-6 rounded shadow-md transform hover:scale-[1.01] transition-transform">
                            <i class="fas fa-save mr-1"></i> Save Voice Library
                        </button>
                    </div>
                </form>
                <script>
                    function addVoicePhraseRow(category) {
                        const container = document.getElementById('phrases-container-' + category);
                        const notice = container.querySelector('.no-phrases-notice');
                        if (notice) {
                            notice.remove();
                        }
                        const div = document.createElement('div');
                        div.className = 'flex items-center gap-2 phrase-row-item';
                        div.innerHTML = `
                            <textarea name="vs[\${category}][]" class="flex-1 bg-gray-800 border border-gray-700 rounded p-1.5 text-xs text-white resize-none" rows="2" placeholder="Announce phrase..." required></textarea>
                            <button type="button" onclick="this.closest('.phrase-row-item').remove(); checkEmptyCategoryNotice('\${category}')" class="text-red-500 hover:text-red-400 hover:bg-red-950/20 p-2 rounded" title="Delete phrase"><i class="fas fa-trash"></i></button>
                        `;
                        container.appendChild(div);
                        div.querySelector('textarea').focus();
                    }

                    function checkEmptyCategoryNotice(category) {
                        const container = document.getElementById('phrases-container-' + category);
                        if (container.children.length === 0) {
                            container.innerHTML = `<div class="no-phrases-notice text-[10px] text-gray-500 italic py-1">No custom scripts added. Add one or the game will default to system phrases.</div>`;
                        }
                    }
                </script>
            <?php }); ?>

            <!-- 6. DECK MANAGEMENT -->
            <?php renderAccordion('decks', 'Deck Management', 'layer-group', function () use ($deckStats, $availDecks, $globalConfig) { ?>

                <!-- Deck List & Availability -->
                <div class="mb-6">
                    <!-- Deck Integrity Section -->
                    <div class="mb-6 border-b border-gray-700 pb-4">
                        <h3 class="text-xs font-bold text-gray-200 uppercase mb-3 flex items-center">
                            <i class="fas fa-check-double mr-2 text-orange-400"></i> Deck Integrity
                        </h3>
                        <p class="text-xs text-gray-400 mb-3">Scan all deck files for duplicate cards. This helps clean up your collection and reduce redundancy.</p>
                        <div class="flex gap-2">
                            <button type="button" onclick="checkDecksForDuplicates()" class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold py-2 px-4 rounded transition-colors">
                                <i class="fas fa-search mr-1"></i> Scan For Duplicates
                            </button>
                            <button type="button" onclick="autoRemoveDuplicates()" class="bg-red-600 hover:bg-red-500 text-white text-xs font-bold py-2 px-4 rounded transition-colors">
                                <i class="fas fa-magic mr-1"></i> Auto-Deduplicate decks.md
                            </button>
                        </div>
                        <div id="duplicate-check-results" class="hidden mt-3 p-3 bg-gray-950/80 rounded border border-purple-500/30 text-xs text-gray-300 max-h-96 overflow-y-auto space-y-2"></div>
                    </div>

                    <h3 class="text-xs font-bold text-gray-200 uppercase mb-3 flex justify-between items-center">
                        <span>Available Decks</span>
                        <span class="text-[10px] font-normal normal-case text-gray-300">Check to enable in game</span>
                    </h3>

                    <form method="POST" class="space-y-2">
                        <input type="hidden" name="action" value="save_decks">

                        <div class="max-h-60 overflow-y-auto custom-scrollbar space-y-2 pr-1">
                            <?php foreach ($deckStats as $tag => $stat):
                                if ($tag === 'base' || $tag === 'base_deck' && ($stat['black'] === 0 && $stat['white'] === 0)) continue;
                                $checked = ($availDecks[$tag] ?? true) ? 'checked' : '';
                                $displayLabel = ucwords(str_replace('-', ' ', $tag));
                            ?>
                                <div class="bg-gray-800 rounded border border-gray-700 flex items-center p-2 hover:bg-gray-750 transition-colors">
                                    <label class="flex items-center space-x-3 cursor-pointer flex-1 min-w-0">
                                        <input type="checkbox" name="available_decks[]" value="<?php echo $tag; ?>" <?php echo $checked; ?> class="accent-orange-500 w-4 h-4 flex-shrink-0">
                                        <div class="flex flex-col min-w-0">
                                            <span class="text-sm font-bold text-gray-200 truncate"><?php echo $displayLabel; ?></span>
                                            <span class="text-[10px] text-gray-300"><?php echo $stat['black']; ?> Black / <?php echo $stat['white']; ?> White</span>
                                        </div>
                                    </label>

                                    <div class="flex items-center space-x-1 ml-2">
                                        <button type="button" onclick="inspectDeck('<?php echo $tag; ?>')" class="p-2 text-blue-400 hover:text-blue-300 hover:bg-blue-900/30 rounded" title="View/Edit Cards">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($tag !== 'base_deck' && $tag !== 'orange_deck'): ?>
                                            <button type="button" onclick="deleteDeck('<?php echo $tag; ?>')" class="p-2 text-red-400 hover:text-red-300 hover:bg-red-900/30 rounded" title="Delete Deck">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" class="mt-3 w-full bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-2 px-4 rounded">
                            <i class="fas fa-save mr-1"></i> Save Deck Availability
                        </button>

                        <?php
                        $deletedDecksFile = __DIR__ . '/data/deleted_decks.json';
                        $deletedDecks = file_exists($deletedDecksFile) ? (json_decode(file_get_contents($deletedDecksFile), true) ?: []) : [];
                        if (!empty($deletedDecks)):
                        ?>
                            <div class="mt-4 border-t border-gray-700 pt-3">
                                <h4 class="text-[10px] font-bold text-red-400 uppercase mb-2">Deleted Decks</h4>
                                <div class="space-y-1">
                                    <?php foreach ($deletedDecks as $delSlug): 
                                        $displayLabel = ucwords(str_replace('_', ' ', $delSlug));
                                    ?>
                                        <div class="bg-gray-800 rounded border border-red-950 flex items-center justify-between p-2 text-xs">
                                            <span class="font-bold text-gray-200"><?php echo $displayLabel; ?></span>
                                            <button type="button" onclick="restoreDeck('<?php echo $delSlug; ?>')" class="text-xs text-green-400 hover:text-green-300 uppercase font-bold">
                                                Restore
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Global Card Search -->
                <div class="mt-6 border-t border-gray-700 pt-4">
                    <h3 class="text-xs font-bold text-gray-200 uppercase mb-3 flex items-center">
                        <i class="fas fa-search mr-2 text-orange-400"></i> Search Cards Across All Decks
                    </h3>
                    <div class="flex gap-2 mb-3">
                        <input type="text" id="global-card-search-input" placeholder="Enter keywords (e.g. horse)..." class="w-full bg-gray-900 border border-gray-700 rounded p-2 text-xs text-white" onkeydown="if(event.key==='Enter') performGlobalCardSearch()">
                        <button type="button" onclick="performGlobalCardSearch()" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold px-4 py-2 rounded">
                            Search
                        </button>
                        <button type="button" onclick="clearGlobalCardSearch()" class="bg-gray-600 hover:bg-gray-500 text-white text-xs font-bold px-4 py-2 rounded">
                            Clear
                        </button>
                    </div>
                    <div id="global-search-results" class="max-h-60 overflow-y-auto custom-scrollbar space-y-1">
                        <div class="text-[10px] text-gray-400 italic text-center py-2">Enter keyword above and click Search.</div>
                    </div>
                </div>

                <!-- Add Card Inline Form -->
                <div class="bg-gray-850 p-4 rounded-lg border border-gray-700 mb-6">
                    <h4 class="text-xs font-bold text-orange-400 uppercase mb-3 flex items-center">
                        <i class="fas fa-plus-circle mr-2"></i> Add Single Custom Card
                    </h4>
                    <form method="POST" class="space-y-3" onsubmit="saveForm(event)">
                        <input type="hidden" name="action" value="add_card">
                        <input type="hidden" name="ajax" value="1">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">Card Text</label>
                            <input type="text" id="single_card_text_input" name="card_text" placeholder="e.g. A tiny horse ______." required class="w-full bg-gray-900 border border-gray-700 rounded p-2 text-xs text-white mb-2">
                            <button type="button" onclick="insertBlank('single_card_text_input')" class="bg-gray-700 hover:bg-gray-600 text-white text-[9px] font-bold py-1 px-2.5 rounded">
                                <i class="fas fa-underscore mr-1"></i> Insert Blank (______)
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <label class="inline-flex items-center mr-3 text-xs text-gray-300 cursor-pointer">
                                    <input type="radio" name="card_type" value="White" checked class="accent-orange-500 mr-1.5"> White Card
                                </label>
                                <label class="inline-flex items-center text-xs text-gray-300 cursor-pointer">
                                    <input type="radio" name="card_type" value="Black" class="accent-orange-500 mr-1.5"> Black Card
                                </label>
                            </div>
                            <button type="submit" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-1.5 px-4 rounded">
                                Add Card
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Import Deck Block -->
                <div class="bg-gray-850 p-4 rounded-lg border border-gray-700">
                    <button type="button" onclick="document.getElementById('import-area').classList.toggle('hidden')" class="w-full flex items-center justify-between text-xs font-bold text-gray-200 uppercase">
                        <i class="fas fa-file-import mr-2"></i> Import Full Deck
                        <i class="fas fa-chevron-down ml-auto"></i>
                    </button>

                    <div id="import-area" class="hidden mt-3">
                        <div class="text-[10px] text-gray-300 mb-2">
                            Format: "The [Name] Black Cards List" followed by cards, then "The [Name] White Cards List" followed by cards.
                        </div>
                        <form method="POST" class="space-y-3" onsubmit="saveForm(event, 'deck_content_textarea')">
                            <input type="hidden" name="action" value="import_deck">
                            <input type="hidden" name="ajax" value="1">
                            <textarea id="deck_content_textarea" name="deck_content" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-xs text-white h-32 font-mono" placeholder="Paste deck content here..."></textarea>
                            <label class="flex items-center space-x-2 text-xs text-gray-300 cursor-pointer mb-2">
                                <input type="checkbox" name="sanitize_import" value="1" checked class="accent-orange-500 rounded">
                                <span>Auto-Sanitize/Correct Import Syntax (add missing blanks, separate cards, strip invalid blanks)</span>
                            </label>
                            <div class="flex gap-2">
                                <button type="button" onclick="insertBlank('deck_content_textarea')" class="bg-gray-700 hover:bg-gray-600 text-white text-xs font-bold py-2 px-4 rounded">
                                    <i class="fas fa-underscore mr-1"></i> Insert Blank
                                </button>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold py-2 px-4 rounded flex-1">
                                    Import Deck
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Deck Inspector Modal (Hidden by default, shown via JS) -->
                <div id="deck-inspector-modal" class="fixed inset-0 bg-black/80 z-50 hidden flex items-center justify-center p-4">
                    <div class="bg-gray-900 rounded-xl border border-gray-700 w-full max-w-4xl max-h-[90vh] flex flex-col shadow-2xl">
                        <div class="p-4 border-b border-gray-800 flex justify-between items-center bg-gray-800 rounded-t-xl">
                            <h3 class="font-bold text-orange-500" id="inspector-title">Deck Inspector</h3>
                            <button type="button" onclick="closeInspector()" class="text-gray-200 hover:text-white">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <div class="p-4 border-b border-gray-800 bg-gray-800/50">
                            <input type="text" id="inspector-search-modal" placeholder="Search cards in this deck..." class="w-full bg-gray-900 border border-gray-700 rounded p-2 text-sm text-white focus:border-orange-500 outline-none" oninput="filterInspectorModal()">
                        </div>

                        <div class="flex-1 overflow-hidden flex flex-col md:flex-row">
                            <!-- Black Cards Column -->
                            <div class="flex-1 border-r border-gray-800 flex flex-col min-h-0">
                                <div class="p-2 bg-gray-800/30 text-xs font-bold text-gray-200 uppercase text-center sticky top-0">Black Cards</div>
                                <div id="inspector-black-list" class="flex-1 overflow-y-auto p-2 space-y-1 custom-scrollbar"></div>
                            </div>

                            <!-- White Cards Column -->
                            <div class="flex-1 flex flex-col min-h-0">
                                <div class="p-2 bg-gray-800/30 text-xs font-bold text-gray-200 uppercase text-center sticky top-0">White Cards</div>
                                <div id="inspector-white-list" class="flex-1 overflow-y-auto p-2 space-y-1 custom-scrollbar"></div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php }); ?>

            <!-- 7. USER MODERATION -->
            <?php renderAccordion('moderation', 'User Moderation', 'shield-alt', function () use ($globalConfig) { ?>
                <form method="POST" class="space-y-4" onsubmit="saveForm(event)">
                    <input type="hidden" name="action" value="save_global">
                    <input type="hidden" name="ajax" value="1">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-200 uppercase mb-2">Banned IPs (one per line)</label>
                            <textarea name="banned_ips" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-xs text-white h-24 font-mono"><?php echo implode("\n", $globalConfig['banned_ips'] ?? []); ?></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-200 uppercase mb-2">Blocked Users (one per line)</label>
                            <textarea name="blocked_users" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-xs text-white h-24"><?php echo implode("\n", $globalConfig['blocked_users'] ?? []); ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Reserved Users -->
                    <div class="border-t border-gray-700 pt-4 mt-4">
                        <h3 class="text-xs font-bold text-gray-200 uppercase mb-2">Reserved Users</h3>
                        <p class="text-[10px] text-gray-400 mb-3">Create special users with a password. If a player enters this password in the username field, they will be assigned the reserved name and avatar.</p>
                        <div id="reserved-users-container" class="space-y-2">
                            <?php foreach (($globalConfig['reserved_users'] ?? []) as $ru): ?>
                            <div class="grid grid-cols-3 gap-2 items-center reserved-user-row">
                                <input type="text" name="ru_username[]" placeholder="Username" class="bg-gray-900 border border-gray-700 rounded p-2 text-xs text-white" value="<?php echo htmlspecialchars($ru['username']); ?>">
                                <input type="text" name="ru_password[]" placeholder="Password" class="bg-gray-900 border border-gray-700 rounded p-2 text-xs text-white" value="<?php echo htmlspecialchars($ru['password']); ?>">
                                <div class="flex items-center gap-2">
                                    <select name="ru_avatar[]" class="flex-1 bg-gray-900 border border-gray-700 rounded p-2 text-xs text-white">
                                        <option value="dicebear" <?php echo ($ru['avatar'] ?? '') === 'dicebear' ? 'selected' : ''; ?>>DiceBear</option>
                                        <option value="gen_m" <?php echo ($ru['avatar'] ?? '') === 'gen_m' ? 'selected' : ''; ?>>Generic Male</option>
                                        <option value="gen_f" <?php echo ($ru['avatar'] ?? '') === 'gen_f' ? 'selected' : ''; ?>>Generic Female</option>
                                    </select>
                                    <button type="button" onclick="this.closest('.reserved-user-row').remove()" class="text-red-400 hover:text-red-300 p-1"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addReservedUserRow()" class="mt-2 text-xs font-bold text-green-400 hover:text-green-300"><i class="fas fa-plus mr-1"></i> Add Reserved User</button>
                    </div>

                    <div class="mt-4 border-t border-gray-700 pt-4">
                        <button type="submit" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-2 px-4 rounded">
                            Save Moderation Settings
                        </button>
                    </div>
                </form>
                <script>
                    function addReservedUserRow() {
                        const container = document.getElementById('reserved-users-container');
                        const row = document.createElement('div');
                        row.className = 'grid grid-cols-3 gap-2 items-center reserved-user-row';
                        row.innerHTML = `
                            <input type="text" name="ru_username[]" placeholder="Username" class="bg-gray-900 border border-gray-700 rounded p-2 text-xs text-white">
                            <input type="text" name="ru_password[]" placeholder="Password" class="bg-gray-900 border border-gray-700 rounded p-2 text-xs text-white">
                            <div class="flex items-center gap-2">
                                <select name="ru_avatar[]" class="flex-1 bg-gray-900 border border-gray-700 rounded p-2 text-xs text-white">
                                    <option value="dicebear">DiceBear</option>
                                    <option value="gen_m">Generic Male</option>
                                    <option value="gen_f">Generic Female</option>
                                </select>
                                <button type="button" onclick="this.closest('.reserved-user-row').remove()" class="text-red-400 hover:text-red-300 p-1"><i class="fas fa-trash"></i></button>
                            </div>
                        `;
                        container.appendChild(row);
                    }
                </script>
            <?php }); ?>

            <!-- 8. GAME LIST & CLEANUPS -->
            <?php renderAccordion('games_cleanups', 'Game List & Cleanups', 'list-ul', function () use ($activeRooms, $globalConfig) { ?>
                <div class="space-y-6">
                    <!-- Game List Section -->
                    <div>
                        <h3 class="text-xs font-bold text-gray-200 uppercase mb-3 flex justify-between items-center">
                            <span>Active Games</span>
                            <?php if (!empty($activeRooms)): ?>
                            <form method="POST" onsubmit="return confirm('Delete ALL games?');" class="inline">
                                <input type="hidden" name="action" value="delete_all_rooms">
                                <button class="bg-red-600 text-white text-[10px] font-bold py-1 px-2.5 rounded hover:bg-red-500">
                                    <i class="fas fa-trash mr-1"></i> Delete All
                                </button>
                            </form>
                            <?php endif; ?>
                        </h3>

                        <?php if (empty($activeRooms)): ?>
                            <p class="text-xs text-gray-400 text-center py-2">No active games.</p>
                        <?php else: ?>
                            <div class="space-y-2 max-h-40 overflow-y-auto pr-1 custom-scrollbar">
                                <?php foreach ($activeRooms as $r): ?>
                                    <div class="flex justify-between items-center bg-gray-800 p-2.5 rounded border border-gray-700 text-xs">
                                        <div>
                                            <span class="font-bold text-gray-200"><?php echo htmlspecialchars($r['config']['room_name'] ?? 'Unnamed'); ?></span>
                                            <span class="text-gray-400 ml-2">(<?php echo count($r['players'] ?? []) . ' players'; ?>)</span>
                                        </div>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="delete_room">
                                            <input type="hidden" name="room_id" value="<?php echo $r['id']; ?>">
                                            <button class="text-red-400 hover:text-red-300">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Cleanups Section -->
                    <div class="border-t border-gray-800 pt-4">
                        <h3 class="text-xs font-bold text-gray-200 uppercase mb-3">Cleanup Settings</h3>
                        <form method="POST" class="space-y-4" onsubmit="saveForm(event)">
                            <input type="hidden" name="action" value="save_global">
                            <input type="hidden" name="ajax" value="1">

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-400 uppercase mb-2">Delete Idle Games After (days)</label>
                                    <input type="number" name="stale_days" value="<?php echo (int)($globalConfig['stale_days'] ?? 2); ?>" min="1" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs">
                                    <p class="text-[9px] text-gray-400 mt-1">Games with no activity will be automatically deleted</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-400 uppercase mb-2">Clear Idle Names After (days)</label>
                                    <input type="number" name="idle_days_to_clear_names" value="<?php echo (int)($globalConfig['idle_days_to_clear_names'] ?? 7); ?>" min="1" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs">
                                    <p class="text-[9px] text-gray-400 mt-1">Player name reservations expire after this period</p>
                                </div>
                            </div>

                            <button type="submit" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-2 px-4 rounded">
                                Save Cleanup Settings
                            </button>
                        </form>
                    </div>

                    <!-- System Export & Integrity Check Section -->
                    <div class="border-t border-gray-800 pt-4 flex flex-col sm:flex-row gap-4">
                        <div class="flex-1">
                            <h4 class="text-[10px] font-bold text-gray-200 uppercase mb-2">System Export</h4>
                            <a href="api.php?action=download_zip" class="inline-block bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold py-2 px-4 rounded transition-colors">
                                <i class="fas fa-file-archive mr-1"></i> Download Game as ZIP
                            </a>
                            <p class="text-[9px] text-gray-400 mt-1">Download a clean copy of the game files for redistribution.</p>
                        </div>
                    </div>
                </div>
            <?php }); ?>



        </main>

        <script>
            function insertBlank(elementId) {
                const el = document.getElementById(elementId);
                if (!el) return;
                const start = el.selectionStart;
                const end = el.selectionEnd;
                const text = el.value;
                const before = text.substring(0, start);
                const after = text.substring(end, text.length);
                el.value = before + '______' + after;
                el.focus();
                el.selectionStart = el.selectionEnd = start + 6;
            }

            function clearGlobalCardSearch() {
                document.getElementById('global-card-search-input').value = '';
                const container = document.getElementById('global-search-results');
                container.innerHTML = '<div class="text-[10px] text-gray-400 italic text-center py-2">Enter keyword above and click Search.</div>';
            }

            async function deleteDeck(slug) {
                if (!confirm("Are you sure you want to delete this deck? Cards belonging to this deck will be hidden from gameplay and setup.")) return;
                const fd = new FormData();
                fd.append('action', 'delete_deck');
                fd.append('deck_slug', slug);
                const res = await fetch('settings.php', { method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd });
                const data = await res.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.msg || "Failed to delete deck");
                }
            }

            async function restoreDeck(slug) {
                const fd = new FormData();
                fd.append('action', 'restore_deck');
                fd.append('deck_slug', slug);
                const res = await fetch('settings.php', { method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd });
                const data = await res.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.msg || "Failed to restore deck");
                }
            }

            async function performGlobalCardSearch() {
                const q = document.getElementById('global-card-search-input').value.trim();
                const container = document.getElementById('global-search-results');
                if (!q) {
                    container.innerHTML = '<div class="text-[10px] text-gray-400 italic text-center py-2">Please enter a keyword first.</div>';
                    return;
                }

                container.innerHTML = '<div class="text-center text-gray-300 text-xs py-4"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
                
                try {
                    const res = await fetch('api.php?action=search_cards&q=' + encodeURIComponent(q));
                    const data = await res.json();
                    container.innerHTML = '';

                    if (data.cards && data.cards.length > 0) {
                        data.cards.forEach(c => {
                            const div = document.createElement('div');
                            div.className = 'inspector-card flex items-start justify-between p-2 bg-gray-800 rounded border border-gray-700 hover:bg-gray-750 group mb-1';
                            
                            // Programmatically set datasets to avoid quotes escaping issue in HTML attributes
                            div.dataset.originalText = c.original_text || c.text;
                            div.dataset.currentText = c.text;
                            div.dataset.deck = c.deck;
                            div.dataset.type = c.type;
                            
                            const badgeColor = c.type === 'black' ? 'bg-black text-white border border-gray-600' : 'bg-white text-gray-900';
                            
                            div.innerHTML = `
                                <div class="flex items-center flex-1 mr-2 min-w-0 break-words text-xs">
                                    <span class="text-[9px] font-bold px-1.5 py-0.5 rounded ${badgeColor} mr-2 uppercase flex-shrink-0">${c.type}</span>
                                    <span class="text-[10px] font-bold text-orange-400 mr-2 flex-shrink-0 uppercase">${c.deck.replace(/_/g, ' ')}</span>
                                    <span class="text-xs text-gray-300 break-all">${c.text}</span>
                                </div>
                                <div class="flex items-center space-x-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button onclick="editCardGlobalSearch(this)" class="text-yellow-500 hover:text-yellow-400 px-1" title="Edit Card">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteCardGlobalSearch(this)" class="text-red-500 hover:text-red-400 px-1" title="Delete Card">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            `;
                            container.appendChild(div);
                        });
                    } else {
                        container.innerHTML = '<div class="text-[10px] text-gray-400 italic text-center py-2">No matching cards found.</div>';
                    }
                } catch(e) {
                    console.error(e);
                    container.innerHTML = '<div class="text-center text-red-500 text-xs py-4">Error performing search</div>';
                }
            }

            function editCardGlobalSearch(btn) {
                const cardDiv = btn.closest('.inspector-card');
                const textSpan = cardDiv.querySelector('span.text-gray-300');
                if (!textSpan || cardDiv.classList.contains('editing')) return;

                const currentText = cardDiv.dataset.currentText;

                cardDiv.classList.add('editing');
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'w-full bg-gray-900 border border-gray-600 rounded p-1 text-xs text-white';
                input.value = currentText;

                const oldSpan = textSpan.cloneNode(true);
                textSpan.replaceWith(input);
                input.focus();

                const actionDiv = cardDiv.querySelector('.group-hover\\:opacity-100');
                const originalActions = actionDiv.innerHTML;

                actionDiv.innerHTML = `
                    <button onclick="confirmEditGlobalSearch(this)" class="text-green-500 hover:text-green-400 px-1">
                        <i class="fas fa-check"></i>
                    </button>
                    <button class="text-gray-400 hover:text-gray-300 px-1 cancel-edit">
                        <i class="fas fa-times"></i>
                    </button>
                `;

                actionDiv.querySelector('.cancel-edit').onclick = () => {
                    cardDiv.classList.remove('editing');
                    input.replaceWith(oldSpan);
                    actionDiv.innerHTML = originalActions;
                };
            }

            async function confirmEditGlobalSearch(btn) {
                const cardDiv = btn.closest('.inspector-card');
                const originalText = cardDiv.dataset.originalText;
                const deckTag = cardDiv.dataset.deck;
                const type = cardDiv.dataset.type;

                const input = cardDiv.querySelector('input');
                const newText = input.value.trim();

                if (!newText) return;
                
                const formData = new FormData();
                formData.append('action', 'edit_card');
                formData.append('deck', deckTag);
                formData.append('type', type);
                formData.append('old_text', originalText);
                formData.append('new_text', newText);                

                try {
                    const res = await fetch('api.php', { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.success) {
                        performGlobalCardSearch(); // Refresh search
                    } else {
                        alert('Error: ' + (data.error || 'Unknown'));
                    }
                } catch (e) {
                    alert('Connection error');
                }
            }

            async function deleteCardGlobalSearch(btn) {
                const cardDiv = btn.closest('.inspector-card');
                const text = cardDiv.dataset.currentText;
                const deckTag = cardDiv.dataset.deck;

                if (!confirm('Delete this card?\n\n' + text)) return;

                const formData = new FormData();
                formData.append('action', 'delete_card');
                formData.append('text', text);
                formData.append('deck', deckTag);

                try {
                    const res = await fetch('api.php', { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.success) {
                        performGlobalCardSearch(); // Refresh search
                    } else {
                        alert('Error: ' + (data.error || 'Unknown'));
                    }
                } catch (e) {
                    alert('Connection error');
                }
            }

            function cleanCardText(text, isBlack) {
                let cleaned = text.trim();
                if (!cleaned) return "";

                if (isBlack) {
                    cleaned = cleaned.replace(/_{3,}/g, '______');
                    cleaned = cleaned.replace(/(\w)\s*______/g, '$1 ______');
                    cleaned = cleaned.replace(/______\s*(\w)/g, '______ $1');
                    cleaned = cleaned.replace(/______\s+([.,;:?!])/g, '______$1');
                    cleaned = cleaned.replace(/ {2,}/g, ' ');
                } else {
                    cleaned = cleaned.replace(/_+/g, '');
                    cleaned = cleaned.replace(/ {2,}/g, ' ');
                }
                return cleaned;
            }

            function toggleAccordion(id) {
                const allContent = document.querySelectorAll('.accordion-content');
                const allIcons = document.querySelectorAll('.accordion-icon');

                const targetContent = document.getElementById('content-' + id);
                const targetIcon = document.getElementById('icon-' + id);
                const isClosed = !targetContent.classList.contains('open');

                allContent.forEach(el => el.classList.remove('open'));
                allIcons.forEach(el => el.classList.remove('rotate-180'));

                if (isClosed) {
                    targetContent.classList.add('open');
                    targetIcon.classList.add('rotate-180');
                }
            }

            function testGeminiConnection() {
                const resDiv = document.getElementById('gemini-test-result');
                resDiv.classList.remove('hidden', 'text-green-400', 'text-red-400');
                resDiv.className = 'text-xs mt-2 text-orange-400';
                resDiv.textContent = 'Testing connection...';

                const form = document.querySelector('#content-security form');
                const fd = new FormData(form);
                fd.set('action', 'test_gemini');

                fetch('settings.php', {
                    method: 'POST',
                    body: fd
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        resDiv.className = 'text-xs mt-2 text-green-400';
                        resDiv.textContent = 'Success! Gemini API connection verified successfully.';
                    } else {
                        resDiv.className = 'text-xs mt-2 text-red-400';
                        let msg = 'Failed: ' + (data.error || 'Unknown error');
                        if (data.available_models && data.available_models.length > 0) {
                            msg += '\n\nAvailable models for your API key:\n- ' + data.available_models.join('\n- ');
                        }
                        resDiv.textContent = msg;
                    }
                })
                .catch(err => {
                    console.error(err);
                    resDiv.className = 'text-xs mt-2 text-red-400';
                    resDiv.textContent = 'Network error occurred during test.';
                });
            }

            function testWordPressConnection() {
                const resDiv = document.getElementById('wp-test-result');
                resDiv.classList.remove('hidden', 'text-green-400', 'text-red-400');
                resDiv.className = 'text-xs mt-2 text-orange-400';
                resDiv.textContent = 'Testing connection...';

                const form = document.querySelector('#content-wordpress form');
                const fd = new FormData(form);
                fd.set('action', 'test_wp');

                fetch('settings.php', {
                    method: 'POST',
                    body: fd
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        resDiv.className = 'text-xs mt-2 text-green-400';
                        resDiv.innerHTML = `Success! Connection verified. A draft post was created successfully. <a href="${data.url}" target="_blank" class="underline text-green-400">View Draft</a>`;
                    } else {
                        resDiv.className = 'text-xs mt-2 text-red-400';
                        resDiv.textContent = 'Failed: ' + (data.error || 'Unknown error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    resDiv.className = 'text-xs mt-2 text-red-400';
                    resDiv.textContent = 'Network error occurred during test.';
                });
            }

            async function saveForm(e, clearTextareaId = null) {
                e.preventDefault();
                const btn = e.target.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';

                const formData = new FormData(e.target);
                formData.append('ajax', '1');

                try {
                    const res = await fetch('settings.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();

                    if (data.success) {
                        btn.innerHTML = '<i class="fas fa-check mr-1"></i> Saved!';
                        btn.classList.remove('bg-orange-600', 'bg-green-600', 'bg-red-600', 'bg-blue-600');
                        btn.classList.add('bg-green-600');

                        // Automatically trigger a duplicate sanity scan when importing/adding cards
                        const actionVal = formData.get('action');
                        if (actionVal === 'import_deck' || actionVal === 'add_card') {
                            setTimeout(() => {
                                checkDecksForDuplicates();
                            }, 500);
                        }

                        setTimeout(() => {
                            if (clearTextareaId) {
                                document.getElementById(clearTextareaId).value = '';
                            }
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        }, 2000);
                    } else {
                        alert('Error: ' + (data.msg || 'Unknown error'));
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                } catch (err) {
                    console.error(err);
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            }

            async function inspectDeck(tag) {
                const modal = document.getElementById('deck-inspector-modal');
                const title = document.getElementById('inspector-title');
                const blackList = document.getElementById('inspector-black-list');
                const whiteList = document.getElementById('inspector-white-list');

                title.textContent = tag.replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) + ' Deck';
                modal.classList.remove('hidden');
                modal.dataset.currentDeck = tag; // Store current deck tag

                blackList.innerHTML = '<div class="text-center text-gray-300 text-xs pt-10"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
                whiteList.innerHTML = '<div class="text-center text-gray-300 text-xs pt-10"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

                try {
                    const res = await fetch('api.php?action=get_deck_cards&deck=' + encodeURIComponent(tag));
                    const data = await res.json();

                    blackList.innerHTML = '';
                    whiteList.innerHTML = '';

                    if (data.cards) {
                        const blackCards = data.cards.filter(c => c.type === 'black');
                        const whiteCards = data.cards.filter(c => c.type === 'white');

                        renderCards(blackCards, blackList, tag);
                        renderCards(whiteCards, whiteList, tag);
                    }
                } catch (e) {
                    console.error(e);
                    blackList.innerHTML = '<div class="text-center text-red-500 text-xs pt-10">Error loading cards</div>';
                    whiteList.innerHTML = '<div class="text-center text-red-500 text-xs pt-10">Error loading cards</div>';
                }
            }

            let currentAudioObject = null;
            function playCardAudio(urls) {
                if (currentAudioObject) {
                    try { currentAudioObject.pause(); } catch(e) {}
                    currentAudioObject = null;
                }
                if (!urls || urls.length === 0) return;
                let index = 0;
                function playNext() {
                    if (index >= urls.length) return;
                    currentAudioObject = new Audio(urls[index]);
                    currentAudioObject.onended = () => {
                        index++;
                        playNext();
                    };
                    currentAudioObject.onerror = () => {
                        index++;
                        playNext();
                    };
                    currentAudioObject.play().catch(e => console.error("Playback failed", e));
                }
                playNext();
            }

            function renderCards(cards, container, deckTag) {
                if (cards.length === 0) {
                    container.innerHTML = '<div class="text-center text-gray-300 text-xs italic pt-4">No cards</div>';
                    return;
                }
                cards.forEach(c => {
                    const div = document.createElement('div');
                    div.className = 'inspector-card flex items-start justify-between p-2 bg-gray-800 rounded border border-gray-700 hover:bg-gray-700 group mb-1';
                    
                    // Programmatically set datasets to avoid quotes escaping issue in HTML attributes
                    div.dataset.originalText = c.original_text || c.text;
                    div.dataset.currentText = c.text;
                    div.dataset.deck = deckTag;
                    div.dataset.type = c.type;
                    
                    let audioIcon = '';
                    if (c.has_audio && c.audio_urls && c.audio_urls.length > 0) {
                        const urlsJson = JSON.stringify(c.audio_urls).replace(/"/g, '&quot;');
                        audioIcon = `<button onclick="playCardAudio(${urlsJson})" class="text-green-500 hover:text-green-400 mr-2 flex-shrink-0" title="Click to test audio"><i class="fas fa-volume-up"></i></button>`;
                    } else {
                        audioIcon = `<i class="fas fa-volume-mute text-gray-500 mr-2 flex-shrink-0" title="No audio generated yet"></i>`;
                    }
                    
                    div.innerHTML = `
                        <div class="flex items-center flex-1 mr-2 min-w-0 break-words">
                            ${audioIcon}
                            <span class="text-xs text-gray-300 break-all">${c.text}</span>
                        </div>
                        <div class="flex items-center space-x-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button onclick="editCard(this)" class="text-yellow-500 hover:text-yellow-400 px-1" title="Edit Card">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteCard(this)" class="text-red-500 hover:text-red-400 px-1" title="Delete Card">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    `;
                    container.appendChild(div);
                });
            }

            function editCard(btn) {
                const cardDiv = btn.closest('.inspector-card');
                const textSpan = cardDiv.querySelector('span.text-gray-300');
                if (!textSpan || cardDiv.classList.contains('editing')) return;

                const currentText = cardDiv.dataset.currentText;
                const originalText = cardDiv.dataset.originalText;
                const deckTag = cardDiv.dataset.deck;
                const type = cardDiv.dataset.type;

                cardDiv.classList.add('editing');
                const originalHTML = cardDiv.innerHTML;

                // Create text input
                const input = document.createElement('input');
                input.type = 'text';
                input.value = currentText;
                input.className = 'border border-gray-600 rounded px-2 py-1 text-xs flex-1 mr-2 focus:border-orange-500 outline-none';
                input.style.cssText = 'color: #ffffff !important; background-color: #111827 !important; display: inline-block !important;';
                
                // Replace span with input
                const textContainer = textSpan.parentElement;
                const oldSpan = textSpan;
                textSpan.replaceWith(input);
                input.focus();

                // Replace action buttons with Save/Cancel
                const actionDiv = cardDiv.querySelector('.flex-shrink-0');
                const originalActions = actionDiv.innerHTML;
                actionDiv.innerHTML = `
                    <button class="save-btn bg-green-600 hover:bg-green-500 text-white text-[10px] font-bold py-1 px-2 rounded mr-1" style="color: #ffffff !important; border: none !important;">Save</button>
                    <button class="cancel-btn bg-gray-600 hover:bg-gray-500 text-white text-[10px] font-bold py-1 px-2 rounded" style="color: #ffffff !important; border: none !important;">Cancel</button>
                `;

                // Handle Cancel
                actionDiv.querySelector('.cancel-btn').onclick = () => {
                    cardDiv.classList.remove('editing');
                    input.replaceWith(oldSpan);
                    actionDiv.innerHTML = originalActions;
                };

                // Handle Save
                actionDiv.querySelector('.save-btn').onclick = async () => {
                    const newText = input.value.trim();
                    if (!newText) {
                        alert("Text cannot be empty");
                        return;
                    }
                    if (newText === currentText) {
                        cardDiv.classList.remove('editing');
                        input.replaceWith(oldSpan);
                        actionDiv.innerHTML = originalActions;
                        return;
                    }

                    const formData = new FormData();
                    formData.append('action', 'edit_card');
                    formData.append('deck', deckTag);
                    formData.append('type', type);
                    formData.append('old_text', originalText);
                    formData.append('new_text', newText);

                    try {
                        const res = await fetch('api.php', { method: 'POST', body: formData });
                        const data = await res.json();
                        if (data.success) {
                            inspectDeck(deckTag); // Reload current deck
                        } else {
                            alert('Error: ' + (data.error || 'Unknown'));
                        }
                    } catch (e) {
                        alert('Connection error');
                    }
                };
            }

            function closeInspector() {
                document.getElementById('deck-inspector-modal').classList.add('hidden');
            }

            function filterInspectorModal() {
                const q = document.getElementById('inspector-search-modal').value.toLowerCase();
                const cards = document.querySelectorAll('.inspector-card');
                cards.forEach(c => {
                    const text = c.querySelector('span').textContent.toLowerCase();
                    c.style.display = text.includes(q) ? 'flex' : 'none';
                });
            }

            async function deleteCard(btn) {
                const cardDiv = btn.closest('.inspector-card');
                const text = cardDiv.dataset.currentText;
                const deck = cardDiv.dataset.deck;

                if (!confirm('Delete this card?\n\n' + text)) return;

                const formData = new FormData();
                formData.append('action', 'delete_card');
                formData.append('text', text);
                formData.append('deck', deck);

                try {
                    const res = await fetch('api.php', { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.success) {
                        inspectDeck(deck); // Reload current deck
                    } else {
                        alert('Error: ' + (data.error || 'Unknown'));
                    }
                } catch (e) { alert('Connection error'); }
            }

            async function addCardsDual(e) {
                e.preventDefault();
                const btn = e.target.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Adding...';

                const formData = new FormData(e.target);

                // Clean and syntax-check card texts
                let black = formData.get('black_cards') || '';
                let white = formData.get('white_cards') || '';

                if (black) {
                    const lines = black.split('\n');
                    const cleanBlackLines = [];
                    lines.forEach(line => {
                        const cleaned = cleanCardText(line, true);
                        if (cleaned) cleanBlackLines.push(cleaned);
                    });
                    formData.set('black_cards', cleanBlackLines.join('\n'));
                }

                if (white) {
                    const lines = white.split('\n');
                    const cleanWhiteLines = [];
                    lines.forEach(line => {
                        const cleaned = cleanCardText(line, false);
                        if (cleaned) cleanWhiteLines.push(cleaned);
                    });
                    formData.set('white_cards', cleanWhiteLines.join('\n'));
                }

                try {
                    const res = await fetch('api.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();

                    if (data.success) {
                        btn.innerHTML = '<i class="fas fa-check mr-1"></i> Added!';
                        btn.classList.remove('bg-green-600');
                        btn.classList.add('bg-blue-600');
                        e.target.reset();
                        setTimeout(() => {
                            btn.innerHTML = originalText;
                            btn.classList.remove('bg-blue-600');
                            btn.classList.add('bg-green-600');
                            btn.disabled = false;
                        }, 2000);
                    } else {
                        alert('Error: ' + (data.msg || 'Unknown error'));
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                } catch (err) {
                    console.error(err);
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            }
            async function autoRemoveDuplicates() {
                if (!confirm("Are you sure you want to automatically remove all duplicate cards from decks.md? This will modify the file directly and remove redundant lines.")) return;
                const resultsEl = document.getElementById('duplicate-check-results');
                resultsEl.classList.remove('hidden');
                resultsEl.innerHTML = `<span class="text-gray-400"><i class="fas fa-spinner fa-spin mr-1"></i>Removing duplicates from decks.md...</span>`;
                
                const fd = new FormData();
                fd.append('action', 'remove_duplicates');
                fd.append('ajax', '1');
                
                try {
                    const res = await fetch('settings.php', {
                        method: 'POST',
                        body: fd
                    });
                    const data = await res.json();
                    if (data.success) {
                        resultsEl.innerHTML = `<div class="text-green-400 font-bold"><i class="fas fa-check-circle mr-1"></i>Duplicates successfully removed from decks.md! Scanning again...</div>`;
                        setTimeout(() => checkDecksForDuplicates(), 1500);
                    } else {
                        resultsEl.innerHTML = `<div class="text-red-400 font-bold"><i class="fas fa-times-circle mr-1"></i>Error: ${data.error || "Deduplication failed"}</div>`;
                    }
                } catch (err) {
                    resultsEl.innerHTML = `<div class="text-red-400 font-bold"><i class="fas fa-times-circle mr-1"></i>Request failed.</div>`;
                }
            }
            async function checkDecksForDuplicates() {
                const resultsEl = document.getElementById('duplicate-check-results');
                resultsEl.classList.remove('hidden');
                resultsEl.innerHTML = `<span class="text-gray-400"><i class="fas fa-spinner fa-spin mr-1"></i>Scanning decks.md for duplicate cards...</span>`;
                
                const fd = new FormData();
                fd.append('action', 'check_duplicates');
                fd.append('ajax', '1');
                
                try {
                    const res = await fetch('settings.php', {
                        method: 'POST',
                        body: fd
                    });
                    const data = await res.json();
                    if (data.success) {
                        const bDups = data.black_duplicates || [];
                        const wDups = data.white_duplicates || [];                        
                        
                        if (bDups.length === 0 && wDups.length === 0) {
                            resultsEl.innerHTML = `<div class="text-green-400 font-bold"><i class="fas fa-check-circle mr-1"></i>No duplicates found! All decks are clean.</div>`;
                            return;
                        }
                        
                        let html = `<form id="resolve-duplicates-form" onsubmit="resolveDuplicates(event)">`;
                        html += `<div class="flex justify-between items-center mb-2">
                                    <div class="text-orange-400 font-bold"><i class="fas fa-exclamation-triangle mr-1"></i>Found ${bDups.length + wDups.length} duplicates:</div>
                                    <button type="submit" class="bg-red-600 hover:bg-red-500 text-white text-xs font-bold py-1 px-3 rounded">Delete Selected</button>
                                 </div>`;
                        
                        if (bDups.length > 0) {
                            html += `<div class="font-bold text-white mb-1">Black Cards (${bDups.length}):</div><div class="space-y-1 mb-2">`;
                            bDups.forEach(dup => {
                                const text = dup.text.replace(/"/g, '&quot;');
                                html += `<div class="p-2 bg-gray-800 rounded border border-gray-700">
                                    <div class="text-gray-300 mb-1">"${text}"</div>
                                    <div class="flex gap-4 text-xs">`;
                                dup.locations.forEach((loc, i) => {
                                    const id = `dup-black-${i}-${btoa(text)}`;
                                    html += `<label for="${id}" class="flex items-center cursor-pointer">
                                        <input type="checkbox" id="${id}" name="delete_cards[]" value="${loc.deck}::${text}" class="accent-orange-500 mr-1.5">
                                        <span class="text-purple-400">from ${loc.deck}</span>
                                    </label>`;
                                });
                                html += `</div></div>`;
                            });
                            html += `</div>`;
                        }
                        
                        if (wDups.length > 0) {
                            html += `<div class="font-bold text-white mb-1">White Cards (${wDups.length}):</div><div class="space-y-1">`;
                            wDups.forEach(dup => {
                                const text = dup.text.replace(/"/g, '&quot;');
                                html += `<div class="p-2 bg-gray-800 rounded border border-gray-700">
                                    <div class="text-gray-300 mb-1">"${text}"</div>
                                    <div class="flex gap-4 text-xs">`;
                                dup.locations.forEach((loc, i) => {
                                    const id = `dup-white-${i}-${btoa(text)}`;
                                    html += `<label for="${id}" class="flex items-center cursor-pointer">
                                        <input type="checkbox" id="${id}" name="delete_cards[]" value="${loc.deck}::${text}" class="accent-orange-500 mr-1.5">
                                        <span class="text-purple-400">from ${loc.deck}</span>
                                    </label>`;
                                });
                                html += `</div></div>`;
                            });
                            html += `</div>`;
                        }
                        
                        html += `</form>`;
                        resultsEl.innerHTML = html;
                    } else {
                        resultsEl.innerHTML = `<div class="text-red-400"><i class="fas fa-times-circle mr-1"></i>Scan failed: ${data.msg || 'Unknown error'}</div>`;
                    }
                } catch(e) {
                    console.error(e);
                    resultsEl.innerHTML = `<div class="text-red-400"><i class="fas fa-times-circle mr-1"></i>Connection error.</div>`;
                }
            }

            async function resolveDuplicates(e) {
                e.preventDefault();
                const form = e.target;
                const checked = form.querySelectorAll('input[name="delete_cards[]"]:checked');
                if (checked.length === 0) {
                    alert('Please select at least one duplicate card to delete.');
                    return;
                }

                if (!confirm(`Are you sure you want to delete the ${checked.length} selected duplicate card(s)?`)) {
                    return;
                }

                const deletePromises = Array.from(checked).map(async (cb) => {
                    const val = cb.value; // format "deck::text"
                    const parts = val.split('::');
                    const deck = parts[0];
                    const text = parts.slice(1).join('::');

                    const fd = new FormData();
                    fd.append('action', 'delete_card');
                    fd.append('text', text);
                    fd.append('deck', deck);

                    const res = await fetch('api.php', { method: 'POST', body: fd });
                    return res.json();
                });

                try {
                    const results = await Promise.all(deletePromises);
                    const failures = results.filter(r => !r.success);
                    if (failures.length === 0) {
                        alert('Selected duplicate cards successfully removed!');
                        checkDecksForDuplicates(); // Rescan
                    } else {
                        alert(`Successfully removed ${results.length - failures.length} duplicates, but encountered errors on ${failures.length}.`);
                    }
                } catch (err) {
                    console.error(err);
                    alert('Connection error resolving duplicates.');
                }
            }
        </script>

    <?php endif; ?>
</body>

</html>
<?php
// Helper to render accordion item
/**
 * @param string $id
 * @param string $title
 * @param string $icon
 * @param callable(): void $contentCallback
 */
function renderAccordion(string $id, string $title, string $icon, callable $contentCallback): void
{
    $prefix = ($icon === 'wordpress') ? 'fab' : 'fas';
    echo '
    <div class="bg-[#25262b] rounded-xl shadow-xl border border-gray-800 overflow-hidden">
        <div class="p-4 flex items-center justify-between cursor-pointer hover:bg-gray-800 transition-colors" onclick="toggleAccordion(\'' . $id . '\')">
            <h2 class="font-bold text-sm flex items-center text-orange-400 uppercase tracking-widest">
                <i class="' . $prefix . ' fa-' . $icon . ' mr-3 w-5 text-center"></i> ' . $title . '
            </h2>
            <i class="fas fa-chevron-down accordion-icon transition-transform duration-300 text-gray-300" id="icon-' . $id . '"></i>
        </div>
        <div id="content-' . $id . '" class="accordion-content border-t border-gray-800">
            <div class="p-4">';
    $contentCallback();
    echo '  </div>
        </div>
    </div>';
}
?>
