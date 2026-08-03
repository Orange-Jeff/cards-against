<?php
/**
 * Filename: setup.php
 * Date: July 14, 2026
 * Version: 4.9 - Disallow Google TTS key fallback for Gemini AI calls
 * Changes:
 *   - Upgraded version number to 4.9.
 *   - Consolidated Room Options and created a Game Hosts & Bots section.
 *   - Separated options for Digital Voice, AI Host, and AI Bots.
 *   - Added live black/white card calculations with warning threshold.
 */
session_start();

$isAdmin = $_SESSION['is_admin'] ?? false;

// Load global config to check master switches
$globalConfigFile = __DIR__ . '/data/global_config.json';
$globalConfig = [];
if(file_exists($globalConfigFile)) {
    $globalConfig = json_decode(file_get_contents($globalConfigFile), true) ?: [];
}
$masterEnableTTS = $globalConfig['enable_tts'] ?? false;
$masterEnableChat = $globalConfig['enable_chat'] ?? true;
$gameTitle = $globalConfig['game_title'] ?? 'Cards Against Everyone';

// Load theme data for room names
$themesFile = __DIR__ . '/data/themes.json';
$themes = file_exists($themesFile) ? (json_decode(file_get_contents($themesFile), true) ?: []) : [];
$currentThemeKey = $_SESSION['selected_theme_key'] ?? $globalConfig['default_theme'] ?? 'default';
$currentTheme = $themes[$currentThemeKey] ?? ($themes['default'] ?? []);
$themeRoomNames = $currentTheme['room_names'] ?? [];
$themeCharacterNames = $currentTheme['character_names'] ?? [];

// Random default room name from theme (fallback to configured default)
$roomNameDefault = ($themeRoomNames && count($themeRoomNames) > 0)
    ? $themeRoomNames[array_rand($themeRoomNames)]
    : ($globalConfig['default_room_name'] ?? 'The Lounge');

require_once __DIR__ . '/deck_parser.php';

// Load deck availability from settings
function load_available_decks() {
    $file = __DIR__ . '/data/decks_available.json';
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true) ?: null;
}

$parsedDecks = parseDecksShared();
$deckTags = $parsedDecks['tags'];
$deckCounts = [];
foreach ($deckTags as $tag) {
    $deckCounts[$tag] = ['black' => 0, 'white' => 0];
}
foreach ($parsedDecks['black'] as $c) {
    if (isset($deckCounts[$c['deck']])) $deckCounts[$c['deck']]['black']++;
}
foreach ($parsedDecks['white'] as $c) {
    if (isset($deckCounts[$c['deck'] ?? ''])) $deckCounts[$c['deck']]['white']++;
}

// Also add orange deck count if file exists
$ORANGE_DECK_FILE = __DIR__ . '/data/user_additions.json';
$orangeDeckCards = ['black' => 0, 'white' => 0];
if (file_exists($ORANGE_DECK_FILE)) {
    $userAdditions = json_decode(file_get_contents($ORANGE_DECK_FILE), true) ?: [];
    foreach ($userAdditions as $card) {
        if (($card['type'] ?? 'white') === 'black') {
            $orangeDeckCards['black']++;
        } else {
            $orangeDeckCards['white']++;
        }
    }
}
if ($orangeDeckCards['black'] > 0 || $orangeDeckCards['white'] > 0) {
    $deckCounts['orange_deck'] = $orangeDeckCards;
}

$availableDecks = load_available_decks();
if ($availableDecks !== null) {
    $deckTags = array_filter($deckTags, function($tag) use ($availableDecks) {
        return ($availableDecks[$tag] ?? true);
    });
}

