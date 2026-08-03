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
        'ai_provider' => 'gemini',
        'openai_api_key' => '',
        'openai_model' => 'gpt-5.6-luna',
        'openai_voice' => 'ash',
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

// Wrapper functions for camelCase naming
function getGlobalConfig()
{
    return load_global_config();
}

function saveGlobalConfig(array $config): void
{
    save_global_config($config);
}

// cleanCardTextPHP is provided by deck_parser.php

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
        $hdr = parseDeckHeaderLine($trimmed);
        if ($hdr !== null) {
            $headerType = $hdr['type'];
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

        $hdr = parseDeckHeaderLine($trimmed);
        if ($hdr !== null) {
            $currentType = $hdr['type'];
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

// generateCardAudio is provided by api.php (retired — Web Speech API handles TTS client-side)

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

// EXPORT DECK FILE FOR SHARING
if (isset($_GET['action']) && $_GET['action'] === 'export_deck') {
    if (!$isAdmin) {
        die("Admin access required.");
    }
    $tag = trim($_GET['deck_tag'] ?? 'all');
    $parsed = parseDecksShared(false); // exclude deleted/banned

    $blackCards = [];
    $whiteCards = [];

    if ($tag === 'all' || $tag === '') {
        $filename = "cards_against_all_decks.md";
        $blackCards = $parsed['black'];
        $whiteCards = $parsed['white'];
    } elseif ($tag === 'orange_deck') {
        $filename = "cards_against_orange_deck.md";
        $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
        if (file_exists($USER_ADDITIONS_FILE)) {
            $userAdditions = json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: [];
            foreach ($userAdditions as $card) {
                if (($card['type'] ?? 'white') === 'black') {
                    $blackCards[] = ['text' => $card['text']];
                } else {
                    $whiteCards[] = ['text' => $card['text']];
                }
            }
        }
    } else {
        $filename = "cards_against_" . strtolower(str_replace(' ', '_', $tag)) . ".md";
        foreach ($parsed['black'] as $c) {
            if (strcasecmp($c['deck'], $tag) === 0) {
                $blackCards[] = $c;
            }
        }
        foreach ($parsed['white'] as $c) {
            if (strcasecmp($c['deck'], $tag) === 0) {
                $whiteCards[] = $c;
            }
        }
    }

    $out = "";
    if (!empty($blackCards)) {
        $out .= "Black Cards\n\n";
        foreach ($blackCards as $c) {
            $out .= cleanCardTextPHP($c['text'], true) . "\n";
        }
        $out .= "\n";
    }
    if (!empty($whiteCards)) {
        $out .= "White Cards\n\n";
        foreach ($whiteCards as $c) {
            $out .= cleanCardTextPHP($c['text'], false) . "\n";
        }
    }

    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

// --- DECK MANAGEMENT ---
$deckFile = __DIR__ . '/decks.md';
$deckAvailabilityFile = __DIR__ . '/data/decks_available.json';

if (isset($_POST['action'])) {
    $ajax = isset($_POST['ajax']);
    $msg = "";

    if (!$isAdmin) {
        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Admin authentication required. Please enter admin password.', 'msg' => 'Admin authentication required.']);
            exit;
        }
    }

    // VALIDATE MASTER DECK PREVIEW
    if ($_POST['action'] === 'validate_master_preview') {
        if (empty($_FILES['master_deck_file']['tmp_name'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
            exit;
        }

        $tmpFile = $_FILES['master_deck_file']['tmp_name'];
        $content = file_get_contents($tmpFile);

        if ($content === false || trim($content) === '') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Uploaded file is empty.']);
            exit;
        }

        // Always sanitize first
        $content = sanitizeDeckContent($content);
        $validation = validateDeckImport($content);

        if (!$validation['valid']) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => "Validation failed:\n" . implode("\n", $validation['errors'])]);
            exit;
        }

        // Parse the uploaded content to get deck stats
        $newDecks = [];
        $lines = preg_split('/\R/', $content);
        $currentSlug = 'base_deck';
        $currentLabel = 'Base Deck';
        $type = 'black';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;

            $hdr = parseDeckHeaderLine($trimmed);
            if ($hdr !== null) {
                $currentSlug = $hdr['pack_slug'];
                $currentLabel = $hdr['raw_pack'];
                $type = $hdr['type'];
                continue;
            }
            if (stripos($trimmed, 'Cards List') !== false) continue;

            // Resolve inline tag
            $deckLabel = $currentLabel;
            $deckSlug = $currentSlug;
            if (preg_match('/\(([^)]+)\)\s*$/', $trimmed, $m)) {
                $deckLabel = trim($m[1]);
                $deckSlug = slug_deck_label($deckLabel);
            }

            if (!isset($newDecks[$deckSlug])) {
                $newDecks[$deckSlug] = ['slug' => $deckSlug, 'label' => $deckLabel, 'black' => 0, 'white' => 0];
            }
            if ($type === 'black') {
                $newDecks[$deckSlug]['black']++;
            } else {
                $newDecks[$deckSlug]['white']++;
            }
        }

        // Get current decks stats for comparison
        $currentParsed = parseDecksShared(false);
        $currentDecks = [];
        // Extract tags and build current stats
        foreach ($currentParsed['tags'] as $tag) {
            $currentDecks[$tag] = [
                'slug' => $tag,
                'label' => get_deck_label($tag),
                'black' => 0,
                'white' => 0
            ];
        }
        foreach ($currentParsed['black'] as $c) {
            if (isset($currentDecks[$c['deck']])) {
                $currentDecks[$c['deck']]['black']++;
            }
        }
        foreach ($currentParsed['white'] as $c) {
            if (isset($currentDecks[$c['deck']])) {
                $currentDecks[$c['deck']]['white']++;
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'new_decks' => array_values($newDecks),
            'current_decks' => array_values($currentDecks)
        ]);
        exit;
    }

    // BACKUP MASTER DECK
    if ($_POST['action'] === 'backup_master_deck' || ($_GET['action'] ?? '') === 'backup_master_deck') {
        $masterFile = __DIR__ . '/decks.md';
        if (!file_exists($masterFile)) {
            if ($ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'decks.md does not exist.']);
                exit;
            }
            die('decks.md does not exist.');
        }
        $backupDir = __DIR__ . '/data/backups';
        if (!is_dir($backupDir)) mkdir($backupDir, 0775, true);
        $filename = 'decks_backup_' . date('Y-m-d_H-i-s') . '.md';
        $backupPath = $backupDir . '/' . $filename;
        copy($masterFile, $backupPath);

        if (isset($_GET['download']) || !empty($_POST['download'])) {
            header('Content-Type: text/markdown');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            readfile($masterFile);
            exit;
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'msg' => 'Backup successfully saved to data/backups/' . $filename, 'file' => $filename]);
        exit;
    }

    // UPLOAD MASTER DECK FILE
    if ($_POST['action'] === 'upload_master_deck') {
        if (empty($_FILES['master_deck_file']['tmp_name'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'No file uploaded or file too large.']);
            exit;
        }

        $tmpFile = $_FILES['master_deck_file']['tmp_name'];
        $content = file_get_contents($tmpFile);

        if ($content === false || trim($content) === '') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Uploaded file is empty.']);
            exit;
        }

        // Always sanitize first
        $content = sanitizeDeckContent($content);
        $validation = validateDeckImport($content);

        if (!$validation['valid']) {
            $msg = "Error: Master deck validation failed:\n\n" . implode("\n", $validation['errors']);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        }

        // Save auto-backup before overwrite
        $masterFile = __DIR__ . '/decks.md';
        if (file_exists($masterFile)) {
            $backupDir = __DIR__ . '/data/backups';
            if (!is_dir($backupDir)) mkdir($backupDir, 0775, true);
            $backupPath = $backupDir . '/decks_auto_backup_' . date('Y-m-d_H-i-s') . '.md';
            @copy($masterFile, $backupPath);
        }

        // Save it as decks.md
        $success = file_put_contents($masterFile, $content) !== false;

        if ($success) {
            // Clear imported decks, bans, and edits to ensure a completely clean master state
            $importedFile = __DIR__ . '/data/imported_decks.md';
            if (file_exists($importedFile)) @unlink($importedFile);
            
            $bannedFile = __DIR__ . '/data/banned_cards.json';
            if (file_exists($bannedFile)) @unlink($bannedFile);
            
            $editedFile = __DIR__ . '/data/edited_cards.json';
            if (file_exists($editedFile)) @unlink($editedFile);

            // Re-read available decks list to automatically include any new tags
            $availFile = __DIR__ . '/data/decks_available.json';
            $availDecks = [];
            $parsed = parseDecksShared(false);
            foreach ($parsed['tags'] as $tag) {
                $availDecks[$tag] = true;
            }
            if (!is_dir(dirname($availFile))) mkdir(dirname($availFile), 0775, true);
            file_put_contents($availFile, json_encode($availDecks, JSON_PRETTY_PRINT));

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'msg' => 'Master decks.md successfully uploaded and registered! Cleared old imports, bans, and edits. Found ' . $validation['blackCardCount'] . ' black cards and ' . $validation['whiteCardCount'] . ' white cards.',
                'reload' => true
            ]);
            exit;
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to write content to decks.md. Check directory permissions.']);
            exit;
        }
    }

    // SANITIZE ALL DECKS IN PLACE
    if ($_POST['action'] === 'sanitize_all_decks') {
        $r1 = sanitizeDeckFile(__DIR__ . '/decks.md');
        $r2 = true;
        if (file_exists(__DIR__ . '/data/imported_decks.md')) {
            $r2 = sanitizeDeckFile(__DIR__ . '/data/imported_decks.md');
        }
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $r1 && $r2,
            'msg' => ($r1 && $r2) ? 'All deck files successfully scanned and sanitized! (Blanks set to ______, trailing periods removed).' : 'Failed to sanitize one or more deck files.'
        ]);
        exit;
    }

    // CHECK DUPLICATE CHECK
    if ($_POST['action'] === 'check_duplicates') {
        $parsed = parseDecksShared(false); // exclude deleted/banned cards
        $blackSeen = [];
        $whiteSeen = [];

        // Group cards by normalized text
        foreach ($parsed['black'] as $card) {
            $normalized = trim(strtolower(cleanCardTextPHP($card['text'], true)));
            if (!isset($blackSeen[$normalized])) {
                $blackSeen[$normalized] = ['text' => $card['text'], 'locations' => []];
            }
            $blackSeen[$normalized]['locations'][] = ['deck' => $card['deck']];
        }
        foreach ($parsed['white'] as $card) {
            $normalized = trim(strtolower(cleanCardTextPHP($card['text'], false)));
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
            
            $hdr = parseDeckHeaderLine($trimmed);
            if ($hdr !== null) {
                $type = $hdr['type'];
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

    // SAVE ELEVENLABS PRESET
    if (isset($_POST['action']) && $_POST['action'] === 'save_elevenlabs_preset') {
        $presetName = trim($_POST['preset_name'] ?? '');
        $voiceId = trim($_POST['voice_id'] ?? '');
        if ($presetName === '' || $voiceId === '') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Preset name and Voice ID are required.']);
            exit;
        }
        $globalConfig = getGlobalConfig();
        if (!isset($globalConfig['elevenlabs_voice_presets']) || !is_array($globalConfig['elevenlabs_voice_presets'])) {
            $globalConfig['elevenlabs_voice_presets'] = [];
        }
        $globalConfig['elevenlabs_voice_presets'][$voiceId] = $presetName;
        $globalConfig['elevenlabs_voice_id'] = $voiceId;
        saveGlobalConfig($globalConfig);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'presets' => $globalConfig['elevenlabs_voice_presets']]);
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

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;
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
            $listUrl = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;
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
            'google_tts_voice',
            'elevenlabs_api_key',
            'elevenlabs_voice_id',
            'elevenlabs_voice_presets',
            'enable_vdo',
            'vdo_room_id',
            'vdo_api_key',
            'admin_password',
            'wp_publish_url',
            'wp_publish_username',
            'wp_publish_password',
            'gemini_api_key',
            'ai_provider',
            'openai_api_key',
            'openai_model',
            'openai_voice',
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

    if ($_POST['action'] === 'import_deck') {
        $content = $_POST['deck_content'] ?? '';
        $deckNameInput = trim($_POST['deck_name'] ?? '');
        
        if (trim($content) === '') {
            $msg = "Error: Please paste card content to import.";
            if ($ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'msg' => $msg, 'error' => $msg]);
                exit;
            }
        }
        if ($deckNameInput === '') {
            $msg = "Error: Deck Name / Title is mandatory for imports.";
            if ($ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'msg' => $msg, 'error' => $msg]);
                exit;
            }
        }

        if (trim($content) !== '') {
            // Always sanitize first
            $content = sanitizeDeckImport($content);
            $validation = validateDeckImport($content);

            if (!$validation['valid']) {
                $msg = "Error: Deck import validation failed:\n\n" . implode("\n", $validation['errors']);
                if ($ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'msg' => $msg]);
                    exit;
                }
            } else {
                // Proceed with import - parse and clean the content
                $deckNameInput = trim($_POST['deck_name'] ?? '');
                $cardTypeForce = trim($_POST['card_type_force'] ?? 'detect');
                $importThemes = $_POST['import_themes'] ?? [];

                $lines = preg_split('/\R/', $content);
                $cleanedLines = [];

                // Load existing cards to prevent duplicates
                $existingParsed = parseDecksShared(true);
                $existingBlacks = [];
                $existingWhites = [];
                foreach ($existingParsed['black'] as $c) {
                    $existingBlacks[trim(strtolower(cleanCardTextPHP($c['text'], true)))] = true;
                }
                foreach ($existingParsed['white'] as $c) {
                    $existingWhites[trim(strtolower(cleanCardTextPHP($c['text'], false)))] = true;
                }
                $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
                if (file_exists($USER_ADDITIONS_FILE)) {
                    $userAdditions = json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: [];
                    foreach ($userAdditions as $c) {
                        $txt = trim(strtolower(cleanCardTextPHP($c['text'], (($c['type'] ?? 'white') === 'black'))));
                        if (($c['type'] ?? 'white') === 'black') {
                            $existingBlacks[$txt] = true;
                        } else {
                            $existingWhites[$txt] = true;
                        }
                    }
                }

                $skippedBlack = 0;
                $skippedWhite = 0;
                $importedBlack = 0;
                $importedWhite = 0;

                // First pass: detect if the file contains any explicit headers
                $hasFileHeaders = false;
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed !== '' && parseDeckHeaderLine($trimmed) !== null) {
                        $hasFileHeaders = true;
                        break;
                    }
                }

                // Determine active type
                $type = 'black'; // default assumption
                $wroteBlackHeader = false;
                $wroteWhiteHeader = false;

                // If forcing card type and we have a deck name, write the initial header
                if (!$hasFileHeaders && $deckNameInput !== '') {
                    if ($cardTypeForce === 'black') {
                        $cleanedLines[] = "# {$deckNameInput} Black Cards";
                        $wroteBlackHeader = true;
                    } elseif ($cardTypeForce === 'white') {
                        $cleanedLines[] = "# {$deckNameInput} White Cards";
                        $type = 'white';
                        $wroteWhiteHeader = true;
                    }
                }

                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        $cleanedLines[] = '';
                        continue;
                    }

                    // Header detection
                    $hdr = parseDeckHeaderLine($trimmed);
                    if ($hdr !== null) {
                        $type = $hdr['type'];
                        $packName = $deckNameInput !== '' ? $deckNameInput : $hdr['raw_pack'];
                        // Standardize the header output
                        $cleanedLines[] = ($packName === 'Base Deck' ? '' : $packName . ' ') . ucfirst($type) . ' Cards';
                        if ($type === 'black') $wroteBlackHeader = true;
                        else $wroteWhiteHeader = true;
                        continue;
                    }
                    if (stripos($trimmed, 'Cards List') !== false) {
                        $cleanedLines[] = $trimmed;
                        continue;
                    }

                    // It's a card line. Clean it!
                    $isBlack = ($type === 'black');
                    if (!$hasFileHeaders && $deckNameInput !== '' && $cardTypeForce === 'detect') {
                        $checkLine = str_replace('\\', '', $trimmed);
                        $isBlack = (preg_match('/[._\/]{4,}/', $checkLine) === 1 || preg_match('/_{2,}/', $checkLine) === 1 || substr(trim($trimmed), -1) === '?');
                    }

                    $cleanedCard = cleanCardTextPHP($trimmed, $isBlack);
                    if ($cleanedCard !== '') {
                        $norm = trim(strtolower($cleanedCard));
                        if ($isBlack) {
                            if (isset($existingBlacks[$norm])) {
                                $skippedBlack++;
                                continue;
                            }
                            $existingBlacks[$norm] = true;
                            $importedBlack++;
                            
                            if (!$hasFileHeaders && $deckNameInput !== '' && $cardTypeForce === 'detect') {
                                if (!$wroteBlackHeader) {
                                    $cleanedLines[] = "# {$deckNameInput} Black Cards";
                                    $wroteBlackHeader = true;
                                }
                            }
                        } else {
                            if (isset($existingWhites[$norm])) {
                                $skippedWhite++;
                                continue;
                            }
                            $existingWhites[$norm] = true;
                            $importedWhite++;

                            if (!$hasFileHeaders && $deckNameInput !== '' && $cardTypeForce === 'detect') {
                                if (!$wroteWhiteHeader) {
                                    $cleanedLines[] = "# {$deckNameInput} White Cards";
                                    $wroteWhiteHeader = true;
                                }
                            }
                        }
                        $cleanedLines[] = $cleanedCard;
                    }
                }

                $tagsBefore = $existingParsed['tags'];

                if ($importedBlack > 0 || $importedWhite > 0) {
                    $cleanedContent = implode("\n", $cleanedLines);
                    $importedFile = __DIR__ . '/data/imported_decks.md';

                    if (!is_dir(dirname($importedFile))) {
                        mkdir(dirname($importedFile), 0777, true);
                    }

                    $current = file_exists($importedFile) ? file_get_contents($importedFile) : "Black Cards\n\nWhite Cards\n";
                    file_put_contents($importedFile, trim($current) . "\n\n" . trim($cleanedContent));
                }

                // Auto-enable new imported deck tags in decks_available.json
                $availFile = __DIR__ . '/data/decks_available.json';
                $availDecks = file_exists($availFile) ? (json_decode(file_get_contents($availFile), true) ?: []) : [];
                $parsed = parseDecksShared(false);
                foreach ($parsed['tags'] as $tag) {
                    if (!isset($availDecks[$tag])) {
                        $availDecks[$tag] = true;
                    }
                }
                if (!is_dir(dirname($availFile))) mkdir(dirname($availFile), 0777, true);
                file_put_contents($availFile, json_encode($availDecks, JSON_PRETTY_PRINT));

                // Add newly imported tags to selected themes if requested
                $newTags = array_diff($parsed['tags'], $tagsBefore);
                if (!empty($newTags) && !empty($importThemes)) {
                    $themesFile = __DIR__ . '/data/themes.json';
                    $themes = file_exists($themesFile) ? (json_decode(file_get_contents($themesFile), true) ?: []) : [];
                    $themeUpdated = false;
                    foreach ($importThemes as $tSlug) {
                        if (isset($themes[$tSlug])) {
                            if (!isset($themes[$tSlug]['default_decks']) || !is_array($themes[$tSlug]['default_decks'])) {
                                $themes[$tSlug]['default_decks'] = ['base_deck'];
                            }
                            foreach ($newTags as $newTag) {
                                if (!in_array($newTag, $themes[$tSlug]['default_decks'], true)) {
                                    $themes[$tSlug]['default_decks'][] = $newTag;
                                    $themeUpdated = true;
                                }
                            }
                        }
                    }
                    if ($themeUpdated) {
                        file_put_contents($themesFile, json_encode($themes, JSON_PRETTY_PRINT));
                    }
                }

                $msg = "✓ Deck scanned, sanitized & imported successfully!";
                $msgParts = [];
                if ($importedBlack > 0 || $importedWhite > 0) {
                    $msgParts[] = "Imported " . ($importedBlack + $importedWhite) . " new card(s) (" . $importedBlack . " black, " . $importedWhite . " white)";
                }
                if (($skippedBlack + $skippedWhite) > 0) {
                    $msgParts[] = "Skipped " . ($skippedBlack + $skippedWhite) . " duplicate card(s) (" . $skippedBlack . " black, " . $skippedWhite . " white)";
                }
                if (!empty($msgParts)) {
                    $msg .= " (" . implode(", ", $msgParts) . ")";
                } else {
                    $msg .= " (No new unique cards found)";
                }

                if ($ajax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'msg' => $msg,
                        'blackCount' => $importedBlack,
                        'whiteCount' => $importedWhite,
                        'reload' => true
                    ]);
                    exit;
                }
            }
        }
    }

    // ADD CARD (force into Orange Deck via user_additions.json)
    if ($_POST['action'] === 'add_card') {
        $type = (($_POST['card_type'] ?? '') === 'Black') ? 'black' : 'white';
        $text = trim($_POST['card_text'] ?? '');

        $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
        $additions = file_exists($USER_ADDITIONS_FILE) ? (json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: []) : [];

        if ($text) {
            $text = cleanCardTextPHP($text, ($type === 'black'));

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

                $msg = "Card added to Orange Deck.";
            } else {
                $msg = "Card already exists in Orange Deck.";
            }
        } else {
            $exists = true;
            $msg = "Card text cannot be empty.";
        }

        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => !$exists && !empty($text),
                'msg' => $msg,
                'exists' => $exists,
                'total_orange_cards' => count($additions)
            ]);
            exit;
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

    // Include Orange Deck (User Additions)
    $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
    $orangeBlack = 0;
    $orangeWhite = 0;
    if (file_exists($USER_ADDITIONS_FILE)) {
        $userAdditions = json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: [];
        foreach ($userAdditions as $card) {
            if (($card['type'] ?? 'white') === 'black') {
                $orangeBlack++;
            } else {
                $orangeWhite++;
            }
        }
    }
    $stats['orange_deck'] = ['black' => $orangeBlack, 'white' => $orangeWhite];

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
        $hdr = parseDeckHeaderLine($line);
        if ($hdr !== null) {
            $type = $hdr['type'];
            $currentLabel = $hdr['raw_pack'] ?: 'Base Deck';
            $currentSlug = $hdr['pack_slug'] ?: 'base_deck';
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

    // Include Orange Deck (User Additions)
    $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
    if (file_exists($USER_ADDITIONS_FILE)) {
        $userAdditions = json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: [];
        if (!empty($userAdditions)) {
            $ensureDeck('orange_deck', 'Orange Deck (User Additions)');
            foreach ($userAdditions as $c) {
                if (($c['type'] ?? 'white') === 'black') {
                    $decks['orange_deck']['black'][] = $c['text'];
                } else {
                    $decks['orange_deck']['white'][] = $c['text'];
                }
            }
        }
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
    $availDecks = json_decode(file_get_contents($deckAvailabilityFile), true) ?: [];
}
if (empty($availDecks)) {
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
        <div class="max-w-4xl mx-auto px-4 text-center">
            <h1 class="text-sm sm:text-base font-black uppercase tracking-[0.18em]">
                <span class="text-orange-500">CARDS AGAINST</span>
                <span class="text-gray-200">(<?php echo htmlspecialchars($defaultTheme['game_name_suffix'] ?? 'Everyone'); ?>)</span>
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
                                    $displayLabel = ucwords(str_replace('-', ' ', $slug));
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
                            const cacheBuster = '?t=' + Date.now();
                            if (['mp4', 'webm', 'mov'].includes(ext)) {
                                const video = document.createElement('video');
                                video.src = t.banner_media + cacheBuster;
                                video.controls = true;
                                video.muted = true;
                                video.className = 'w-full rounded';
                                container.appendChild(video);
                            } else {
                                const img = document.createElement('img');
                                img.src = t.banner_media + cacheBuster;
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
                            player.src = t.intro_audio_url + '?t=' + Date.now();
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
                            player.src = t.win_audio_url + '?t=' + Date.now();
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

            <!-- 4. AI MODELS & ADMIN SECURITY -->
            <?php renderAccordion('security', 'AI Models & Security', 'robot', function () use ($globalConfig) { ?>
                <form method="POST" class="space-y-5" onsubmit="saveForm(event)">
                    <input type="hidden" name="action" value="save_global">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="tts_enabled" value="<?php echo !empty($globalConfig['tts_enabled']) ? '1' : '0'; ?>">
                    <input type="hidden" name="enable_vdo" value="<?php echo !empty($globalConfig['enable_vdo']) ? '1' : '0'; ?>">

                    <!-- Notice Banner -->
                    <div class="bg-blue-900/30 border border-blue-700/50 rounded-lg p-3 text-xs text-blue-200 flex items-start gap-2.5">
                        <i class="fas fa-info-circle text-blue-400 text-sm mt-0.5"></i>
                        <div>
                            <strong>AI Text Models vs Voice Synthesis:</strong>
                            <p class="text-[11px] text-blue-300/90 mt-0.5">
                                This section configures <strong>AI Text Models</strong> (Gemini & OpenAI) which handle smart bot plays, game host commentary, and room chat responses. 
                                <em>Voice synthesis APIs (Google Cloud TTS, ElevenLabs, OpenAI Voice) are set separately below in the <a href="#accordion-voice_options" class="text-orange-400 underline font-semibold">Voice Options</a> section.</em>
                            </p>
                        </div>
                    </div>

                    <!-- AI Provider Choice -->
                    <div class="bg-gray-900/50 border border-gray-700/70 rounded-lg p-4 space-y-3">
                        <h4 class="text-xs font-bold text-orange-400 uppercase tracking-wider"><i class="fas fa-brain mr-1"></i> Active AI Text Provider</h4>
                        <div>
                            <label class="block text-xs font-bold text-gray-200 uppercase mb-1">Selected AI Provider</label>
                            <select name="ai_provider" id="ai-provider" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs">
                                <option value="gemini" <?php echo (($globalConfig['ai_provider'] ?? 'gemini') === 'gemini') ? 'selected' : ''; ?>>Google Gemini (gemini-2.5-flash)</option>
                                <option value="openai" <?php echo (($globalConfig['ai_provider'] ?? 'gemini') === 'openai') ? 'selected' : ''; ?>>OpenAI (gpt-5.6-luna / GPT-4o)</option>
                            </select>
                            <p class="text-[10px] text-gray-400 mt-1">Controls which provider generates host commentary, bot card choices, and chat reactions.</p>
                        </div>
                    </div>

                    <!-- Model API Keys Box -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Google Gemini Section -->
                        <div class="bg-gray-900/40 border border-gray-700/50 rounded-lg p-4 space-y-2">
                            <h4 class="text-xs font-bold text-green-400 uppercase tracking-wider flex items-center justify-between">
                                <span><i class="fab fa-google mr-1"></i> Google Gemini API</span>
                                <span class="text-[9px] bg-green-900/50 text-green-300 px-1.5 py-0.5 rounded">AI Studio</span>
                            </h4>
                            <div>
                                <label class="block text-xs font-bold text-gray-300 mb-1">Gemini API Key</label>
                                <input type="password" name="gemini_api_key" value="<?php echo htmlspecialchars($globalConfig['gemini_api_key'] ?? ''); ?>" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs" placeholder="AQ.Ab... / AIzaSy...">
                                <p class="text-[10px] text-gray-400 mt-1">Get your free API key at <a href="https://aistudio.google.com/" target="_blank" class="text-orange-400 underline">Google AI Studio</a>.</p>
                            </div>
                        </div>

                        <!-- OpenAI Section -->
                        <div class="bg-gray-900/40 border border-gray-700/50 rounded-lg p-4 space-y-2">
                            <h4 class="text-xs font-bold text-purple-400 uppercase tracking-wider flex items-center justify-between">
                                <span><i class="fas fa-robot mr-1"></i> OpenAI API</span>
                                <span class="text-[9px] bg-purple-900/50 text-purple-300 px-1.5 py-0.5 rounded">Platform API</span>
                            </h4>
                            <div>
                                <label class="block text-xs font-bold text-gray-300 mb-1">OpenAI API Key</label>
                                <input type="password" name="openai_api_key" value="<?php echo htmlspecialchars($globalConfig['openai_api_key'] ?? ''); ?>" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs" placeholder="sk-...">
                                <p class="text-[10px] text-gray-400 mt-1">Requires an OpenAI Platform API key.</p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-300 mb-1">OpenAI Model ID</label>
                                <input type="text" name="openai_model" value="<?php echo htmlspecialchars($globalConfig['openai_model'] ?? 'gpt-5.6-luna'); ?>" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs" placeholder="gpt-5.6-luna">
                            </div>
                        </div>
                    </div>

                    <!-- Admin Security Credentials -->
                    <div class="bg-gray-900/40 border border-gray-700/50 rounded-lg p-4 space-y-2">
                        <h4 class="text-xs font-bold text-gray-300 uppercase tracking-wider"><i class="fas fa-shield-alt mr-1"></i> Admin Panel Security</h4>
                        <div>
                            <label class="block text-xs font-bold text-gray-200 uppercase mb-1">Admin Password</label>
                            <input type="password" name="admin_password" value="<?php echo htmlspecialchars($globalConfig['admin_password'] ?? 'orange'); ?>" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs">
                            <p class="text-[10px] text-gray-400 mt-1">Change the master password required to access this Settings panel.</p>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-2 px-4 rounded">
                            Save AI & Security Settings
                        </button>
                        <button type="button" onclick="testAIConnection()" class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold py-2 px-4 rounded">
                            Test Selected AI Text Model
                        </button>
                    </div>
                    <div id="ai-test-result" class="text-xs mt-2 hidden" style="white-space: pre-wrap;"></div>
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
            <?php renderAccordion('voice_options', 'Voice Options', 'volume-up', function () use ($voiceScripts, $globalConfig) { ?>

                <!-- ── VOICE SELECTION ─────────────────────────────────────────── -->
                <form method="POST" class="space-y-5 mb-8" onsubmit="saveForm(event)">
                    <input type="hidden" name="action" value="save_global">
                    <input type="hidden" name="ajax" value="1">

                    <p class="text-xs text-gray-400">
                        Choose the host voice engine and pick a specific voice. Browser TTS is always the free fallback.
                    </p>

                    <!-- TTS Provider Toggle Cards -->
                    <div>
                        <label class="block text-xs font-bold text-gray-200 uppercase mb-2">Host Voice Engine</label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <?php
                            $currentTTSProvider = $globalConfig['tts_provider'] ?? 'browser';
                            $hasGoogleKey = !empty($globalConfig['google_tts_api_key']);
                            $hasElevenLabsKey = !empty($globalConfig['elevenlabs_api_key']);
                            $hasOpenAIKey = !empty($globalConfig['openai_api_key']);
                            $providers = [
                                'browser'    => ['label' => '🌐 Browser', 'desc' => 'Free, built-in'],
                                'google'     => ['label' => '🎙️ Google Neural2', 'desc' => 'Free tier (~380 games/mo)'],
                                'elevenlabs' => ['label' => '🗣️ ElevenLabs', 'desc' => 'Hyper-realistic AI voice'],
                                'openai'     => ['label' => '🤖 OpenAI TTS', 'desc' => 'AI-generated voices'],
                            ];
                            foreach ($providers as $pKey => $pInfo):
                                $active = ($currentTTSProvider === $pKey);
                                $disabled = ($pKey === 'google' && !$hasGoogleKey) || ($pKey === 'elevenlabs' && !$hasElevenLabsKey) || ($pKey === 'openai' && !$hasOpenAIKey);
                            ?>
                            <label onclick="selectTTSEngine('<?php echo $pKey; ?>')" class="cursor-pointer <?php echo $disabled ? 'opacity-40 cursor-not-allowed' : ''; ?>">
                                <input type="radio" name="tts_provider" value="<?php echo $pKey; ?>" <?php echo $active ? 'checked' : ''; ?> <?php echo $disabled ? 'disabled' : ''; ?> class="sr-only peer">
                                <div id="tts-card-<?php echo $pKey; ?>" class="border-2 rounded-lg p-3 text-center transition-all peer-checked:border-orange-500 peer-checked:bg-orange-500/10 border-gray-700 bg-gray-900/50 hover:border-gray-500">
                                    <div class="text-sm font-bold text-gray-200"><?php echo $pInfo['label']; ?></div>
                                    <div class="text-[10px] text-gray-400 mt-0.5"><?php echo $pInfo['desc']; ?></div>
                                    <?php if ($disabled): ?><div class="text-[9px] text-yellow-500 mt-1">⚠ No API key</div><?php endif; ?>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ── PROVIDER ACCORDION PANELS (ONLY ONE OPEN AT A TIME) ── -->

                    <!-- 1. Chrome Browser Voice Panel -->
                    <div id="tts-panel-browser" class="bg-gray-900/40 border border-gray-700/50 rounded-lg p-4 space-y-3 <?php echo ($currentTTSProvider === 'browser') ? '' : 'hidden'; ?>">
                        <h4 class="text-xs font-bold text-gray-300 uppercase tracking-wider"><i class="fab fa-chrome mr-1 text-orange-400"></i> Chrome Browser Voice Settings</h4>
                        <div class="flex items-end gap-3">
                            <div class="flex-1">
                                <label class="block text-xs font-bold text-gray-300 mb-1">Browser Installed Voices</label>
                                <select id="admin-chrome-voice" onchange="localStorage.setItem('game_tts_voice_name', this.value)" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs">
                                    <option value="">Loading installed browser voices...</option>
                                </select>
                                <p class="text-[10px] text-gray-400 mt-1">Free, local voice built into your browser or OS. Displays full voice names with language codes.</p>
                            </div>
                            <div class="flex flex-col gap-1 pb-5">
                                <button type="button" onclick="testChromeVoice()" id="test-chrome-btn" class="text-[11px] bg-gray-700 hover:bg-gray-600 text-white font-bold py-1.5 px-3 rounded transition-colors flex items-center gap-1.5 whitespace-nowrap">
                                    <i class="fas fa-play text-orange-400 text-[10px]"></i> Test Browser Voice
                                </button>
                            </div>
                        </div>
                        <div id="test-chrome-result" class="hidden text-[10px] p-2 bg-gray-900 rounded border border-gray-700"></div>
                    </div>

                    <!-- 2. Google Neural2 Voice Panel -->
                    <div id="tts-panel-google" class="bg-gray-900/40 border border-gray-700/50 rounded-lg p-4 space-y-3 <?php echo ($currentTTSProvider === 'google') ? '' : 'hidden'; ?>">
                        <h4 class="text-xs font-bold text-orange-400 uppercase tracking-wider"><i class="fab fa-google mr-1"></i> Google Neural2 Voice Settings</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-gray-300 mb-1">Google Cloud TTS API Key</label>
                                <input type="password" name="google_tts_api_key" value="<?php echo htmlspecialchars($globalConfig['google_tts_api_key'] ?? ''); ?>" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs" placeholder="AIzaSy...">
                                <p class="text-[10px] text-gray-400 mt-1"><strong class="text-green-400">Free tier: 1M chars/month</strong> (~380 games free). Get one at <a href="https://console.cloud.google.com/" target="_blank" class="text-orange-400 underline">Google Cloud Console</a> → Cloud Text-to-Speech.</p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-300 mb-1">Neural2 Voice</label>
                                <select id="admin-google-voice" name="google_tts_voice" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs">
                                    <?php
                                    $googleVoices = [
                                        'en-US-Neural2-F' => '🇺🇸 US Female F – Warm, natural',
                                        'en-US-Neural2-C' => '🇺🇸 US Female C – Clear, confident',
                                        'en-US-Neural2-H' => '🇺🇸 US Female H – Friendly, bright',
                                        'en-US-Neural2-A' => '🇺🇸 US Male A – Deep, authoritative',
                                        'en-US-Neural2-D' => '🇺🇸 US Male D – Strong, engaging',
                                        'en-US-Neural2-I' => '🇺🇸 US Male I – Casual, relaxed',
                                        'en-US-Neural2-J' => '🇺🇸 US Male J – Energetic, clear',
                                        'en-GB-Neural2-A' => '🇬🇧 UK Female A – Elegant British',
                                        'en-GB-Neural2-B' => '🇬🇧 UK Male B – Crisp British',
                                        'en-GB-Neural2-C' => '🇬🇧 UK Female C – Warm British',
                                        'en-GB-Neural2-D' => '🇬🇧 UK Male D – Confident British',
                                        'en-AU-Neural2-A' => '🇦🇺 AU Female A – Australian',
                                        'en-AU-Neural2-B' => '🇦🇺 AU Male B – Australian',
                                        'en-US-Wavenet-F' => '🇺🇸 US Female F (Wavenet)',
                                        'en-US-Wavenet-D' => '🇺🇸 US Male D (Wavenet)',
                                    ];
                                    $savedGoogleVoice = $globalConfig['google_tts_voice'] ?? 'en-US-Neural2-F';
                                    foreach ($googleVoices as $val => $label):
                                    ?>
                                        <option value="<?php echo $val; ?>" <?php echo $savedGoogleVoice === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-[10px] text-gray-400 mt-1">Neural2 sounds dramatically more natural than Wavenet.</p>
                                <button type="button" onclick="testGoogleVoice()" id="test-google-btn" class="mt-2 text-[11px] bg-gray-700 hover:bg-gray-600 text-white font-bold py-1.5 px-3 rounded transition-colors flex items-center gap-1.5">
                                    <i class="fas fa-play text-orange-400 text-[10px]"></i> Test Google Neural2 Voice
                                </button>
                                <div id="test-google-result" class="hidden mt-2 text-[10px] p-2 bg-gray-900 rounded border border-gray-700"></div>
                            </div>
                        </div>
                    </div>

                    <!-- 3. ElevenLabs Voice Panel -->
                    <div id="tts-panel-elevenlabs" class="bg-gray-900/40 border border-gray-700/50 rounded-lg p-4 space-y-3 <?php echo ($currentTTSProvider === 'elevenlabs') ? '' : 'hidden'; ?>">
                        <h4 class="text-xs font-bold text-emerald-400 uppercase tracking-wider"><i class="fas fa-microphone-alt mr-1"></i> ElevenLabs Voice Settings</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-gray-300 mb-1">ElevenLabs API Key</label>
                                <input type="password" name="elevenlabs_api_key" value="<?php echo htmlspecialchars($globalConfig['elevenlabs_api_key'] ?? ''); ?>" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs" placeholder="xi-api-key...">
                                <p class="text-[10px] text-gray-400 mt-1">Get your key at <a href="https://elevenlabs.io/" target="_blank" class="text-orange-400 underline">ElevenLabs.io</a> under Profile Settings.</p>
                            </div>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-bold text-gray-300 mb-1">Voice Presets</label>
                                    <select id="elevenlabs-preset-select" onchange="applyElevenLabsPreset(this.value)" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs">
                                        <option value="">-- Choose a Saved Preset --</option>
                                        <?php
                                        $defaultPresets = [
                                            '21m00Tcm4TlvDq8ikWAM' => 'Rachel (Calm & Clear Female)',
                                            'AZnzlk1XvdvUeBnXmlld' => 'Domi (Energetic & Bold Female)',
                                            'EXAVITQu4vr4xnSDxMaL' => 'Bella (Narrative & Expressive Female)',
                                            'ErXwobaYiN019PkySvjV' => 'Antoni (Modulated Male)',
                                            'TxGEqnHWrfWFTfGW9XjX' => 'Josh (Young & Casual Male)',
                                            'VR6AewLTigWG4xSOukaG' => 'Arnold (Crisp & Strong Male)',
                                            'pNInz6obpgDQGcFmaJgB' => 'Adam (Deep & Authoritative Male)',
                                            'yoZ06aMxZJJ28mfd3POQ' => 'Sam (Raspy & Playful Male)'
                                        ];
                                        $savedPresets = $globalConfig['elevenlabs_voice_presets'] ?? [];
                                        $allPresets = array_merge($defaultPresets, is_array($savedPresets) ? $savedPresets : []);
                                        $currentVoiceId = $globalConfig['elevenlabs_voice_id'] ?? '21m00Tcm4TlvDq8ikWAM';
                                        foreach ($allPresets as $vId => $vName):
                                        ?>
                                            <option value="<?php echo htmlspecialchars($vId); ?>" <?php echo ($currentVoiceId === $vId) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($vName); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-300 mb-1">Active Voice ID</label>
                                    <div class="flex gap-2">
                                        <input type="text" id="admin-elevenlabs-voice" name="elevenlabs_voice_id" value="<?php echo htmlspecialchars($currentVoiceId); ?>" class="flex-1 bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs" placeholder="Voice ID...">
                                        <button type="button" onclick="saveCustomElevenLabsPreset()" class="bg-gray-700 hover:bg-gray-600 text-white text-xs font-bold px-3 py-1.5 rounded flex items-center gap-1.5 whitespace-nowrap" title="Save this Voice ID as a custom preset">
                                            <i class="fas fa-bookmark text-emerald-400"></i> Save Preset
                                        </button>
                                    </div>
                                    <p class="text-[10px] text-gray-400 mt-1">Select a preset above or enter any custom ElevenLabs Voice ID.</p>
                                </div>
                                <div class="pt-1">
                                    <button type="button" onclick="testElevenLabsVoice()" id="test-elevenlabs-btn" class="text-[11px] bg-gray-700 hover:bg-gray-600 text-white font-bold py-1.5 px-3 rounded transition-colors flex items-center gap-1.5">
                                        <i class="fas fa-play text-emerald-400 text-[10px]"></i> Test ElevenLabs Voice
                                    </button>
                                    <div id="test-elevenlabs-result" class="hidden mt-2 text-[10px] p-2 bg-gray-900 rounded border border-gray-700"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4. OpenAI Voice Panel -->
                    <div id="tts-panel-openai" class="bg-gray-900/40 border border-gray-700/50 rounded-lg p-4 space-y-3 <?php echo ($currentTTSProvider === 'openai') ? '' : 'hidden'; ?>">
                        <h4 class="text-xs font-bold text-purple-400 uppercase tracking-wider"><i class="fas fa-robot mr-1"></i> OpenAI Host Voice Settings</h4>
                        <div class="flex items-end gap-3">
                            <div class="flex-1">
                                <label class="block text-xs font-bold text-gray-300 mb-1">Voice Character</label>
                                <select id="admin-openai-voice" name="openai_voice" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white text-xs">
                                    <?php foreach (['ash', 'coral', 'echo', 'fable', 'onyx', 'nova', 'sage', 'shimmer'] as $voice): ?>
                                        <option value="<?php echo $voice; ?>" <?php echo (($globalConfig['openai_voice'] ?? 'ash') === $voice) ? 'selected' : ''; ?>><?php echo ucfirst($voice); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-[10px] text-gray-400 mt-1">Used for AI host comments, card readings, and game announcements when OpenAI TTS is selected.</p>
                            </div>
                            <div class="flex flex-col gap-1 pb-5">
                                <button type="button" onclick="testOpenAIVoice()" id="test-openai-btn" class="text-[11px] bg-gray-700 hover:bg-gray-600 text-white font-bold py-1.5 px-3 rounded transition-colors flex items-center gap-1.5 whitespace-nowrap">
                                    <i class="fas fa-play text-purple-400 text-[10px]"></i> Test OpenAI Voice
                                </button>
                            </div>
                        </div>
                        <div id="test-openai-result" class="hidden text-[10px] p-2 bg-gray-900 rounded border border-gray-700"></div>
                    </div>

                    <div>
                        <button type="submit" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-2 px-4 rounded">
                            <i class="fas fa-save mr-1"></i> Save Voice Settings
                        </button>
                    </div>
                </form>

                <!-- ── VOICE SCRIPTS SUB-ACCORDION ───────────────────────────── -->
                <div class="border-t border-gray-700/50 pt-5">
                    <button type="button" onclick="toggleVoiceScripts()" id="vs-toggle-btn" class="w-full flex items-center justify-between text-left bg-gray-900/40 hover:bg-gray-900/60 border border-gray-700/50 rounded-lg px-4 py-3 transition-colors">
                        <span class="text-sm font-bold text-gray-200">
                            <i class="fas fa-comment-dots mr-2 text-orange-400"></i> Voice Scripts
                            <span class="text-[10px] font-normal text-gray-400 ml-2">Customise what the host says at each game event</span>
                        </span>
                        <i class="fas fa-chevron-down text-gray-400 transition-transform" id="vs-chevron"></i>
                    </button>

                    <div id="vs-scripts-body" class="hidden mt-4">
                        <form method="POST" class="space-y-6" onsubmit="saveForm(event)">
                            <input type="hidden" name="action" value="save_voice_scripts">
                            <input type="hidden" name="ajax" value="1">

                            <p class="text-xs text-gray-400 mb-4">
                                Customise dynamic voice comments announced by the host. Use placeholders like <code>[name]</code>, <code>[winner]</code>, <code>[sentence]</code>, <code>[round]</code>, <code>[text]</code>, <code>[GAME_TITLE]</code>, or <code>[champ]</code> where applicable.
                            </p>

                            <div class="space-y-4">
                                <?php
                                $categories = [
                                    'welcome'     => ['label' => 'Start of Game',            'desc' => 'Announced when a new game starts. Placeholders: [GAME_TITLE]'],
                                    'voting'      => ['label' => 'Time to Vote',              'desc' => 'Announced when cards are revealed and players begin voting.'],
                                    'afk_warning' => ['label' => 'Waiting On… (AFK Warning)', 'desc' => 'Played when waiting for a slow player. Placeholders: [name]'],
                                    'afk_sub'     => ['label' => 'Players AFK (Bot Sub)',     'desc' => 'Announced when a bot replaces an AFK player. Placeholders: [name], [subbingFor]'],
                                    'paused'      => ['label' => 'Pause',                     'desc' => 'Announced when a player pauses the game. Placeholders: [pauser]'],
                                    'unpaused'    => ['label' => 'Unpause',                   'desc' => 'Announced when the game is resumed.'],
                                    'join'        => ['label' => 'Player Joined',             'desc' => 'Announced when a new player joins the lobby. Placeholders: [name]'],
                                    'leave'       => ['label' => 'Player Left',              'desc' => 'Announced when a player leaves. Placeholders: [name]'],
                                    'new_round'   => ['label' => 'New Round Started',         'desc' => 'Announced at the beginning of each round. Placeholders: [round], [text]'],
                                    'winner'      => ['label' => 'Round Winner Announced',    'desc' => 'Announced when a round is won. Placeholders: [winner], [sentence]'],
                                    'tie'         => ['label' => 'Round Tied',               'desc' => 'Announced when a voting result is tied.'],
                                    'bot_leading' => ['label' => 'Bot Nearing Win',          'desc' => 'Announced when a bot is one point away from winning.'],
                                    'timer_low'   => ['label' => 'Turn Timer Low',           'desc' => 'Announced when only 15 seconds remain in the turn.'],
                                    'game_over'   => ['label' => 'Game Over',                'desc' => 'Announced when the game concludes. Placeholders: [champ]'],
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
                                                <div class="no-phrases-notice text-[10px] text-gray-500 italic py-1">No custom scripts. The game will use built-in default phrases.</div>
                                            <?php else: ?>
                                                <?php foreach ($phrases as $phrase): ?>
                                                    <div class="flex items-center gap-2 phrase-row-item">
                                                        <textarea name="vs[<?php echo htmlspecialchars($catKey); ?>][]" class="flex-1 bg-gray-800 border border-gray-700 rounded p-1.5 text-xs text-white resize-none" rows="2" placeholder="Announce phrase..."><?php echo htmlspecialchars($phrase); ?></textarea>
                                                        <button type="button" onclick="this.closest('.phrase-row-item').remove(); checkEmptyCategoryNotice('<?php echo htmlspecialchars($catKey); ?>')" class="text-red-500 hover:text-red-400 hover:bg-red-950/20 p-2 rounded" title="Delete phrase"><i class="fas fa-trash"></i></button>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="mt-4 flex justify-end">
                                <button type="submit" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-2.5 px-6 rounded shadow-md">
                                    <i class="fas fa-save mr-1"></i> Save Voice Scripts
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                    function toggleVoiceScripts() {
                        const body = document.getElementById('vs-scripts-body');
                        const chev = document.getElementById('vs-chevron');
                        body.classList.toggle('hidden');
                        chev.style.transform = body.classList.contains('hidden') ? '' : 'rotate(180deg)';
                    }

                    function addVoicePhraseRow(category) {
                        const container = document.getElementById('phrases-container-' + category);
                        const notice = container.querySelector('.no-phrases-notice');
                        if (notice) notice.remove();
                        const div = document.createElement('div');
                        div.className = 'flex items-center gap-2 phrase-row-item';
                        div.innerHTML = `
                            <textarea name="vs[${category}][]" class="flex-1 bg-gray-800 border border-gray-700 rounded p-1.5 text-xs text-white resize-none" rows="2" placeholder="Announce phrase..." required></textarea>
                            <button type="button" onclick="this.closest('.phrase-row-item').remove(); checkEmptyCategoryNotice('${category}')" class="text-red-500 hover:text-red-400 hover:bg-red-950/20 p-2 rounded" title="Delete phrase"><i class="fas fa-trash"></i></button>
                        `;
                        container.appendChild(div);
                        div.querySelector('textarea').focus();
                    }

                    function checkEmptyCategoryNotice(category) {
                        const container = document.getElementById('phrases-container-' + category);
                        if (container.children.length === 0) {
                            container.innerHTML = '<div class="no-phrases-notice text-[10px] text-gray-500 italic py-1">No custom scripts. The game will use built-in default phrases.</div>';
                        }
                    }
                </script>
            <?php });
?>

            <!-- 6. DECK MANAGEMENT -->
            <?php renderAccordion('decks', 'Deck Management', 'layer-group', function () use ($deckStats, $availDecks, $globalConfig) { ?>

                <!-- 1. SEARCH -->
                <div class="mb-6 bg-gray-850 p-4 rounded-lg border border-gray-700">
                    <button type="button" onclick="document.getElementById('deck-search-area').classList.toggle('hidden')" class="w-full flex items-center justify-between text-xs font-bold text-gray-200 uppercase mb-3">
                        <span class="flex items-center"><i class="fas fa-search mr-2 text-orange-400"></i> Search Cards Across All Decks</span>
                        <i class="fas fa-chevron-down ml-auto"></i>
                    </button>
                    <div id="deck-search-area" class="hidden mb-6 bg-gray-850 p-4 rounded-lg border border-gray-700">
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
                </div>

                <!-- 2. DECKS -->
                <div class="mb-6 bg-gray-850 p-4 rounded-lg border border-gray-700">
                    <button type="button" onclick="document.getElementById('deck-list-area').classList.toggle('hidden')" class="w-full flex items-center justify-between text-xs font-bold text-gray-200 uppercase mb-3">
                        <span class="flex items-center"><i class="fas fa-layer-group mr-2 text-orange-400"></i> Available Decks</span>
                        <i class="fas fa-chevron-down ml-auto"></i>
                    </button>
                    <div id="deck-list-area" class="hidden mb-6">
                        <div class="text-[10px] font-normal normal-case text-gray-300 mb-3">Check to enable in game</div>
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
                                            <button type="button" onclick="inspectDeck('<?php echo $tag; ?>')" class="p-2 text-blue-400 hover:text-blue-300 hover:bg-blue-900/30 rounded" title="Open / Edit Cards">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" onclick="downloadFileSilently('settings.php?action=export_deck&deck_tag=<?php echo urlencode($tag); ?>', 'cards_against_<?php echo urlencode($tag); ?>.md')" class="p-2 text-green-400 hover:text-green-300 hover:bg-green-900/30 rounded" title="Download / Export Deck for Sharing">
                                                <i class="fas fa-download"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" class="mt-3 w-full bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-2 px-4 rounded">
                                <i class="fas fa-save mr-1"></i> Save Deck Availability
                            </button>
                        </form>
                    </div>
                </div>

                <!-- 3. ADD SINGLE CARD -->
                <div class="mb-6 bg-gray-850 p-4 rounded-lg border border-gray-700">
                    <button type="button" onclick="document.getElementById('add-card-area').classList.toggle('hidden')" class="w-full flex items-center justify-between text-xs font-bold text-gray-200 uppercase mb-3">
                        <span class="flex items-center"><i class="fas fa-plus-circle mr-2 text-orange-400"></i> Add Single Custom Card</span>
                        <i class="fas fa-chevron-down ml-auto"></i>
                    </button>
                    <div id="add-card-area" class="hidden mb-6">
                        <p class="text-[11px] text-gray-400 mb-3 italic">Note: Single cards added here are saved to the <strong class="text-orange-400">Orange Deck</strong> (<code class="text-gray-300">data/user_additions.json</code>).</p>
                        <div id="add-card-status-banner" class="hidden mb-3"></div>
                        <form method="POST" class="space-y-3" onsubmit="saveForm(event)">
                            <input type="hidden" name="action" value="add_card">
                            <input type="hidden" name="ajax" value="1">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">Card Text</label>
                                <input type="text" id="single_card_text_input" name="card_text" placeholder="e.g. A tiny horse ______" required class="w-full bg-gray-900 border border-gray-700 rounded p-2 text-xs text-white mb-2">
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
                </div>

                <!-- 4. IMPORT DECK -->
                <div class="mb-6 bg-gray-850 p-4 rounded-lg border border-gray-700">
                    <button type="button" onclick="document.getElementById('import-area').classList.toggle('hidden')" class="w-full flex items-center justify-between text-xs font-bold text-gray-200 uppercase mb-3">
                        <span class="flex items-center"><i class="fas fa-file-import mr-2 text-orange-400"></i> Import Full Deck</span>
                        <i class="fas fa-chevron-down ml-auto"></i>
                    </button>
                    <div id="import-area" class="hidden mt-3">
                        <div id="import-status-banner" class="hidden mb-3"></div>
                        <p class="text-[11px] text-gray-400 mb-3">Paste a markdown formatted deck below (with <code>### Pack Name</code> or <code># Pack Name</code>, <code>Cards List</code>, and <code>Black Cards</code>/<code>White Cards</code> headers).</p>

                        <form method="POST" class="space-y-3" onsubmit="saveForm(event, 'deck_content_textarea')">
                            <input type="hidden" name="action" value="import_deck">
                            <input type="hidden" name="ajax" value="1">

                            <!-- Paste Box First -->
                            <div>
                                <label class="block text-[10px] font-bold text-gray-300 uppercase mb-1">Paste Card Text Content:</label>
                                <textarea id="deck_content_textarea" name="deck_content" class="w-full bg-gray-900 border border-gray-700 rounded p-2 text-xs text-white h-36 font-mono" placeholder="Paste card lines here..."></textarea>
                                <div class="mt-1 flex justify-between items-center">
                                    <button type="button" onclick="insertBlank('deck_content_textarea')" class="bg-gray-700 hover:bg-gray-600 text-white text-[9px] font-bold py-1 px-2.5 rounded">
                                        <i class="fas fa-underscore mr-1"></i> Insert Blank (______)
                                    </button>
                                </div>
                            </div>

                            <!-- Mandatory Deck Name & Card Type -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-gray-800/40 p-3 rounded border border-gray-700/60">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-300 uppercase mb-1">Deck Name / Title <span class="text-orange-400 font-bold">*Mandatory</span>:</label>
                                    <input type="text" name="deck_name" required placeholder="e.g. Harry Potter" class="w-full bg-gray-900 border border-gray-700 rounded p-1.5 text-xs text-white placeholder-gray-500 focus:border-orange-500 outline-none">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-300 uppercase mb-1">Card Type Option:</label>
                                    <select name="card_type_force" class="w-full bg-gray-900 border border-gray-700 rounded p-1.5 text-xs text-white focus:border-orange-500 outline-none">
                                        <option value="detect">Auto (Looks for # Black Cards / # White Cards headers)</option>
                                        <option value="black">Force all as Black Cards</option>
                                        <option value="white">Force all as White Cards</option>
                                    </select>
                                </div>
                            </div>

                            <?php if (!empty($themes)): ?>
                            <div class="bg-gray-800/40 p-3 rounded border border-gray-700/60 mt-2">
                                <label class="block text-[10px] font-bold text-gray-300 uppercase mb-1.5"><i class="fas fa-palette mr-1 text-orange-400"></i> Add to Theme Default Decks:</label>
                                <div class="grid grid-cols-2 gap-2 max-h-24 overflow-y-auto custom-scrollbar">
                                    <?php foreach ($themes as $slug => $t): ?>
                                        <label class="flex items-center space-x-2 text-xs text-gray-300 cursor-pointer hover:text-white">
                                            <input type="checkbox" name="import_themes[]" value="<?php echo htmlspecialchars($slug); ?>" class="accent-orange-500 rounded w-3.5 h-3.5">
                                            <span><?php echo htmlspecialchars($t['label'] ?? $slug); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold py-2 px-4 rounded w-full">
                                <i class="fas fa-file-import mr-1"></i> Import Deck
                            </button>
                        </form>

                        <!-- Upload Option Last -->
                        <div class="mt-4 border-t border-gray-750 pt-3">
                            <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1.5">Or Upload Deck File (.txt / .md):</label>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="document.getElementById('deck_file_input').click()" class="bg-gray-700 hover:bg-gray-600 border border-gray-600 text-white text-xs font-bold py-1.5 px-3 rounded flex-1">
                                    <i class="fas fa-file-upload mr-1"></i> Choose File to Load into Paste Box
                                </button>
                                <span id="file-name-display" class="text-[10px] text-gray-400 italic">No file selected</span>
                            </div>
                            <input type="file" id="deck_file_input" accept=".txt,.md" class="hidden" onchange="handleDeckFileUpload(event)">
                        </div>
                    </div>
                </div>

                <!-- 5. DECKS.MD INTEGRITY -->
                <div class="bg-gray-850 p-4 rounded-lg border border-gray-700">
                    <button type="button" onclick="document.getElementById('integrity-area').classList.toggle('hidden')" class="w-full flex items-center justify-between text-xs font-bold text-gray-200 uppercase">
                        <span class="flex items-center"><i class="fas fa-shield-alt mr-2 text-orange-400"></i> decks.md Integrity</span>
                        <i class="fas fa-chevron-down ml-auto"></i>
                    </button>

                    <div id="integrity-area" class="hidden mt-3 space-y-4">
                        <p class="text-xs text-gray-400">Maintain, backup, scan for duplicates, sanitize, or replace your master <code>decks.md</code> list.</p>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <button type="button" onclick="performBackupMasterDeck()" class="bg-green-700 hover:bg-green-600 text-white text-xs font-bold py-2 px-3 rounded text-center flex items-center justify-center transition-colors">
                                <i class="fas fa-download mr-1.5"></i> Backup
                            </button>
                            <button type="button" onclick="checkDecksForDuplicates()" class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold py-2 px-3 rounded text-center transition-colors">
                                <i class="fas fa-search mr-1.5"></i> Scan Duplicates
                            </button>
                            <button type="button" onclick="sanitizeAllDecks()" class="bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold py-2 px-3 rounded text-center transition-colors">
                                <i class="fas fa-broom mr-1.5"></i> Sanitize
                            </button>
                            <button type="button" onclick="downloadFileSilently('settings.php?action=export_deck&deck_tag=all', 'decks.md')" class="bg-gray-700 hover:bg-gray-600 text-white text-xs font-bold py-2 px-3 rounded text-center flex items-center justify-center transition-colors">
                                <i class="fas fa-save mr-1.5"></i> Save decks.md
                            </button>
                        </div>

                        <div id="duplicate-check-results" class="hidden p-3 bg-gray-950/80 rounded border border-purple-500/30 text-xs text-gray-300 max-h-96 overflow-y-auto space-y-2"></div>

                        <!-- Import decks.md / Master Upload Block -->
                        <div class="bg-gray-800/80 border border-gray-700/60 p-3.5 rounded">
                            <h4 class="text-xs font-bold text-orange-400 uppercase mb-2 flex items-center">
                                <i class="fas fa-file-upload mr-1.5"></i> Import / Replace Master decks.md
                            </h4>
                            <p class="text-[11px] text-gray-300 mb-3">Uploading a master deck file will automatically create a timestamped backup in <code>data/backups/</code> before setting the new file as master.</p>

                            <div id="upload-master-status-banner" class="hidden mb-3"></div>

                            <!-- Step 1: Choose File -->
                            <div id="master-upload-step-1">
                                <div class="mb-3">
                                    <div class="flex items-center gap-2 mb-2">
                                        <button type="button" onclick="document.getElementById('master_deck_file_input').click()" class="bg-gray-700 hover:bg-gray-600 border border-gray-600 text-white text-xs font-bold py-1.5 px-3 rounded flex-1">
                                            <i class="fas fa-file-upload mr-1"></i> Choose decks.md File
                                        </button>
                                        <span id="master-file-name-display" class="text-[10px] text-gray-400 italic">No file selected</span>
                                    </div>
                                    <input type="file" id="master_deck_file_input" accept=".txt,.md" class="hidden" onchange="handleMasterDeckFileUpload(event)">
                                </div>
                                <button type="button" onclick="generateUploadPreview()" class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold py-2 px-4 rounded w-full">
                                    Scan File & Generate Preview Report
                                </button>
                            </div>

                            <!-- Step 2: Preview Report -->
                            <div id="master-upload-step-2" class="hidden space-y-4 border-t border-gray-750 pt-4 mt-4 animate-fade-in">
                                <h4 class="text-xs font-bold text-orange-400 uppercase"><i class="fas fa-file-contract mr-1"></i> Master Deck Overwrite Preview Report:</h4>
                                
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs border-collapse">
                                        <thead>
                                            <tr class="bg-gray-800 text-gray-300 font-bold border-b border-gray-750">
                                                <th class="p-2.5">Deck Name</th>
                                                <th class="p-2.5 text-center">Current Cards</th>
                                                <th class="p-2.5 text-center">New Cards</th>
                                                <th class="p-2.5 text-center">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="preview-report-table-body" class="divide-y divide-gray-800 text-gray-200">
                                            <!-- Report rows inserted by JS -->
                                        </tbody>
                                    </table>
                                </div>

                                <div class="flex flex-col sm:flex-row gap-2 mt-4">
                                    <button type="button" onclick="resetUploadSteps()" class="bg-gray-700 hover:bg-gray-600 border border-gray-600 text-white text-xs font-bold py-2 px-4 rounded flex-1">
                                        <i class="fas fa-arrow-left mr-1"></i> Choose Another File
                                    </button>
                                    <button type="button" onclick="commitMasterOverwrite()" id="commit-overwrite-btn" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-2 px-4 rounded flex-1 shadow-lg hover:shadow-orange-500/20 transition-all">
                                        <i class="fas fa-exclamation-triangle mr-1"></i> Commit Overwrite & Apply
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Deck Inspector Modal (Hidden by default, shown via JS) -->
                <div id="deck-inspector-modal" class="fixed inset-0 bg-black/80 z-50 hidden flex items-center justify-center p-4">
                    <div class="bg-gray-900 rounded-xl border border-gray-700 w-full max-w-4xl max-h-[90vh] flex flex-col shadow-2xl">
                        <div class="p-4 border-b border-gray-800 flex justify-between items-center bg-gray-800 rounded-t-xl">
                            <div class="flex items-center gap-3">
                                <h3 class="font-bold text-orange-500" id="inspector-title">Deck Inspector</h3>
                                <button type="button" onclick="exportCurrentInspectedDeck()" class="text-xs bg-green-600 hover:bg-green-500 text-white font-bold py-1 px-3 rounded shadow transition-colors">
                                    <i class="fas fa-download mr-1"></i> Download Deck (.md)
                                </button>
                            </div>
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
                            <h4 class="text-[10px] font-bold text-gray-200 uppercase mb-2">System Export & Voice Cache</h4>
                            <div class="flex flex-wrap gap-2">
                                <a href="api.php?action=download_zip" class="inline-block bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold py-2 px-4 rounded transition-colors">
                                    <i class="fas fa-file-archive mr-1"></i> Download Release ZIP
                                </a>
                                <a href="api.php?action=download_voice_cache_zip" class="inline-block bg-emerald-700 hover:bg-emerald-600 text-white text-xs font-bold py-2 px-4 rounded transition-colors">
                                    <i class="fas fa-microphone-alt mr-1"></i> Download Voice Cache ZIP
                                </a>
                            </div>
                            <p class="text-[9px] text-gray-400 mt-1">Download clean game release files (code, decks, themes) or secondary voice cache MP3 library.</p>
                        </div>
                    </div>
                </div>
            <?php }); ?>



        </main>

        <script>
            function handleDeckFileUpload(event) {
                const file = event.target.files[0];
                if (!file) return;
                document.getElementById('file-name-display').textContent = file.name;
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('deck_content_textarea').value = e.target.result;
                };
                reader.readAsText(file);
            }

            function toggleDuplicateSelections(type) {
                const groups = document.querySelectorAll('#resolve-duplicates-form .p-3.bg-gray-900');
                groups.forEach(group => {
                    const checkboxes = group.querySelectorAll('input[name="delete_cards[]"]');
                    checkboxes.forEach((cb, idx) => {
                        const deck = cb.value.split('::')[0];
                        if (type === 'all') {
                            cb.checked = true;
                        } else if (type === 'none') {
                            cb.checked = false;
                        } else if (type === 'first') {
                            cb.checked = (idx === 0);
                        } else if (type === 'second') {
                            cb.checked = (idx === 1);
                        } else if (type === 'non-base') {
                            cb.checked = (deck !== 'base_deck');
                        }
                    });
                });
            }

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

                // Strip backslashes
                cleaned = cleaned.replace(/\\/g, '');
                // Strip <i> and </i> tags
                cleaned = cleaned.replace(/<\/?i\b[^>]*>/gi, '');
                // Strip parenthesis characters ( and )
                cleaned = cleaned.replace(/[\(\)]/g, '');

                if (isBlack) {
                    cleaned = cleaned.replace(/[._\/]{4,}/g, '______');
                    cleaned = cleaned.replace(/_{2,}/g, '______');
                    cleaned = cleaned.replace(/\./g, '');
                    cleaned = cleaned.replace(/(\w)\s*______/g, '$1 ______');
                    cleaned = cleaned.replace(/______\s*(\w)/g, '______ $1');
                    cleaned = cleaned.replace(/______\s+([,;:?!])/g, '______$1');
                    cleaned = cleaned.replace(/ {2,}/g, ' ');
                } else {
                    cleaned = cleaned.replace(/_+/g, '');
                    cleaned = cleaned.replace(/\./g, '');
                    cleaned = cleaned.replace(/ {2,}/g, ' ');
                    cleaned = cleaned.trim();
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

            function testAIConnection() {
                const resDiv = document.getElementById('ai-test-result');
                resDiv.classList.remove('hidden', 'text-green-400', 'text-red-400');
                resDiv.className = 'text-xs mt-2 text-orange-400';
                resDiv.textContent = 'Testing connection...';

                const form = document.querySelector('#content-security form');
                const fd = new FormData(form);
                const provider = fd.get('ai_provider');
                const apiKey = provider === 'openai' ? fd.get('openai_api_key') : fd.get('gemini_api_key');
                fd.set('action', 'test_ai');
                fd.set('api_key', apiKey || '');

                fetch('api.php', {
                    method: 'POST',
                    body: fd
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        resDiv.className = 'text-xs mt-2 text-green-400';
                        resDiv.textContent = 'Success! ' + provider + ' replied: ' + (data.greeting || 'OK');
                    } else {
                        resDiv.className = 'text-xs mt-2 text-red-400';
                        let msg = 'Failed: ' + (data.error || 'Unknown error');
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
                const actionVal = formData.get('action');

                if (actionVal === 'add_card') {
                    const banner = document.getElementById('add-card-status-banner');
                    if (banner) {
                        banner.classList.remove('hidden');
                        banner.innerHTML = `<div class="p-2.5 bg-blue-950/80 border border-blue-500/60 rounded text-xs text-blue-200 font-bold shadow flex items-center gap-2"><i class="fas fa-spinner fa-spin text-orange-400"></i> Adding card to Orange Deck...</div>`;
                    }
                }

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

                        if (actionVal === 'import_deck') {
                            const banner = document.getElementById('import-status-banner');
                            if (banner) {
                                banner.classList.remove('hidden');
                                banner.innerHTML = `<div class="p-3 bg-green-900/80 border border-green-500 rounded text-xs text-green-100 font-bold mb-2 shadow"><i class="fas fa-check-circle mr-1"></i> ${data.msg || 'Deck successfully imported!'}</div>`;
                            }
                            if (clearTextareaId) {
                                const el = document.getElementById(clearTextareaId);
                                if (el) el.value = '';
                            }
                            setTimeout(() => {
                                window.location.reload();
                            }, 1200);
                            return;
                        }

                        if (actionVal === 'add_card') {
                            const banner = document.getElementById('add-card-status-banner');
                            if (banner) {
                                banner.classList.remove('hidden');
                                const totalText = data.total_orange_cards ? ` (${data.total_orange_cards} cards total in Orange Deck)` : '';
                                banner.innerHTML = `<div class="p-2.5 bg-green-950/80 border border-green-500/60 rounded text-xs text-green-200 font-bold shadow flex items-center gap-2"><i class="fas fa-check-circle text-green-400"></i> ${data.msg || 'Card added to Orange Deck.'}${totalText}</div>`;
                            }
                            const cardInput = document.getElementById('single_card_text_input');
                            if (cardInput) cardInput.value = '';

                            setTimeout(() => {
                                btn.innerHTML = originalText;
                                btn.disabled = false;
                                btn.classList.remove('bg-green-600');
                                btn.classList.add('bg-orange-600');
                            }, 1500);

                            setTimeout(() => {
                                window.location.reload();
                            }, 1200);
                            return;
                        }

                        setTimeout(() => {
                            if (clearTextareaId) {
                                const el = document.getElementById(clearTextareaId);
                                if (el) el.value = '';
                            }
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        }, 2000);
                    } else {
                        if (actionVal === 'import_deck') {
                            const banner = document.getElementById('import-status-banner');
                            if (banner) {
                                banner.classList.remove('hidden');
                                banner.innerHTML = `<div class="p-3 bg-red-900/80 border border-red-500 rounded text-xs text-red-100 font-bold mb-2 shadow"><i class="fas fa-times-circle mr-1"></i> ${data.msg || data.error || 'Import failed'}</div>`;
                            }
                        } else if (actionVal === 'add_card') {
                            const banner = document.getElementById('add-card-status-banner');
                            if (banner) {
                                banner.classList.remove('hidden');
                                banner.innerHTML = `<div class="p-2.5 bg-yellow-950/80 border border-yellow-500/60 rounded text-xs text-yellow-200 font-bold shadow flex items-center gap-2"><i class="fas fa-exclamation-triangle text-yellow-400"></i> ${data.msg || data.error || 'Failed to add card.'}</div>`;
                            }
                        } else {
                            alert('Error: ' + (data.msg || data.error || 'Unknown error'));
                        }
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                } catch (err) {
                    console.error(err);
                    if (actionVal === 'add_card') {
                        const banner = document.getElementById('add-card-status-banner');
                        if (banner) {
                            banner.classList.remove('hidden');
                            banner.innerHTML = `<div class="p-2.5 bg-red-950/80 border border-red-500/60 rounded text-xs text-red-200 font-bold shadow flex items-center gap-2"><i class="fas fa-times-circle text-red-400"></i> Network or server error adding card.</div>`;
                        }
                    }
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            }

            async function downloadFileSilently(url, defaultFilename = 'download.md') {
                try {
                    const res = await fetch(url);
                    if (!res.ok) throw new Error('Download request failed (' + res.status + ')');
                    const blob = await res.blob();
                    const disposition = res.headers.get('Content-Disposition');
                    let filename = defaultFilename;
                    if (disposition && disposition.includes('filename=')) {
                        const match = disposition.match(/filename="?([^";]+)"?/);
                        if (match && match[1]) filename = match[1];
                    }
                    const blobUrl = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = blobUrl;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    setTimeout(() => URL.revokeObjectURL(blobUrl), 2000);
                } catch (err) {
                    console.error('Silent download failed, falling back to direct iframe:', err);
                    triggerSilentFrameDownload(url);
                }
            }

            function triggerSilentFrameDownload(url) {
                let frame = document.getElementById('silent-download-frame');
                if (!frame) {
                    frame = document.createElement('iframe');
                    frame.id = 'silent-download-frame';
                    frame.style.display = 'none';
                    document.body.appendChild(frame);
                }
                frame.src = url;
            }

            async function performBackupMasterDeck() {
                const banner = document.getElementById('upload-master-status-banner');
                if (banner) {
                    banner.classList.remove('hidden');
                    banner.innerHTML = `<div class="p-3 bg-blue-950/80 border border-blue-500/60 rounded text-xs text-blue-200 font-bold mb-2 shadow flex items-center gap-2"><i class="fas fa-spinner fa-spin text-orange-400"></i> Backing up master deck...</div>`;
                }

                try {
                    const url = 'settings.php?action=backup_master_deck&download=1';
                    await downloadFileSilently(url, 'decks_backup.md');
                    if (banner) {
                        banner.innerHTML = `<div class="p-3 bg-green-950/80 border border-green-500/60 rounded text-xs text-green-200 font-bold mb-2 shadow flex items-center gap-2"><i class="fas fa-check-circle text-green-400"></i> Backup successfully created and downloading! Saved to data/backups/</div>`;
                        setTimeout(() => banner.classList.add('hidden'), 5000);
                    }
                } catch (err) {
                    console.error(err);
                    if (banner) {
                        banner.innerHTML = `<div class="p-3 bg-red-950/80 border border-red-500/60 rounded text-xs text-red-200 font-bold mb-2 shadow flex items-center gap-2"><i class="fas fa-times-circle text-red-400"></i> Backup failed: ${err.message}</div>`;
                    }
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
                    if (!res.ok) {
                        throw new Error('Network response was not OK (' + res.status + ')');
                    }
                    const data = await res.json();
                    if (data.error) {
                        throw new Error(data.error);
                    }

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
                    blackList.innerHTML = '<div class="text-center text-red-500 text-xs pt-10">Network/server error loading cards</div>';
                    whiteList.innerHTML = '<div class="text-center text-red-500 text-xs pt-10">Network/server error loading cards</div>';
                }
            }


            function exportCurrentInspectedDeck() {
                const modal = document.getElementById('deck-inspector-modal');
                const tag = modal.dataset.currentDeck || 'all';
                window.location.href = `settings.php?action=export_deck&deck_tag=${encodeURIComponent(tag)}`;
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
                    
                    div.innerHTML = `
                        <div class="flex items-center flex-1 mr-2 min-w-0 break-words">
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
            async function sanitizeAllDecks() {
                const resultsEl = document.getElementById('duplicate-check-results');
                resultsEl.classList.remove('hidden');
                resultsEl.innerHTML = `<span class="text-gray-400"><i class="fas fa-spinner fa-spin mr-1"></i>Scanning & sanitizing deck files (blanks -> ______, removing trailing periods)...</span>`;
                
                const fd = new FormData();
                fd.append('action', 'sanitize_all_decks');
                fd.append('ajax', '1');
                
                try {
                    const res = await fetch('settings.php', {
                        method: 'POST',
                        body: fd
                    });
                    const data = await res.json();
                    if (data.success) {
                        resultsEl.innerHTML = `<div class="text-green-400 font-bold"><i class="fas fa-check-circle mr-1"></i>${data.msg}</div>`;
                        setTimeout(() => checkDecksForDuplicates(), 1200);
                    } else {
                        resultsEl.innerHTML = `<div class="text-red-400 font-bold"><i class="fas fa-times-circle mr-1"></i>${data.msg || "Sanitization failed"}</div>`;
                    }
                } catch (err) {
                    console.error(err);
                    resultsEl.innerHTML = `<div class="text-red-400 font-bold"><i class="fas fa-times-circle mr-1"></i>Connection error.</div>`;
                }
            }

            async function checkDecksForDuplicates() {
                const resultsEl = document.getElementById('duplicate-check-results');
                resultsEl.classList.remove('hidden');
                resultsEl.innerHTML = `<span class="text-gray-400"><i class="fas fa-spinner fa-spin mr-1"></i>Scanning deck files for duplicate cards...</span>`;
                
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
                        html += `<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 mb-3 pb-2 border-b border-gray-700">
                                    <div class="text-orange-400 font-bold text-xs"><i class="fas fa-exclamation-triangle mr-1"></i>Found ${bDups.length + wDups.length} duplicate card group(s):</div>
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <button type="button" onclick="toggleDuplicateSelections('first')" class="bg-gray-800 hover:bg-gray-750 text-gray-300 text-[10px] font-bold py-1 px-2.5 rounded border border-gray-700">
                                            Select First
                                        </button>
                                        <button type="button" onclick="toggleDuplicateSelections('second')" class="bg-gray-800 hover:bg-gray-750 text-gray-300 text-[10px] font-bold py-1 px-2.5 rounded border border-gray-700">
                                            Select Second
                                        </button>
                                        <button type="button" onclick="toggleDuplicateSelections('non-base')" class="bg-gray-800 hover:bg-gray-750 text-gray-300 text-[10px] font-bold py-1 px-2.5 rounded border border-gray-700">
                                            Select Non-Base
                                        </button>
                                        <button type="button" onclick="toggleDuplicateSelections('all')" class="bg-gray-800 hover:bg-gray-750 text-gray-300 text-[10px] font-bold py-1 px-2.5 rounded border border-gray-700">
                                            Select All
                                        </button>
                                        <button type="button" onclick="toggleDuplicateSelections('none')" class="bg-gray-800 hover:bg-gray-750 text-gray-300 text-[10px] font-bold py-1 px-2.5 rounded border border-gray-700">
                                            Clear All
                                        </button>
                                        <button type="submit" class="bg-red-600 hover:bg-red-500 text-white text-[10px] font-bold py-1.5 px-3 rounded shadow transition-colors ml-1">
                                            <i class="fas fa-trash-alt mr-1"></i> Delete Selected
                                        </button>
                                    </div>
                                 </div>`;
                        
                        if (bDups.length > 0) {
                            html += `<div class="font-bold text-white mb-2 uppercase text-[11px] tracking-wider text-purple-300">Black Cards (${bDups.length} duplicates):</div><div class="space-y-2 mb-3">`;
                            bDups.forEach((dup, cardIdx) => {
                                const text = dup.text.replace(/"/g, '&quot;');
                                const hasBaseDeck = dup.locations.some(l => l.deck === 'base_deck');
                                html += `<div class="p-3 bg-gray-900 rounded border border-gray-700">
                                    <div class="text-gray-200 font-medium text-xs mb-2 bg-black/40 p-2 rounded border border-gray-800">"${text}"</div>
                                    <div class="text-[11px] text-gray-400 mb-1">Appears in ${dup.locations.length} deck locations — select which one(s) to remove:</div>
                                    <div class="flex flex-wrap gap-3 text-xs pl-1">`;
                                dup.locations.forEach((loc, locIdx) => {
                                    const id = `dup-b-${cardIdx}-${locIdx}`;
                                    const deckLabel = loc.deck.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                                    const isChecked = hasBaseDeck ? (loc.deck !== 'base_deck') : (locIdx > 0);
                                    const checkedAttr = isChecked ? 'checked' : '';
                                    html += `<label for="${id}" class="flex items-center cursor-pointer bg-gray-800 px-2 py-1 rounded border border-gray-700 hover:border-orange-500 transition-colors">
                                        <input type="checkbox" id="${id}" name="delete_cards[]" value="${loc.deck}::${text}" ${checkedAttr} class="accent-orange-500 mr-2 w-3.5 h-3.5">
                                        <span class="text-orange-300 font-semibold">${deckLabel}</span>
                                    </label>`;
                                });
                                html += `</div></div>`;
                            });
                            html += `</div>`;
                        }
                        
                        if (wDups.length > 0) {
                            html += `<div class="font-bold text-white mb-2 uppercase text-[11px] tracking-wider text-blue-300">White Cards (${wDups.length} duplicates):</div><div class="space-y-2">`;
                            wDups.forEach((dup, cardIdx) => {
                                const text = dup.text.replace(/"/g, '&quot;');
                                const hasBaseDeck = dup.locations.some(l => l.deck === 'base_deck');
                                html += `<div class="p-3 bg-gray-900 rounded border border-gray-700">
                                    <div class="text-gray-200 font-medium text-xs mb-2 bg-black/40 p-2 rounded border border-gray-800">"${text}"</div>
                                    <div class="text-[11px] text-gray-400 mb-1">Appears in ${dup.locations.length} deck locations — select which one(s) to remove:</div>
                                    <div class="flex flex-wrap gap-3 text-xs pl-1">`;
                                dup.locations.forEach((loc, locIdx) => {
                                    const id = `dup-w-${cardIdx}-${locIdx}`;
                                    const deckLabel = loc.deck.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                                    const isChecked = hasBaseDeck ? (loc.deck !== 'base_deck') : (locIdx > 0);
                                    const checkedAttr = isChecked ? 'checked' : '';
                                    html += `<label for="${id}" class="flex items-center cursor-pointer bg-gray-800 px-2 py-1 rounded border border-gray-700 hover:border-orange-500 transition-colors">
                                        <input type="checkbox" id="${id}" name="delete_cards[]" value="${loc.deck}::${text}" ${checkedAttr} class="accent-orange-500 mr-2 w-3.5 h-3.5">
                                        <span class="text-orange-300 font-semibold">${deckLabel}</span>
                                    </label>`;
                                });
                                html += `</div></div>`;
                            });
                            html += `</div>`;
                        }
                        
                        html += `</form>`;
                        resultsEl.innerHTML = html;
                    } else {
                        resultsEl.innerHTML = `<div class="text-red-400 font-bold"><i class="fas fa-times-circle mr-1"></i>Scan failed: ${data.msg || data.error || 'Unknown error'}</div>`;
                    }
                } catch(e) {
                    console.error(e);
                    resultsEl.innerHTML = `<div class="text-red-400 font-bold"><i class="fas fa-times-circle mr-1"></i>Error running duplicate scan. ${e.message || ''}</div>`;
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

            function handleMasterDeckFileUpload(event) {
                const input = event.target;
                const file = input.files[0];
                const display = document.getElementById('master-file-name-display');
                if (file) {
                    display.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
                } else {
                    display.textContent = 'No file selected';
                }
            }

            async function generateUploadPreview() {
                const fileInput = document.getElementById('master_deck_file_input');
                if (!fileInput.files || fileInput.files.length === 0) {
                    alert('Please select a file to scan first.');
                    return;
                }

                const banner = document.getElementById('upload-master-status-banner');
                banner.className = 'hidden';

                const fd = new FormData();
                fd.append('action', 'validate_master_preview');
                fd.append('ajax', '1');
                fd.append('master_deck_file', fileInput.files[0]);
                fd.append('sanitize_master', '1');

                const scanBtn = document.querySelector('button[onclick="generateUploadPreview()"]');
                const origText = scanBtn.innerHTML;
                scanBtn.disabled = true;
                scanBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Scanning File...';

                try {
                    const res = await fetch('settings.php', {
                        method: 'POST',
                        body: fd
                    });
                    const data = await res.json();

                    if (data.success) {
                        const tbody = document.getElementById('preview-report-table-body');
                        
                        const allSlugs = new Set();
                        const currentMap = {};
                        const newMap = {};

                        data.current_decks.forEach(d => {
                            allSlugs.add(d.slug);
                            currentMap[d.slug] = d;
                        });

                        data.new_decks.forEach(d => {
                            allSlugs.add(d.slug);
                            newMap[d.slug] = d;
                        });

                        const sortedSlugs = Array.from(allSlugs).sort((a, b) => {
                            if (a === 'base_deck') return -1;
                            if (b === 'base_deck') return 1;
                            return a.localeCompare(b);
                        });

                        let tableHtml = '';
                        sortedSlugs.forEach(slug => {
                            const curr = currentMap[slug];
                            const newD = newMap[slug];

                            const label = (newD ? newD.label : curr.label) || slug;
                            const displayLabel = label.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

                            let currentCell = '<div class="text-center text-gray-500 font-medium italic">—</div>';
                            if (curr) {
                                const total = curr.black + curr.white;
                                currentCell = `<div class="text-center font-bold text-gray-300">${total} <span class="text-[10px] text-gray-500">(${curr.black}B / ${curr.white}W)</span></div>`;
                            }

                            let newCell = '<div class="text-center text-gray-500 font-medium italic">—</div>';
                            if (newD) {
                                const total = newD.black + newD.white;
                                newCell = `<div class="text-center font-bold text-orange-400">${total} <span class="text-[10px] text-orange-500/70">(${newD.black}B / ${newD.white}W)</span></div>`;
                            }

                            let statusBadge = '';
                            if (curr && !newD) {
                                statusBadge = '<span class="px-2 py-0.5 rounded text-[10px] font-black bg-red-950/40 text-red-400 border border-red-900/40">Deleted</span>';
                            } else if (!curr && newD) {
                                statusBadge = '<span class="px-2 py-0.5 rounded text-[10px] font-black bg-green-950/40 text-green-400 border border-green-900/40">New Deck</span>';
                            } else {
                                const currTotal = curr.black + curr.white;
                                const newTotal = newD.black + newD.white;
                                if (currTotal !== newTotal || curr.black !== newD.black || curr.white !== newD.white) {
                                    statusBadge = '<span class="px-2 py-0.5 rounded text-[10px] font-black bg-blue-950/40 text-blue-400 border border-blue-900/40">Updated</span>';
                                } else {
                                    statusBadge = '<span class="px-2 py-0.5 rounded text-[10px] font-black bg-gray-900 text-gray-500 border border-gray-800">Unchanged</span>';
                                }
                            }

                            tableHtml += `<tr class="border-b border-gray-800 hover:bg-gray-800/20">
                                <td class="p-2.5 font-bold text-gray-200">${displayLabel}</td>
                                <td class="p-2.5">${currentCell}</td>
                                <td class="p-2.5">${newCell}</td>
                                <td class="p-2.5 text-center">${statusBadge}</td>
                            </tr>`;
                        });

                        tbody.innerHTML = tableHtml;

                        // Switch view steps
                        document.getElementById('master-upload-step-1').classList.add('hidden');
                        document.getElementById('master-upload-step-2').classList.remove('hidden');
                    } else {
                        banner.className = 'p-3 bg-red-900/60 border border-red-600 text-red-400 rounded text-xs font-bold whitespace-pre-wrap';
                        banner.innerHTML = `<i class="fas fa-times-circle mr-1"></i> ${data.error || 'Scan failed'}`;
                        banner.classList.remove('hidden');
                    }
                } catch(e) {
                    console.error(e);
                    banner.className = 'p-3 bg-red-900/60 border border-red-600 text-red-400 rounded text-xs font-bold';
                    banner.innerHTML = `<i class="fas fa-times-circle mr-1"></i> Connection error occurred while scanning.`;
                    banner.classList.remove('hidden');
                } finally {
                    scanBtn.innerHTML = origText;
                    scanBtn.disabled = false;
                }
            }

            function resetUploadSteps() {
                document.getElementById('master-upload-step-2').classList.add('hidden');
                document.getElementById('master-upload-step-1').classList.remove('hidden');
            }

            async function commitMasterOverwrite() {
                const fileInput = document.getElementById('master_deck_file_input');
                if (!fileInput.files || fileInput.files.length === 0) {
                    alert('Please select a file first.');
                    return;
                }

                if (!confirm('Are you absolutely sure? This will delete all custom imported decks, bans, edits, and set this file as the new master decks list.')) {
                    return;
                }

                const commitBtn = document.getElementById('commit-overwrite-btn');
                const origText = commitBtn.innerHTML;
                commitBtn.disabled = true;
                commitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Overwriting & Resetting State...';

                const banner = document.getElementById('upload-master-status-banner');
                banner.className = 'hidden';

                const fd = new FormData();
                fd.append('action', 'upload_master_deck');
                fd.append('ajax', '1');
                fd.append('master_deck_file', fileInput.files[0]);
                fd.append('sanitize_master', '1');

                try {
                    const res = await fetch('settings.php', {
                        method: 'POST',
                        body: fd
                    });
                    const data = await res.json();

                    if (data.success) {
                        banner.className = 'p-3 bg-green-900/60 border border-green-600 text-green-400 rounded text-xs font-bold shadow-lg';
                        banner.innerHTML = `<i class="fas fa-check-circle mr-1"></i> ${data.msg}`;
                        banner.classList.remove('hidden');
                        
                        document.getElementById('master-file-name-display').textContent = 'No file selected';
                        fileInput.value = '';
                        
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    } else {
                        banner.className = 'p-3 bg-red-900/60 border border-red-600 text-red-400 rounded text-xs font-bold whitespace-pre-wrap';
                        banner.innerHTML = `<i class="fas fa-times-circle mr-1"></i> ${data.error || 'Upload failed'}`;
                        banner.classList.remove('hidden');
                        commitBtn.innerHTML = origText;
                        commitBtn.disabled = false;
                    }
                } catch (err) {
                    console.error(err);
                    banner.className = 'p-3 bg-red-900/60 border border-red-600 text-red-400 rounded text-xs font-bold';
                    banner.innerHTML = `<i class="fas fa-times-circle mr-1"></i> Connection or write error occurred.`;
                    banner.classList.remove('hidden');
                    commitBtn.innerHTML = origText;
                    commitBtn.disabled = false;
                }
            }
        </script>
        <script>
            // Voice control & accordion functions
            function selectTTSEngine(provider) {
                const radio = document.querySelector(`input[name="tts_provider"][value="${provider}"]`);
                if (radio && !radio.disabled) {
                    radio.checked = true;
                }
                
                ['browser', 'google', 'elevenlabs', 'openai'].forEach(p => {
                    const panel = document.getElementById(`tts-panel-${p}`);
                    if (panel) {
                        if (p === provider) {
                            panel.classList.remove('hidden');
                        } else {
                            panel.classList.add('hidden');
                        }
                    }
                });
            }

            function applyElevenLabsPreset(voiceId) {
                if (voiceId) {
                    const input = document.getElementById('admin-elevenlabs-voice');
                    if (input) input.value = voiceId;
                }
            }

            function saveCustomElevenLabsPreset() {
                const voiceId = document.getElementById('admin-elevenlabs-voice').value.trim();
                if (!voiceId) {
                    alert('Please enter an ElevenLabs Voice ID first.');
                    return;
                }
                const presetName = prompt('Enter a name for this ElevenLabs Voice Preset:', 'My Custom Voice');
                if (!presetName) return;

                fetch('settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'save_elevenlabs_preset',
                        preset_name: presetName,
                        voice_id: voiceId,
                        ajax: '1'
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Voice preset saved successfully!');
                        const select = document.getElementById('elevenlabs-preset-select');
                        if (select) {
                            const opt = document.createElement('option');
                            opt.value = voiceId;
                            opt.textContent = `${presetName}`;
                            opt.selected = true;
                            select.appendChild(opt);
                        }
                    } else {
                        alert('Failed to save preset: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Network error while saving preset.');
                });
            }

            function populateChromeVoices() {
                const voiceSelect = document.getElementById('admin-chrome-voice');
                if (!voiceSelect) return;
                
                const populateVoiceList = () => {
                    const voices = window.speechSynthesis.getVoices();
                    voiceSelect.innerHTML = '';
                    
                    if (voices.length === 0) {
                        voiceSelect.innerHTML = '<option value="">No browser voices available</option>';
                        return;
                    }

                    const savedVoice = localStorage.getItem('game_tts_voice_name') || '';

                    // Sort English voices first, then other languages
                    const english = voices.filter(v => v.lang && v.lang.startsWith('en')).sort((a, b) => a.name.localeCompare(b.name));
                    const others = voices.filter(v => !v.lang || !v.lang.startsWith('en')).sort((a, b) => a.name.localeCompare(b.name));

                    if (english.length > 0) {
                        const grpEn = document.createElement('optgroup');
                        grpEn.label = 'English Installed Voices';
                        english.forEach(v => {
                            const opt = document.createElement('option');
                            opt.value = v.name;
                            opt.textContent = `${v.name} (${v.lang})`;
                            if (savedVoice && v.name === savedVoice) opt.selected = true;
                            grpEn.appendChild(opt);
                        });
                        voiceSelect.appendChild(grpEn);
                    }

                    if (others.length > 0) {
                        const grpOther = document.createElement('optgroup');
                        grpOther.label = 'Other Languages';
                        others.forEach(v => {
                            const opt = document.createElement('option');
                            opt.value = v.name;
                            opt.textContent = `${v.name} (${v.lang})`;
                            if (savedVoice && v.name === savedVoice) opt.selected = true;
                            grpOther.appendChild(opt);
                        });
                        voiceSelect.appendChild(grpOther);
                    }

                    if (savedVoice) {
                        voiceSelect.value = savedVoice;
                    }
                };
                
                if (window.speechSynthesis.onvoiceschanged !== undefined) {
                    window.speechSynthesis.onvoiceschanged = populateVoiceList;
                }
                
                populateVoiceList();
            }
            
            function testElevenLabsVoice() {
                const apiKey = document.querySelector('input[name="elevenlabs_api_key"]').value;
                const voiceId = document.getElementById('admin-elevenlabs-voice').value || '21m00Tcm4TlvDq8ikWAM';
                
                if (!apiKey) {
                    showTestResult('test-elevenlabs-result', 'Please enter your ElevenLabs API key');
                    return;
                }
                
                showTestResult('test-elevenlabs-result', 'Testing ElevenLabs voice...');
                
                fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'test_elevenlabs_voice',
                        api_key: apiKey,
                        voice_id: voiceId,
                        ajax: '1'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showTestResult('test-elevenlabs-result', `Success! ${data.message || 'ElevenLabs connected!'}`);
                        if (data.audio_data) {
                            playAudioSample(data.audio_data);
                        }
                    } else {
                        showTestResult('test-elevenlabs-result', `Failed: ${data.error || 'Unknown error'}`);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showTestResult('test-elevenlabs-result', 'Network error occurred');
                });
            }

            function testGoogleVoice() {
                const apiKey = document.querySelector('input[name="google_tts_api_key"]').value;
                const voiceName = document.getElementById('admin-google-voice').value;
                
                if (!apiKey) {
                    showTestResult('test-google-result', 'Please enter your Google Cloud TTS API key');
                    return;
                }
                
                if (!voiceName) {
                    showTestResult('test-google-result', 'Please select a voice');
                    return;
                }
                
                showTestResult('test-google-result', 'Testing Google voice...');
                
                fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'test_google_voice',
                        api_key: apiKey,
                        voice: voiceName,
                        ajax: '1'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showTestResult('test-google-result', `Success! Voice tested: ${data.message || 'OK'}`);
                        
                        // Try to play a sample if audio data is provided
                        if (data.audio_data) {
                            playAudioSample(data.audio_data);
                        }
                    } else {
                        showTestResult('test-google-result', `Failed: ${data.error || 'Unknown error'}`);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showTestResult('test-google-result', 'Network error occurred');
                });
            }
            
            function testOpenAIVoice() {
                const apiKey = document.querySelector('input[name="openai_api_key"]').value;
                const voiceName = document.getElementById('admin-openai-voice').value;
                
                if (!apiKey) {
                    showTestResult('test-openai-result', 'Please enter your OpenAI API key');
                    return;
                }
                
                if (!voiceName) {
                    showTestResult('test-openai-result', 'Please select a voice');
                    return;
                }
                
                showTestResult('test-openai-result', 'Testing OpenAI voice...');
                
                fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'test_openai_voice',
                        api_key: apiKey,
                        voice: voiceName,
                        ajax: '1'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showTestResult('test-openai-result', `Success! Voice tested: ${data.message || 'OK'}`);
                        
                        // Try to play a sample if audio data is provided
                        if (data.audio_data) {
                            playAudioSample(data.audio_data);
                        }
                    } else {
                        showTestResult('test-openai-result', `Failed: ${data.error || 'Unknown error'}`);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showTestResult('test-openai-result', 'Network error occurred');
                });
            }
            
            function testChromeVoice() {
                const voiceName = document.getElementById('admin-chrome-voice').value;
                
                if (!voiceName) {
                    showTestResult('test-chrome-result', 'Please select a voice');
                    return;
                }
                
                showTestResult('test-chrome-result', 'Testing Chrome voice...');
                
                // Use Web Speech API to test the voice
                if ('speechSynthesis' in window) {
                    // Cancel any ongoing speech
                    window.speechSynthesis.cancel();
                    
                    const utterance = new SpeechSynthesisUtterance('Hello, this is a test of the text to speech system.');
                    utterance.voice = window.speechSynthesis.getVoices().find(v => v.name === voiceName) || 
                                     window.speechSynthesis.getVoices()[0];
                    utterance.rate = 1;
                    utterance.pitch = 1;
                    utterance.volume = 1;
                    
                    utterance.onend = () => {
                        showTestResult('test-chrome-result', 'Voice test completed successfully!');
                    };
                    
                    utterance.onerror = (event) => {
                        showTestResult('test-chrome-result', `Voice test failed: ${event.error}`);
                    };
                    
                    window.speechSynthesis.speak(utterance);
                } else {
                    showTestResult('test-chrome-result', 'Speech synthesis not supported in this browser');
                }
            }
            
            function showTestResult(elementId, message) {
                const resultDiv = document.getElementById(elementId);
                if (resultDiv) {
                    resultDiv.textContent = message;
                    resultDiv.className = 'mt-2 text-[10px] p-2 bg-gray-900 rounded border border-gray-700';
                    resultDiv.classList.remove('hidden');
                }
            }
            
            function playAudioSample(base64Audio) {
                try {
                    const audioData = atob(base64Audio);
                    const arrayBuffer = new ArrayBuffer(audioData.length);
                    const uintArray = new Uint8Array(arrayBuffer);
                    
                    for (let i = 0; i < audioData.length; i++) {
                        uintArray[i] = audioData.charCodeAt(i);
                    }
                    
                    const blob = new Blob([uintArray], { type: 'audio/wav' });
                    const url = URL.createObjectURL(blob);
                    const audio = new Audio(url);
                    audio.play();
                } catch (e) {
                    console.error('Error playing audio sample:', e);
                }
            }
            
            // Initialize voice controls when page loads
            document.addEventListener('DOMContentLoaded', function() {
                populateChromeVoices();
                selectTTSEngine('<?php echo $globalConfig['tts_provider'] ?? 'browser'; ?>');
            });
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
