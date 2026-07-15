<?php

/**
 * Filename: index.php
 * Date: July 14, 2026
 * Version: 4.9 - Disallow Google TTS key fallback for Gemini AI calls
 * Changes:
 *   - Upgraded version number to 4.9.
 *   - Consolidated API, deck validation, and system controls.
 */
session_start();

define('AGAINST_COOKIE', 'against_profile');
define('AGAINST_COOKIE_DAYS', 30);

/**
 * @param string $userId
 * @param string $name
 * @param string $avatarType
 * @param string $avatarVal
 */
function setProfileCookie($userId, $name, $avatarType, $avatarVal) {
    $payload = base64_encode(json_encode([
        'uid' => $userId,
        'name' => $name,
        'at'  => $avatarType,
        'av'  => $avatarVal,
    ]));
    setcookie(AGAINST_COOKIE, $payload, time() + (AGAINST_COOKIE_DAYS * 86400), '/', '', false, true);
}

function clearProfileCookie() {
    setcookie(AGAINST_COOKIE, '', time() - 3600, '/');
}

function getReservedNames() {
    $f = __DIR__ . '/data/reserved_names.json';
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}

/**
 * @param array<string, string> $map
 */
function saveReservedNames($map) {
    @file_put_contents(__DIR__ . '/data/reserved_names.json', json_encode($map, JSON_PRETTY_PRINT));
}

// Restore persistent profile from cookie before session check
if (!isset($_SESSION['user_name']) && isset($_COOKIE[AGAINST_COOKIE])) {
    $c = json_decode(base64_decode($_COOKIE[AGAINST_COOKIE]), true);
    if ($c && !empty($c['uid']) && !empty($c['name'])) {
        $_SESSION['user_id']          = $c['uid'];
        $_SESSION['user_name']        = $c['name'];
        $_SESSION['user_avatar_type'] = $c['at'] ?? 'dicebear';
        $_SESSION['user_avatar_val']  = $c['av'] ?? '';
        $_SESSION['name_source']      = 'hand_entered';
        $_SESSION['profile_set']      = true;
        // Renew cookie on each visit so the 30 days resets from last activity
        setProfileCookie($c['uid'], $c['name'], $c['at'] ?? 'dicebear', $c['av'] ?? '');
    }
}

// Handle Profile Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_profile') {
    // Ensure user_id is set before processing
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = uniqid('usr_');
    }
    $userId     = $_SESSION['user_id'];
    $rawName    = trim(htmlspecialchars($_POST['username']));
    $avatarType = $_POST['avatar_type'] ?? 'dicebear';
    $avatarVal  = $_POST['avatar_val'] ?? '';
    $nameSource = ($_POST['name_source'] ?? 'hand_entered') === 'random' ? 'random' : 'hand_entered';
    $password   = trim($_POST['password'] ?? '');

    // Check for reserved user password match
    $globalConfigFile = __DIR__ . '/data/global_config.json';
    if (file_exists($globalConfigFile)) {
        $globalConfig = json_decode(file_get_contents($globalConfigFile), true);
        $reservedUsers = $globalConfig['reserved_users'] ?? [];
        foreach ($reservedUsers as $ru) {
            if (!empty($ru['password']) && strcasecmp($rawName, $ru['password']) === 0) {
                // Password matched, assign the reserved user's properties
                $rawName = $ru['username'];
                $avatarType = $ru['avatar'] ?? 'dicebear';
                // Use username for DiceBear seed if it's the chosen avatar type
                $avatarVal = ($avatarType === 'dicebear') ? $rawName : ($ru['avatar_val'] ?? $rawName);
                break;
            }
        }
    }
    
    // If not a random name, handle password/ownership
    if ($nameSource !== 'random') {
        $reserved = getReservedNames();
        $key = strtolower($rawName);

        // Check if name is owned and password matches
        $isCustomAvatar = ($avatarType === 'upload' && !empty($avatarVal));
        if (isset($reserved[$key])) {
            if (isset($reserved[$key]['pass']) && !password_verify($password, $reserved[$key]['pass'])) {
                 // Name taken, wrong password
                $_SESSION['profile_error'] = "This name is taken. Please enter the correct password or choose a different name.";
                header("Location: index.php");
                exit;
            }
        } elseif ($password !== '' && !$isCustomAvatar) {
            // Claim this name for this user
            $reserved[$key] = [
                'id' => $userId,
                'pass' => password_hash($password, PASSWORD_DEFAULT)
            ];
            saveReservedNames($reserved);
        }
    }

    $_SESSION['user_name']        = $rawName;
    $_SESSION['user_avatar_type'] = $avatarType;
    $_SESSION['user_avatar_val']  = $avatarVal;
    $_SESSION['name_source']      = $nameSource;
    $_SESSION['profile_set']      = true;

    if ($nameSource === 'hand_entered') {
        setProfileCookie($userId, $rawName, $avatarType, $avatarVal);
    } else {
        clearProfileCookie(); // Random names don't persist across sessions
    }

    header("Location: index.php");
    exit;
}

