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
            echo json_decode(['success' => false, 'error' => 'No file uploaded.']);
            exit;
        }
    }
}