// No per-game theme selection; themes live in master settings
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Game</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <style>
        body { background-color: #1a1b1e; color: white; font-family: sans-serif; }
        select { -webkit-appearance: none; appearance: none; }
        /* Remove arrows from number inputs */
        input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; appearance: none; margin: 0; }
        input[type=number] { appearance: none; -moz-appearance: textfield; }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-b from-[#1a1b1e] to-[#111214]">

    <!-- BRANDING BANNER (Line 2) -->
    <div class="bg-[#141517] border-b border-gray-800 py-2 flex-none">
        <div class="max-w-4xl mx-auto px-4 text-center">
            <h1 id="game-title" class="text-lg sm:text-2xl font-black uppercase tracking-[0.22em]">
                <span class="text-orange-500">CARDS AGAINST</span>
                <span class="text-gray-200"><?php echo htmlspecialchars($currentTheme['game_name_suffix'] ?? 'Everyone'); ?></span>
            </h1>
        </div>
    </div>

    <!-- SETUP HEADER (Line 3) - Menu -->
    <nav class="bg-[#141517] border-b border-gray-800 shadow-lg sticky top-0 z-50 flex-none">
        <div class="max-w-4xl mx-auto px-4 py-3 flex justify-between items-center">
            <a href="index.php" class="text-sm font-bold text-gray-200 uppercase hover:text-white transition-colors p-1"><i class="fas fa-times mr-1"></i>Cancel</a>
            <button type="submit" form="setup-form" class="bg-gradient-to-r from-orange-500 to-orange-600 text-white font-bold py-2 px-4 rounded-lg shadow-lg uppercase tracking-widest hover:from-orange-600 hover:to-orange-700 transition-all transform hover:scale-[1.01] active:scale-[0.99]">
                <i class="fas fa-save mr-2"></i> Save & Exit
            </button>
        </div>
    </nav>

    <!-- FORM -->
    <main class="p-6 max-w-4xl mx-auto w-full space-y-8 flex-1">
        <div class="flex items-center justify-between border-b border-gray-700 pb-4">
            <h1 class="text-2xl font-bold uppercase tracking-widest text-gray-300">
                <i class="fas fa-rocket text-orange-500 mr-3"></i>New Game Setup
            </h1>
        </div>

        <form id="setup-form" onsubmit="createGame(event)" class="space-y-6">

            <!-- Theme Selector -->
            <div class="bg-[#25262b] p-6 rounded-xl shadow-xl border border-gray-800">
                <label class="font-bold text-sm text-gray-200 uppercase mb-2 block"><i class="fas fa-palette mr-1"></i> Theme</label>
                <select id="theme_selector" onchange="changeTheme()" class="w-full bg-gray-800 border border-gray-600 rounded-lg p-3 text-white focus:border-orange-500 focus:ring-1 focus:ring-orange-500 outline-none transition-all shadow-inner">
                    <?php foreach ($themes as $key => $t): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>"
                        data-suffix="<?php echo htmlspecialchars($t['game_name_suffix'] ?? 'Everyone'); ?>"
                        data-rooms='<?php echo htmlspecialchars(json_encode($t['room_names'] ?? [])); ?>'
                        data-deck="<?php echo htmlspecialchars($t['mandatory_deck'] ?? ''); ?>"
                        data-default-decks='<?php echo htmlspecialchars(json_encode($t['default_decks'] ?? [])); ?>'
                        <?php echo ($key === $currentThemeKey) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($t['label'] ?? ucfirst($key)); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="bg-[#25262b] p-6 rounded-xl shadow-xl border border-gray-800">
                <label class="font-bold text-sm text-gray-200 uppercase mb-2 block"><i class="fas fa-tag mr-1"></i> Room Name</label>
                <div class="flex gap-2">
                    <input type="text" name="room_name" id="room_name_input" value="<?php echo htmlspecialchars($roomNameDefault); ?>" class="flex-1 bg-gray-800 border border-gray-600 rounded-lg p-3 text-white focus:border-orange-500 focus:ring-1 focus:ring-orange-500 outline-none transition-all shadow-inner">
                    <button type="button" onclick="diceRoomName()" class="px-3 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg border border-gray-600"><i class="fas fa-dice"></i></button>
                </div>
            </div>

            <?php
            // Check for pre-configured Gemini or OpenAI API Key
            $envPath = dirname(__DIR__) . '/.env';
            $envApiKey = '';
            if (file_exists($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, '#') === 0) continue;
                    if (strpos($line, '=') !== false) {
                        list($key, $val) = explode('=', $line, 2);
                        if (trim($key) === 'GEMINI_API_KEY' || trim($key) === 'OPENAI_API_KEY') {
                            $envApiKey = trim($val);
                        }
                    }
                }
            }
            $configApiKey = $globalConfig['gemini_api_key'] ?? ($globalConfig['openai_api_key'] ?? '');
            $hasPreConfiguredKey = !empty($envApiKey) || !empty($configApiKey);
            ?>

            <!-- Game Options -->
            <!-- Room Options Category -->
            <div class="bg-[#25262b] p-6 rounded-xl shadow-xl border border-gray-800 space-y-4">
                <h3 class="font-bold text-sm text-gray-200 uppercase block mb-1">
                    <i class="fas fa-cog mr-2 text-orange-500"></i> Room Options
                </h3>
                <label class="flex items-center justify-between cursor-pointer group">
                    <span class="text-sm font-bold text-gray-300 group-hover:text-white transition-colors"><i class="fas fa-vote-yea mr-2 text-gray-300"></i> Allow voting for yourself</span>
                    <input type="checkbox" name="self_vote" id="st-self" checked class="w-5 h-5 accent-orange-500 rounded cursor-pointer">
                </label>

                <label class="flex items-center justify-between cursor-pointer group">
                    <span class="text-sm font-bold text-gray-300 group-hover:text-white transition-colors"><i class="fas fa-sign-in-alt mr-2 text-gray-300"></i> Allow Joining Mid-Game</span>
                    <input type="checkbox" name="allow_join_mid_game" id="st-join-mid" checked class="w-5 h-5 accent-orange-500 rounded cursor-pointer">
                </label>

                <label class="flex items-center justify-between cursor-pointer group">
                    <span class="text-sm font-bold text-gray-300 group-hover:text-white transition-colors"><i class="fas fa-eye mr-2 text-gray-300"></i> Allow Watchers</span>
                    <input type="checkbox" name="allow_watchers" id="st-watchers" checked class="w-5 h-5 accent-orange-500 rounded cursor-pointer">
                </label>

                <label class="flex items-center justify-between cursor-pointer group">
                    <span class="text-sm font-bold text-gray-300 group-hover:text-white transition-colors"><i class="fas fa-comments mr-2 text-gray-300"></i> Enable text chat between players</span>
                    <input type="checkbox" name="enable_chat" checked class="w-5 h-5 accent-orange-500 rounded cursor-pointer">
                </label>
            </div>

            <!-- Voice & AI Settings Category -->
            <div class="bg-[#25262b] p-6 rounded-xl shadow-xl border border-gray-800 space-y-4">
                <h3 class="font-bold text-sm text-gray-200 uppercase block mb-1">
                    <i class="fas fa-volume-up mr-2 text-orange-500"></i> Voice & AI Options
                </h3>

                <!-- Digital Voice Toggle -->
                <label class="flex items-center justify-between cursor-pointer group">
                    <div>
                        <span class="text-sm font-bold text-gray-300 group-hover:text-white transition-colors">Digital Voice</span>
                        <span class="block text-[10px] text-gray-400 font-normal">Enable host audio card readings and game announcements</span>
                    </div>
                    <input type="checkbox" name="enable_tts" id="enable_tts_toggle" checked class="w-5 h-5 accent-orange-500 rounded cursor-pointer">
                </label>

                <hr class="border-gray-700/50 my-2">

                <!-- AI Personality Toggle -->
                <label class="flex items-center justify-between cursor-pointer group <?php echo !$hasPreConfiguredKey ? 'opacity-40 cursor-not-allowed' : ''; ?>">
                    <div>
                        <span class="text-sm font-bold text-gray-300 group-hover:text-white transition-colors">
                            AI Personality
                            <?php if (!$hasPreConfiguredKey): ?><span class="text-[10px] text-yellow-500 font-bold ml-2">⚠ (API not connected)</span><?php endif; ?>
                        </span>
                        <span class="block text-[10px] text-gray-400 font-normal">Self-aware AI host roasts, game intro comments, and round analysis</span>
                    </div>
                    <input type="checkbox" name="use_ai_host" id="use_ai_host_toggle" <?php echo $hasPreConfiguredKey ? 'checked' : 'disabled'; ?> class="w-5 h-5 accent-orange-500 rounded cursor-pointer">
                </label>

                <!-- AI Smart Chat Bots Toggle -->
                <label class="flex items-center justify-between cursor-pointer group <?php echo !$hasPreConfiguredKey ? 'opacity-40 cursor-not-allowed' : ''; ?>">
                    <div>
                        <span class="text-sm font-bold text-gray-300 group-hover:text-white transition-colors">
                            AI Smart Chat bots
                            <?php if (!$hasPreConfiguredKey): ?><span class="text-[10px] text-yellow-500 font-bold ml-2">⚠ (API not connected)</span><?php endif; ?>
                        </span>
                        <span class="block text-[10px] text-gray-400 font-normal">Enables self-aware smart bots chat roasts and card selection</span>
                    </div>
                    <input type="checkbox" name="use_ai_bots" id="use_ai_bots_toggle" <?php echo $hasPreConfiguredKey ? 'checked' : 'disabled'; ?> class="w-5 h-5 accent-orange-500 rounded cursor-pointer">
                </label>

            </div>

            <!-- Deck Selection -->
            <div class="bg-[#25262b] p-6 rounded-xl shadow-xl border border-gray-800">
                <div class="flex items-center justify-between mb-1">
                    <label class="font-bold text-sm text-gray-200 uppercase block"><i class="fas fa-layer-group mr-1"></i> Card Decks</label>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="toggleAllDecks()" class="text-xs font-bold text-orange-500 border border-orange-500 px-2 py-1 rounded hover:bg-orange-500/10"><i class="fas fa-check-double mr-1"></i> Select All</button>
                    </div>
                </div>
                <p class="text-[10px] text-gray-200 mb-2">Recommended: Game play works best with 1-3 decks per game.</p>

                <div class="space-y-2 max-h-40 overflow-y-auto pr-2 custom-scrollbar" id="deck-list">
                    <?php foreach($deckTags as $tag): if ($tag === 'orange_deck') continue; $label = get_deck_label($tag); $bc = $deckCounts[$tag]['black'] ?? 0; $wc = $deckCounts[$tag]['white'] ?? 0; $total = $bc + $wc; $isBase = ($tag === 'base_deck'); ?>
                    <label class="flex items-center justify-between p-2 rounded hover:bg-gray-800 cursor-pointer border border-gray-700" data-label="<?php echo htmlspecialchars(strtolower($label)); ?>">
                        <div class="flex items-center">
                            <input type="checkbox" name="decks[]" value="<?php echo htmlspecialchars($tag); ?>" <?php echo $isBase ? 'checked' : ''; ?> class="deck-checkbox w-4 h-4 accent-orange-500 mr-3">
                            <span class="text-sm"><?php echo htmlspecialchars($label); ?></span>
                        </div>
                        <span class="text-[10px] text-gray-200"><?php echo $total; ?> cards • <?php echo $bc; ?> black • <?php echo $wc; ?> white</span>
                    </label>
                    <?php endforeach; ?>

                    <?php if (isset($deckCounts['orange_deck']) && ($deckCounts['orange_deck']['black'] > 0 || $deckCounts['orange_deck']['white'] > 0)): $bc = $deckCounts['orange_deck']['black']; $wc = $deckCounts['orange_deck']['white']; $total = $bc + $wc; ?>
                    <!-- Orange Deck (User Additions) -->
                    <label class="flex items-center justify-between p-2 rounded hover:bg-gray-800 cursor-pointer border border-gray-700 mb-1 bg-orange-900/20 border-orange-500/50" data-label="orange deck user additions">
                        <div class="flex items-center">
                            <input type="checkbox" name="decks[]" value="orange_deck" class="deck-checkbox w-4 h-4 accent-orange-500 mr-3">
                            <span class="text-sm font-bold text-orange-400">Orange Deck (User Additions)</span>
                        </div>
                        <span class="text-[10px] text-gray-200"><?php echo $total; ?> cards • <?php echo $bc; ?> black • <?php echo $wc; ?> white</span>
                    </label>
                    <?php endif; ?>
                </div>
                
                <div class="mt-3 p-3 bg-gray-900/60 rounded border border-gray-700/50 flex justify-between items-center text-xs">
                    <div>
                        <span class="text-gray-300 font-bold uppercase text-[10px] block">Selected Cards Summary</span>
                        <span class="text-orange-400 font-bold" id="selected-summary-text">0 black / 0 white cards</span>
                    </div>
                    <div id="deck-warning-badge" class="hidden text-[10px] font-bold text-red-400 bg-red-950/40 px-2 py-0.5 rounded border border-red-800/50 flex items-center gap-1">
                        <i class="fas fa-exclamation-triangle"></i> Low Black Cards
                    </div>
                </div>
            </div>

            <!-- Game Rules -->
            <div class="bg-[#25262b] p-6 rounded-xl shadow-xl border border-gray-800 space-y-6">
                <div class="grid grid-cols-2 gap-4">
                    <!-- Rounds -->
                    <div>
                        <label class="text-xs font-bold text-gray-200 uppercase mb-1 block">Rounds to Win</label>
                        <div class="flex items-center bg-gray-800 rounded border border-gray-600">
                            <button type="button" onclick="adjustVal('st-win', -1)" class="px-3 py-2 text-gray-200 hover:text-white border-r border-gray-700"><i class="fas fa-minus text-xs"></i></button>
                            <input type="number" id="st-win" name="win_limit" value="5" min="1" max="50" class="w-full bg-transparent text-center text-white outline-none p-2">
                            <button type="button" onclick="adjustVal('st-win', 1)" class="px-3 py-2 text-gray-200 hover:text-white border-l border-gray-700"><i class="fas fa-plus text-xs"></i></button>
                        </div>
                    </div>
                    <!-- Timer -->
                    <div>
                        <label class="text-xs font-bold text-gray-200 uppercase mb-1 block">Turn Timer</label>
                        <div class="relative">
                            <select name="timer" id="st-timer" class="w-full bg-gray-800 border border-gray-600 rounded p-2 text-white text-center appearance-none">
                                <option value="0">No Timer</option>
                                <option value="30">30 Seconds</option>
                                <option value="60" selected>60 Seconds</option>
                                <option value="90">90 Seconds</option>
                            </select>
                            <i class="fas fa-chevron-down absolute right-3 top-3 text-xs text-gray-300 pointer-events-none"></i>
                        </div>
                    </div>
                </div>

                <!-- Hand Size & Max Players -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-bold text-gray-200 uppercase mb-1 block"><i class="fas fa-hand-paper mr-1"></i> Hand Size</label>
                        <div class="flex items-center bg-gray-800 rounded border border-gray-600">
                            <button type="button" onclick="adjustVal('st-hand', -1)" class="px-3 py-2 text-gray-200 hover:text-white border-r border-gray-700"><i class="fas fa-minus text-xs"></i></button>
                            <input type="number" id="st-hand" name="hand_size" value="7" min="4" max="10" class="w-full bg-transparent text-center text-white outline-none p-2">
                            <button type="button" onclick="adjustVal('st-hand', 1)" class="px-3 py-2 text-gray-200 hover:text-white border-l border-gray-700"><i class="fas fa-plus text-xs"></i></button>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-200 uppercase mb-1 block">Max Players</label>
                        <div class="flex items-center bg-gray-800 rounded border border-gray-600">
                            <button type="button" onclick="adjustVal('st-max', -1)" class="px-3 py-2 text-gray-200 hover:text-white border-r border-gray-700"><i class="fas fa-minus text-xs"></i></button>
                            <input type="number" id="st-max" name="max_players" value="8" min="3" max="20" class="w-full bg-transparent text-center text-white outline-none p-2">
                            <button type="button" onclick="adjustVal('st-max', 1)" class="px-3 py-2 text-gray-200 hover:text-white border-l border-gray-700"><i class="fas fa-plus text-xs"></i></button>
                        </div>
                    </div>
                </div>
            </div>

        </form>
    </main>

    <script>
        // Deck info for validation
        window.DECKS_INFO = <?php echo json_encode($deckCounts, JSON_UNESCAPED_SLASHES); ?>;

        function adjustVal(id, delta) {
            const el = document.getElementById(id);
            if (!el) return;
            let val = parseInt(el.value) || 0;
            const min = parseInt(el.min) || 0;
            const max = parseInt(el.max) || 100;
            val += delta;
            if (val < min) val = min;
            if (val > max) val = max;
            el.value = val;
        }

        // Lightweight two-word generator: adjective + noun
        const ROOM_ADJECTIVES = ['Cheeky','Cosmic','Dusty','Electric','Fuzzy','Golden','Honeyed','Icy','Jolly','Lucky','Misty','Neon','Plucky','Rusty','Snazzy','Sunny','Twisty','Velvet','Witty','Zesty'];
        const ROOM_NOUNS = ['Badgers','Banana','Bandits','Biscuit','Comets','Coyote','Crumpets','Dragons','Ferrets','Goblins','Heroes','Koalas','Llamas','Monkeys','Ninjas','Octopus','Pirates','Pickles','Robots','Wizards'];
        window.ALL_THEMES = <?php echo json_encode($themes, JSON_UNESCAPED_SLASHES); ?>;
        window.CURRENT_THEME_ROOMS = <?php echo json_encode($themeRoomNames); ?>;

        function diceRoomName(){
            let name = "";
            const themeRooms = window.CURRENT_THEME_ROOMS || [];
            if (themeRooms && themeRooms.length > 0) {
                name = themeRooms[Math.floor(Math.random()*themeRooms.length)];
            } else {
                const adj = ROOM_ADJECTIVES[Math.floor(Math.random()*ROOM_ADJECTIVES.length)];
                const noun = ROOM_NOUNS[Math.floor(Math.random()*ROOM_NOUNS.length)];
                name = `${adj} ${noun}`;
            }
            const inp = document.getElementById('room_name_input');
            inp.value = name;
        }

        async function createGame(e) {
            e.preventDefault();
            let btn = document.querySelector('button[type="submit"][form="setup-form"]');

            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData.entries());

            // Validate deck selection and black card count
            const selectedDecks = formData.getAll('decks[]');
            if (!selectedDecks || selectedDecks.length === 0) {
                alert('Please select at least one deck');
                return;
            }

            // Check if we have enough black cards for the number of rounds
            let totalBlackCards = 0;
            selectedDecks.forEach(deck => {
                if (window.DECKS_INFO[deck]) {
                    totalBlackCards += window.DECKS_INFO[deck].black || 0;
                }
            });

            const winLimit = parseInt(document.getElementById('st-win').value) || 5;
            const minBlackCardsNeeded = winLimit * 2; // Rough estimate: 2 black cards per round for variety

            if (totalBlackCards < minBlackCardsNeeded) {
                const warning = `⚠️ Warning: You've selected only ${totalBlackCards} black cards, ` +
                    `but need approximately ${minBlackCardsNeeded} for ${winLimit} rounds. ` +
                    `The game may run out of cards. Continue anyway?`;
                if (!confirm(warning)) {
                    return;
                }
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Saving...';

            // Checkbox handling (unchecked = missing in FormData)
            data.self_vote = formData.get('self_vote') === 'on';
            data.allow_join_mid_game = formData.get('allow_join_mid_game') === 'on';
            data.allow_watchers = formData.get('allow_watchers') === 'on';
            data.enable_tts = document.getElementById('enable_tts_toggle') ? document.getElementById('enable_tts_toggle').checked : false;
            const chatChoice = formData.get('enable_chat');
            data.enable_chat = (chatChoice === 'on'); // null = unchecked or not rendered = chat disabled
            data.decks = formData.getAll('decks[]'); // Get all selected decks
            data.theme_key = document.getElementById('theme_selector').value;
            data.use_ai_host = document.getElementById('use_ai_host_toggle') ? document.getElementById('use_ai_host_toggle').checked : false;
            data.use_ai_bots = document.getElementById('use_ai_bots_toggle') ? document.getElementById('use_ai_bots_toggle').checked : false;

            // Per-game voice settings
            data.game_tts_provider = formData.get('game_tts_provider') || 'browser';
            data.offline_tts = formData.get('offline_tts') === 'on';
            data.game_chrome_voice = document.getElementById('setup-chrome-voice-select')?.value || '';
            if (data.game_chrome_voice) {
                localStorage.setItem('game_tts_voice_name', data.game_chrome_voice);
            }

            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 15000); // 15-second timeout

                const res = await fetch('api.php?action=create', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(data),
                    signal: controller.signal
                });

                clearTimeout(timeoutId);

                const result = await res.json();

                if (result.success) {
                    window.location.href = `game.php?room_id=${result.room_id}`;
                } else {
                    alert("Error creating room: " + (result.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = 'Save & Exit';
                }
            } catch (err) {
                console.error(err);
                if (err.name === 'AbortError') {
                    alert("Connection timed out. The server is taking too long to respond. Please try again.");
                } else {
                    alert("Connection failed. Could not create the game.");
                }
                btn.disabled = false;
                btn.innerHTML = 'Save & Exit';
            }
        }

        // Change theme - update title, room names, and mandatory deck
        function changeTheme() {
            const sel = document.getElementById('theme_selector');
            const opt = sel.options[sel.selectedIndex];
            const key = sel.value;
            const suffix = opt.dataset.suffix || 'Everyone';
            const rooms = JSON.parse(opt.dataset.rooms || '[]');
            const mandatoryDeck = opt.dataset.deck || '';
            const defaultDecks = JSON.parse(opt.dataset.defaultDecks || '[]');

            // Save to session via API
            try {
                const fd = new FormData();
                fd.append('action', 'select_theme');
                fd.append('theme_key', key);
                fetch('api.php', { method: 'POST', body: fd });
            } catch(e) {}

            // Update title
            document.getElementById('game-title').textContent = 'Cards Against ' + suffix;

            // Update room names array for dice button
            window.CURRENT_THEME_ROOMS = rooms;

            // Generate new room name from theme
            if (rooms.length > 0) {
                document.getElementById('room_name_input').value = rooms[Math.floor(Math.random() * rooms.length)];
            }

            // Load theme configuration settings if present in ALL_THEMES
            const t = (window.ALL_THEMES || {})[key] || {};
            if (t) {
                if (t.win_limit !== undefined) document.getElementById('st-win').value = t.win_limit;
                if (t.timer !== undefined) document.getElementById('st-timer').value = t.timer;
                if (t.hand_size !== undefined) document.getElementById('st-hand').value = t.hand_size;
                if (t.self_vote !== undefined) document.getElementById('st-self').checked = !!t.self_vote;
                if (t.enable_tts !== undefined) {
                    const ttsToggle = document.getElementById('enable_tts_toggle');
                    if (ttsToggle) {
                        ttsToggle.checked = !!t.enable_tts;
                    }
                }
                
                // Filter and select decks based on the theme
                const themeDecks = defaultDecks.length > 0 ? defaultDecks : null;
                document.querySelectorAll('#deck-list > label').forEach(label => {
                    const checkbox = label.querySelector('.deck-checkbox');
                    if (!checkbox) return;
                    const deckSlug = checkbox.value;

                    // If themeDecks is defined, only show decks from that theme. Otherwise, show all.
                    const shouldBeVisible = themeDecks === null || themeDecks.includes(deckSlug);
                    label.style.display = shouldBeVisible ? 'flex' : 'none';
                    
                    // Check the box if it's in the default list for the theme.
                    checkbox.checked = shouldBeVisible && (themeDecks ? themeDecks.includes(deckSlug) : (deckSlug === 'base_deck'));
                });
            } else {
                // Fallback for themes without specific decks: show all, check base_deck
                document.querySelectorAll('#deck-list > label').forEach(label => {
                    label.style.display = 'flex';
                    const checkbox = label.querySelector('.deck-checkbox');
                    if (checkbox) checkbox.checked = (checkbox.value === 'base_deck');
                });
            }
            updateDeckCounts();
        }

        // Initialize on load
        window.addEventListener('DOMContentLoaded', () => {
            // Select all decks by default on first load
            changeTheme(); // This will now filter and select decks based on the default theme
            // Then attach change listeners
            document.querySelectorAll('.deck-checkbox').forEach(cb => {
                cb.addEventListener('change', updateDeckCounts);
            });
            // updateDeckCounts is called by changeTheme, so this is redundant.
        });

        // Real-time deck card calculations
        function updateDeckCounts() {
            const checkedBoxes = document.querySelectorAll('.deck-checkbox:checked');
            let totalBlack = 0;
            let totalWhite = 0;
            
            checkedBoxes.forEach(cb => {
                const tag = cb.value;
                const info = window.DECKS_INFO[tag] || { black: 0, white: 0 };
                totalBlack += info.black;
                totalWhite += info.white;
            });
            
            const summaryText = document.getElementById('selected-summary-text');
            if (summaryText) {
                summaryText.textContent = `${totalBlack} black / ${totalWhite} white cards`;
            }
            
            const warningBadge = document.getElementById('deck-warning-badge');
            if (warningBadge) {
                if (totalBlack < 15 && totalBlack > 0) {
                    warningBadge.classList.remove('hidden');
                } else {
                    warningBadge.classList.add('hidden');
                }
            }
        }

        function toggleGameTTSFields() {
            const provider = document.getElementById('game_tts_provider')?.value || 'browser';
            const googleFields = document.getElementById('game_google_fields');
            const elevenFields = document.getElementById('game_elevenlabs_fields');
            if (googleFields) googleFields.classList.toggle('hidden', provider !== 'google');
            if (elevenFields) elevenFields.classList.toggle('hidden', provider !== 'elevenlabs');
        }

        function toggleAllDecks() {
            const checkboxes = document.querySelectorAll('.deck-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => cb.checked = !allChecked);
        }

        let setupVoices = [];

        function populateSetupVoicesDropdown() {
            const select = document.getElementById('setup-chrome-voice-select');
            if (!select) return;

            if (window.speechSynthesis) {
                setupVoices = window.speechSynthesis.getVoices();
            }

            if (setupVoices.length === 0) return;

            select.innerHTML = '';
            
            const defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.textContent = 'Default Browser Voice';
            select.appendChild(defOpt);

            const isNaturalVoice = (v) => {
                const n = v.name.toLowerCase();
                return n.includes('natural') || n.includes('enhanced') || n.includes('google us english') || n.includes('google uk english') || n.includes('online (natural)') || n.includes('premium');
            };

            const englishVoices = setupVoices.filter(v => v.lang && v.lang.startsWith('en'));
            const otherVoices = setupVoices.filter(v => !v.lang || !v.lang.startsWith('en'));

            const naturalVoices = englishVoices.filter(isNaturalVoice).sort((a, b) => a.name.localeCompare(b.name));
            const standardEnglish = englishVoices.filter(v => !isNaturalVoice(v)).sort((a, b) => a.name.localeCompare(b.name));

            const savedVoice = localStorage.getItem('game_tts_voice_name') || '';

            if (naturalVoices.length > 0) {
                const grpNat = document.createElement('optgroup');
                grpNat.label = '🌟 High Quality / Natural Voices';
                naturalVoices.forEach(voice => {
                    const opt = document.createElement('option');
                    opt.value = voice.name;
                    opt.textContent = '🌟 ' + voice.name + ' (' + voice.lang + ')';
                    if (savedVoice && voice.name === savedVoice) opt.selected = true;
                    grpNat.appendChild(opt);
                });
                select.appendChild(grpNat);
            }

            if (standardEnglish.length > 0) {
                const grpStd = document.createElement('optgroup');
                grpStd.label = 'Standard Voices';
                standardEnglish.forEach(voice => {
                    const opt = document.createElement('option');
                    opt.value = voice.name;
                    opt.textContent = voice.name + ' (' + voice.lang + ')';
                    if (savedVoice && voice.name === savedVoice) opt.selected = true;
                    grpStd.appendChild(opt);
                });
                select.appendChild(grpStd);
            }

            if (otherVoices.length > 0) {
                const grpOther = document.createElement('optgroup');
                grpOther.label = 'Other Languages';
                otherVoices.forEach(voice => {
                    const opt = document.createElement('option');
                    opt.value = voice.name;
                    opt.textContent = voice.name + ' (' + voice.lang + ')';
                    if (savedVoice && voice.name === savedVoice) opt.selected = true;
                    grpOther.appendChild(opt);
                });
                select.appendChild(grpOther);
            }
        }

        function onSetupVoiceChange() {
            const select = document.getElementById('setup-chrome-voice-select');
            if (select && select.value) {
                localStorage.setItem('game_tts_voice_name', select.value);
            }
        }

        function toggleVoiceSelectUI() {
            const chk = document.getElementById('enable_tts_toggle');
            const container = document.getElementById('setup-voice-select-container');
            if (container) {
                container.classList.toggle('hidden', !chk || !chk.checked);
            }
        }

        function testSetupVoice() {
            if (!window.speechSynthesis) {
                alert('Speech synthesis is not supported in this browser.');
                return;
            }
            window.speechSynthesis.cancel();
            const select = document.getElementById('setup-chrome-voice-select');
            const voiceName = select ? select.value : '';

            if (setupVoices.length === 0) {
                setupVoices = window.speechSynthesis.getVoices();
            }

            let chosen = setupVoices.find(v => v.name === voiceName);
            if (!chosen && voiceName) {
                chosen = setupVoices.find(v => v.name.includes(voiceName));
            }

            const testText = "Welcome to Cards Against Everyone! Voice settings test active.";
            const ut = new SpeechSynthesisUtterance(testText);
            if (chosen) ut.voice = chosen;
            ut.pitch = 0.9;
            ut.rate = 1.0;

            const btn = document.getElementById('test-voice-btn');
            if (btn) btn.innerHTML = '<i class="fas fa-spinner fa-spin text-orange-400 text-[10px]"></i> Speaking...';
            
            ut.onend = ut.onerror = () => {
                if (btn) btn.innerHTML = '<i class="fas fa-play text-orange-400 text-[10px]"></i> Test Voice';
            };

            window.speechSynthesis.speak(ut);
        }

        if (window.speechSynthesis) {
            if (speechSynthesis.onvoiceschanged !== undefined) {
                speechSynthesis.onvoiceschanged = populateSetupVoicesDropdown;
            }
            setTimeout(populateSetupVoicesDropdown, 250);
        }
    </script>
</body>
</html>