// Default Session Init
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = uniqid('usr_');
}
$profileSet = $_SESSION['profile_set'] ?? false;

// Load Global Config
$configFile = __DIR__ . '/data/global_config.json';
$globalConfig = [];
if (file_exists($configFile)) $globalConfig = json_decode(file_get_contents($configFile), true);

// Theme data for names
$themesFile = __DIR__ . '/data/themes.json';
$themes = file_exists($themesFile) ? (json_decode(file_get_contents($themesFile), true) ?: []) : [];
$currentThemeKey = $globalConfig['default_theme'] ?? 'default';
$currentTheme = $themes[$currentThemeKey] ?? ($themes['default'] ?? []);
$themeRoomNames = $currentTheme['room_names'] ?? [];
$themeCharacterNames = $currentTheme['character_names'] ?? [];
$defaultCharacterName = '';
if (!empty($themeCharacterNames)) {
    $defaultCharacterName = $themeCharacterNames[array_rand($themeCharacterNames)];
}

// Config defaults
$staleDays = $globalConfig['stale_days'] ?? 2;
$staleSeconds = $staleDays * 86400;
$gameTitle = 'Cards Against ' . ($currentTheme['game_name_suffix'] ?? 'Everyone');
$introUrl = !empty($currentTheme['banner_media']) ? $currentTheme['banner_media'] : ($globalConfig['intro_media_url'] ?? 'ocah.png');
$introType = $globalConfig['intro_media_type'] ?? 'image';
if (!empty($currentTheme['banner_media'])) {
    $introType = preg_match('/\.(mp4|webm|ogg)$/i', $currentTheme['banner_media']) ? 'video' : 'image';
}

// Get Active Rooms & Cleanup (delete zero-player rooms immediately)
$rooms = [];
$files = glob(__DIR__ . '/data/room_*.json');
$currentUserId = $_SESSION['user_id'];
$userActiveRooms = [];
if ($files) {
    foreach ($files as $f) {
        $json = file_get_contents($f);
        $data = json_decode($json, true);

        if (!$data) {
            @unlink($f); // Corrupt
            continue;
        }

        $lastActive = $data['updated_at'] ?? 0;
        $age = time() - $lastActive;

        // Delete if older than configured stale days
        if ($age > $staleSeconds) {
            @unlink($f);
            continue;
        }

        // Delete zero-player rooms (cleanup) on each lobby load
        $playerCount = count($data['players'] ?? []);
        if ($playerCount === 0) {
            @unlink($f);
            continue;
        }

        // Track rooms the current user is in
        foreach (($data['players'] ?? []) as $p) {
            if (($p['id'] ?? '') === $currentUserId) {
                $userActiveRooms[] = $data;
                break;
            }
        }

        // Check Idle Status (30 mins = 1800s)
        if ($playerCount > 0 && $age > 1800) {
            $data['is_idle'] = true;
        } else {
            $data['is_idle'] = false;
        }

        $rooms[] = $data;
    }
}

