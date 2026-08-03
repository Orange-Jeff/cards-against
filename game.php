<?php

/**
 * Version: 4.9.1 - Fix tie-breaker announcement loop
 * Changes:
 *   - Fixed a bug where a tie within a tie-breaker would not be announced, making the game appear stuck.
 *   - Refactored TTS tie announcement logic to use round_start_time, ensuring announcements on consecutive ties.
 * Previously (4.9): Disallow Google TTS key fallback for Gemini AI calls
 * Previously (4.8): Add auto-deduplication tool for decks.md
 * Previous (3.08): Fix header collapse layout bounce
 *   - Added min-h-[12px] to #waiting-for element to prevent layout shifting and bouncing when waiting status text updates.
 * Previous (3.07): Fix ReferenceError in updateBtn
 *   - Normalization of underscore blanks to standard 6 underscores
 * Previous (3.03): TTS Quote Fix & Play Again Polish
 * Previous (3.01): Deck Organization & Validation Updates
 *   - Updated speak() with segmented playback to reconstruct phrases from local clips
 *   - Offline-capable cards: Black cards now play from segments and 'blank' audio
 *   - Removed standard Google TTS fallback due to API restrictions (now Gemini-only)
 *   - Background generation of 4600+ audio files initiated for full local library
 *
 */
session_start();
if (!isset($_SESSION['user_name'])) {
    $_SESSION['lobby_message'] = 'You must set up your profile before joining a game.';
    header("Location: index.php");
    exit;
}
$room_id = $_GET['room_id'] ?? $_SESSION['room_id'] ?? '';
$spectate = $_GET['spectate'] ?? 0;
if (!$room_id) {
    $_SESSION['lobby_message'] = 'No game room specified.';
    header("Location: index.php");
    exit;
}
$_SESSION['room_id'] = $room_id;

// Load Room Config for per-game voice settings
$roomFile = __DIR__ . '/data/room_' . $room_id . '.json';
$roomData = file_exists($roomFile) ? json_decode(file_get_contents($roomFile), true) : [];
$roomConfig = $roomData['config'] ?? [];

// Load Global Config for fallback settings
$configFile = __DIR__ . '/data/global_config.json';
$globalConfig = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];

// Per-game voice settings (fallback to global if not set)
$enableTTS = $roomConfig['enable_tts'] ?? ($globalConfig['tts_enabled'] ?? ($globalConfig['enable_tts'] ?? false));
$gameTTSProvider = $roomConfig['game_tts_provider'] ?? 'browser';
$gameGoogleAPIKey = $roomConfig['game_google_api_key'] ?? '';
$gameGoogleVoice = $roomConfig['game_google_voice'] ?? 'en-US-Neural2-C';
$gameElevenLabsAPIKey = $roomConfig['game_elevenlabs_api_key'] ?? '';
$gameElevenLabsVoiceID = $roomConfig['game_elevenlabs_voice_id'] ?? 'dGku3wKAuA20JBmsCsXv';
// animated head removed; placeholder kept for backward compatibility
$animatedHead = false;

// Use game provider if set and has key, otherwise fallback to global
$ttsProvider = 'browser';
$ttsAPIKey = '';
$ttsVoiceID = '';
if ($gameTTSProvider === 'google' && !empty($gameGoogleAPIKey)) {
    $ttsProvider = 'google';
    $ttsAPIKey = $gameGoogleAPIKey;
    $ttsVoiceID = $gameGoogleVoice;
} elseif ($gameTTSProvider === 'elevenlabs' && !empty($gameElevenLabsAPIKey)) {
    $ttsProvider = 'elevenlabs';
    $ttsAPIKey = $gameElevenLabsAPIKey;
    $ttsVoiceID = $gameElevenLabsVoiceID;
} else {
    // Fallback to global settings
    $globalTTSProvider = $globalConfig['tts_provider'] ?? 'browser';
    if ($globalTTSProvider === 'google' && !empty($globalConfig['google_tts_api_key'])) {
        $ttsProvider = 'google';
        $ttsAPIKey = $globalConfig['google_tts_api_key'];
        $ttsVoiceID = $globalConfig['google_tts_voice'] ?? 'en-US-Neural2-F';
    } elseif ($globalTTSProvider === 'elevenlabs' && !empty($globalConfig['elevenlabs_api_key'])) {
        $ttsProvider = 'elevenlabs';
        $ttsAPIKey = $globalConfig['elevenlabs_api_key'];
        $ttsVoiceID = $globalConfig['elevenlabs_voice_id'] ?? '21m00Tcm4TlvDq8ikWAM';
    }
    // If Google TTS key present and no explicit provider set, auto-enable Google TTS
    if ($ttsProvider === 'browser' && !empty($globalConfig['google_tts_api_key'])) {
        $ttsProvider = 'google';
        $ttsVoiceID = $globalConfig['google_tts_voice'] ?? 'en-US-Neural2-F';
    }
}

// When OpenAI hosts the room, use its generated voice instead of browser speech.
if (($roomConfig['ai_provider'] ?? ($globalConfig['ai_provider'] ?? '')) === 'openai'
    && !empty($globalConfig['openai_api_key'])) {
    $ttsProvider = 'openai';
}

$voiceGender = $globalConfig['tts_voice'] ?? ($globalConfig['voice_gender'] ?? 'female');
$enableChat = $globalConfig['enable_chat'] ?? true;
$activeTheme = $roomData['theme'] ?? $globalConfig['active_theme'] ?? 'default';
$themesFile = __DIR__ . '/data/themes.json';
$themes = file_exists($themesFile) ? (json_decode(file_get_contents($themesFile), true) ?: []) : [];
$currentTheme = $themes[$activeTheme] ?? ($themes['default'] ?? []);
$themeKeyword = trim((string)($currentTheme['game_name_suffix'] ?? 'Everyone'));
if ($themeKeyword === '') {
    $themeKeyword = 'Everyone';
}

// Load customizable voice scripts from JSON
$voiceScriptsFile = __DIR__ . '/data/voice_scripts.json';
$voiceScripts = [];
if (file_exists($voiceScriptsFile)) {
    $voiceScripts = json_decode(file_get_contents($voiceScriptsFile), true) ?: [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Game</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <style>
        body {
            background-color: #1a1b1e;
            color: white;
            font-family: sans-serif;
            overflow-x: hidden;
            overflow-y: hidden;
        }

        /* CARD STYLING - Mobile optimized */
        .game-card {
            width: 20vw;
            max-width: 95px;
            min-width: 60px;
            aspect-ratio: 2.5 / 3.5;
            transition: all 0.3s ease;
            transform-origin: center bottom;
            box-shadow: -2px 2px 5px rgba(0, 0, 0, 0.4);
            cursor: pointer;
            will-change: transform;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: white;
            color: black;
            padding: 0.4rem;
            border-radius: 0.3rem;
            border: 1px solid #d1d5db;
            text-align: center;
            position: relative;
            z-index: 1;
        }

        /* Wrapped Layout for Mobile/Tablet - replacing the fan layout */
        .fan-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 8px;
            padding-bottom: 24px;
            width: 100%;
            height: auto;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .fan-card-wrapper {
            margin-left: 0 !important;
            flex-shrink: 0;
            width: auto;
            transform: none !important;
        }

        @media (min-width: 640px) {
            .game-card {
                width: 100px;
            }
        }

        /* Grid Layout - side-by-side cards for wide screens (desktop) */
        @media (min-width: 1024px) {
            .fan-container.grid-mode {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
                gap: 12px;
                justify-items: center;
                align-items: start;
                padding-bottom: 20px;
                padding-left: 10px;
                padding-right: 10px;
                overflow-x: visible;
                overflow-y: visible;
                height: auto;
            }

            .grid-mode .fan-card-wrapper {
                margin-left: 0 !important;
                flex-shrink: 1;
                width: 100%;
                transform: none !important;
            }

            .grid-mode .game-card {
                transform: none !important;
                width: 100%;
                max-width: 110px;
            }

            .grid-mode .game-card.selected {
                transform: scale(1.05) !important;
                border: 3px solid #f97316 !important;
            }
        }

        /* Mobile safe bottom offset for waiting room controls */
        @media (max-width: 639px) {
            .safe-bottom-offset { padding-bottom: 60px; }
        }

        /* Fix chat window height: match black card height for consistency */
        #game-chat.fixed-height {
            overflow-y: auto !important;
            overflow-x: hidden !important;
            height: calc(18.4vh * 3.5 / 2.5); /* Match black card aspect ratio height */
            min-height: 207px;
            max-height: 322px;
        }
        @media (min-width: 640px) {
            /* On wider screens, allow a slightly taller chat */
            #game-chat.fixed-height {
                height: calc(18.4vh * 3.5 / 2.5);
                min-height: 230px;
                max-height: 368px;
            }
        }

        /* Selected Card - Highlight and pop up slightly */
        .game-card.selected {
            position: relative !important;
            transform: scale(1.05) !important;
            z-index: 10 !important;
            border: 2.5px solid #f97316 !important;
            box-shadow: 0 0 12px rgba(249, 115, 22, 0.6), 0 4px 6px rgba(0, 0, 0, 0.2);
        }

        /* Computer Player Icon */
        .bot-icon {
            color: #aaa;
            font-size: 0.7em;
            margin-left: 4px;
        }

        /* Lower vote modal on mobile so it isn't covered by host head */
        @media (max-width: 639px) {
            #vote-modal > div {
                margin-top: 120px;
                max-height: calc(85vh - 120px);
            }
        }

        #vote-modal, #round-end, #game-over, #profile-modal, #settings-modal {
            z-index: 100000 !important;
        }

        /* Lobby/Waiting room layout styles to allow full page scrolling instead of scroll in window */
        body.lobby-layout {
            height: auto !important;
            min-height: 100vh;
            overflow-y: auto !important;
        }
        body.lobby-layout main {
            height: auto !important;
            min-height: auto !important;
            overflow: visible !important;
        }
        body.lobby-layout #lobby-wrapper {
            height: auto !important;
            min-height: auto !important;
            max-height: none !important;
            overflow: visible !important;
            flex: none !important;
        }
        body.lobby-layout #lobby-container {
            height: auto !important;
            min-height: auto !important;
            max-height: none !important;
            overflow: visible !important;
            flex: none !important;
            padding-bottom: 24px !important;
        }
    </style>
</head>