$hasBlockingGame = false;
foreach ($userActiveRooms as $activeRoom) {
    if (empty($activeRoom['paused']) && ($activeRoom['state'] ?? '') !== 'finished') {
        foreach (($activeRoom['players'] ?? []) as $p) {
            if (($p['id'] ?? '') === $currentUserId) {
                if (empty($p['afk'])) {
                    $hasBlockingGame = true;
                    break 2;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lobby</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <style>
        body {
            background-color: #1a1b1e;
            color: white;
            font-family: sans-serif;
        }

        .nav-link {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            font-weight: 700;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: color 0.3s;
        }

        .nav-link:hover {
            color: white;
        }

        .nav-link.active {
            color: #f97316;
            border-bottom: 2px solid #f97316;
        }

        .btn-primary {
            background-color: #f97316;
            color: white;
            font-weight: 700;
            border-radius: 0.375rem;
            transition: background-color 0.3s;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }

        .btn-primary:hover {
            background-color: #ea580c;
        }

        .btn-secondary {
            border: 1px solid #4b5563;
            color: #d1d5db;
            font-weight: 700;
            border-radius: 0.375rem;
            transition: background-color 0.3s;
        }

        .btn-secondary:hover {
            background-color: #374151;
        }

        /* Avatar Selection Styles */
        .avatar-option {
            border: 2px solid transparent;
            border-radius: 0.5rem;
            padding: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .avatar-option:hover {
            background-color: #1f2937;
        }

        .avatar-option.selected {
            border-color: #f97316;
            background-color: #1f2937;
        }

        /* Stack Effect for DiceBear */
        .avatar-stack {
            box-shadow: 3px 3px 0 #374151, 6px 6px 0 #1f2937;
            transition: all 0.2s;
        }

        .avatar-stack:active {
            transform: translate(3px, 3px);
            box-shadow: 3px 3px 0 #1f2937;
        }
    </style>
</head>

<body class="flex flex-col min-h-screen bg-gradient-to-b from-[#1a1b1e] to-[#111214]">

    <!-- BRANDING BANNER (Line 1) -->
    <div class="bg-[#141517] border-b border-gray-800 py-2 flex-none">
        <div class="max-w-4xl mx-auto px-4 flex items-center justify-center gap-2">
            <i class="fas fa-tshirt text-orange-500 text-xl sm:text-2xl"></i>
            <h1 class="text-lg sm:text-xl font-black uppercase tracking-[0.15em] text-gray-200">
                <?php echo htmlspecialchars($gameTitle); ?>
            </h1>
        </div>
    </div>

    <!-- LOBBY HEADER (Line 2) - Status & Menu -->
    <div class="bg-[#141517] border-b border-gray-800 sticky top-0 z-50 shadow-md flex-none">
        <div class="max-w-4xl mx-auto px-4 py-3 flex justify-between items-center">

            <!-- LEFT: Lobby Status / Theme -->
            <div class="text-xs sm:text-sm text-gray-200 font-bold uppercase tracking-wider">
                <i class="fas fa-palette mr-2 text-orange-500"></i>Theme: <span class="text-orange-400"><?php echo htmlspecialchars($currentTheme['label'] ?? ucfirst($currentThemeKey)); ?></span>
            </div>

            <!-- RIGHT: Menu Actions -->
            <div class="flex items-center gap-2 sm:gap-3 text-gray-200 text-base sm:text-lg flex-shrink-0">
                <!-- Refresh Lobby -->
                <button type="button" onclick="location.reload();" class="hover:text-white transition-colors p-1" title="Refresh Lobby">
                    <i class="fas fa-sync-alt"></i>
                </button>

                <!-- Gallery -->
                <a href="gallery.php" class="hover:text-white transition-colors p-1" title="Game Gallery">
                    <i class="fas fa-images"></i>
                </a>

                <!-- Settings -->
                <a href="settings.php" class="hover:text-white transition-colors p-1" title="Settings">
                    <i class="fas fa-cog"></i>
                </a>

                <!-- User Profile -->
                <button type="button" onclick="promptForUsername()" class="flex items-center gap-2 bg-gray-800/50 py-1 pl-2 pr-1 rounded-full border border-gray-700 hover:border-orange-500 hover:bg-gray-800 transition-colors">
                    <span class="text-xs font-bold text-gray-300 hidden sm:inline"><?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'No Name', 0, 10)); ?></span>
                    <div class="w-6 h-6 rounded-full bg-gray-600 overflow-hidden border-2 border-orange-500 shadow-sm flex items-center justify-center">
                        <?php
                        $profileSet = $_SESSION['profile_set'] ?? false;
                        $aType = $profileSet ? ($_SESSION['user_avatar_type'] ?? 'dicebear') : 'dicebear';
                        $aVal = $profileSet ? ($_SESSION['user_avatar_val'] ?? $_SESSION['user_name']) : 'new_user';
                        if ($aType === 'dicebear') {
                            echo '<img src="https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($aVal) . '" alt="Avatar">';
                        } elseif ($aType === 'gen_m') {
                            echo '<i class="fas fa-user text-blue-400"></i>';
                        } elseif ($aType === 'gen_f') {
                            echo '<i class="fas fa-user text-pink-400"></i>';
                        } elseif ($aType === 'upload') {
                            echo '<img src="' . $aVal . '" alt="Avatar" class="w-full h-full object-cover">';
                        } else {
                            echo '<img src="https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($aVal) . '" alt="Avatar">';
                        }
                        ?>
                    </div>
                </button>
            </div>
        </div>
    </div>

    <script>
        function leaveAllGames() {
            fetch('api.php', {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: 'leave_all_rooms'
                    })
                })
                .then(() => {
                    window.location.reload();
                })
                .catch(() => {
                    window.location.reload();
                });
        }
    </script>



    <script>
        document.addEventListener('DOMContentLoaded', () => {
            <?php if (!empty($currentTheme['intro_audio_url'])): ?>
            try {
                let plays = parseInt(sessionStorage.getItem('lobby_audio_counter') || '0');
                plays++;
                sessionStorage.setItem('lobby_audio_counter', plays);
                if (plays % 5 === 1) {
                    const introAudio = new Audio('<?php echo htmlspecialchars($currentTheme['intro_audio_url']); ?>');
                    introAudio.play().catch(e => console.warn("Intro audio playback failed:", e));
                }
            } catch (e) {
                console.error("Could not play intro audio:", e);
            }
            <?php endif; ?>
        });
    </script>

    <!-- LOBBY MESSAGE (from redirects) -->
    <?php if (isset($_SESSION['lobby_message'])): ?>
        <div class="bg-blue-900/40 text-blue-200 border border-blue-700 text-xs font-bold uppercase tracking-wider py-2 px-4 text-center">
            <i class="fas fa-info-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['lobby_message']); ?>
        </div>
    <?php unset($_SESSION['lobby_message']);
    endif; ?>

    <!-- ACTIVE SESSION NOTICE -->
    <?php if (!empty($userActiveRooms)): ?>
        <div class="bg-yellow-900/40 text-yellow-200 border border-yellow-700 text-xs font-bold uppercase tracking-wider py-2 px-4 text-center flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-center">
            <span>You are still in <?php echo count($userActiveRooms); ?> game<?php echo count($userActiveRooms) === 1 ? '' : 's'; ?> as <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'You'); ?>.</span>
            <div class="flex gap-3 justify-center items-center">
                <a href="game.php?room_id=<?php echo htmlspecialchars($userActiveRooms[0]['id']); ?>" class="underline text-yellow-100">Rejoin latest</a>
                <button onclick="leaveAllGames()" class="bg-yellow-700 hover:bg-yellow-600 text-white px-3 py-1 rounded border border-yellow-400 uppercase text-[10px] font-black">Leave all games</button>
            </div>
        </div>
    <?php endif; ?>

    <!-- USERNAME SETUP MODAL (Replaces old profile modal) -->
    <div id="username-modal" class="hidden fixed inset-0 z-[100] bg-black/90 flex items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-[#25262b] w-full max-w-sm rounded-xl shadow-2xl border border-gray-700 overflow-hidden">
            <div class="bg-gradient-to-r from-orange-600 to-orange-500 p-4">
                <h2 class="text-lg font-bold text-white uppercase tracking-widest"><i class="fas fa-user-astronaut mr-2"></i> Choose Your Name</h2>
            </div>

            <form id="profile-form" onsubmit="return setUsernameAndProceed(event)" class="p-6 space-y-4">
                <input type="hidden" id="next_action" value="">
                <input type="hidden" id="next_room_id" value="">
                <input type="hidden" name="name_source" id="name_source" value="hand_entered">

                <div>
                    <label class="block text-xs font-bold text-gray-200 uppercase mb-2 flex items-center justify-between">
                        <span>Codename</span>
                        <button type="button" onclick="assignRandomPresetName()" class="text-[10px] bg-gray-700 hover:bg-gray-600 px-2 py-1 rounded border border-gray-600 uppercase tracking-wider transition-colors">
                            <i class="fas fa-dice mr-1"></i>Random
                        </button>
                    </label>
                    <input type="text" id="username-input-modal" required placeholder="Enter your alias..." value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? $defaultCharacterName); ?>"
                        class="w-full bg-gray-800 border border-gray-600 rounded-lg p-3 text-white focus:border-orange-500 outline-none font-bold tracking-wide"
                        oninput="handleNameInput(this)">
                </div>

                <div id="password-field-modal" class="hidden">
                    <label class="block text-xs font-bold text-gray-200 uppercase mb-2">Password</label>
                    <input type="password" id="password-input-modal" placeholder="Password for this reserved name"
                        class="w-full bg-gray-800 border border-gray-600 rounded-lg p-3 text-white focus:border-orange-500 outline-none">
                </div>

                <div class="flex gap-2">
                    <button type="button" onclick="document.getElementById('username-modal').classList.add('hidden')" class="w-full btn-secondary py-2">Cancel</button>
                    <button type="submit" class="w-full btn-primary py-2">Continue</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const allReservedNames = <?php echo json_encode(array_keys(getReservedNames())); ?>;
        const allPresetNames = <?php echo json_encode(!empty($themeCharacterNames) ? $themeCharacterNames : []); ?>;

        function handleNameInput(input) {
            const name = input.value.trim().toLowerCase();
            const passwordField = document.getElementById('password-field-modal');
            const passwordInput = document.getElementById('password-input-modal');
            const isPreset = allPresetNames.some(p => p.toLowerCase() === name);
            const isReserved = allReservedNames.includes(name);

            // A preset name never requires a password, even if it's also reserved
            if (isPreset) {
                passwordField.classList.add('hidden');
                passwordInput.removeAttribute('required');
            } else if (isReserved) {
                // A reserved name requires a password
                passwordField.classList.remove('hidden');
                passwordInput.setAttribute('required', 'required');
            } else {
                // A new custom name can have an optional password to reserve it.
                passwordField.classList.remove('hidden');
                passwordInput.removeAttribute('required');
            }
        }

        function assignRandomPresetName() {
            const nameInput = document.getElementById('username-input-modal');
            const fallbackNames = ["Captain Chaos", "Baron Von Snark", "Queen Sarcasm", "Duke Disaster", "Sir Puns-a-Lot"];
            const source = allPresetNames.length > 0 ? allPresetNames : fallbackNames;
            const randomName = source[Math.floor(Math.random() * source.length)];
            nameInput.value = randomName;
            document.getElementById('name_source').value = 'random';
            handleNameInput(nameInput);
        }

        function promptForUsername(action = '', roomId = '') {
            document.getElementById('username-modal').classList.remove('hidden');
            document.getElementById('next_action').value = action;
            document.getElementById('next_room_id').value = roomId;
            
            // If user has no name set yet, give them a random one to start
            const nameInput = document.getElementById('username-input-modal');
            if (!nameInput.value.trim()) {
                assignRandomPresetName();
            }
            handleNameInput(nameInput); // Check password field visibility
            document.getElementById('username-input-modal').focus();
        }

        async function setUsernameAndProceed(event) {
            event.preventDefault();
            const username = document.getElementById('username-input-modal').value.trim();
            const password = document.getElementById('password-input-modal').value.trim();
            if (!username) return;

            const formData = new FormData();
            formData.append('action', 'save_profile');
            formData.append('username', username);
            formData.append('password', password);
            formData.append('name_source', document.getElementById('name_source').value);
            // No avatar data sent
            formData.append('avatar_type', 'dicebear');
            formData.append('avatar_val', username);

            try {
                const res = await fetch('index.php', { method: 'POST', body: formData });
                // The PHP script saves the session and issues a redirect back to this page.
                // The fetch response will have `res.redirected: true`.
                // We just need to reload the page to continue the original action with the new profile.
                if (res.redirected) {
                    window.location.reload();
                } else {
                    // Fallback in case redirect doesn't happen as expected
                    const nextAction = document.getElementById('next_action').value;
                    if (nextAction) {
                         window.location.reload();
                    }
                }

            } catch (e) {
                alert('Error setting username.');
            }
        }
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username-input-modal').addEventListener('input', function() {
                handleNameInput(this);
            });
        });
    </script>



    <!-- MAIN CONTENT AREA -->
    <!-- MAIN CONTENT -->
    <main class="w-full max-w-4xl mx-auto p-4 flex-1 space-y-8">

        <!-- INTRO / MEDIA -->
        <div class="mb-6">
            <div class="relative w-full rounded-xl overflow-hidden border border-gray-800 shadow-lg">
                <div style="padding-top:56.25%"></div>
                <?php if ($introType === 'video'): ?>
                    <video src="<?php echo htmlspecialchars($introUrl); ?>" autoplay muted loop playsinline class="absolute inset-0 w-full h-full object-cover"></video>
                <?php else: ?>
                    <img src="<?php echo htmlspecialchars($introUrl); ?>" alt="<?php echo htmlspecialchars($gameTitle); ?>" class="absolute inset-0 w-full h-full object-cover" onerror="this.style.display='none'">
                <?php endif; ?>
            </div>
        </div>
        <div class="flex items-center justify-between mb-6 border-b border-gray-700 pb-2">
            <div>
                <h2 class="text-xl font-bold uppercase text-gray-200 tracking-widest">
                    <i class="fas fa-gamepad mr-2 text-orange-500"></i> Active Games
                </h2>
                <p class="text-[10px] text-gray-200 mt-1">v4.9</p>
            </div>
        </div>

        <?php if (empty($rooms)): ?>
            <!-- Empty State -->
            <div class="text-center text-gray-300 mt-6 p-6 border-2 border-dashed border-gray-700 rounded-xl bg-gray-800/20">
                <i class="fas fa-ghost text-3xl mb-2 text-gray-200"></i>
                <p class="mb-3 text-base font-medium">No active games detected.</p>
                <button onclick="handleGameAction('create')" class="btn-primary px-6 py-2 text-xs uppercase tracking-wider rounded-lg shadow-md hover:shadow-orange-500/20">
                    <i class="fas fa-plus mr-1"></i> Create a Game
                </button>
            </div>
        <?php else: ?>
            <!-- Room List -->
            <div class="grid grid-cols-1 gap-4">
                <?php foreach ($rooms as $room): ?>
                    <div class="bg-[#25262b] p-5 rounded-xl shadow-lg flex flex-col sm:flex-row justify-between items-start sm:items-center border border-gray-800 hover:border-gray-600 transition-all group">
                        <div class="mb-3 sm:mb-0">
                            <h3 class="font-bold text-xl text-white group-hover:text-orange-400 transition-colors">
                                <?php echo htmlspecialchars($room['config']['room_name'] ?? 'Unnamed Room'); ?>
                            </h3>
                            <div class="text-xs text-gray-200 mt-2 flex items-center gap-4">
                                <?php
                                $cfg = $room['config'] ?? [];
                                $allowMid = $cfg['allow_join_mid_game'] ?? true;
                                $voiceChat = !empty($cfg['voice_chat']);
                                $voiceHost = !empty($cfg['enable_tts']) || !empty($cfg['audio_host']);
                                $fullNow = ($room['state'] === 'playing' && !$allowMid);
                                ?>
                                <span class="bg-gray-800 px-2 py-1 rounded border border-gray-700"><i class="fas fa-users mr-1 text-gray-300"></i> <?php echo count($room['players']); ?> Players</span>
                                <span class="<?php echo ($room['state'] === 'playing') ? 'text-green-400 border-green-900 bg-green-900/20' : 'text-yellow-400 border-yellow-900 bg-yellow-900/20'; ?> font-bold uppercase text-[10px] tracking-wider border px-2 py-1 rounded">
                                    <i class="fas fa-circle text-[6px] mr-1 align-middle"></i> <?php echo $room['state']; ?>
                                </span>
                                <?php if ($voiceHost): ?>
                                    <span class="text-orange-300 border-orange-900 bg-orange-900/20 font-bold uppercase text-[10px] tracking-wider border px-2 py-1 rounded">
                                        <i class="fas fa-bullhorn mr-1"></i> Voice Hosted
                                    </span>
                                <?php endif; ?>
                                <?php if ($fullNow): ?>
                                    <span class="text-red-300 border-red-900 bg-red-900/20 font-bold uppercase text-[10px] tracking-wider border px-2 py-1 rounded">
                                        <i class="fas fa-ban mr-1"></i> Full
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($room['is_idle'])): ?>
                                    <span class="text-gray-300 border-gray-700 bg-gray-800 font-bold uppercase text-[10px] tracking-wider border px-2 py-1 rounded">
                                        <i class="fas fa-moon text-[8px] mr-1 align-middle"></i> Idle
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex gap-3 w-full sm:w-auto">
                            <button onclick="handleGameAction('join', '<?php echo $room['id']; ?>')" class="btn-primary w-full sm:w-auto px-6 py-2 text-xs uppercase tracking-wider rounded-lg shadow-md hover:shadow-orange-500/20">
                                Join
                            </button>

                            <!-- Watch Button -->
                            <form action="game.php" method="GET" class="flex-1 sm:flex-none">
                                <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                <input type="hidden" name="spectate" value="1">
                                <button type="submit" class="btn-secondary w-full sm:w-auto px-6 py-2 text-xs uppercase tracking-wider rounded-lg hover:bg-gray-700">
                                    Watch
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="text-center mt-6">
                 <button onclick="handleGameAction('create')" class="btn-primary px-6 py-2 text-xs uppercase tracking-wider rounded-lg shadow-md hover:shadow-orange-500/20">
                    <i class="fas fa-plus mr-1"></i> Create New Game
                </button>
            </div>
        <?php endif; ?>
    </main>

    <script>
        function handleGameAction(action, roomId = '') {
            <?php if (!$profileSet): ?>
                promptForUsername(action, roomId);
            <?php else: ?>
                window.location.href = action === 'create' ? 'setup.php' : `game.php?room_id=${roomId}`;
            <?php endif; ?>
        }
    </script>

</body>

</html>