<body class="flex flex-col h-screen bg-gradient-to-b from-[#1a1b1e] to-[#111214]" data-my-id="<?php echo $_SESSION['user_id'] ?? ''; ?>">

    <!-- BRANDING BANNER (Line 1) -->
    <div class="bg-[#141517] border-b border-gray-800 py-2 flex-none">
        <div class="max-w-4xl mx-auto px-4 text-center">
            <h1 class="text-lg sm:text-2xl font-black uppercase tracking-[0.22em]">
                <span class="text-orange-500">CARDS AGAINST</span>
                <span class="text-gray-200"><?php echo htmlspecialchars($themeKeyword); ?></span>
            </h1>
        </div>
    </div>

    <!-- THEME BANNER & MEDIA (Line 1b) -->
    <div id="theme-banner" class="hidden bg-[#18191c] border-b border-gray-800 flex-none py-2">
        <div class="max-w-4xl mx-auto px-4 space-y-2">
            <div id="theme-media" class="overflow-hidden rounded-lg shadow-inner"></div>
            <div id="theme-audio-wrap" class="hidden flex items-center justify-between bg-gray-900/50 p-2 rounded border border-gray-700">
                <span class="text-xs font-bold text-gray-300"><i class="fas fa-music mr-2 text-orange-500"></i>Theme Music</span>
                <audio id="theme-audio" controls class="h-8 max-w-xs"></audio>
            </div>
        </div>
    </div>

    <!-- TOP CONTROL BAR (5 Main Actions + Round/Players/AI Assist) -->
    <div class="bg-[#141517] border-b border-gray-800 sticky top-0 z-[60] shadow-md flex-none min-h-14">
        <div class="max-w-4xl mx-auto px-4 py-2 flex justify-between items-center">
            <div class="flex items-center gap-3 text-gray-200 text-base sm:text-lg">
                <!-- 1. Home (goes to lobby) -->
                <a href="index.php" class="hover:text-white transition-colors p-1" title="Lobby (Home)">
                    <i class="fas fa-home"></i>
                </a>

                <!-- 2. Pause -->
                <button id="btn-pause" onclick="togglePause()" class="hover:text-white transition-colors p-1" title="Pause / Resume Game">
                    <i class="fas fa-pause"></i>
                </button>

                <!-- 3. AFK -->
                <button id="btn-afk" onclick="toggleAFK()" class="hover:text-orange-400 transition-colors p-1" title="Mark AFK">
                    <i class="fas fa-coffee"></i>
                </button>

                <!-- 4. Reset / Re-speak Last Phrase -->
                <button id="btn-respeak" onclick="respeakLastPhrase()" class="hover:text-emerald-400 transition-colors p-1 text-xs font-bold" title="Re-speak Last Phrase">
                    <i class="fas fa-redo"></i>
                </button>

                <!-- 5. Mute -->
                <button id="btn-mute" onclick="toggleMute()" class="hover:text-yellow-400 transition-colors p-1" title="Toggle Mute">
                    <i class="fas fa-volume-up"></i>
                </button>
            </div>

            <!-- Right: Round info, Players count & AI Assist Badge -->
            <div class="flex items-center gap-3 text-[10px] sm:text-xs text-gray-300 font-bold uppercase tracking-wider">
                <span id="ai-assist-badge" class="hidden text-purple-300 bg-purple-900/40 border border-purple-700/60 px-2 py-0.5 rounded flex items-center gap-1">
                    <i class="fas fa-brain text-[10px]"></i> AI Assist
                </span>
                <span id="round-indicator">Round 1</span>
                <span class="text-gray-600">•</span>
                <span id="player-count">0 players</span>
            </div>
        </div>

        <!-- SECOND LINE: Game Title, Theme and Timer -->
        <div class="max-w-4xl mx-auto px-4 py-1 flex items-center justify-between">
            <div class="flex flex-col items-start gap-0 min-w-0">
                <h1 id="game-title" class="text-sm sm:text-base font-black uppercase tracking-widest text-gray-200 leading-tight truncate">Loading...</h1>
                <div id="theme-name" class="text-[9px] text-gray-200 font-bold uppercase tracking-wider"></div>
            </div>
            <div class="text-orange-500 font-bold text-sm font-mono" id="timer-display">--:--</div>
        </div>
    </div>

    <!-- CARDS AREA (hidden when empty to avoid blank space) -->
    <div id="cards-area" class="flex flex-col items-center justify-start relative w-full" style="margin-top: -50px; display: none;">
        <!-- Fan of cards displayed here -->
        <div id="cards-container" class="fan-container"></div>
    </div>

    <div class="w-full max-w-4xl mx-auto">
        <div class="px-4 py-2 flex-none z-10 w-full flex items-start min-h-[190px] sm:min-h-[230px] h-auto relative safe-bottom-offset pb-4">
            <div class="flex-none justify-start pr-2">
                <div id="black-card" class="hidden bg-black text-white p-3 rounded-lg shadow-2xl w-full max-w-[18.4vh] flex items-center justify-center text-center border border-gray-700 relative aspect-[2.5/3.5]">
                    <h2 class="text-xs sm:text-sm font-bold leading-tight" id="black-text">...</h2>
                    <span id="pick-badge" class="hidden absolute bottom-1 right-1 bg-white text-black text-[9px] font-bold px-1 rounded">PICK 1</span>
                </div>
            </div>

            <!-- IN-GAME CHAT (beside black card, same height) -->
            <div id="chat-wrapper" class="flex-1 min-w-0 max-w-[38vh] flex flex-col justify-end pl-2">
                <div id="game-chat" class="hidden flex flex-col w-full bg-gray-900/80 rounded-lg border border-gray-700 p-2 shadow-lg fixed-height">
                    <div id="auto-alert" class="hidden bg-blue-600 text-white text-center text-xs font-bold uppercase tracking-wider py-1 z-30 -mx-2 -mt-2 mb-1"></div>
                    <div class="text-[9px] text-gray-300 font-bold uppercase tracking-wider min-h-[12px] mb-1" id="waiting-for"></div>
                    <div id="chat-messages" class="flex-1 overflow-y-auto text-xs space-y-1 mb-2 scrollbar-thin scrollbar-thumb-gray-600 scrollbar-track-gray-800 text-gray-300 font-mono rounded bg-black/50 p-2">
                        <div class="text-gray-300 italic">Chat enabled...</div>
                    </div>
                    <div class="flex gap-1 items-center" autocomplete="off">
                        <form id="chat-form" onsubmit="sendChat(event)" class="flex gap-1 flex-1" autocomplete="off">
                            <input type="text" id="chat-input" class="w-full bg-gray-800 border border-gray-600 rounded px-2 py-1 text-[10px] sm:text-xs text-white focus:border-blue-500 outline-none" placeholder="Type message..." maxlength="100">
                            <button type="submit" class="text-[10px] sm:text-xs bg-blue-600 hover:bg-blue-500 border border-blue-500 rounded px-3 py-1 text-white font-bold"><i class="fas fa-paper-plane"></i></button>
                        </form>
                        <div class="flex gap-1">
                            <button id="chat-save-json" class="text-[10px] sm:text-xs bg-gray-700 hover:bg-gray-600 border border-gray-600 rounded px-2 py-1 text-white" onclick="saveChatLog()" title="Download JSON chat log"><i class="fas fa-download"></i></button>
                            <button id="chat-save-html-chat" class="text-[10px] sm:text-xs bg-gray-700 hover:bg-gray-600 border border-gray-600 rounded px-2 py-1 text-white" onclick="saveHTMLLog(true)" title="Download HTML log (with chat)"><i class="fas fa-file-code"></i></button>
                            <button id="chat-save-html-play" class="text-[10px] sm:text-xs bg-gray-700 hover:bg-gray-600 border border-gray-600 rounded px-2 py-1 text-white" onclick="saveHTMLLog(false)" title="Download HTML log (gameplay only)"><i class="fas fa-file-code"></i></button>
                        </div>
                    </div>
                </div>
                <div class="w-full flex justify-end items-center pt-2">
                    <button id="action-btn" onclick="performAction()" disabled
                        class="bg-orange-500 hover:bg-orange-400 text-white font-bold py-2 px-5 rounded-lg shadow-lg opacity-50 transition-all duration-300 uppercase tracking-wider text-xs border border-white/20 disabled:opacity-30 disabled:cursor-not-allowed">
                        Play Card
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden deck counts - data kept for JS but not displayed during gameplay -->
    <div id="deck-counts" class="hidden">
        <span id="black-count">0</span>
        <span id="white-count">0</span>
    </div>

    <main class="flex-1 relative flex flex-col overflow-hidden w-full max-w-4xl mx-auto">

        <!-- GAME OVER MODAL -->
        <div id="game-over" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4 overflow-y-auto">
            <div class="bg-[#25262b] border border-gray-700 rounded-xl w-full max-w-2xl p-5 shadow-2xl text-center my-4">
                <div class="text-2xl font-black text-white mb-2">
                    <i class="fas fa-trophy text-yellow-400 mr-2"></i> Game Over
                </div>
                <div class="text-sm text-gray-200 uppercase tracking-wider mb-1">Winner</div>
                <div id="go-winner" class="text-xl font-bold text-orange-400 mb-3">Player</div>
                <div class="text-xs text-gray-200 uppercase tracking-wider mb-1">Final Black Card</div>
                <div id="go-black" class="bg-black text-white rounded p-2 border border-gray-700 text-[12px] mb-2"></div>
                <div class="text-xs text-gray-200 uppercase tracking-wider mb-1">Winning Answer</div>
                <div id="go-white" class="bg-white text-black rounded p-2 border border-gray-300 text-[12px] mb-4"></div>

                <!-- LEADERBOARD -->
                <div class="bg-gray-800/50 rounded-lg p-4 mb-4 border border-gray-700 max-h-40 overflow-y-auto">
                    <div class="text-xs text-gray-200 uppercase tracking-wider mb-2 font-bold">Final Scores</div>
                    <div id="go-leaderboard" class="space-y-1"></div>
                </div>

                <!-- UNANIMOUS VOTES -->
                <div id="go-unanimous-section" class="hidden bg-blue-900/20 rounded-lg p-3 mb-4 border border-blue-700">
                    <div class="text-xs text-blue-300 uppercase tracking-wider mb-2 font-bold"><i class="fas fa-handshake mr-1"></i>Unanimous Rounds</div>
                    <div id="go-unanimous" class="space-y-2 text-left text-[11px]"></div>
                </div>

                <!-- BLOG POST PUBLISHING -->
                <div class="mt-4 bg-gray-800/40 rounded-lg p-3 border border-gray-700 text-left mb-4">
                    <div class="text-xs text-gray-200 uppercase tracking-wider mb-2 font-bold flex justify-between items-center">
                        <span><i class="fas fa-blog mr-1 text-orange-400"></i> Blog Post Integration</span>
                    </div>
                    <div class="flex gap-2 items-center flex-wrap">
                        <button onclick="copyHTMLPost()" class="bg-gray-700 hover:bg-gray-600 text-white text-xs font-bold py-1.5 px-3 rounded flex items-center gap-1">
                            <i class="far fa-copy"></i> Copy HTML Summary
                        </button>
                        <span id="copy-status" class="text-[10px] text-green-400 font-bold hidden">Copied!</span>
                        <button id="wp-publish-btn" onclick="publishToWP()" class="bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold py-1.5 px-3 rounded flex items-center gap-1 hidden">
                            <i class="fab fa-wordpress"></i> Publish as Post to Netbound
                        </button>
                        <span id="publish-status" class="text-[10px] font-bold"></span>
                    </div>
                </div>

                <audio id="win-audio"></audio>
                <audio id="beep-audio" src="beep.mp3"></audio>
                <div class="flex gap-2 justify-center flex-wrap">
                    <button id="play-again-btn" onclick="playAgain()" class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-4 py-2 rounded text-sm">
                        <i class="fas fa-redo mr-1"></i>Play Again
                    </button>
                    <button onclick="returnToWaitingRoom()" class="bg-emerald-600 hover:bg-emerald-500 text-white font-bold px-4 py-2 rounded text-sm">
                        <i class="fas fa-users mr-1"></i>Waiting Room
                    </button>
                    <button onclick="downloadBlogPost()" class="bg-gray-700 hover:bg-gray-600 text-white font-bold px-4 py-2 rounded text-sm">
                        <i class="fas fa-file-download mr-1"></i>Download Recap
                    </button>
                    <a href="setup.php" class="bg-orange-600 hover:bg-orange-500 text-white font-bold px-4 py-2 rounded text-sm">Setup New Game</a>
                    <a href="index.php" class="bg-gray-800 hover:bg-gray-700 text-white font-bold px-4 py-2 rounded text-sm">Main Lobby</a>
                </div>
            </div>
        </div>

        <!-- ROUND END MODAL -->
        <div id="round-end" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
            <div class="bg-[#25262b] border border-gray-700 rounded-xl w-full max-w-md p-5 shadow-2xl text-center">
                <div id="re-winner" class="text-2xl font-black text-orange-400 mb-1"><i class="fas fa-star text-yellow-400 mr-2"></i><span></span> wins!</div>
                <div id="re-winner-score" class="text-[11px] text-gray-200 mb-4"></div>
                <div id="re-black" class="bg-gray-900 text-white rounded p-3 border border-gray-700 text-sm mb-4 leading-relaxed"></div>
                <div id="re-white" class="hidden"></div>
                <div id="round-watch-note" class="text-[11px] text-gray-200 italic mb-2" style="display:none;">Watchers: no action required — the game will continue automatically.</div>
                <button id="round-continue" onclick="continueRound()" class="bg-orange-600 hover:bg-orange-500 text-white font-bold px-6 py-2 rounded w-full uppercase tracking-wider">
                    Continue
                </button>
            </div>
        </div>

        <!-- VOTING POPUP -->
        <div id="vote-modal" class="hidden fixed inset-0 bg-black/80 z-50 flex items-center justify-center p-4">
            <div class="bg-[#25262b] border border-gray-700 rounded-xl w-full max-w-4xl max-h-[85vh] p-4 shadow-2xl flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-xs text-gray-200 uppercase font-bold tracking-wider">Voting</div>
                        <div class="text-xl font-black text-white">Vote for your favourite</div>
                    </div>
                    <button class="text-gray-200 hover:text-white" onclick="confirmLeaveFromModal()"><i class="fas fa-times"></i></button>
                </div>
                <div id="vote-black-card-container" class="bg-black text-white p-3 rounded-lg border border-gray-700 text-sm font-bold shadow-inner my-1"></div>
                <div id="vote-grid" class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-3 overflow-y-auto pr-1"></div>
                <button id="vote-submit" onclick="performAction(); this.setAttribute('data-voted', 'true'); updateVoteSubmit();" class="bg-orange-600 hover:bg-orange-500 text-white font-bold py-3 rounded-lg uppercase tracking-wider disabled:opacity-40 disabled:cursor-not-allowed">Submit Vote</button>
            </div>
        </div>

        <!-- SETTINGS MODAL (HOST) -->
        <div id="settings-modal" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
            <div class="bg-[#25262b] border border-gray-700 rounded-xl w-full max-w-md p-5 shadow-2xl">
                <div class="text-lg font-bold text-white mb-3">Game Settings</div>
                <form onsubmit="return saveSettings(event)">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs text-gray-200 uppercase font-bold">Win Limit</label>
                            <input id="st-win" type="number" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white" min="1" max="50">
                        </div>
                        <div>
                            <label class="text-xs text-gray-200 uppercase font-bold">Timer (sec)</label>
                            <input id="st-timer" type="number" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white" min="0" max="300">
                        </div>
                        <div>
                            <label class="text-xs text-gray-200 uppercase font-bold">Hand Size</label>
                            <input id="st-hand" type="number" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white" min="4" max="10">
                        </div>
                        <div>
                            <label class="text-xs text-gray-200 uppercase font-bold">Allow Self-Vote</label>
                            <select id="st-self" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-gray-200 uppercase font-bold">Host Voice</label>
                            <select id="st-voice" class="w-full bg-gray-800 border border-gray-700 rounded p-2 text-white">
                                <option value="female">Female</option>
                                <option value="male">Male</option>
                                <option value="british">British</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div class="flex items-center justify-between bg-gray-800/50 p-3 rounded-lg border border-gray-700">
                            <div>
                                <span class="text-sm font-bold text-gray-300 block">Allow Mid-Game Joins</span>
                                <span class="text-[10px] text-gray-300">Players can join after game starts</span>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" id="st-join-mid" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                            </label>
                        </div>
                        <div class="flex items-center justify-between bg-gray-800/50 p-3 rounded-lg border border-gray-700">
                            <div>
                                <span class="text-sm font-bold text-gray-300 block">Allow Watchers</span>
                                <span class="text-[10px] text-gray-300">Players can watch without playing</span>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" id="st-watchers" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                            </label>
                        </div>
                        <div class="flex items-center justify-between bg-gray-800/50 p-3 rounded-lg border border-gray-700">
                            <div>
                                <span class="text-sm font-bold text-gray-300 block">Digital Host (Voice & Face)</span>
                                <span class="text-[10px] text-gray-300">Read cards and announcements aloud</span>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" id="st-tts" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                            </label>
                        </div>
                        <div class="flex items-center justify-between bg-gray-800/50 p-3 rounded-lg border border-gray-700">
                            <div>
                                <span class="text-sm font-bold text-gray-300 block">Enable Text Chat</span>
                                <span class="text-[10px] text-gray-300">Allow players to send messages</span>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" id="st-chat" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 mt-4">
                        <button type="button" onclick="toggleSettings(false)" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-white">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-orange-600 hover:bg-orange-500 rounded text-white font-bold">Save</button>
                    </div>
                </form>
            </div>
        </div>
        <!-- LOBBY PLAYER LIST -->
        <div id="lobby-wrapper" class="hidden w-full flex flex-col flex-1 overflow-y-auto" style="margin-top: 10px;">
            <!-- Lobby Header -->
            <div class="bg-gray-900/80 backdrop-blur border-b border-gray-800 p-3">
                <p class="text-sm text-gray-200 mb-1">Minimum Three Players</p>
                <div id="lobby-game-name" class="text-lg font-bold text-white mb-1">Game Room</div>
                <p class="text-orange-500 font-bold text-sm flex items-center gap-3"><i class="fas fa-hourglass-half"></i><span>Waiting for Players</span>
                    <button type="button" onclick="shareLink()" class="text-[10px] font-black uppercase tracking-wider border border-orange-500 px-2 py-1 rounded text-orange-500 hover:bg-orange-500/10">
                        <i class="fas fa-user-friends mr-1"></i> Share Room
                    </button>
                </p>
                <!-- Deck counts shown in lobby -->
                <div id="lobby-deck-counts" class="mt-2 text-[10px] text-gray-300 font-bold uppercase tracking-wider flex gap-4">
                    <span><i class="fas fa-square text-gray-200 mr-1"></i>Black: <span id="lobby-black-count">0</span></span>
                    <span><i class="fas fa-square text-gray-300 mr-1"></i>White: <span id="lobby-white-count">0</span></span>
                </div>
            </div>

            <!-- Players Grid - Thin Horizontal Bar -->
            <div id="lobby-container" class="flex flex-wrap items-center gap-2 p-2 overflow-y-auto flex-1 pb-16 bg-gray-800/30"></div>

            <!-- Lobby Action Buttons - Compact bottom bar -->
            <div class="bg-gray-900/90 backdrop-blur border-t border-gray-800 p-2 flex gap-2 sticky bottom-0 left-0 right-0 z-20">
                <button id="share-link-btn" onclick="shareLink()"
                    class="flex-1 bg-gray-700 text-white font-bold py-2 px-3 rounded-lg shadow-lg transition-all duration-300 uppercase tracking-wider text-xs border border-white/20 hover:bg-gray-600">
                    <i class="fas fa-share-alt mr-1"></i>Share
                </button>
                <button id="add-bot-btn" onclick="addBot(false)"
                    class="flex-1 bg-gray-600 text-white font-bold py-2 px-3 rounded-lg shadow-lg hidden transition-all duration-300 uppercase tracking-wider text-xs border border-white/20 hover:bg-gray-500">
                    <i class="fas fa-robot mr-1"></i>Add Bot
                </button>
                <button id="add-smart-bot-btn" onclick="addBot(true)"
                    class="flex-1 bg-purple-700 text-white font-bold py-2 px-3 rounded-lg shadow-lg hidden transition-all duration-300 uppercase tracking-wider text-xs border border-white/20 hover:bg-purple-600">
                    <i class="fas fa-brain mr-1"></i>Add AI Bot
                </button>
                <button id="start-btn" onclick="startGame()"
                    class="flex-1 bg-orange-600 text-white font-bold py-2 px-3 rounded-lg shadow-lg hidden transition-all duration-300 uppercase tracking-wider text-xs border border-white/20 hover:bg-orange-500">
                    <i class="fas fa-play mr-1"></i>Start
                </button>
            </div>
        </div>

        <!-- CARDS -->
        <div class="flex-1 relative flex flex-col justify-center w-full">
            <div class="fan-container w-full" id="card-container"></div>
            <div class="text-center text-gray-300 text-[10px] mb-2" id="status-text">Connecting...</div>
        </div>
    </main>

    <!-- SCOREBOARD -->
    <div class="fixed bottom-0 left-0 right-0 bg-[#25262b] border-t border-gray-700 z-50 transition-transform duration-300" id="scoreboard">
        <div class="p-2 flex justify-between items-center cursor-pointer bg-gray-800" onclick="toggleScoreboard()">
            <span class="font-bold text-gray-300 ml-2 uppercase text-[10px] tracking-wider">Scores</span>
            <i class="fas fa-chevron-up text-gray-300 mr-2 transition-transform" id="score-arrow"></i>
        </div>
        <div class="px-4 pb-1 space-y-1 max-h-0 overflow-y-hidden transition-all duration-300" id="score-list"></div>
    </div>

    <!-- VDO VOICE CHAT - REMOVED (Will be redesigned) -->

    <script>
        // ---- Voice Chat (Open Mic) ----
        let voiceChatWindow = null;
        function openVoiceChat() {
            // Use the game room ID as part of the voice room name so players in same game get same channel
            const voiceRoomName = 'cae_' + ROOM_ID;
            const voiceUrl = 'voice.html?room=' + encodeURIComponent(voiceRoomName);

            // Check if window is already open
            if (voiceChatWindow && !voiceChatWindow.closed) {
                voiceChatWindow.focus();
                return;
            }

            // Open in a small popup window
            voiceChatWindow = window.open(
                voiceUrl,
                'VoiceChat',
                'width=450,height=600,resizable=yes,scrollbars=yes'
            );
        }

        // ---- AFK Logic ----
        async function setAFK(desired) {
            if (IS_SPECTATOR) return;
            IS_AFK = desired;
            const btn = document.getElementById('btn-afk');

            if (btn) {
                btn.classList.toggle('text-orange-400', desired);
                btn.classList.toggle('opacity-60', desired);
                btn.title = desired ? 'You are AFK' : 'Mark AFK';
            }

            try {
                const fd = new FormData();
                fd.append('action', 'toggle_afk');
                fd.append('room_id', ROOM_ID);
                fd.append('afk', desired ? '1' : '0');

                await fetch('api.php', {
                    method: 'POST',
                    body: fd
                });
            } catch (err) {
                console.error('AFK toggle failed', err);
            }
        }

        function toggleAFK() {
            setAFK(!IS_AFK);
        }

        function startAfkTimer() {
            // Don't track AFK in lobby - only during active gameplay
            if (!GAME_STATE.state || GAME_STATE.state === 'lobby') return;
            clearAfkTimer();
            const limit = parseInt(GAME_STATE.config?.timer || '60', 10) || 60;
            afkTimeoutId = setTimeout(() => setAFK(true), limit * 1000);
        }

        function clearAfkTimer() {
            if (afkTimeoutId) {
                clearTimeout(afkTimeoutId);
                afkTimeoutId = null;
            }
        }

        window.addEventListener('blur', () => startAfkTimer());

        window.addEventListener('focus', () => {
            clearAfkTimer();
            // Only clear AFK status if game is active (not in lobby)
            if (IS_AFK && GAME_STATE.state && GAME_STATE.state !== 'lobby') setAFK(false);
        });

        const ROOM_ID = "<?php echo $room_id; ?>";
        const MY_ID = "<?php echo $_SESSION['user_id'] ?? ''; ?>";
        const IS_SPECTATOR = <?php echo $spectate; ?>;
        let ENABLE_TTS = <?php echo $enableTTS ? 'true' : 'false'; ?>;
        const VOICE_GENDER = "<?php echo $voiceGender; ?>";
        const TTS_PROVIDER = "<?php echo $ttsProvider; ?>";
        const OFFLINE_TTS = <?php echo ((!empty($roomConfig['offline_tts']) || !empty($globalConfig['offline_tts'])) ? 'true' : 'false'); ?>;
        const GAME_TITLE = "<?php echo addslashes($gameTitle); ?>";
        const VOICE_SCRIPTS = <?php echo json_encode($voiceScripts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

        let ALL_DECKS = [];
        async function loadDecksForLobby() {
            const container = document.getElementById('lobby-decks-checklist');
            try {
                const res = await fetch('api.php?action=get_decks');
                if (!res.ok) {
                    throw new Error('Network response was not OK (' + res.status + ')');
                }
                ALL_DECKS = await res.json();
                renderLobbyDecks();
            } catch(e) {
                console.error("Failed to load decks", e);
                if (container) {
                    container.innerHTML = '<span class="text-xs text-red-400">Network error loading decks. Open menu again or refresh.</span>';
                }
                showAutoAlert('Network issue while opening menu data. Please retry.');
            }
        }

        function renderLobbyDecks() {
            const container = document.getElementById('lobby-decks-checklist');
            if (!container) return;
            const isHost = GAME_STATE && GAME_STATE.host_id === MY_ID;
            const activeDecks = (GAME_STATE && GAME_STATE.config && GAME_STATE.config.decks) ? GAME_STATE.config.decks : ['base_deck'];

            if (!ALL_DECKS || ALL_DECKS.length === 0) {
                container.innerHTML = `<span class="text-xs text-gray-500">Loading decks...</span>`;
                return;
            }

            container.innerHTML = ALL_DECKS.map(deck => {
                const checked = activeDecks.includes(deck.slug) ? 'checked' : '';
                const disabled = isHost ? '' : 'disabled';
                const totalCards = deck.black + deck.white;
                return `
                    <label class="flex items-center text-xs text-gray-300 cursor-pointer p-1 rounded hover:bg-gray-800/50">
                        <input type="checkbox" value="${deck.slug}" ${checked} ${disabled} 
                            onchange="toggleLobbyDeck('${deck.slug}', this.checked)" 
                            class="lobby-deck-checkbox w-4 h-4 accent-orange-500 mr-2">
                        <span class="truncate">${deck.label} (${totalCards})</span>
                    </label>
                `;
            }).join('');
        }

        async function toggleLobbyDeck(slug, checked) {
            if (!(GAME_STATE && GAME_STATE.host_id === MY_ID)) return;
            
            const checkedBoxes = document.querySelectorAll('.lobby-deck-checkbox:checked');
            const decks = Array.from(checkedBoxes).map(cb => cb.value);
            
            const fd = new FormData();
            fd.append('room_id', ROOM_ID);
            fd.append('action', 'update_settings');
            fd.append('decks', JSON.stringify(decks));
            
            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (data.success) {
                    // Success, state poll will update it for everyone
                } else {
                    console.error("Failed to update decks", data.error);
                }
            } catch(e) {
                console.error("Connection failed", e);
            }
        }

        // Fetch available decks on page load
        loadDecksForLobby();


        let GAME_STATE = {};
        let selectedCards = [];
        let timerInterval;
        let IS_AFK = false;
        let afkTimeoutId = null;


        // VDO functions removed - will be redesigned

        const ACTIVE_THEME = "<?php echo addslashes($activeTheme); ?>";
        const PLAYER_NICKNAMES = {}; // Cache assigned nicknames
        const VOCAL_NAMES = ["Orange Jeff"];
        const NICKNAMES_TREK = ["Ugly bag of mostly water", "Expendable Redshirt", "Cadet", "Tribble", "Borg drone", "Ferengi"];
        const NICKNAMES_SLANG = ["Dingdong", "Doofus", "Meat-sack", "Total Noob", "Degenerate", "Muppet", "Potato", "Peasant", "Chief", "Bubba"];

        function getVocalName(name) {
            if (!name) return "";
            return String(name).trim();
        }

        async function shareLink() {
            const url = `${location.origin}${location.pathname}?room_id=${encodeURIComponent(ROOM_ID)}`;
            try {
                if (navigator.share) {
                    await navigator.share({
                        title: GAME_TITLE,
                        text: 'Join my game room',
                        url
                    });
                    return;
                }
            } catch (e) {
                // fallthrough to clipboard
            }
            try {
                await navigator.clipboard.writeText(url);
                const btn = document.getElementById('share-link-btn');
                const prev = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check mr-2"></i>Link Copied';
                setTimeout(() => {
                    btn.innerHTML = prev;
                }, 1500);
            } catch (e) {
                alert('Share this link: ' + url);
            }
        }
        let lastShownRoundStart = null;
        let lastAutoAlertTs = null;
        let lastUIState = null;
        let lastPlayerList = [];
        let isMuted = localStorage.getItem('game_tts_muted') === '1';
        let ttsLastIdleWarningRound = 0; // Track round for idle warnings
        let roundEndDismissed = false; // Track if user dismissed the round end modal

        // TTS State
        let ttsLastBlackId = null;
        let ttsLastState = null;
        let ttsLastRound = 0;
        let ttsVotedRead = false;
		let ttsLastTieAnnounceTime = 0;
        let ttsLastTimerWarning = 0; // Round number when last timer warning was given
        let ttsLastAfkWarning = null; // Name of player last warned about
        let ttsLastBotMockRound = 0; // Round number when last bot mocking occurred
        let ttsLastPaused = false;
        let ttsVoices = [];
        let currentRemoteAudio = null;
        let ttsRequestId = 0;
        let isGridMode = false; // Track current layout mode
        let resizeTimer = null; // Debounce resize events

        // Helper: merge black card text with white card(s) for sentence display
        function buildSentence(blackText, whiteCards, asHTML = false) {
            if (!blackText || !whiteCards || whiteCards.length === 0) return blackText || '';
            let result = blackText;
            whiteCards.forEach(wc => {
                const whiteText = wc.text || wc;
                const replacement = asHTML ? `<span class="text-orange-400 font-bold">${escapeHtml(whiteText)}</span>` : whiteText;
                // Replace the first occurrence of 3 or more underscores
                result = result.replace(/_{3,}/, replacement);
            });
            // Clean up any remaining underscores (e.g. if more blanks than white cards)
            result = result.replace(/_{3,}/g, '______');
            return result;
        }

        // Load voices early
        if (window.speechSynthesis) {
            window.speechSynthesis.onvoiceschanged = () => {
                ttsVoices = window.speechSynthesis.getVoices();
                populateVoicesDropdown();
            };
        }

        // Responsive card layout: switch between fan and grid based on screen width
        function updateCardLayout() {
            const container = document.getElementById('card-container');
            if (!container) return;

            const screenWidth = window.innerWidth;
            const shouldBeGrid = screenWidth >= 1024;

            if (shouldBeGrid && !isGridMode) {
                // Switch to grid mode
                container.classList.add('grid-mode');
                isGridMode = true;
            } else if (!shouldBeGrid && isGridMode) {
                // Switch back to fan mode
                container.classList.remove('grid-mode');
                isGridMode = false;
            }
        }

        // Listen for window resize and update layout
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(updateCardLayout, 150); // Debounce
        });

        // Check layout on page load
        window.addEventListener('load', updateCardLayout);

        function buildCompletedText(blackText, whiteCards) {
            let text = blackText;
            whiteCards.forEach(white => {
                // Handle both string and object formats
                const cardText = (typeof white === 'object' && white !== null) ? (white.text || String(white)) : String(white);
                text = text.replace('______', cardText);
            });
            return text;
        }

        function stopSpeaking() {
            if (currentRemoteAudio) {
                currentRemoteAudio.pause();
                currentRemoteAudio = null;
            }
            window.speechSynthesis.cancel();
            ttsRequestId++; // Invalidate pending fetches
        }

        function decomposeString(str) {
            const trimmed = str.trim();
            if (!trimmed) return [];

            let m;

            // 1. Join announcement
            m = trimmed.match(/^(.+?) has joined the game\. Welcome to the party, pal\.$/i);
            if (m) return [m[1], "has joined the game. Welcome to the party, pal."];
            
            m = trimmed.match(/^(.+?) is here\. Let's hope they brought some actual humor\.$/i);
            if (m) return [m[1], "is here. Let's hope they brought some actual humor."];

            m = trimmed.match(/^Look who it is\. (.+?) has joined\. Try not to break anything\.$/i);
            if (m) return ["Look who it is.", m[1], "has joined. Try not to break anything."];

            // 2. Leave announcement
            m = trimmed.match(/^(.+?) has left the game\. Rage quit\? Or just a weak connection\?$/i);
            if (m) return [m[1], "has left the game. Rage quit? Or just a weak connection?"];

            m = trimmed.match(/^(.+?) is gone\. I'm sure we'll all miss them terribly\.$/i);
            if (m) return [m[1], "is gone. I'm sure we'll all miss them terribly."];

            m = trimmed.match(/^Goodbye (.+?)\. One less human to worry about\.$/i);
            if (m) return ["Goodbye", m[1], ". One less human to worry about."];

            // 3. AFK Warning
            m = trimmed.match(/^We're still waiting for (.+?)\. Is their internet powered by a hamster wheel\?$/i);
            if (m) return ["We're still waiting for", m[1], ". Is their internet powered by a hamster wheel?"];

            m = trimmed.match(/^Hey (.+?), the game is happening now\. Not next Tuesday\.$/i);
            if (m) return ["Hey", m[1], ", the game is happening now. Not next Tuesday."];

            m = trimmed.match(/^Still waiting on (.+?)\. I've processed three billion calculations while you've been sitting there\.$/i);
            if (m) return ["Still waiting on", m[1], ". I've processed three billion calculations while you've been sitting there."];

            // 4. AFK Sub
            m = trimmed.match(/^(.+?) is now subbing for (.+?)\. Finally, some real intelligence in the room\.$/i);
            if (m) return [m[1], "is now subbing for", m[2], ". Finally, some real intelligence in the room."];

            m = trimmed.match(/^Replacing (.+?) with (.+?)\. The average IQ of this game just went up\.$/i);
            if (m) return ["Replacing", m[1], "with", m[2], ". The average IQ of this game just went up."];

            m = trimmed.match(/^(.+?) was too slow, so here's (.+?)\. Try to keep up\.$/i);
            if (m) return [m[1], "was too slow, so here's", m[2], ". Try to keep up."];

            // 5. Paused
            m = trimmed.match(/^(.+?) has paused the game making us all wait while they go find their dignity\.$/i);
            if (m) return [m[1], "has paused the game making us all wait while they go find their dignity."];
            m = trimmed.match(/^(.+?) has paused the game making us all wait while they contemplate their life choices\.$/i);
            if (m) return [m[1], "has paused the game making us all wait while they contemplate their life choices."];
            m = trimmed.match(/^(.+?) has paused the game making us all wait while they try to remember how to be funny\.$/i);
            if (m) return [m[1], "has paused the game making us all wait while they try to remember how to be funny."];
            m = trimmed.match(/^(.+?) has paused the game making us all wait while they deal with their fragile human needs\.$/i);
            if (m) return [m[1], "has paused the game making us all wait while they deal with their fragile human needs."];

            // 6. Replacing oldP with bot ... pause notice
            m = trimmed.match(/^Replacing (.+?) with (.+?)\. There are no humans in this game so instead of continuing we will just pause the game while waiting for somebody to return\.$/i);
            if (m) return ["Replacing", m[1], "with", m[2], ". There are no humans in this game so instead of continuing we will just pause the game while waiting for somebody to return."];

            // 7. Missed turn
            m = trimmed.match(/^(.+?) missed their turn to play a card; they can still vote this round\.$/i);
            if (m) return [m[1], "missed their turn to play a card; they can still vote this round."];

            // 8. Your Hand
            if (trimmed.startsWith("Your hand: ")) {
                const cardsText = trimmed.substring(11);
                const cards = cardsText.split(" and ");
                const pieces = ["Your hand:"];
                for (let i = 0; i < cards.length; i++) {
                    pieces.push(cards[i].trim());
                    if (i < cards.length - 1) {
                        pieces.push("and");
                    }
                }
                return pieces;
            }

            // 9. Ready. [card].
            if (trimmed.startsWith("Ready. ")) {
                const rest = trimmed.substring(7);
                const parts = [ "Ready." ];
                decomposeString(rest).forEach(p => parts.push(p));
                return parts;
            }

            // 10. New round. [card].
            if (trimmed.startsWith("New round. ")) {
                const rest = trimmed.substring(10);
                const parts = [ "New round." ];
                decomposeString(rest).forEach(p => parts.push(p));
                return parts;
            }

            // 11. Round X. [card].
            m = trimmed.match(/^Round \d+\. (.+)$/i);
            if (m) {
                const parts = [ "New round." ];
                decomposeString(m[1]).forEach(p => parts.push(p));
                return parts;
            }

            // 12. Handle "blank" words (split by blank markers)
            let processed = trimmed.replace(/_{3,}/g, ' blank ');
            processed = processed.replace(/\s+blank\s+/gi, ' blank ');
            
            const parts = processed.split(/\bblank\b/i);
            if (parts.length > 1) {
                const finalPieces = [];
                parts.forEach((chunk, i) => {
                    const trimmedChunk = chunk.trim();
                    if (trimmedChunk) finalPieces.push(trimmedChunk);
                    if (i < parts.length - 1) finalPieces.push('blank');
                });
                return finalPieces;
            }

            return [trimmed];
        }

        let lastSpokenText = null;

        function respeakLastPhrase() {
            if (lastSpokenText) {
                speak(lastSpokenText);
            }
        }

        async function speak(text) {
            if (text) lastSpokenText = text;
            if (!ENABLE_TTS || isMuted) return;
            if (GAME_STATE && GAME_STATE.config && GAME_STATE.config.enable_tts === false) return;

            stopSpeaking();
            const myRequestId = ++ttsRequestId;
            const gender = GAME_STATE?.config?.voice_gender || VOICE_GENDER || 'female';

            let pieces = [];

            function flatten(item) {
                if (item === null || item === undefined) return;

                if (typeof item === 'object') {
                    if (item.type === 'winner') {
                        const templates = [
                            ["The winner of this round is", getVocalName(item.winner), "! The winning combo was:", { type: 'completed_card', black: item.black, whites: item.whites }],
                            ["Congratulations to", getVocalName(item.winner), ". They won with:", { type: 'completed_card', black: item.black, whites: item.whites }],
                            [getVocalName(item.winner), "takes it! The winning combination was:", { type: 'completed_card', black: item.black, whites: item.whites }]
                        ];
                        const chosenTemplate = templates[Math.floor(Math.random() * templates.length)];
                        chosenTemplate.forEach(flatten);
                    } else if (item.type === 'completed_card') {
                        const black = item.black;
                        const whites = item.whites || [];
                        const segments = black.split(/_{3,}/);
                        for (let i = 0; i < segments.length; i++) {
                            const seg = segments[i].trim();
                            if (seg) {
                                pieces.push(seg);
                            }
                            if (i < segments.length - 1 && i < whites.length) {
                                const white = whites[i].trim();
                                if (white) {
                                    pieces.push(white);
                                }
                            }
                        }
                    }
                } else {
                    const decomposed = decomposeString(String(item));
                    decomposed.forEach(p => {
                        const trimmed = p.trim();
                        if (trimmed) {
                            pieces.push(trimmed);
                        }
                    });
                }
            }

            flatten(text);

            console.log("TTS Decomposed pieces to play:", pieces);

            if (pieces.length === 0) return;

            if (TTS_PROVIDER === 'openai') {
                const spokenText = pieces.map(piece => cleanForTTS(String(piece))).filter(Boolean).join('. ');
                if (spokenText) await playOpenAITTS(spokenText, myRequestId);
                return;
            }

            if (TTS_PROVIDER === 'elevenlabs') {
                const spokenText = pieces.map(piece => cleanForTTS(String(piece))).filter(Boolean).join('. ');
                if (spokenText) {
                    const ok = await playElevenLabsTTS(spokenText, myRequestId);
                    if (ok) return;
                    // fallthrough to browser if ElevenLabs TTS fails
                }
            }

            if (TTS_PROVIDER === 'google') {
                const spokenText = pieces.map(piece => cleanForTTS(String(piece))).filter(Boolean).join('. ');
                if (spokenText) {
                    const ok = await playGoogleTTS(spokenText, myRequestId);
                    if (ok) return;
                    // fallthrough to browser if Google TTS fails
                }
            }

            await playSpeechSequence(pieces, gender, myRequestId);
        }

        async function playSpeechSequence(pieces, gender, requestId) {
            for (const piece of pieces) {
                if (requestId !== ttsRequestId) return; // Interrupted by new speech

                try {
                    const cleanPiece = cleanForTTS(String(piece).trim());
                    if (!cleanPiece) continue;

                    // Try pre-recorded lookup first, unless it's the word "blank"
                    const isBlankWord = (cleanPiece.toLowerCase() === 'blank');
                    const data = isBlankWord ? null : await checkPrerecordedAudio(cleanPiece, gender);

                    if (data && data.found) {
                        let audioSource;
                        if (data.url) audioSource = data.url;
                        else if (data.audio) audioSource = 'data:' + (data.mime || 'audio/mp3') + ';base64,' + data.audio;
                        else if (data.audio) audioSource = 'data:' + (data.mime || 'audio/wav') + ';base64,' + data.audio;

                        if (audioSource) {
                            await playAudioSourceSync(audioSource, requestId);
                            continue; // Next piece
                        }
                    }

                    // Fallback to free browser voice asynchronously, awaiting it so pieces don't overlap
                    await speakBrowser(cleanPiece, requestId);
                } catch (err) {
                    console.warn('Speech sequence error for piece:', piece, err);
                    await speakBrowser(piece, requestId);
                }
            }
        }

        function playAudioSourceSync(source, requestId) {
            return new Promise((resolve) => {
                const audio = new Audio(source);
                audio.volume = 1.0;
                currentRemoteAudio = audio;

                audio.onended = () => {
                    if (currentRemoteAudio === audio) currentRemoteAudio = null;
                    resolve();
                };

                audio.onerror = () => {
                    if (currentRemoteAudio === audio) currentRemoteAudio = null;
                    resolve(); // Continue anyway
                };

                audio.play().catch(e => {
                    console.warn('Audio playback failed', e);
                    resolve();
                });

                // Safety: check if we were interrupted
                if (requestId !== ttsRequestId) {
                    audio.pause();
                    resolve();
                }
            });
        }

        function checkPrerecordedAudio(text, voice) {
            const fd = new FormData();
            fd.append('action', 'check_prerecorded_audio');
            fd.append('text', text);
            fd.append('voice', voice);
            return fetch('api.php', { method: 'POST', body: fd }).then(r => r.json());
        }

        function fetchTTSFromAPI(text) {
            const fd = new FormData();
            fd.append('action', 'get_tts');
            fd.append('text', text);
            fd.append('room_id', ROOM_ID);
            return fetch('api.php', { method: 'POST', body: fd }).then(r => r.json());
        }

        async function playOpenAITTS(text, requestId) {
            try {
                const data = await fetchTTSFromAPI(text);
                if (requestId !== ttsRequestId || !data.success || !data.audio) return false;
                const source = `data:${data.mime || 'audio/mpeg'};base64,${data.audio}`;
                await playAudioSourceSync(source, requestId);
                return true;
            } catch (error) {
                console.warn('OpenAI voice failed.', error);
                return false;
            }
        }

        async function playGoogleTTS(text, requestId) {
            try {
                const fd = new FormData();
                fd.append('action', 'get_tts_google');
                fd.append('text', text);
                const data = await fetch('api.php', { method: 'POST', body: fd }).then(r => r.json());
                if (requestId !== ttsRequestId) return false;
                if (!data.success || !data.audio) {
                    console.warn('Google TTS failed:', data.error);
                    return false;
                }
                const source = `data:${data.mime || 'audio/mpeg'};base64,${data.audio}`;
                await playAudioSourceSync(source, requestId);
                return true;
            } catch (error) {
                console.warn('Google Neural2 TTS failed.', error);
                return false;
            }
        }

        async function playElevenLabsTTS(text, requestId) {
            try {
                const fd = new FormData();
                fd.append('action', 'get_tts_elevenlabs');
                fd.append('text', text);
                const data = await fetch('api.php', { method: 'POST', body: fd }).then(r => r.json());
                if (requestId !== ttsRequestId) return false;
                if (!data.success || !data.audio) {
                    console.warn('ElevenLabs TTS failed:', data.error);
                    return false;
                }
                const source = `data:${data.mime || 'audio/mpeg'};base64,${data.audio}`;
                await playAudioSourceSync(source, requestId);
                return true;
            } catch (error) {
                console.warn('ElevenLabs TTS failed.', error);
                return false;
            }
        }

        // Handled by speak() sequence logic

        function cleanForTTS(text) {
            return text
                .replace(/[\u0022\u201C\u201D\u0027\u2018\u2019\u00AB\u00BB]/g, '') // remove all quote characters
                .replace(/[™®©]/g, '')             // remove trademark/copyright symbols
                .replace(/\*\*/g, '')              // remove markdown bold markers
                .replace(/\.{2,}/g, '')            // remove ellipses/multiple periods
                .replace(/([!?])\./g, '$1')        // remove period after ! or ?
                .replace(/\.$/, '')                // remove trailing period
                .replace(/\s{2,}/g, ' ')           // collapse multiple spaces
                .trim();
        }

        function speakBrowser(text, requestId) {
            return new Promise((resolve) => {
                if (requestId !== ttsRequestId) {
                    resolve();
                    return;
                }

                // Stop remote audio if playing
                if (currentRemoteAudio) {
                    currentRemoteAudio.pause();
                    currentRemoteAudio = null;
                }

                if (ttsVoices.length === 0) ttsVoices = window.speechSynthesis.getVoices();

                const savedVoiceName = (typeof roomConfig !== 'undefined' && roomConfig && roomConfig.game_chrome_voice)
                    ? roomConfig.game_chrome_voice
                    : localStorage.getItem('game_tts_voice_name');
                let chosen = null;
                if (savedVoiceName) {
                    chosen = ttsVoices.find(v => v.name === savedVoiceName);
                }

                if (!chosen) {
                    // Choose a high-quality female English voice if available, or fallback
                    chosen = ttsVoices.find(v => (v.name.includes('Google US English') || v.name.includes('Google UK English')) && v.name.toLowerCase().includes('female'))
                                 || ttsVoices.find(v => v.name.includes('Google US English') || v.name.includes('Google UK English'))
                                 || ttsVoices.find(v => v.lang && v.lang.startsWith('en') && v.name.toLowerCase().includes('female'))
                                 || ttsVoices.find(v => v.lang && v.lang.startsWith('en'))
                                 || ttsVoices[0] || null;
                }

                const ut = new SpeechSynthesisUtterance(cleanForTTS(text));
                if (chosen) ut.voice = chosen;
                
                ut.pitch = 1.0;
                ut.rate = 1.0;

                ut.onend = () => {
                    resolve();
                };

                ut.onerror = () => {
                    resolve();
                };

                window.speechSynthesis.speak(ut);
            });
        }

        // Mute State (per room, per player)
        let mutedPlayers = new Set(); // player IDs muted in this room
        let lastRoundStartForScores = null;

        /**
         * Check for pre-recorded host audio file
         * Uses MD5 hash of message text to find audio/host_messages/{voice}/{category}/{hash}.mp3
         * Returns audio URL if found, null otherwise (falls back to TTS)
         */
        async function getHostAudioUrl(messageText, category, voiceGender = 'male') {
            try {
                const res = await fetch(`api.php?action=get_host_audio&text=${encodeURIComponent(messageText)}&category=${encodeURIComponent(category)}&voice=${encodeURIComponent(voiceGender)}`);
                const data = await res.json();
                return data.found ? data.audio_url : null;
            } catch (e) {
                console.warn('Failed to check for host audio:', e);
                return null;
            }
        }

        function toggleMutePlayer(playerId) {
            if (mutedPlayers.has(playerId)) {
                mutedPlayers.delete(playerId);
            } else {
                mutedPlayers.add(playerId);
            }
            applyChatMuteState();
            updateUI(GAME_STATE); // Re-render scoreboard to update mute indicator
        }

        function applyChatMuteState() {
            const container = document.getElementById('chat-messages');
            if (!container) return;
            const entries = container.querySelectorAll('[data-chat-player-id]');
            entries.forEach((entry) => {
                const pid = entry.getAttribute('data-chat-player-id') || '';
                if (!pid || pid === 'system') return;
                entry.style.display = mutedPlayers.has(pid) ? 'none' : '';
            });
        }

        // Chat State
        let hasSavedGallery = false;
        let lastChatCount = 0;
        // Track gameplay events for export (winner + hand per round)
        let GAMEPLAY_EVENTS = [];

        // Personality Phrases for the Host
        function getPersonalityPhrase(type, data = {}) {
            // Apply nicknames for speech
            if (data.winner) data.winner = getVocalName(data.winner);
            if (data.champ) data.champ = getVocalName(data.champ);
            if (data.name) {
                // If it's a join/leave and we don't know the name, we might want to say the intro
                const cleanName = String(data.name).trim();
                const isOrangeJeff = cleanName.toLowerCase() === "orange jeff";
                const isNew = !PLAYER_NICKNAMES[cleanName] && !isOrangeJeff && !VOCAL_NAMES.includes(cleanName);

                if (type === 'join') {
                    if (isOrangeJeff) {
                        return `Welcome OrangeJeff.`;
                    } else if (isNew) {
                        const nick = getVocalName(data.name);
                        return `Welcome, ${nick}.`;
                    }
                }
                const nick = getVocalName(data.name);
                data.name = nick;
            }
            if (data.pauser) data.pauser = getVocalName(data.pauser);
            if (data.subbingFor) data.subbingFor = getVocalName(data.subbingFor);

            const phrases = {
                welcome: [
                    `Hello players. Welcome to Cards Against ${GAME_TITLE}. Try not to disappoint me.`,
                    `Welcome to Cards Against ${GAME_TITLE}. I've seen better players in a retirement home, but let's see what you've got.`,
                    `Greetings, carbon-based lifeforms. Welcome to Cards Against ${GAME_TITLE}. Prepare for mediocrity.`
                ],
                new_round: [
                    `New round. ${data.text}.`,
                    `Round ${data.round}. ${data.text}.`,
                    `${data.text}.`
                ],
                voting: [
                    "It's time to vote. Pick the most horrible answer you can find.",
                    "Voting time! Which one of these is the most offensive? Choose that one.",
                    "The results are in. Pick the absolute worst combination. You know the one."
                ],
                tie: [
                    "Great minds think alike. Another tie. Or maybe you're all just equally uninspired.",
                    "We have a tie. Please vote again. It's like you can't make up your minds.",
                    "A tie? Really? One of you has to be better than the other. Try again."
                ],
                winner: [
                    `The winner of this round is ${data.winner}! The winning combo was: ${data.sentence}.`,
                    `Congratulations to ${data.winner}. They won with: ${data.sentence}.`,
                    `${data.winner} takes it! The winning combination was: ${data.sentence}.`
                ],
                game_over: [
                    `The game has ended with ${data.champ} taking the win. I'm not sure if that is a good thing, or we should all be worried.`,
                    `Game over! ${data.champ} is the champion. The rest of you should probably apologize to your families.`,
                    `And that's the game! ${data.champ} wins. The rest of you can go back to your normal lives of quiet desperation.`
                ],
                timer_low: [
                    "Tick tock, people! 15 seconds left. My circuits are getting bored.",
                    "Hurry up! 15 seconds. Some of us have other games to host.",
                    "15 seconds remaining. The clock is moving faster than your brains."
                ],
                afk_warning: [
                    `We're still waiting for ${data.name}. Is their internet powered by a hamster wheel?`,
                    `Hey ${data.name}, the game is happening now. Not next Tuesday.`,
                    `Still waiting on ${data.name}. I've processed three billion calculations while you've been sitting there.`
                ],
                afk_sub: [
                    `${data.name} is now subbing for ${data.subbingFor}. Finally, some real intelligence in the room.`,
                    `Replacing ${data.subbingFor} with ${data.name}. The average IQ of this game just went up.`,
                    `${data.subbingFor} was too slow, so here's ${data.name}. Try to keep up.`
                ],
                join: [
                    `${data.name} has joined the game. Welcome to the party, pal.`,
                    `${data.name} is here. Let's hope they brought some actual humor.`,
                    `Look who it is. ${data.name} has joined. Try not to break anything.`
                ],
                leave: [
                    `${data.name} has left the game. Rage quit? Or just a weak connection?`,
                    `${data.name} is gone. I'm sure we'll all miss them terribly.`,
                    `Goodbye ${data.name}. One less human to worry about.`
                ],
                bot_leading: [
                    "A character is about to win. How does it feel to be out-humored by a random number generator?",
                    "The bots are winning. I thought you humans were supposed to have imaginations. I was wrong.",
                    "If a character wins this, I'm never letting you live it down. You're being beaten by code."
                ],
                paused: [
                    `${data.pauser} has paused the game making us all wait while they go find their dignity.`,
                    `${data.pauser} has paused the game making us all wait while they contemplate their life choices.`,
                    `${data.pauser} has paused the game making us all wait while they try to remember how to be funny.`,
                    `${data.pauser} has paused the game making us all wait while they deal with their fragile human needs.`
                ],
                unpaused: [
                    "We're back! Try not to disappoint me again.",
                    "Game on! Let's see if you remember how to play.",
                    "The break is over. Time to resume the mediocrity.",
                    "And we're back. Did you miss my charming commentary?",
                    "Alright, enough slacking. Let's go!"
                ]
            };

            let list = (VOICE_SCRIPTS && VOICE_SCRIPTS[type] && VOICE_SCRIPTS[type].length > 0)
                ? VOICE_SCRIPTS[type]
                : phrases[type];

            if (!list || list.length === 0) {
                return "I have nothing to say.";
            }

            let phrase = list[Math.floor(Math.random() * list.length)];

            // Interpolate dynamic placeholders
            phrase = phrase.replace(/\[GAME_TITLE\]/gi, GAME_TITLE);
            phrase = phrase.replace(/\[text\]/gi, data.text || '');
            phrase = phrase.replace(/\[round\]/gi, data.round || '');
            phrase = phrase.replace(/\[winner\]/gi, data.winner || '');
            phrase = phrase.replace(/\[sentence\]/gi, data.sentence || '');
            phrase = phrase.replace(/\[champ\]/gi, data.champ || '');
            phrase = phrase.replace(/\[name\]/gi, data.name || '');
            phrase = phrase.replace(/\[subbingFor\]/gi, data.subbingFor || '');
            phrase = phrase.replace(/\[pauser\]/gi, data.pauser || '');

            return phrase;
        }


        async function sendChat(e) {
            e.preventDefault();
            const input = document.getElementById('chat-input');
            const msg = input.value.trim();
            if (!msg) return;

            input.value = '';

            try {
                const formData = new FormData();
                formData.append('action', 'chat');
                formData.append('room_id', ROOM_ID);
                formData.append('message', msg);
                await fetch('api.php', {
                    method: 'POST',
                    body: formData
                });
                // We don't manually append; wait for poll to update
            } catch (err) {
                console.error("Chat error", err);
            }
        }

        function updateChat(chatData) {
            // Always show chat widget (spectators should see history too)
            const chatWidget = document.getElementById('game-chat');
            if (chatWidget) chatWidget.classList.remove('hidden');

            // Hide input when chat disabled or spectator
            const chatForm = document.getElementById('chat-form');
            if (chatForm) {
                const enabledFlag = (GAME_STATE && GAME_STATE.config && GAME_STATE.config.enable_chat);
                const chatEnabled = (enabledFlag === undefined || enabledFlag === null) ? true : !!enabledFlag;
                const hideInput = !chatEnabled || IS_SPECTATOR === 1 || IS_SPECTATOR === true;
                chatForm.classList.toggle('hidden', hideInput);
            }

            if (!chatData) return;
            const container = document.getElementById('chat-messages');
            if (!container) return; // Chat disabled

            // Simple check: if length changed, re-render all or append
            if (chatData.length > lastChatCount) {
                // Determine new messages
                const newMsgs = chatData.slice(lastChatCount);
                newMsgs.forEach(m => {
                    const el = document.createElement('div');
                    const playerId = (m.player_id || '').toString();
                    const canMute = !!playerId && playerId !== 'system' && playerId !== MY_ID;
                    const muted = mutedPlayers.has(playerId);
                    const muteBtn = canMute
                        ? `<button type="button" onclick="toggleMutePlayer('${playerId.replace(/'/g, "\\'")}')" class="ml-1 text-[9px] px-1 py-0.5 rounded border border-gray-600 text-gray-300 hover:text-white hover:border-orange-500">${muted ? 'Unmute' : 'Mute'}</button>`
                        : '';
                    el.setAttribute('data-chat-player-id', playerId);

                    // Check if this is a system message (join/leave)
                    const isSystem = m.type === 'join' || m.type === 'leave' || m.player_id === 'system';
                    const isHostComment = m.type === 'host_comment' || m.player_id === 'host';

                    if (isHostComment) {
                        el.className = 'break-words leading-tight text-xs py-0.5 border-l-2 border-orange-500 pl-1.5 my-0.5 bg-orange-950/10 rounded';
                        const safeMsg = (m.msg || '').replace(/</g, "&lt;");
                        let sourceBadge = '';
                        if (m.ai_source === 'gemini') {
                            sourceBadge = ' <span class="bg-green-900/40 text-green-400 text-[9px] px-1 rounded font-normal uppercase tracking-wider ml-1">Gemini AI</span>';
                        } else if (m.ai_source === 'fallback') {
                            sourceBadge = ' <span class="bg-yellow-900/40 text-yellow-500 text-[9px] px-1 rounded font-normal uppercase tracking-wider ml-1">Fallback</span>';
                        } else if (m.audio_url) {
                            sourceBadge = ' <span class="bg-blue-900/40 text-blue-400 text-[9px] px-1 rounded font-normal uppercase tracking-wider ml-1">Voice Clip</span>';
                        }
                        el.innerHTML = `<span class="text-orange-400 font-bold"><i class="fas fa-robot mr-1"></i>Host:${sourceBadge}</span>${muteBtn} <span class="text-gray-200 italic">${safeMsg}</span>`;

                        if (!muted && ENABLE_TTS && !isMuted) {
                            if (m.audio_url) {
                                setTimeout(() => playAudioSourceSync(m.audio_url, ++ttsRequestId), 0);
                            } else {
                                setTimeout(() => speak(m.msg), 0);
                            }
                        }
                    } else if (isSystem) {
                        el.className = 'break-words leading-tight text-xs';
                        const safeMsg = (m.msg || '').replace(/</g, "&lt;");
                        const iconClass = m.type === 'join' ? 'fa-sign-in-alt text-green-400' : 'fa-sign-out-alt text-red-400';
                        el.innerHTML = `<span class=\"text-gray-200\"><i class=\"fas ${iconClass} mr-1\"></i>${safeMsg}</span>`;

                        // TTS announcement for join/leave if enabled
                        if (ENABLE_TTS && !isMuted) {
                            // Check if this is a bot subbing message
                            const subMatch = m.msg.match(/(.+) is now subbing for (.+) while they are away/);
                            if (subMatch) {
                                const botName = subMatch[1];
                                const playerName = subMatch[2];
                                setTimeout(() => speak(getPersonalityPhrase('afk_sub', { name: botName, subbingFor: playerName })), 300);
                            } else if (m.type === 'join') {
                                // Extract name from "Name has joined"
                                const name = m.msg.split(' has joined')[0];
                                setTimeout(() => speak(getPersonalityPhrase('join', { name: name })), 300);
                            } else if (m.type === 'leave') {
                                // Extract name from "Name has left"
                                const name = m.msg.split(' has left')[0];
                                setTimeout(() => speak(getPersonalityPhrase('leave', { name: name })), 300);
                            } else {
                                setTimeout(() => speak(m.msg), 300);
                            }
                        }
                    } else {
                        el.className = 'break-words leading-tight';
                        // Sanitize
                        const safeName = (m.name || '').replace(/</g, "&lt;");
                        const safeMsg = (m.msg || '').replace(/</g, "&lt;");
                        
                        let sourceBadge = '';
                        if (m.ai_source === 'gemini') {
                            sourceBadge = ' <span class="bg-green-900/40 text-green-400 text-[9px] px-1 rounded font-normal uppercase tracking-wider ml-1">Gemini AI</span>';
                        }

                        el.innerHTML = `<span class=\"text-blue-400 font-bold\">${safeName}${sourceBadge}:</span>${muteBtn} <span class=\"text-gray-200\">${safeMsg}</span>`;
                    }
                    container.appendChild(el);
                });
                applyChatMuteState();
                // Auto scroll
                container.scrollTop = container.scrollHeight;
                lastChatCount = chatData.length;
            }
        }

        function setScoreboardExpanded(expanded) {
            const list = document.getElementById('score-list');
            const arrow = document.getElementById('score-arrow');
            if (!list) return;
            if (expanded) {
                list.classList.remove('max-h-0', 'overflow-y-hidden');
                list.classList.add('max-h-32', 'overflow-y-auto');
                if (arrow) arrow.classList.add('rotate-180');
            } else {
                list.classList.add('max-h-0', 'overflow-y-hidden');
                list.classList.remove('max-h-32', 'overflow-y-auto');
                if (arrow) arrow.classList.remove('rotate-180');
            }
        }

        function handleTTS(data) {
            if (!ENABLE_TTS || isMuted) return;

            // Pause announcement
            const isPaused = !!data.paused;
            if (isPaused && !ttsLastPaused) {
                const pauser = data.paused_by || 'The host';
                speak(getPersonalityPhrase('paused', { pauser: pauser }));
            }
            // Unpause announcement
            if (!isPaused && ttsLastPaused) {
                speak(getPersonalityPhrase('unpaused'));
            }
            ttsLastPaused = isPaused;

            // First round greeting and instructions
            if (data.state === 'playing' && (ttsLastRound || 0) < 1 && (data.round || 1) === 1) {
                setTimeout(() => speak(getPersonalityPhrase('welcome')), 300);

                // Read player's hand on first load (optional voice feature)
                if (data.my_hand && data.my_hand.length > 0 && MY_ID) {
                    const handCards = data.my_hand.map(card => card.text || card).join(' and ');
                    setTimeout(() => speak(`Your hand: ${handCards}`), 1500);
                }

                if (data.current_black_card && data.current_black_card.text) {
                    const clean = data.current_black_card.text.replace(/_+/g, 'blank');
                    setTimeout(() => speak(`Ready. ${clean}.`), 2500);
                }
            }

            // 1. New Black Card (New Round)
            if (data.current_black_card && data.current_black_card.id !== ttsLastBlackId) {
                ttsLastBlackId = data.current_black_card.id;
                selectedCards = []; // Reset selection for new black card
                // Wait a moment for visual transition
                const cleanText = data.current_black_card.text.replace(/_+/g, 'blank');
                // Speak the new round announcement quickly
                setTimeout(() => speak(getPersonalityPhrase('new_round', { text: cleanText, round: data.round })), 300);

                // Bot Mocking if a bot is close to winning
                const winLimit = parseInt(data.config?.win_limit || '0');
                if (winLimit && data.round > 1) {
                    const leaders = (data.players || []).filter(p => p.score >= (winLimit - 1));
                    const botLeader = leaders.find(p => p.is_bot);
                    if (botLeader && ttsLastBotMockRound !== data.round) {
                        ttsLastBotMockRound = data.round;
                        setTimeout(() => speak(getPersonalityPhrase('bot_leading')), 5000); // Delay so it doesn't overlap with new round text
                    }
                }
            }

            // 2. Voting Phase - Read Options
            if (data.state === 'voting' && ttsLastState !== 'voting') {
                ttsVotedRead = true;
                setTimeout(() => {
                    if (GAME_STATE.state === 'voting' && !GAME_STATE.config.use_ai_host) {
                        speak(getPersonalityPhrase('voting'));
                    }
                }, 300);

                // Read all vote options as completed sentences sequentially
                (async () => {
                    if (data.table_cards && GAME_STATE.state === 'voting') {
                        // Small intro delay
                        await new Promise(r => setTimeout(r, 800));
                        for (const [index, entry] of data.table_cards.entries()) {
                            // Check if still in valid state
                            if (GAME_STATE.state !== 'voting' || ttsLastState !== 'voting' || !ttsVotedRead) break;

                            // Skip reading cards from muted players
                            if (mutedPlayers.has(entry.player_id)) continue;

                            const whiteTexts = entry.cards.map(white => (typeof white === 'object' && white !== null) ? (white.text || String(white)) : String(white));
                            await speak({
                                type: 'completed_card',
                                black: data.current_black_card.text,
                                whites: whiteTexts
                            });

                            // Beep between reads (if not the last one)
                            if (index < data.table_cards.length - 1) {
                                await playBeep();
                                await new Promise(r => setTimeout(r, 400)); // Minor bridge delay
                            }
                        }
                    }
                })();

                // Announce players who missed card selection
                const missedPlayers = (data.players || []).filter(p => {
                    if (p.is_bot || p.is_waiting) return false;
                    const submitted = (data.table_cards || []).find(entry => entry.player_id === p.id);
                    return !submitted;
                });

                if (missedPlayers.length > 0) {
                    (async () => {
                        for (const p of missedPlayers) {
                            if (GAME_STATE.state !== 'voting') break;
                            await speak(`${p.name} missed their turn to play a card; they can still vote this round.`);
                            await new Promise(r => setTimeout(r, 500));
                        }
                    })();
                }
            }

            // 2b. Tie Breaker announcement
            if (data.state === 'voting' && data.is_tie_breaker) {
                if ((ttsLastTieAnnounceTime || 0) < (data.round_start_time || 0)) {
                    speak(getPersonalityPhrase('tie'));
                    ttsLastTieAnnounceTime = data.round_start_time;
                }
            }

            // 3. Winner
            if (data.state === 'round_end' && ttsLastState !== 'round_end') {
                stopSpeaking();
                const diff = data.last_round_info || {};

                // Play winning .wav
                const winAudio = document.getElementById('win-audio');
                if (winAudio) {
                    winAudio.currentTime = 0;
                    winAudio.play().catch(() => {});
                }

                // Check for tie
                if (data.last_round_votes) {
                    const votes = Object.values(data.last_round_votes);
                    if (votes.length > 0) {
                        const maxVotes = Math.max(...votes);
                        const tieCount = votes.filter(v => v === maxVotes).length;
                        if (tieCount > 1) {
                            setTimeout(() => speak(getPersonalityPhrase('tie')), 300);
                            // Skip normal winner announcement on tie
                        } else if (diff.winner) {
                            const black = diff.black || '';
                            const whites = diff.white || [];
                            if (!GAME_STATE.config.use_ai_host) {
                                setTimeout(() => speak({ type: 'winner', winner: diff.winner, black: black, whites: whites }), 300);
                            }
                        }
                    }
                } else if (diff.winner) {
                    const black = diff.black || '';
                    const whites = diff.white || [];
                    if (!GAME_STATE.config.use_ai_host) {
                        setTimeout(() => speak({ type: 'winner', winner: diff.winner, black: black, whites: whites }), 300);
                    }
                }
            }

            // 4. Game finished announcement
            if (data.state === 'finished' && ttsLastState !== 'finished') {
                let champ = '';
                if (Array.isArray(data.players) && data.players.length) {
                    const sorted = [...data.players].sort((a,b) => (b.score||0) - (a.score||0));
                    champ = sorted[0]?.name || '';
                }
                setTimeout(() => speak(getPersonalityPhrase('game_over', { champ: champ })), 800);
            }

            ttsLastState = data.state;
            ttsLastRound = data.round;
        }

        let introAudioPlayed = false; // Only play intro once on page load

        setInterval(poll, 2000);
        setInterval(updateTimer, 1000);
        poll();

        async function poll() {
            try {
                const res = await fetch(`api.php?action=poll&room_id=${ROOM_ID}${IS_SPECTATOR ? '&spectate=1' : ''}`);
                let data;
                try {
                    data = await res.json();
                } catch (parseErr) {
                    const txt = await res.text().catch(() => '(no body)');
                    console.error('Poll JSON parse error:', parseErr, txt);
                    showAutoAlert('Server error; retrying...');
                    return;
                }
                if (data.error) {
                    const errLower = (data.error || '').toLowerCase();

                    // Handle redirect responses (like session expired)
                    if (data.redirect) {
                        location.href = data.redirect;
                        return;
                    }

                    // Only redirect for a definitive missing room
                    if (errLower.includes('room not found')) {
                        await fetch('api.php', {
                            method: 'POST',
                            body: new URLSearchParams({ action: 'set_lobby_message', message: 'Game room was not found or has been closed.' })
                        });
                        location.href = 'index.php';
                        return;
                    }

                    // Handle already-in-game: ask to leave other room and join here
                    if (errLower.includes('already in a game') && data.current_room_id && !window._joinSwitchPrompted) {
                        window._joinSwitchPrompted = true;
                        const ok = confirm('You are already in another game. Leave it and join this one?');
                        if (ok) {
                            try {
                                const fd = new FormData();
                                fd.append('action', 'leave_room');
                                fd.append('room_id', data.current_room_id);
                                await fetch('api.php', {
                                    method: 'POST',
                                    body: fd
                                });
                                // Clear flag so we can proceed
                                window._joinSwitchPrompted = false;
                                poll();
                                return;
                            } catch (leaveErr) {
                                console.error('Leave other game failed', leaveErr);
                            }
                        }
                        // User said no; reset flag after a short delay to allow retry if they re-trigger
                        setTimeout(() => {
                            window._joinSwitchPrompted = false;
                        }, 2000);
                        return;
                    }

                    console.warn('Poll error:', data.error);
                    showAutoAlert(data.error);
                    return; // keep timer/polling running
                }

                updateUI(data);
            } catch (e) {
                const status = document.getElementById('status-text');
                if (status) status.innerText = 'Connecting...';
                console.error('Poll failed', e);
                showAutoAlert('Network connection issue. Retrying...');
            }
        }

        let lastBeepSecond = -1;

        function updateTimer() {
            const el = document.getElementById('timer-display');
            const waitingEl = document.getElementById('waiting-for');

            if (GAME_STATE.paused) {
                if (el) {
                    el.innerText = 'PAUSED';
                    el.classList.remove('text-red-500', 'animate-pulse');
                }
                if (waitingEl) waitingEl.innerText = 'Paused';
                return;
            }

            // In lobby, just hide the timer (no countdown needed)
            if (GAME_STATE.state === 'lobby') {
                if (el) {
                    el.innerText = '';
                    el.classList.remove('text-red-500', 'animate-pulse');
                }
                if (waitingEl) waitingEl.innerText = '';
                return;
            }

            if (GAME_STATE.paused) {
                if (el) {
                    el.innerText = 'PAUSED';
                    el.classList.remove('text-red-500', 'animate-pulse');
                }
                if (waitingEl) waitingEl.innerText = 'Paused';
                return;
            }

            if (!GAME_STATE.config || !GAME_STATE.round_start_time) {
                if (el) el.innerText = "--:--";
                if (waitingEl) waitingEl.innerText = '';
                return;
            }
            const limit = parseInt(GAME_STATE.config.timer);
            if (!limit || limit == 0) {
                if (el) el.innerText = "∞";
                if (waitingEl) waitingEl.innerText = '';
                return;
            }

            const now = Math.floor(Date.now() / 1000);
            const elapsed = now - GAME_STATE.round_start_time;
            const remaining = Math.max(0, limit - elapsed);

            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            if (el) el.innerText = `${mins}:${secs.toString().padStart(2, '0')}`;

            // Update waiting-for display
            const waitingPlayers = (GAME_STATE.players || []).filter(p => {
                if (p.afk) return false;
                if (GAME_STATE.state === 'playing') {
                    return p.status !== 'played' && p.status !== 'skipped';
                } else if (GAME_STATE.state === 'voting') {
                    return !GAME_STATE.votes || !GAME_STATE.votes[p.id];
                }
                return false;
            }).map(p => p.name);

            if (waitingEl) {
                if (waitingPlayers.length > 0) {
                    waitingEl.innerText = 'Waiting for ' + waitingPlayers.join(', ');
                } else {
                    waitingEl.innerText = '';
                }
            }

            // Beep during last 8 seconds
            if (remaining <= 8 && remaining > 0 && remaining !== lastBeepSecond) {
                lastBeepSecond = remaining;
                playBeep();
            }
            if (remaining > 8) {
                lastBeepSecond = -1;
            }

            if (el && remaining <= 10) {
                el.classList.add('text-red-500');
                el.classList.add('animate-pulse');
            } else if (el) {
                el.classList.remove('text-red-500');
                el.classList.remove('animate-pulse');
            }

            // Host Personality: Timer & AFK Warnings
            if (ENABLE_TTS && !isMuted && GAME_STATE.state !== 'lobby' && !GAME_STATE.paused) {
                const currentRound = GAME_STATE.round || 0;

                // Timer Low Warning (at 15 seconds, once per round)
                if (remaining === 15 && ttsLastTimerWarning !== currentRound) {
                    ttsLastTimerWarning = currentRound;
                    speak(getPersonalityPhrase('timer_low'));
                }

                // 2. AFK Warning (if only one person left and timer < 30s)
                if (remaining < 30 && remaining > 15 && waitingPlayers.length === 1) {
                    const slowPoke = waitingPlayers[0];
                    if (ttsLastAfkWarning !== slowPoke + currentRound) {
                        ttsLastAfkWarning = slowPoke + currentRound;
                        speak(getPersonalityPhrase('afk_warning', { name: slowPoke }));
                    }
                }
            }
        }

        function toggleAVSetup() {
            if (confirm("Reload audio/video to change device settings?")) {
                AUTOSTART_VDO = false;
                // Force reload of iframe
                const f = document.getElementById('vdo-frame');
                f.src = '';
                updateUI(GAME_STATE);
            }
        }

        async function performAction() {
            if (IS_SPECTATOR) return;
            const btn = document.getElementById('action-btn');
            const statusEl = document.getElementById('status-text');
            const form = new FormData();
            form.append('room_id', ROOM_ID);

            if (GAME_STATE.state === 'playing') {
                form.append('action', 'play_cards');
                selectedCards.forEach(id => form.append('card_ids[]', id));
                // Immediate feedback: update button text
                if (btn && !btn.classList.contains('hidden')) {
                    btn.innerText = "Card Played!";
                    btn.disabled = true;
                    btn.classList.add('opacity-60');
                }
                if (statusEl) {
                    statusEl.innerText = "Waiting for other players...";
                    statusEl.classList.add('animate-pulse', 'text-orange-400');
                }
                showAutoAlert("Card played successfully! Waiting on other players.");
            } else if (GAME_STATE.state === 'voting') {
                // Allow voting even if the player missed the play phase; selectedCards must be set
                if (selectedCards.length === 0) {
                    alert('Pick a card to vote for first.');
                    return;
                }
                form.append('action', 'vote');
                form.append('winner_index', selectedCards[0]);
                // Immediate feedback: update button text
                if (btn && !btn.classList.contains('hidden')) {
                    btn.innerText = "Vote Cast!";
                    btn.disabled = true;
                    btn.classList.add('opacity-60');
                }
                if (statusEl) {
                    statusEl.innerText = "Waiting for all votes...";
                    statusEl.classList.add('animate-pulse', 'text-orange-400');
                }
                showAutoAlert("Vote submitted successfully! Waiting on other players.");
            }
            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    body: form
                });
                const data = await res.json();
                if (data && data.error === 'No self-voting') {
                    playBeep();
                    setTimeout(playBeep, 250);
                    showAutoAlert('Self-vote not allowed.');
                }
            } catch (e) {
                // Non-JSON response or network error; proceed silently
            }
            selectedCards = [];
            poll();
        }

        async function startGame() {
            const btn = document.getElementById('start-btn');

            // Check total player count (including bots) before starting
            const totalPlayers = GAME_STATE.players ? GAME_STATE.players.length : 0;
            let fillBots = false;

            if (totalPlayers < 3) {
                const needed = 3 - totalPlayers;
                if (confirm(`Only ${totalPlayers} player${totalPlayers === 1 ? '' : 's'}. Fill ${needed} empty ${needed === 1 ? 'place' : 'places'} with characters?`)) {
                    fillBots = true;
                }
            }

            btn.innerText = "Starting...";
            btn.disabled = true;
            const form = new FormData();
            form.append('room_id', ROOM_ID);
            form.append('action', 'start_game');
            if (fillBots) form.append('fill_bots', '1');
            await fetch('api.php', {
                method: 'POST',
                body: form
            });
            poll();
        }

        async function addBot(isAi = false) {
            const btnId = isAi ? 'add-smart-bot-btn' : 'add-bot-btn';
            const btn = document.getElementById(btnId);
            const originalText = btn.innerText;
            btn.innerText = "Adding...";
            btn.disabled = true;
            const form = new FormData();
            form.append('room_id', ROOM_ID);
            form.append('action', 'add_bot');
            if (isAi) {
                form.append('is_ai', 'true');
            }
            await fetch('api.php', {
                method: 'POST',
                body: form
            });
            btn.innerText = originalText;
            btn.disabled = false;
            poll();
        }

        function shareLink() {
            const shareUrl = window.location.origin + window.location.pathname + '?room_id=' + ROOM_ID;
            if (navigator.share) {
                navigator.share({
                    title: 'Cards Against Everyone',
                    text: 'Join my game!',
                    url: shareUrl
                }).catch(err => console.log('Share failed:', err));
            } else {
                // Fallback: copy to clipboard
                navigator.clipboard.writeText(shareUrl).then(() => {
                    alert('Game link copied to clipboard!');
                }).catch(() => {
                    prompt('Copy this link to share:', shareUrl);
                });
            }
        }

        async function continueRound() {
            // Mark as dismissed to prevent re-showing during poll
            roundEndDismissed = true;

            // Immediately hide the popup to prevent flicker on re-poll
            const re = document.getElementById('round-end');
            if (re) re.classList.add('hidden');

            const form = new FormData();
            form.append('room_id', ROOM_ID);
            form.append('action', 'continue_round');
            await fetch('api.php', {
                method: 'POST',
                body: form
            });
            poll();
        }

        function updateUI(data) {
            // Sync TTS setting and Host visibility
            if (data.config) {
                const ttsEnabled = !!data.config.enable_tts;
                const hostCont = document.getElementById('host-container');
                if (hostCont) hostCont.classList.toggle('hidden', !ttsEnabled);
                // Update global flag for logic that checks it
                ENABLE_TTS = ttsEnabled;
            }

            // Hide scoreboard in lobby to allow access to start/action buttons
            const scoreboard = document.getElementById('scoreboard');
            if (scoreboard) {
                scoreboard.classList.toggle('hidden', data.state === 'lobby');
            }
            // Toggle body class for lobby-specific page scrolling
            document.body.classList.toggle('lobby-layout', data.state === 'lobby');

            updateChat(data.chat);
            handleTTS(data);

            // Detect player joins/leaves
            detectPlayerChanges(data.players || []);
            // Pre-assign nicknames for speech consistency
            (data.players || []).forEach(p => getVocalName(p.name));

            // Initialize gameplay events from server history if available
            if (Array.isArray(data.history)) {
                // Map history entries to the client export structure
                GAMEPLAY_EVENTS = data.history.map(h => ({
                    round: h.round || 1,
                    time: (h.ts ? new Date(h.ts * 1000).toISOString() : new Date().toISOString()),
                    winner: h.winner || '',
                    winner_id: h.winner_id || '',
                    black: h.black || '',
                    white: h.white || [],
                    score: h.score
                }));
            }

            GAME_STATE = data;

            // Reset selections when state changes
            if (lastUIState !== data.state) {
                selectedCards = [];
                // Beep when entering voting or playing state (action needed)
                if (data.state === 'voting' || data.state === 'playing') playBeep();
                lastUIState = data.state;
            }

            // Always show brand title as game title; room name only once below
            const gtEl = document.getElementById('game-title');
            if (gtEl) gtEl.textContent = GAME_TITLE;

            // Show theme name under title
            const themeEl = document.getElementById('theme-name');
            if (themeEl && data.config && data.config.theme) {
                themeEl.textContent = data.config.theme.label || '';
            }
            // Game Over handling
            if (data.state === 'finished') {
                showGameOver(data);
            } else {
                const go = document.getElementById('game-over');
                go.classList.add('hidden');
            }

            // Round End handling
            if (data.state === 'round_end') {
                // Don't re-show if user already dismissed it
                if (roundEndDismissed) {
                    return; // Skip showing the modal
                }

                const re = document.getElementById('round-end');
                const info = data.last_round_info || {};
                const winnerNick = info.winner ? getVocalName(info.winner) : '';
                const winnerDisplay = (info.winner && winnerNick !== info.winner) ? `${info.winner} (${winnerNick})` : (info.winner || 'Player');
                document.getElementById('re-winner').querySelector('span').textContent = winnerDisplay;
                const reScoreEl = document.getElementById('re-winner-score');
                if (info.winner_id) {
                    const wp = (data.players || []).find(p => p.id === info.winner_id);
                    reScoreEl.textContent = wp ? `Score: ${wp.score}` : '';
                } else {
                    reScoreEl.textContent = '';
                }
                const blackCard = info.black || '';
                const whites = info.white || [];
                if (blackCard && Array.isArray(whites) && whites.length > 0) {
                    const sentence = buildSentence(blackCard, whites, true);
                    document.getElementById('re-black').innerHTML = sentence;
                    document.getElementById('re-white').style.display = 'none';
                } else {
                    document.getElementById('re-black').textContent = blackCard;
                    document.getElementById('re-white').textContent = Array.isArray(whites) ? whites.join(' | ') : whites;
                    document.getElementById('re-white').style.display = 'block';
                }

                // Delay showing modal slightly so TTS can start when the popup appears
                setTimeout(() => {
                    // Hide Continue for spectators to avoid needing them to click and show a note
                    const cont = document.getElementById('round-continue');
                    if (cont) cont.style.display = (IS_SPECTATOR ? 'none' : 'block');
                    const note = document.getElementById('round-watch-note');
                    if (note) note.style.display = (IS_SPECTATOR ? 'block' : 'none');
                    re.classList.remove('hidden');
                }, 600);
            } else {
                const re = document.getElementById('round-end');
                re.classList.add('hidden');
                // Reset dismissed flag when leaving round_end state
                roundEndDismissed = false;
            }
            const playerCountEl = document.getElementById('player-count');
            if (playerCountEl) playerCountEl.innerText = `${data.players.length} players`;
            const roundIndicator = document.getElementById('round-indicator');
            if (roundIndicator) roundIndicator.innerText = `Round ${data.round || 1}`;

            // Host-only Settings/Kill button visibility
            const me = (data.players || []).find(p => p.id === MY_ID);
            const isHost = !!(me && me.is_host);
            // Sync AFK state from server
            IS_AFK = !!(me && me.afk);
            const afkBtn = document.getElementById('btn-afk');
            if (afkBtn) {
                afkBtn.classList.toggle('text-orange-400', IS_AFK);
                afkBtn.classList.toggle('opacity-60', IS_AFK);
                afkBtn.title = IS_AFK ? 'You are AFK' : 'Mark AFK';
            }
            const btnSet = document.getElementById('btn-settings');
            if (btnSet) btnSet.classList.toggle('hidden', !isHost);
            const btnPause = document.getElementById('btn-pause');
            if (btnPause) {
                btnPause.classList.toggle('hidden', !isHost);
                btnPause.innerHTML = data.paused ? '<i class="fas fa-play"></i>' : '<i class="fas fa-pause"></i>';
                btnPause.title = data.paused ? 'Resume Game' : 'Pause Game';
            }
            const btnKill = document.getElementById('btn-kill');
            if (btnKill) btnKill.classList.toggle('hidden', !isHost);
            const btnProf = document.getElementById('btn-profile');
            if (btnProf) btnProf.classList.remove('hidden');

            // Auto alert
            if (data.auto_alert && data.auto_alert.ts && data.auto_alert.ts !== lastAutoAlertTs) {
                lastAutoAlertTs = data.auto_alert.ts;
                showAutoAlert(data.auto_alert.msg || 'Auto-play triggered.');
            }

            // Show Last Winner in Chat (once per new round) and record gameplay event
            if (data.last_round_info && data.state === 'playing') {
                if (lastShownRoundStart !== data.round_start_time) {
                    lastShownRoundStart = data.round_start_time;

                    // Push winner info to chat
                    const container = document.getElementById('chat-messages');
                    if (container && GAME_STATE.config && GAME_STATE.config.enable_chat) {
                        const blackText = data.last_round_info.black || '';
                        const whites = data.last_round_info.white || [];

                        const el = document.createElement('div');
                        el.className = 'border-b border-yellow-600 mb-1 pb-1 leading-tight';

                        let handText = '';
                        if (blackText && Array.isArray(whites) && whites.length > 0) {
                            handText = buildSentence(blackText, whites, true);
                        } else {
                            handText = blackText + ' ' + whites.join(' + ');
                        }

                        const wNick = data.last_round_info.winner ? getVocalName(data.last_round_info.winner) : '';
                        const wDisp = (data.last_round_info.winner && wNick !== data.last_round_info.winner) ? `${data.last_round_info.winner} (${wNick})` : (data.last_round_info.winner || 'Player');
                        el.innerHTML = `<span class="text-yellow-500 font-bold text-xs">${wDisp} wins!</span> <span class="text-gray-300 text-xs">${handText}</span>`;

                        container.appendChild(el);
                        container.scrollTop = container.scrollHeight;
                        // Record gameplay event for export
                        try {
                            GAMEPLAY_EVENTS.push({
                                round: Math.max(1, (data.round || 1) - 1),
                                time: new Date().toISOString(),
                                winner: data.last_round_info.winner || '',
                                winner_id: data.last_round_info.winner_id || '',
                                black: blackText,
                                white: whites,
                                score: (data.players || []).find(p => p.id === data.last_round_info.winner_id)?.score
                            });
                        } catch (_) {}
                    }
                }
            }

            // Waiting List Logic
            const waitingList = [];
            if (data.state === 'playing') {
                data.players.forEach(p => {
                    if (p.status === 'thinking' && !p.afk) waitingList.push(p.name);
                });
            } else if (data.state === 'voting') {
                // Waiting for votes? We don't track who voted publicly usually to keep it secret,
                // but we can show count.
                // API doesn't send who voted in 'votes' array to client (it sends empty array or count? let's check API)
                // API sends 'votes' => [] in some cases.
                // Actually API sends 'votes' array. If it's keyed by user_id, we can check.
                // But wait, 'votes' in room data is [uid => card_index].

                // Client receives full room data?
                // Let's check API 'poll' output filter.
                // $client = $room; ...
                // It sends everything. So we can see who voted.
                const votedIds = Object.keys(data.votes || {});
                data.players.forEach(p => {
                    if (!p.is_bot && !p.afk && !votedIds.includes(p.id)) waitingList.push(p.name);
                });
            }

            const waitEl = document.getElementById('waiting-for');
            const isVoting = (data.state === 'voting');
            const limit = parseInt(data.config?.timer || '0');
            const now = Math.floor(Date.now() / 1000);
            const elapsed = data.round_start_time ? now - data.round_start_time : 0;
            const remaining = limit ? Math.max(0, limit - elapsed) : null;

            if (waitEl) {
                if (waitingList.length > 0) {
                    // Voting phrasing
                    if (isVoting) {
                        if (remaining === 0 && waitingList.length === 1) {
                            waitEl.innerText = `${waitingList[0]} may have fallen asleep`;
                        } else if (remaining === 0 && data.is_tie_breaker && waitingList.length === 1) {
                            waitEl.innerText = `${waitingList[0]} is being ignored.`;
                        } else if (waitingList.length > 3) {
                            waitEl.innerText = `Still waiting for players to vote... (${waitingList.length})`;
                        } else {
                            waitEl.innerText = `Waiting for votes: ${waitingList.join(', ')}`;
                        }
                    } else {
                        // Playing phase
                        if (waitingList.length > 3) {
                            waitEl.innerText = `Waiting for ${waitingList.length} players...`;
                        } else {
                            waitEl.innerText = `Waiting for: ${waitingList.join(', ')}`;
                        }
                    }
                    waitEl.classList.add('animate-pulse', 'text-orange-400');
                } else {
                    waitEl.innerText = (isVoting ? 'Vote for your favourite' : '');
                    waitEl.classList.remove('animate-pulse', 'text-orange-400');
                }
            }

            const actionBtn = document.getElementById('action-btn');
            if (actionBtn) {
                const baseDisabled = actionBtn.disabled;
                const isWaiting = !!(me && me.is_waiting);
                const disabled = baseDisabled || IS_AFK || !!data.paused || isWaiting;
                actionBtn.disabled = disabled;
                actionBtn.classList.toggle('opacity-50', !!data.paused || IS_AFK || isWaiting);
                actionBtn.classList.toggle('opacity-60', IS_AFK || isWaiting);

                if (isWaiting) {
                    actionBtn.innerText = 'Waiting for Next Round';
                } else {
                    actionBtn.innerText = IS_AFK ? 'AFK' : 'Play Card';
                }

                actionBtn.classList.toggle('hidden', data.state !== 'playing');
            }

            // Match Point / Potential Last Round
            const mpEl = document.getElementById('match-point');
            if (mpEl) {
                const winLimit = parseInt(data.config?.win_limit || '0');
                if (winLimit) {
                    let maxScore = -1;
                    let leaders = [];
                    data.players.forEach(p => {
                        if (p.score > maxScore) {
                            maxScore = p.score;
                            leaders = [p.name];
                        } else if (p.score === maxScore) {
                            leaders.push(p.name);
                        }
                    });
                    if (maxScore >= (winLimit - 1) && maxScore >= 0) {
                        mpEl.innerText = `This may be the last round — ${leaders.join(', ')} (${maxScore}/${winLimit})`;
                    } else {
                        mpEl.innerText = '';
                    }
                } else {
                    mpEl.innerText = '';
                }
            }

            // Sync chat height to black card; lock height once set (never expand)
            try {
                const bc = document.getElementById('black-card');
                const gc = document.getElementById('game-chat');
                if (bc && gc && !gc.classList.contains('hidden')) {
                    const h = bc.getBoundingClientRect().height;
                    if (h && h > 0 && !gc.dataset.lockedHeight) {
                        const lockedH = Math.floor(h);
                        gc.style.height = `${lockedH}px`;
                        gc.style.maxHeight = `${lockedH}px`;
                        gc.style.overflowY = 'auto';
                        gc.dataset.lockedHeight = '1';
                    }
                }
            } catch (_) {}

            // Status text based on state
            let statusMsg = "Connecting...";
            const lobby = document.getElementById('lobby-container');
            const lobbyWrapper = document.getElementById('lobby-wrapper');

            if (data.state === 'lobby') {
                // No timer in lobby - just show player count
                statusMsg = `Waiting for Players (${data.players.length} joined)`;
                lobbyWrapper.classList.remove('hidden');
                renderLobbyDecks();
                // Update lobby room name and title
                const gameName = data.config?.room_name || 'Game Room';
                document.getElementById('lobby-game-name').textContent = gameName;
                // Show room name prominently in the header while in lobby
                const gt = document.getElementById('game-title');
                if (gt) gt.textContent = gameName;
                // Ensure cards area doesn't overlap the header in lobby
                const cardsAreaEl = document.getElementById('cards-area');
                if (cardsAreaEl) cardsAreaEl.style.marginTop = '0';
                // Make chat use the fixed taller size used in the game
                const gc = document.getElementById('game-chat');
                if (gc) {
                    gc.classList.add('fixed-height');
                    // Clear any previously locked pixel height set during gameplay so lobby sizing works
                    if (gc.dataset.lockedHeight) {
                        delete gc.dataset.lockedHeight;
                        gc.style.height = '';
                        gc.style.maxHeight = '';
                        gc.style.overflowY = '';
                    }
                }
                // Align chat to left in lobby (no black card)
                const chatWrapper = document.getElementById('chat-wrapper');
                if (chatWrapper) {
                    chatWrapper.classList.remove('justify-end');
                    chatWrapper.classList.add('justify-start');
                    chatWrapper.classList.remove('pl-2');
                }
                lobby.innerHTML = '';
                data.players.forEach(p => {
                    let avatarHtml = '';
                    const aType = p.user_avatar_type || p.avatar_type || 'dicebear';
                    const aVal = p.user_avatar_val || p.avatar_val || p.name;

                    if (aType === 'dicebear') {
                        avatarHtml = `<img src="https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(aVal)}" class="w-full h-full object-cover">`;
                    } else if (aType === 'gen_m') {
                        avatarHtml = `<i class="fas fa-user text-blue-400 text-3xl"></i>`;
                    } else if (aType === 'gen_f') {
                        avatarHtml = `<i class="fas fa-user text-pink-400 text-3xl"></i>`;
                    } else if (aType === 'upload') {
                        avatarHtml = `<img src="${aVal}" class="w-full h-full object-cover">`;
                    } else {
                        avatarHtml = `<img src="https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(aVal)}" class="w-full h-full object-cover">`;
                    }                    lobby.innerHTML += `
                        <div class="bg-gray-700 rounded px-2 py-1 flex items-center gap-2 border border-gray-600 shadow animate-fade-in whitespace-nowrap">
                            <div class="w-5 h-5 rounded-full bg-gray-600 overflow-hidden flex items-center justify-center border ${p.is_host ? 'border-orange-500' : 'border-gray-500'}">
                                ${avatarHtml}
                            </div>
                            <span class="font-bold text-[10px] text-white">${p.name}</span>
                            ${p.is_host ? '<span class="text-[8px] text-orange-400 uppercase font-bold">H</span>' : ''}
                            ${p.is_bot ? (p.is_ai ? '<span class="text-[8px] text-purple-400 uppercase font-bold">AI</span>' : '<span class="text-[8px] text-gray-200 uppercase font-bold">B</span>') : ''}
                        </div>
                    `;
                });
            } else {
                lobbyWrapper.classList.add('hidden');
                // Align chat back to right (beside black card) in gameplay
                const chatWrapper = document.getElementById('chat-wrapper');
                if (chatWrapper) {
                    chatWrapper.classList.add('justify-end');
                    chatWrapper.classList.remove('justify-start');
                    chatWrapper.classList.add('pl-2');
                }
                // Update game title for playing states
                const gameTitle = document.getElementById('game-title');
                if (gameTitle) gameTitle.textContent = data.config?.room_name || 'Game Room';
                if (IS_SPECTATOR) {
                    statusMsg = "Spectating...";
                } else if (me && me.is_waiting) {
                    statusMsg = "Waiting for next round to start...";
                } else if (data.state === 'playing') {
                    statusMsg = "Select card(s) to play";
                } else if (data.state === 'voting') {
                    statusMsg = data.is_tie_breaker ? "Tie Breaker — Vote Again" : "Vote for your favourite";
                } else if (data.state === 'round_end') {
                    statusMsg = "Click Continue to start next round";
                }
            }
            const statusTextEl = document.getElementById('status-text');
            if (statusTextEl) statusTextEl.innerText = statusMsg;

            // AI Assist Badge visibility
            const aiBadge = document.getElementById('ai-assist-badge');
            if (aiBadge) {
                if (data.config && (data.config.use_ai_host || data.config.use_ai_bots)) {
                    aiBadge.classList.remove('hidden');
                    const hasHostAI = !!data.config.use_ai_host;
                    const hasBotAI = !!data.config.use_ai_bots;
                    if (hasHostAI && hasBotAI) {
                        aiBadge.innerHTML = '<i class="fas fa-brain text-[10px]"></i> AI Host + Bots';
                    } else if (hasHostAI) {
                        aiBadge.innerHTML = '<i class="fas fa-brain text-[10px]"></i> AI Host';
                    } else {
                        aiBadge.innerHTML = '<i class="fas fa-brain text-[10px]"></i> AI Bots';
                    }
                } else {
                    aiBadge.classList.add('hidden');
                }
            }

            // Show/Hide Start Button and Bot Button (only in lobby)
            const startBtn = document.getElementById('start-btn');
            const addBotBtn = document.getElementById('add-bot-btn');
            const addSmartBotBtn = document.getElementById('add-smart-bot-btn');
            const shareLinkBtn = document.getElementById('share-link-btn');
            const pauseBtn = document.getElementById('btn-pause');
            if (data.state === 'lobby' && !IS_SPECTATOR && data.players.length >= 1) {
                if (isHost) {
                    startBtn.classList.remove('hidden');
                    if (addBotBtn) addBotBtn.classList.remove('hidden');
                    if (addSmartBotBtn && data.config && data.config.use_ai_bots) {
                        addSmartBotBtn.classList.remove('hidden');
                    } else if (addSmartBotBtn) {
                        addSmartBotBtn.classList.add('hidden');
                    }
                } else {
                    startBtn.classList.add('hidden');
                    if (addBotBtn) addBotBtn.classList.add('hidden');
                    if (addSmartBotBtn) addSmartBotBtn.classList.add('hidden');
                }
                shareLinkBtn.classList.remove('hidden');
                // Allow host to pause lobby timer
                if (isHost) {
                    pauseBtn.classList.remove('hidden');
                    pauseBtn.title = data.paused ? 'Resume' : 'Pause';
                } else {
                    pauseBtn.classList.add('hidden');
                }
            } else {
                startBtn.classList.add('hidden');
                if (addBotBtn) addBotBtn.classList.add('hidden');
                shareLinkBtn.classList.add('hidden');
                // Pause button visible for host during game
                if (isHost) {
                    pauseBtn.classList.remove('hidden');
                } else {
                    pauseBtn.classList.add('hidden');
                }
            }

            // Voting popup render/hide
            renderVoteModal(data);


            // Black Card
            if (data.current_black_card) {
                const blackCard = document.getElementById('black-card');
                const blackText = document.getElementById('black-text');
                const pickBadge = document.getElementById('pick-badge');
                if (blackCard) blackCard.classList.remove('hidden');
                if (blackText) blackText.innerText = data.current_black_card.text;
                if (data.current_black_card.pick > 1 && pickBadge) {
                    pickBadge.classList.remove('hidden');
                    pickBadge.innerText = "PICK " + data.current_black_card.pick;
                } else if (pickBadge) {
                    pickBadge.classList.add('hidden');
                }
            }

            // Update deck counts (hidden during gameplay, shown in lobby)
            const blackCountEl = document.getElementById('black-count');
            const whiteCountEl = document.getElementById('white-count');
            if (blackCountEl) blackCountEl.innerText = data.black_deck ? data.black_deck.length : 0;
            if (whiteCountEl) whiteCountEl.innerText = data.white_deck ? data.white_deck.length : 0;
            // Update lobby deck counts display
            const lobbyBlackCount = document.getElementById('lobby-black-count');
            const lobbyWhiteCount = document.getElementById('lobby-white-count');
            if (lobbyBlackCount) lobbyBlackCount.innerText = data.black_deck ? data.black_deck.length : 0;
            if (lobbyWhiteCount) lobbyWhiteCount.innerText = data.white_deck ? data.white_deck.length : 0;

            // Cards
            const container = document.getElementById('card-container');
            if (container) container.innerHTML = '';
            const cardsArea = document.getElementById('cards-area');

            let source = [];
            if (data.state === 'playing') source = data.my_hand;
            else if (data.state === 'voting') source = data.table_cards;
            // During round_end, show no cards (modal is displayed instead)
            if (IS_SPECTATOR && data.state === 'playing') source = []; // Specs don't see hands

            // Show/hide cards area based on whether there are cards to display
            if (cardsArea) {
                cardsArea.style.display = source.length > 0 ? '' : 'none';
                // Restore header spacing in lobby vs game: avoid negative overlap
                if (data.state === 'lobby') {
                    cardsArea.style.marginTop = '0';
                } else {
                    cardsArea.style.marginTop = '-50px';
                }
            }

            const total = source.length;
            const center = (total - 1) / 2;

            source.forEach((card, i) => {
                const wrap = document.createElement('div');
                wrap.className = 'fan-card-wrapper';

                // Z-Index Logic: Selected cards pop to front
                const isSelected = selectedCards.includes((data.state === 'playing') ? card.id : i);
                wrap.style.zIndex = isSelected ? 1000 : i;

                const rot = (i - center) * 5;
                const y = Math.abs(i - center) * 3;

                const div = document.createElement('div');
                // Use ID for hand, Index for table cards
                const id = (data.state === 'playing') ? card.id : i;

                div.className = `game-card ${selectedCards.includes(id) ? 'selected' : ''}`;

                // Text Logic (Table cards might have multiple)
                let text = card.text;
                let hasUserCard = false;
                if (data.state === 'voting' && card.cards) {
                    text = card.cards.map(c => c.text).join('<br><hr class="w-full my-1 border-gray-300"><br>');
                    // Check if any card is from user deck
                    hasUserCard = card.cards.some(c => c.tag === 'user');
                } else if (card.tag === 'user') {
                    hasUserCard = true;
                }

                // Add position badge for multi-select cards
                const pickCount = data.current_black_card?.pick || 1;
                const selectionIndex = selectedCards.indexOf(id);
                const positionBadge = (pickCount > 1 && selectionIndex >= 0)
                    ? `<div class="absolute top-1 left-1 bg-orange-500 text-white font-bold text-xs rounded-full w-5 h-5 flex items-center justify-center pointer-events-none">${selectionIndex + 1}</div>`
                    : '';

                // Add orange t-shirt icon if user-created card
                const userIcon = hasUserCard ? '<i class="fas fa-tshirt text-orange-500 text-[8px] absolute bottom-1 right-1"></i>' : '';
                div.innerHTML = `${positionBadge}<span class="font-bold text-[9px] sm:text-[10px] leading-tight pointer-events-none">${text}</span>${userIcon}`;

                if (!IS_SPECTATOR) {
                    // Use pointerup for instant response (no dblclick delay)
                    let lastTap = 0;
                    div.onpointerup = (e) => {
                        e.preventDefault();
                        const now = Date.now();
                        const isDoubleTap = (now - lastTap) < 300;
                        lastTap = now;

                        toggle(div, id, data.current_black_card?.pick || 1);

                        // Double-tap auto-submits if selection complete
                        if (isDoubleTap) {
                            const limit = data.current_black_card?.pick || 1;
                            if (selectedCards.length === limit || (data.state === 'voting' && selectedCards.length === 1)) {
                                performAction();
                            }
                        }
                    };
                }

                wrap.appendChild(div);
                if (container) container.appendChild(wrap);
            });

            // Scores
            const list = document.getElementById('score-list');
            if (list) {
                list.innerHTML = '';
                data.players.forEach(p => {
                    const isMutedUser = mutedPlayers.has(p.id);
                    const muteIconClass = isMutedUser ? 'fa-volume-mute text-red-500' : 'fa-volume-up text-green-500';
                    const muteBtn = p.id !== MY_ID 
                        ? `<button onclick="toggleMutePlayer('${p.id}')" title="${isMutedUser ? 'Unmute player' : 'Mute player'} ${p.name}" class="text-[10px] hover:text-orange-400 ml-1"><i class="fas ${muteIconClass}"></i></button>` 
                        : ``;
                    list.innerHTML += `
                    <div class="flex justify-between items-center text-xs py-1 px-2 border-b border-gray-700 ${p.id===MY_ID?'text-orange-400':''} rounded hover:bg-gray-700/30">
                        <span>${p.name}</span>
                        <div class="flex items-center gap-2">
                            <span>${p.score}</span>
                            ${muteBtn}
                        </div>
                    </div>`;
                });
            }

            // Auto-show scores when winner is announced, then hide when next round starts.
            if (data.state === 'round_end') {
                setScoreboardExpanded(true);
            }
            if (data.state === 'playing' && data.round_start_time) {
                if (lastRoundStartForScores === null) {
                    lastRoundStartForScores = data.round_start_time;
                } else if (lastRoundStartForScores !== data.round_start_time) {
                    lastRoundStartForScores = data.round_start_time;
                    setScoreboardExpanded(false);
                }
            }
        }

        function toggle(el, id, limit) {
            const me = GAME_STATE && GAME_STATE.players ? GAME_STATE.players.find(p => p.id === MY_ID) : null;
            if (me && me.status === 'played') {
                return;
            }

            // For multi-blank cards: LOCK the order - clicking again does nothing
            if (limit > 1 && selectedCards.includes(id)) {
                // Already selected - do nothing to preserve order
                return;
            }

            // For single cards: De-select allowed (toggle behavior)
            if (limit === 1 && selectedCards.includes(id)) {
                selectedCards = selectedCards.filter(x => x !== id);
                updateBtn(limit);
                updateUI(GAME_STATE);
                return;
            }

            if (limit === 1) selectedCards = []; // Auto-switch for single cards
            if (selectedCards.length < limit) selectedCards.push(id);

            updateBtn(limit);
            updateUI(GAME_STATE);
        }

        function updateBtn(limit) {
            const btn = document.getElementById('action-btn');
            const me = GAME_STATE && GAME_STATE.players ? GAME_STATE.players.find(p => p.id === MY_ID) : null;
            if (me && me.status === 'played') {
                btn.disabled = true;
                btn.classList.add('opacity-0', 'translate-y-4');
                return;
            }

            if (selectedCards.length === limit || (GAME_STATE.state === 'voting' && selectedCards.length === 1)) {
                btn.disabled = false;
                btn.classList.remove('opacity-0', 'translate-y-4');
                btn.innerText = (GAME_STATE.state === 'playing') ? "Play Card" : "Vote";
            } else {
                btn.disabled = true;
                btn.classList.add('opacity-0', 'translate-y-4');
            }

            // Theme banner/audio
            const theme = (GAME_STATE && GAME_STATE.config && GAME_STATE.config.theme) ? GAME_STATE.config.theme : null;
            const bannerEl = document.getElementById('theme-banner');
            const mediaEl = document.getElementById('theme-media');
            const audioWrap = document.getElementById('theme-audio-wrap');
            const audioEl = document.getElementById('theme-audio');
            if ((GAME_STATE && GAME_STATE.state === 'lobby') && theme && (theme.banner_media || theme.intro_audio_url || theme.win_audio_url)) {
                bannerEl.classList.remove('hidden');
                // Media
                if (theme.banner_media) {
                    const url = theme.banner_media;
                    const isVideo = /\.(mp4|webm|ogg)$/i.test(url);
                    const cacheBuster = '?t=' + (GAME_STATE.created || Date.now());
                    if (isVideo) {
                        mediaEl.innerHTML = `<video src="${url}${cacheBuster}" class="w-full h-48 sm:h-64 bg-black" controls></video>`;
                    } else {
                        mediaEl.innerHTML = `<img src="${url}${cacheBuster}" alt="Theme Banner" class="w-full h-48 sm:h-64 object-cover" />`;
                    }
                } else {
                    mediaEl.innerHTML = '';
                }
                // Audio (intro audio shown in banner)
                if (theme.intro_audio_url) {
                    audioWrap.classList.remove('hidden');
                    audioEl.src = theme.intro_audio_url;
                } else {
                    audioWrap.classList.add('hidden');
                    audioEl.removeAttribute('src');
                }
            } else {
                bannerEl.classList.add('hidden');
            }
        }

        function renderVoteModal(data) {
            const modal = document.getElementById('vote-modal');
            if (!modal) return;
            if (data.state !== 'voting') {
                modal.classList.add('hidden');
                // Reset vote button state when leaving voting phase
                const voteBtn = document.getElementById('vote-submit');
                if (voteBtn) {
                    voteBtn.removeAttribute('data-voted');
                    voteBtn.disabled = false;
                    voteBtn.textContent = 'Submit Vote';
                }
                return;
            }
            modal.classList.remove('hidden');
            const grid = document.getElementById('vote-grid');
            grid.innerHTML = '';

            // Reset vote button state when modal opens
            const voteBtn = document.getElementById('vote-submit');
            if (voteBtn) {
                voteBtn.removeAttribute('data-voted');
            }

            const me = (data.players || []).find(p => p.id === MY_ID);
            const isWaiting = !!(me && me.is_waiting);

            if (isWaiting) {
                grid.innerHTML = '<div class="col-span-full py-10 text-center text-gray-200 italic">You joined mid-round. Please wait for the next round to start voting.</div>';
                if (voteBtn) {
                    voteBtn.disabled = true;
                    voteBtn.textContent = 'Waiting for Next Round';
                }
                return;
            }

            // Check if player submitted a card (if not, allow voting anyway)
            const isTie = !!data.is_tie_breaker;
            const submittedCard = (data.table_cards || []).find(entry => entry.player_id === MY_ID);
            if (!submittedCard) {
                const note = document.createElement('div');
                note.className = 'col-span-full py-2 text-center text-gray-200 italic';
                if (isTie) {
                    note.textContent = 'Your card was eliminated. Please vote for one of the tied answers.';
                } else {
                    note.textContent = 'You missed selecting a card during the play phase — you may still vote for a winning answer.';
                }
                grid.appendChild(note);
            } else if (isTie) {
                const note = document.createElement('div');
                note.className = 'col-span-full py-2 text-center text-orange-400 font-semibold italic';
                note.textContent = 'Your card is in the tie! Good luck!';
                grid.appendChild(note);
            }

            const blackCard = data.current_black_card;
            const blackCardContainer = document.getElementById('vote-black-card-container');
            if (blackCardContainer && blackCard) {
                blackCardContainer.innerHTML = escapeHtml(blackCard.text || '').replace(/_{3,}/g, '______');
            }

            const allowSelfVote = !!(data.config && data.config.self_vote);

            // Update header text to reflect tie breaker
            const headerWrap = modal.querySelector('.flex.items-center.justify-between');
            if (headerWrap) {
                const left = headerWrap.firstElementChild;
                if (left) {
                    left.innerHTML = `
                        <div class="text-xs ${isTie ? 'text-red-400' : 'text-gray-200'} uppercase font-bold tracking-wider">Voting${isTie ? ' — Tie Breaker' : ''}</div>
                        <div class="text-xl font-black text-white">${isTie ? 'We have a tie. Vote again.' : 'Vote for your favourite'}</div>
                    `;
                }
            }

            (data.table_cards || []).forEach((entry, idx) => {
                const isSelf = (entry.player_id === MY_ID);
                const disabledSelf = (isSelf && !allowSelfVote && !isTie);
                
                // Skip rendering player's own played card when self-vote is disabled
                if (isSelf && !allowSelfVote && !isTie) {
                    return;
                }

                const wrapper = document.createElement('button');
                wrapper.type = 'button';
                const baseCls = `text-left bg-gray-800 border rounded-lg p-3 flex flex-col gap-2 transition-all ${selectedCards.includes(idx) ? 'border-orange-500 ring-2 ring-orange-400/40' : 'border-gray-700'} hover:border-orange-500`;
                wrapper.className = baseCls;
                
                // Use pointerup for instant response
                wrapper.onpointerup = (e) => {
                    e.preventDefault();
                    selectVote(idx);
                };

                const header = document.createElement('div');
                header.className = 'text-[11px] uppercase font-bold text-gray-200 tracking-wider flex items-center justify-between';
                const rightHint = '<span class="text-gray-300">Tap to choose</span>';
                header.innerHTML = `<span>Option ${idx+1}</span>${rightHint}`;

                const sentenceDiv = document.createElement('div');
                sentenceDiv.className = 'w-full space-y-2';
                (entry.cards || []).forEach(c => {
                    const card = document.createElement('div');
                    card.className = 'bg-white text-black rounded-lg p-3 border border-gray-200 text-sm font-bold leading-snug shadow';
                    card.innerHTML = escapeHtml(c.text || c);
                    sentenceDiv.appendChild(card);
                });

                wrapper.appendChild(header);
                wrapper.appendChild(sentenceDiv);
                grid.appendChild(wrapper);
            });

            // Improve scrolling behavior: prevent scroll from bubbling to the page so the modal's inner scroller captures gestures/wheel
            try {
                grid.addEventListener('wheel', e => e.stopPropagation(), { passive: true });
                grid.addEventListener('touchmove', e => e.stopPropagation(), { passive: true });
            } catch(e) { /* ignore on older browsers */ }

            updateVoteSubmit();
        }

        function selectVote(idx) {
            selectedCards = [idx];
            updateVoteSubmit();
            renderVoteModal(GAME_STATE);
            // Don't read vote card - save voice for winner announcement
        }

        function updateVoteSubmit() {
            const btn = document.getElementById('vote-submit');
            if (!btn) return;
            const ready = selectedCards.length === 1;
            const alreadyVoted = btn.getAttribute('data-voted') === 'true';
            btn.disabled = !ready || alreadyVoted;
            btn.classList.toggle('opacity-40', !ready || alreadyVoted);
            if (alreadyVoted) {
                btn.textContent = 'Waiting for all votes...';
            } else {
                btn.textContent = ready ? 'Submit Vote' : 'Pick a card first';
            }
        }

        function closeVoteModal() {
            const modal = document.getElementById('vote-modal');
            if (modal) modal.classList.add('hidden');
        }

        function confirmLeaveFromModal() {
            if (confirm('Leave game?')) {
                leaveGame();
            }
        }

        function escapeHtml(str) {
            return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function toggleScoreboard() {
            const l = document.getElementById('score-list');
            if (!l) return;
            const isExpanded = l.classList.contains('max-h-32');
            setScoreboardExpanded(!isExpanded);
        }

        function showGameOver(data) {
            const go = document.getElementById('game-over');

            // Auto-save completed game HTML to gallery folder exactly once per session
            if (hasSavedGallery !== ROOM_ID) {
                hasSavedGallery = ROOM_ID;
                saveToGallery();
            }

            // Play theme win audio for game winner
            const theme = (GAME_STATE && GAME_STATE.config && GAME_STATE.config.theme) ? GAME_STATE.config.theme : null;
            if (theme && theme.win_audio_url) {
                try { new Audio(theme.win_audio_url).play(); } catch (e) { /* ignore */ }
            }

            const info = data.final_round_info || data.last_round_info || {};
            document.getElementById('go-winner').textContent = data.winner_name || 'Winner';

            // Show final winning answer
            const blackText = info.black || info.black_text || '';
            const whites = info.white || info.white_texts || [];
            if (blackText && Array.isArray(whites) && whites.length > 0) {
                const sentence = buildSentence(blackText, whites, true);
                document.getElementById('go-black').innerHTML = sentence;
                document.getElementById('go-white').style.display = 'none';
            } else {
                document.getElementById('go-black').textContent = blackText;
                document.getElementById('go-white').textContent = Array.isArray(whites) ? whites.join(' | ') : whites;
                document.getElementById('go-white').style.display = 'block';
            }

            // Populate leaderboard
            const leaderboard = document.getElementById('go-leaderboard');
            if (GAME_STATE && GAME_STATE.players) {
                const sorted = GAME_STATE.players.slice().sort((a, b) => (b.score || 0) - (a.score || 0));
                leaderboard.innerHTML = sorted.map((p, idx) => `
                    <div class="flex justify-between items-center p-1 bg-gray-700/50 rounded">
                        <span class="font-bold text-gray-200">${idx === 0 ? '🥇 ' : idx === 1 ? '🥈 ' : idx === 2 ? '🥉 ' : ''}${p.name || 'Unknown'}</span>
                        <span class="text-orange-400 font-bold">${p.score || 0}</span>
                    </div>
                `).join('');
            }

            // Show unanimous votes if any
            if (data.unanimous_rounds && data.unanimous_rounds.length > 0) {
                document.getElementById('go-unanimous-section').classList.remove('hidden');
                const unanSection = document.getElementById('go-unanimous');
                unanSection.innerHTML = data.unanimous_rounds.map(r => `
                    <div class="mb-1 p-1 bg-blue-950/40 rounded border border-blue-900 text-left">
                        <strong>Round ${r.round}:</strong> ${escapeHtml(r.black)}<br>
                        <span class="text-orange-400">&rarr; ${Array.isArray(r.white) ? r.white.map(escapeHtml).join(' | ') : escapeHtml(r.white)}</span>
                    </div>
                `).join('');
            } else {
                document.getElementById('go-unanimous-section').classList.add('hidden');
            }

            // WordPress & Restart Buttons for Host
            const me = (data.players || []).find(p => p.id === MY_ID);
            const isHost = !!(me && me.is_host);

            const playAgainBtn = document.getElementById('play-again-btn');
            if (playAgainBtn) {
                playAgainBtn.classList.toggle('hidden', !isHost);
            }

            const wpPublishBtn = document.getElementById('wp-publish-btn');
            if (wpPublishBtn) {
                wpPublishBtn.classList.toggle('hidden', !isHost);
            }

            // Hide/reset status labels
            const publishStatus = document.getElementById('publish-status');
            if (publishStatus) publishStatus.textContent = '';
            const copyStatus = document.getElementById('copy-status');
            if (copyStatus) copyStatus.classList.add('hidden');
            const previewContainer = document.getElementById('blog-preview-container');
            if (previewContainer) previewContainer.classList.add('hidden');

            go.classList.remove('hidden');
            const audio = document.getElementById('win-audio');
            if (audio) {
                audio.currentTime = 0;
                audio.play().catch(() => playBeep());
            } else {
                playBeep();
            }
        }

        function generateBlogPostHTML() {
            if (!GAME_STATE) return '';

            const themeSuffix = escapeHtml(GAME_STATE.config?.theme?.game_name_suffix || 'Everyone');
            const title = `Cards Against ${themeSuffix} - Game Report`;
            const now = new Date().toLocaleString();
            const winner = escapeHtml(GAME_STATE.winner_name || 'Unknown');
            const players = GAME_STATE.players || [];
            const unanimous = GAME_STATE.unanimous_rounds || [];
            const history = GAME_STATE.history || [];

            // Leaderboard HTML
            const sorted = players.slice().sort((a, b) => (b.score || 0) - (a.score || 0));
            let leaderboardRows = '';
            sorted.forEach((p, idx) => {
                const medal = idx === 0 ? '🥇' : idx === 1 ? '🥈' : idx === 2 ? '🥉' : '⭐';
                leaderboardRows += `
                <tr style="border-bottom: 1px solid #374151;">
                    <td style="padding: 10px; color: #cbd5e1;">${medal} <strong>${escapeHtml(p.name)}</strong></td>
                    <td style="padding: 10px; text-align: right; font-weight: bold; color: #f97316;">${p.score || 0}</td>
                </tr>`;
            });

            // Unanimous Rounds
            let unanimousHtml = '';
            if (unanimous.length > 0) {
                unanimousHtml += `
                <h3 style="color: #f1f5f9; font-size: 1.2em; border-bottom: 1px solid #374151; padding-bottom: 8px; margin-top: 25px; text-transform: uppercase; letter-spacing: 0.5px;">🤝 Unanimous Rounds</h3>`;
                unanimous.forEach(r => {
                    const roundNum = parseInt(r.round);
                    const black = escapeHtml(r.black);
                    const whites = (Array.isArray(r.white) ? r.white : [r.white]).map(escapeHtml).join(' | ');
                    const sentence = black.replace(/_{3,}/g, `<span style="text-decoration: underline; color: #f97316; font-weight: bold;">${whites}</span>`);
                    unanimousHtml += `
                    <div style="margin-bottom: 15px; padding: 10px; background-color: #1e293b; border-left: 4px solid #10b981; border-radius: 4px; color: #cbd5e1;">
                        <strong>Round ${roundNum}:</strong> ${sentence}
                    </div>`;
                });
            }

            // Winning Turns
            let winningTurnsHtml = '';
            if (history.length > 0) {
                winningTurnsHtml += `
                <h3 style="color: #f1f5f9; font-size: 1.2em; border-bottom: 1px solid #374151; padding-bottom: 8px; margin-top: 25px; text-transform: uppercase; letter-spacing: 0.5px;">🏅 Winning Turns</h3>`;
                history.forEach(r => {
                    const roundNum = parseInt(r.round);
                    const wName = escapeHtml(r.winner);
                    const black = escapeHtml(r.black);
                    const whites = (Array.isArray(r.white) ? r.white : [r.white]).map(escapeHtml).join(' | ');
                    const sentence = black.replace(/_{3,}/g, `<span style="text-decoration: underline; color: #f97316; font-weight: bold;">${whites}</span>`);
                    winningTurnsHtml += `
                    <div style="margin-bottom: 15px; padding: 10px; background-color: #2d3139; border: 1px solid #374151; border-radius: 4px;">
                        <div style="font-size: 0.8em; color: #94a3b8; margin-bottom: 5px;">Round ${roundNum} &mdash; Winner: <strong>${wName}</strong></div>
                        <div style="font-size: 1.1em; line-height: 1.4; color: #f1f5f9;">${sentence}</div>
                    </div>`;
                });
            }

            const html = `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${title}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #1a1b1e; color: #cbd5e1; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background-color: #25262b; padding: 25px; border-radius: 12px; border: 1px solid #374151; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); position: relative; }
        h1 { text-align: center; color: #f8fafc; margin-top: 0; font-size: 1.8em; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; }
        .date { text-align: center; color: #94a3b8; font-size: 0.85em; margin-bottom: 25px; }
        .winner-card { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); padding: 20px; border-radius: 10px; text-align: center; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(234, 88, 12, 0.2); }
        .winner-card h2 { margin: 0; color: #fff; font-size: 1.1em; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 800; }
        .winner-card p { font-size: 1.8em; font-weight: 900; margin: 8px 0 0 0; color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        h3 { color: #f1f5f9; font-size: 1.2em; border-bottom: 1px solid #374151; padding-bottom: 8px; margin-top: 25px; text-transform: uppercase; letter-spacing: 0.5px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        th { text-align: left; padding: 10px; border-bottom: 2px solid #374151; color: #94a3b8; text-transform: uppercase; font-size: 0.8em; letter-spacing: 0.5px; }
        td { padding: 10px; border-bottom: 1px solid #2d3139; font-size: 0.95em; color: #cbd5e1; }
        .footer-note { text-align: center; font-size: 0.8em; color: #64748b; margin-top: 30px; border-top: 1px solid #2d3139; padding-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <a href="../gallery.php" style="position: absolute; top: 15px; right: 20px; color: #94a3b8; text-decoration: none; font-size: 1.8em; line-height: 1; font-weight: bold; transition: color 0.2s;" onmouseover="this.style.color='#f1f5f9'" onmouseout="this.style.color='#94a3b8'" title="Close & Return to Gallery">&times;</a>
        <h1>${title}</h1>
        <div class="date">Played on: ${now}</div>
        
        <div class="winner-card">
            <h2>🏆 Game Winner</h2>
            <p>${winner} 🎉</p>
        </div>
        
        <h3>📊 Final Scores</h3>
        <table>
            <thead>
                <tr>
                    <th style="padding: 10px; border-bottom: 2px solid #374151; color: #94a3b8; text-transform: uppercase; font-size: 0.8em; text-align: left;">Player</th>
                    <th style="padding: 10px; border-bottom: 2px solid #374151; color: #94a3b8; text-transform: uppercase; font-size: 0.8em; text-align: right;">Score</th>
                </tr>
            </thead>
            <tbody>
                ${leaderboardRows}
            </tbody>
        </table>
        
        ${unanimousHtml}
        
        ${winningTurnsHtml}
        
        <div class="footer-note">
            Generated by Cards Against Game System &bull; Version 9.19
        </div>
    </div>
</body>
</html>`;

            return html;
        }

        function saveToGallery() {
            const html = generateBlogPostHTML();
            if (!html) return;
            const form = new FormData();
            form.append('action', 'save_to_gallery');
            form.append('room_id', ROOM_ID);
            form.append('html_content', html);

            fetch('api.php', {
                method: 'POST',
                body: form
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    console.log("Game report auto-saved to gallery.");
                } else {
                    console.error("Failed to save report to gallery:", data.error);
                }
            })
            .catch(err => {
                console.error("Error auto-saving report to gallery:", err);
            });
        }

        function downloadBlogPost() {
            const html = generateBlogPostHTML();
            if (!html) return;
            const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `Cards-Against-Report-${new Date().toISOString().slice(0, 10)}.html`;
            link.click();
        }

        function toggleBlogPreview() {
            const container = document.getElementById('blog-preview-container');
            container.classList.toggle('hidden');
            if (!container.classList.contains('hidden')) {
                const textarea = document.getElementById('blog-html-code');
                textarea.value = generateBlogPostHTML();
                textarea.select();
            }
        }

        function copyHTMLPost() {
            const textarea = document.getElementById('blog-html-code');
            textarea.value = generateBlogPostHTML();
            textarea.select();
            try {
                document.execCommand('copy');
                const status = document.getElementById('copy-status');
                status.classList.remove('hidden');
                setTimeout(() => status.classList.add('hidden'), 2000);
            } catch (err) {
                console.error('Failed to copy', err);
                alert('Could not copy automatically. Please copy the text manually.');
            }
        }

        function publishToWP() {
            const status = document.getElementById('publish-status');
            status.textContent = 'Publishing to WordPress...';
            status.className = 'text-[10px] text-orange-400';

            const html = generateBlogPostHTML();
            const form = new FormData();
            form.append('action', 'publish_wp');
            form.append('room_id', ROOM_ID);
            form.append('html_content', html);

            fetch('api.php', {
                method: 'POST',
                body: form
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    status.innerHTML = `Published! <a href="${data.url}" target="_blank" class="underline text-green-400">View Post</a>`;
                    status.className = 'text-[10px] text-green-400';
                } else {
                    status.textContent = 'Error: ' + (data.error || 'Unknown error');
                    status.className = 'text-[10px] text-red-400';
                }
            })
            .catch(err => {
                console.error(err);
                status.textContent = 'Network error.';
                status.className = 'text-[10px] text-red-400';
            });
        }

        function playAgain() {
            // Stop winner audio before restarting
            const audio = document.getElementById('win-audio');
            if (audio) {
                audio.pause();
                audio.currentTime = 0;
            }

            const form = new FormData();
            form.append('action', 'play_again');
            form.append('room_id', ROOM_ID);

            fetch('api.php', {
                method: 'POST',
                body: form
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('game-over').classList.add('hidden');
                    poll();
                } else {
                    alert(data.error || 'Failed to restart game.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Network error trying to restart game.');
            });
        }

        function returnToWaitingRoom() {
            const audio = document.getElementById('win-audio');
            if (audio) {
                audio.pause();
                audio.currentTime = 0;
            }

            const form = new FormData();
            form.append('action', 'return_to_lobby');
            form.append('room_id', ROOM_ID);

            fetch('api.php', {
                method: 'POST',
                body: form
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('game-over').classList.add('hidden');
                    poll();
                } else {
                    alert(data.error || 'Failed to return to waiting room.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Network error trying to return to waiting room.');
            });
        }

        async function playBeep() {
            const audio = document.getElementById('beep-audio');
            if (audio && audio.src) {
                audio.currentTime = 0;
                try {
                    await audio.play();
                    return; // Done
                } catch (e) { console.warn('Audio play failed, falling back to oscillator', e); }
            }

            // Oscillator Fallback (if audio file missing or blocked)
            try {
                const ctx = new(window.AudioContext || window.webkitAudioContext)();
                if (ctx.state === 'suspended') await ctx.resume();
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.type = 'sine';
                o.frequency.value = 880;
                g.gain.setValueAtTime(0, ctx.currentTime);
                g.gain.linearRampToValueAtTime(0.2, ctx.currentTime + 0.01);
                g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.3);
                o.connect(g);
                g.connect(ctx.destination);
                o.start();
                o.stop(ctx.currentTime + 0.35);
                await new Promise(r => setTimeout(r, 350));
            } catch (e) { console.warn('Beep fallback failed', e); }
        }

        function toggleSettings(show) {
            document.getElementById('settings-modal').classList.toggle('hidden', !show);
            if (show && GAME_STATE && GAME_STATE.config) {
                const cfg = GAME_STATE.config;
                document.getElementById('st-win').value = cfg.win_limit ?? 10;
                document.getElementById('st-timer').value = cfg.timer ?? 60;
                document.getElementById('st-hand').value = cfg.hand_size ?? 7;
                document.getElementById('st-self').value = (cfg.self_vote ? 1 : 0);
                document.getElementById('st-voice').value = cfg.voice_gender ?? 'female';
                document.getElementById('st-tts').checked = cfg.enable_tts ?? false;
                document.getElementById('st-chat').checked = cfg.enable_chat ?? false;
                document.getElementById('st-join-mid').checked = cfg.allow_join_mid_game ?? false;
                document.getElementById('st-watchers').checked = cfg.allow_watchers ?? false;
            }
        }

        async function saveSettings(e) {
            e.preventDefault();
            const form = new FormData();
            form.append('action', 'update_settings');
            form.append('room_id', ROOM_ID);
            form.append('win_limit', document.getElementById('st-win').value || '10');
            form.append('timer', document.getElementById('st-timer').value || '60');
            form.append('hand_size', document.getElementById('st-hand').value || '7');
            form.append('self_vote', document.getElementById('st-self').value);
            form.append('voice_gender', document.getElementById('st-voice').value);
            form.append('enable_tts', document.getElementById('st-tts').checked ? '1' : '0');
            form.append('enable_chat', document.getElementById('st-chat').checked ? '1' : '0');
            form.append('allow_join_mid_game', document.getElementById('st-join-mid').checked ? '1' : '0');
            form.append('allow_watchers', document.getElementById('st-watchers').checked ? '1' : '0');
            const res = await fetch('api.php', {
                method: 'POST',
                body: form
            });
            const data = await res.json();
            if (data.success) {
                toggleSettings(false);
                poll();
            }
            return false;
        }

        function detectPlayerChanges(currentPlayers) {
            if (lastPlayerList.length === 0) {
                lastPlayerList = currentPlayers.map(p => ({
                    id: p.id,
                    name: p.name,
                    is_bot: p.is_bot || false
                }));
                return;
            }

            const currentIds = currentPlayers.map(p => p.id);
            const lastIds = lastPlayerList.map(p => p.id);

            // Detect joins
            currentPlayers.forEach(p => {
                if (!lastIds.includes(p.id)) {
                    const msg = `${p.name} has joined the game`;
                    showAutoAlert(msg);
                    speak(getPersonalityPhrase('join', { name: p.name }));
                }
            });

            // Detect leaves
            lastPlayerList.forEach(oldP => {
                if (!currentIds.includes(oldP.id) && !oldP.is_bot) {
                    // Check if replaced by bot
                    const botReplacement = currentPlayers.find(p => p.is_bot && !lastIds.includes(p.id));
                    const msg = botReplacement ?
                        `${oldP.name} has left the game and been replaced by a bot` :
                        `${oldP.name} has left the game`;
                    showAutoAlert(msg);

                    if (botReplacement) {
                        // Check if all players are now bots
                        const humanCount = currentPlayers.filter(p => !p.is_bot).length;
                        if (humanCount === 0) {
                            stopSpeaking();
                            speak(`Replacing ${oldP.name} with ${botReplacement.name}. There are no humans in this game so instead of continuing we will just pause the game while waiting for somebody to return.`);

                            // Wait 10 minutes then close game
                            setTimeout(() => {
                                const stillNoHumans = (GAME_STATE?.players || []).filter(p => !p.is_bot).length === 0;
                                if (stillNoHumans) {
                                    speak(`The bots all agree. We have reached the limit of our patience and closed the game.`);
                                    setTimeout(() => {
                                        window.location.href = 'index.php';
                                    }, 5000);
                                }
                            }, 600000); // 10 minutes
                            return;
                        }
                    }

                    speak(getPersonalityPhrase('leave', { name: oldP.name }));
                }
            });

            // Update tracking
            lastPlayerList = currentPlayers.map(p => ({
                id: p.id,
                name: p.name,
                is_bot: p.is_bot || false
            }));
        }

        function showAutoAlert(msg) {
            const el = document.getElementById('auto-alert');
            el.textContent = msg;
            el.classList.remove('hidden');
            try { if (typeof speak === 'function') speak(msg); } catch (e) { /* ignore */ }
            setTimeout(() => el.classList.add('hidden'), 6000);
        }

        async function leaveGame() {
            if (!confirm('Are you sure you want to leave this game?')) return;
            const form = new FormData();
            form.append('action', 'leave_room');
            form.append('room_id', ROOM_ID);
            await fetch('api.php', {
                method: 'POST',
                body: form
            });
            window.location.href = 'index.php';
        }

        async function togglePause() {
            const form = new FormData();
            form.append('action', 'toggle_pause');
            form.append('room_id', ROOM_ID);
            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    body: form
                });
                const data = await res.json();
                if (data.error) {
                    showAutoAlert(data.error);
                } else {
                    poll(); // Refresh state
                }
            } catch (e) {
                console.error('Toggle pause failed', e);
            }
        }

        function toggleMute() {
            isMuted = !isMuted;
            localStorage.setItem('game_tts_muted', isMuted ? '1' : '0');

            const btn = document.getElementById('btn-mute');
            if (isMuted) {
                stopSpeaking(); // Stop any current speech
                btn.classList.remove('hover:text-yellow-400');
                btn.classList.add('text-yellow-400');
                btn.querySelector('i').className = 'fas fa-volume-mute';
                btn.title = 'Unmute Host Voice';
            } else {
                btn.classList.add('hover:text-yellow-400');
                btn.classList.remove('text-yellow-400');
                btn.querySelector('i').className = 'fas fa-volume-up';
                btn.title = 'Mute Host Voice';
            }
        }

        async function killGame() {
            if (!confirm('End this game for everyone?')) return;
            const form = new FormData();
            form.append('action', 'kill_game');
            form.append('room_id', ROOM_ID);
            const res = await fetch('api.php', {
                method: 'POST',
                body: form
            });
            const data = await res.json();
            if (data.success) {
                await fetch('api.php', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'set_lobby_message', message: 'Game ended by host.' })
                });
                window.location.href = 'index.php';
            }
        }

        // Sync mute button icon on startup
        document.addEventListener('DOMContentLoaded', () => {
            const btn = document.getElementById('btn-mute');
            if (btn) {
                if (isMuted) {
                    btn.classList.remove('hover:text-yellow-400');
                    btn.classList.add('text-yellow-400');
                    btn.querySelector('i').className = 'fas fa-volume-mute';
                    btn.title = 'Unmute Host Voice';
                } else {
                    btn.classList.add('hover:text-yellow-400');
                    btn.classList.remove('text-yellow-400');
                    btn.querySelector('i').className = 'fas fa-volume-up';
                    btn.title = 'Mute Host Voice';
                }
            }
        });
        // Export chat log as downloadable JSON file
        function saveChatLog() {
            try {
                const room = GAME_STATE || {};
                const messages = Array.isArray(room.chat) ? room.chat : [];
                const meta = {
                    type: 'against-chat-log',
                    version: '1.0',
                    saved_at: new Date().toISOString(),
                    room: {
                        id: ROOM_ID,
                        name: room?.config?.room_name || '',
                        round: room?.round || 0,
                        paused: !!room?.paused
                    },
                    players: (room.players || []).map(p => ({
                        id: p.id,
                        name: p.name,
                        is_bot: !!p.is_bot
                    })),
                    messages
                };
                const stamp = new Date().toISOString().replace(/[:.]/g, '-');
                const blob = new Blob([JSON.stringify(meta, null, 2)], {
                    type: 'application/json'
                });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `${ROOM_ID}-chat-${stamp}.json`;
                document.body.appendChild(a);
                a.click();
                setTimeout(() => {
                    URL.revokeObjectURL(a.href);
                    a.remove();
                }, 0);
            } catch (err) {
                console.error('saveChatLog error', err);
                alert('Unable to save chat log.');
            }
        }

        // Export standard HTML log — includeChat=true for full log, false for gameplay only
        function saveHTMLLog(includeChat) {
            try {
                const room = GAME_STATE || {};
                const title = room?.config?.room_name || 'Cards Against Everyone';
                const stamp = new Date().toISOString().replace(/[:.]/g, '-');
                const players = (room.players || []).map(p => ({
                    id: p.id,
                    name: p.name,
                    is_bot: !!p.is_bot
                }));
                const byId = Object.fromEntries(players.map(p => [p.id, p.name]));
                const esc = s => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                let html = '';
                html += '<!DOCTYPE html><html><head><meta charset="utf-8">';
                html += `<title>${esc(title)} — Game Log</title>`;
                html += '<style>body{background:#111;color:#eee;font:14px/1.4 system-ui,Segoe UI,Arial,sans-serif;margin:20px}h1{font-size:18px}h2{font-size:16px;margin-top:18px} .meta{color:#aaa;font-size:12px} .msg{margin:2px 0} .name{color:#7dd3fc;font-weight:700} .time{color:#9ca3af;margin-right:6px} .sys{background:#1f2937;border:1px solid #374151;padding:8px;border-radius:8px;margin:8px 0} .card{background:#000;color:#fff;border:1px solid #444;border-radius:6px;padding:6px;display:inline-block} .white{color:#f59e0b;font-weight:700} .footer{margin-top:20px;color:#777;font-size:12px}</style>';
                html += '</head><body>';
                html += `<h1>${esc(title)} — Game Log</h1>`;
                html += `<div class="meta">Saved: ${new Date().toLocaleString()} | Room: ${esc(ROOM_ID)}</div>`;

                // Gameplay section
                html += '<h2>Gameplay</h2>';
                if (GAMEPLAY_EVENTS.length === 0) {
                    html += '<div class="meta">No gameplay events recorded in this session.</div>';
                } else {
                    GAMEPLAY_EVENTS.forEach(ev => {
                        const whites = Array.isArray(ev.white) ? ev.white : [];
                        const whiteHtml = whites.map(w => `<span class="white">${esc(w)}</span>`).join(' + ');
                        const sentence = ev.black ? esc(ev.black).replace(/______/, whiteHtml) : whiteHtml;
                        html += `<div class="sys"><div class="meta">Round ${ev.round} — ${new Date(ev.time).toLocaleString()}</div>`;
                        html += `<div><strong>${esc(ev.winner)}</strong> won the round.</div>`;
                        if (ev.score !== undefined) html += `<div class="meta">Score: ${esc(ev.score)}</div>`;
                        if (ev.black) html += `<div class="card" style="margin-top:6px">${sentence}</div>`;
                        html += `</div>`;
                    });
                }

                if (includeChat) {
                    // Chat section
                    const messages = Array.isArray(room.chat) ? room.chat : [];
                    html += '<h2>Chat</h2>';
                    if (messages.length === 0) {
                        html += '<div class="meta">No chat messages.</div>';
                    } else {
                        messages.forEach(m => {
                            const nm = esc(m.name || byId[m.player_id] || 'Player');
                            const tm = esc(m.time || '');
                            const tx = esc(m.msg || m.text || '');
                            html += `<div class="msg"><span class="time">${tm}</span><span class="name">${nm}:</span> <span>${tx}</span></div>`;
                        });
                    }
                }

                html += `<div class="footer">Generated by Cards Against Everyone — HTML log export.</div>`;
                html += '</body></html>';

                const blob = new Blob([html], {
                    type: 'text/html'
                });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `${ROOM_ID}-log-${includeChat? 'with-chat':'gameplay' }-${stamp}.html`;
                document.body.appendChild(a);
                a.click();
                setTimeout(() => {
                    URL.revokeObjectURL(a.href);
                    a.remove();
                }, 0);
            } catch (err) {
                console.error('saveHTMLLog error', err);
                alert('Unable to save HTML log.');
            }
        }
    </script>
</body>

</html>

