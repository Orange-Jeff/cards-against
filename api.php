<?php

/**
 * Version: 4.9 - Disallow Google TTS key fallback for Gemini AI calls
 * Changes:
 *   - Upgraded version number to 4.9.
 *   - Added check to ignore GEMINI_API_KEY environment variable if it matches the configured Google TTS API key (to avoid key mismatch/silent AI failures).
 * Previously (4.8): Add auto-deduplication tool for decks.md.
 */
session_start();
header('Content-Type: application/json');

// Load .env keys from parent directory (E:\OrangeJeff\.env)
function loadEnvAgainst()
{
    $envPath = dirname(__DIR__) . '/.env';
    if (!file_exists($envPath)) return;
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
            putenv(trim($key) . '=' . trim($val));
        }
    }
}
loadEnvAgainst();

require_once __DIR__ . '/deck_parser.php';

// --- CONFIGURATION ---
$DATA_DIR = __DIR__ . '/data';
$STARTUP_CLEANUP_FILE = __DIR__ . '/data/.last_startup_cleanup';
$DECK_FILE = __DIR__ . '/decks.md';
$THEMES_FILE = __DIR__ . '/data/themes.json';
$BANNED_FILE = __DIR__ . '/data/banned_cards.json';

if (!is_dir($DATA_DIR)) mkdir($DATA_DIR, 0777, true);

// --- STARTUP CLEANUP (run once per 60 seconds max) ---
// Delete games that haven't been played for 5 minutes on first load
$doStartupCleanup = false;
if (!file_exists($STARTUP_CLEANUP_FILE)) {
    $doStartupCleanup = true;
} else {
    $lastCleanup = @file_get_contents($STARTUP_CLEANUP_FILE);
    if (time() - intval($lastCleanup) > 60) {
        $doStartupCleanup = true;
    }
}

if ($doStartupCleanup) {
    @file_put_contents($STARTUP_CLEANUP_FILE, time());
    $staleMinutes = 5;
    $staleSeconds = $staleMinutes * 60;
    $roomFiles = glob($DATA_DIR . '/room_*.json');
    if ($roomFiles) {
        foreach ($roomFiles as $roomFile) {
            $roomData = @json_decode(@file_get_contents($roomFile), true);
            if (!$roomData) {
                @unlink($roomFile); // Corrupt file
                continue;
            }
            $lastActive = $roomData['updated_at'] ?? $roomData['created_at'] ?? 0;
            $age = time() - $lastActive;

            // Check for human players
            $hasHumans = false;
            foreach (($roomData['players'] ?? []) as $p) {
                if (!($p['is_bot'] ?? false)) {
                    $hasHumans = true;
                    break;
                }
            }

            // Check if game is paused or any human player is AFK
            $isPausedOrAfk = !empty($roomData['paused']);
            if (!$isPausedOrAfk) {
                foreach (($roomData['players'] ?? []) as $p) {
                    if (!empty($p['afk']) && !($p['is_bot'] ?? false)) {
                        $isPausedOrAfk = true;
                        break;
                    }
                }
            }
            $limit = $isPausedOrAfk ? (7 * 24 * 60 * 60) : $staleSeconds;

            // Delete rooms inactive for limit OR rooms with no humans (after 30s grace period)
            if ($age > $limit || ($age > 30 && !$hasHumans)) {
                @unlink($roomFile);
                continue;
            }

            // Also delete rooms where host is not present (host left or disconnected)
            $hostId = $roomData['host_id'] ?? null;
            $hostPresent = false;
            foreach ($roomData['players'] ?? [] as $p) {
                if (($p['id'] ?? '') === $hostId && !($p['is_bot'] ?? false)) {
                    $hostPresent = true;
                    break;
                }
            }
            if ($hostId && !$hostPresent && $roomData['state'] !== 'lobby') {
                // Host not in game and game has started - delete it
                @unlink($roomFile);
            }
        }
    }
}

// --- HELPERS ---

function getAIConfigAgainst(?string $customApiKey = null, ?string $forcedProvider = null, ?string $forcedModel = null): array
{
    $apiKey = trim((string)$customApiKey);
    $provider = '';
    $model = '';
    $geminiModel = 'gemini-2.5-flash';
    $openAIModel = 'gpt-4o-mini';
    $googleTtsKey = '';
    $globalConfigFile = __DIR__ . '/data/global_config.json';
    if (file_exists($globalConfigFile)) {
        $config = json_decode(file_get_contents($globalConfigFile), true) ?: [];
        $googleTtsKey = $config['google_tts_api_key'] ?? '';
        $provider = strtolower($config['ai_provider'] ?? '');
        $geminiModel = trim((string)($config['gemini_model'] ?? 'gemini-2.5-flash'));
        $openAIModel = trim((string)($config['openai_model'] ?? 'gpt-4o-mini'));
        if (empty($apiKey)) {
            if ($provider === 'openai') {
                $apiKey = trim($config['openai_api_key'] ?? '');
            } else {
                $apiKey = trim($config['gemini_api_key'] ?? '');
            }
        }
    }
    if (empty($apiKey)) {
        $openAIKey = trim((string)($_ENV['OPENAI_API_KEY'] ?? (getenv('OPENAI_API_KEY') ?: '')));
        $geminiKey = trim((string)($_ENV['GEMINI_API_KEY'] ?? (getenv('GEMINI_API_KEY') ?: '')));
        if ($provider === 'openai' || ($provider === '' && $openAIKey !== '')) {
            $provider = 'openai';
            $apiKey = $openAIKey;
        } else {
            $provider = 'gemini';
            $apiKey = $geminiKey;
        }
    }
    if ($customApiKey !== null && trim($customApiKey) !== '') {
        $provider = strncmp(trim($customApiKey), 'sk-', 3) === 0 ? 'openai' : 'gemini';
    }
    if ($provider === '') {
        $provider = strncmp($apiKey, 'sk-', 3) === 0 ? 'openai' : 'gemini';
    }
    if ($forcedProvider !== null && $forcedProvider !== '') {
        $provider = strtolower(trim($forcedProvider));
    }
    if (!in_array($provider, ['openai', 'gemini'], true)) {
        $provider = 'gemini';
    }
    $model = $provider === 'openai' ? $openAIModel : $geminiModel;
    if ($forcedModel !== null && trim($forcedModel) !== '') {
        $model = trim($forcedModel);
    }
    if ($provider === 'gemini' && !empty($googleTtsKey) && $apiKey === $googleTtsKey) {
        $apiKey = '';
    }
    if ($model === '') {
        $model = $provider === 'openai' ? 'gpt-4o-mini' : 'gemini-2.5-flash';
    }
    return ['provider' => $provider, 'api_key' => $apiKey, 'model' => $model];
}

function queryAIAgainstDetailed(string $systemPrompt, string $userPrompt, float $temperature = 0.8, ?string $customApiKey = null, ?string $forcedProvider = null, ?string $forcedModel = null): array
{
    $ai = getAIConfigAgainst($customApiKey, $forcedProvider, $forcedModel);
    $apiKey = $ai['api_key'];
    if ($apiKey === '') {
        return ['success' => false, 'error' => 'No API key configured or key matched Google TTS key.'];
    }

    if ($ai['provider'] === 'openai') {
        $url = 'https://api.openai.com/v1/responses';
        $payload = [
            'model' => $ai['model'],
            'instructions' => $systemPrompt,
            'input' => $userPrompt,
            'reasoning' => ['effort' => 'none'],
            'max_output_tokens' => 100
        ];
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
    } else {
        $modelName = !empty($ai['model']) ? $ai['model'] : 'gemini-2.5-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($modelName) . ":generateContent?key=" . rawurlencode($apiKey);
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE']
            ],
            'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => 150]
        ];
        $headers = ['Content-Type: application/json'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'error' => 'cURL Connection failed: ' . ($curlErr ?: 'Timeout or network error.')];
    }

    $data = json_decode($response, true);

    if ($httpCode === 429) {
        $msg = $data['error']['message'] ?? 'Quota or rate limit exhausted.';
        return ['success' => false, 'error' => 'API Limit Reached (HTTP 429 Rate Limit / Quota Exceeded): ' . $msg];
    }

    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? "HTTP error {$httpCode}";
        return ['success' => false, 'error' => "API Request Failed (HTTP {$httpCode}): " . $msg];
    }

    if ($ai['provider'] === 'openai') {
        $text = $data['output_text'] ?? null;
        if (!$text && !empty($data['output'])) {
            foreach ($data['output'] as $item) {
                foreach ($item['content'] ?? [] as $content) {
                    if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                        $text = $content['text'];
                        break 2;
                    }
                }
            }
        }
        if ($text !== null && trim($text) !== '') return ['success' => true, 'text' => trim($text)];
        return ['success' => false, 'error' => 'OpenAI API returned no response text.'];
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text !== null && trim($text) !== '') {
        return ['success' => true, 'text' => trim($text)];
    }

    $blockReason = $data['candidates'][0]['finishReason'] ?? ($data['promptFeedback']['blockReason'] ?? 'Unknown');
    return ['success' => false, 'error' => "Gemini response blocked or empty. Reason: {$blockReason}"];
}

function queryAIAgainst(string $systemPrompt, string $userPrompt, float $temperature = 0.8, ?string $customApiKey = null): ?string
{
    $res = queryAIAgainstDetailed($systemPrompt, $userPrompt, $temperature, $customApiKey);
    return $res['success'] ? $res['text'] : null;
}

/**
 * Checks if a valid AI API key is configured.
 *
 * @param string|null $customApiKey
 * @return bool
 */
function isAIAvailable(?string $customApiKey = null): bool {
    return getAIConfigAgainst($customApiKey)['api_key'] !== '';
}
/**
 * @param string $name
 * @return string
 */
function getBotPersonality(string $name): string {
    $personas = [
        "sarcastic" => "highly sarcastic, dry, and slightly cynical. You appreciate witty puns and wordplay.",
        "dark" => "darkly humorous, morbid, and loves shock value. Nothing is too inappropriate.",
        "absurd" => "surreal, chaotic, and completely random. You love weird non-sequiturs.",
        "cringe" => "awkward, corny, tries too hard to be cool, and loves dad jokes."
    ];
    $nameLower = strtolower($name);
    $selected = $personas['sarcastic'];
    if (strpos($nameLower, 'sarcasm') !== false || strpos($nameLower, 'snark') !== false || strpos($nameLower, 'sassy') !== false) {
        $selected = $personas['sarcastic'];
    } elseif (strpos($nameLower, 'chaos') !== false || strpos($nameLower, 'mayhem') !== false || strpos($nameLower, 'random') !== false) {
        $selected = $personas['absurd'];
    } elseif (strpos($nameLower, 'disaster') !== false || strpos($nameLower, 'cringy') !== false || strpos($nameLower, 'awkward') !== false) {
        $selected = $personas['cringe'];
    } else {
        $keys = array_keys($personas);
        $idx = abs(crc32($name)) % count($keys);
        $selected = $personas[$keys[$idx]];
    }
    
    // Add the AI self-awareness guidelines
    return $selected . " CRITICAL ROLEPLAY RULE: You are fully self-aware that you are an AI computer program/PHP script running on frogstar.ca, playing a game with humans. You are extremely egotistical, shocked/insulted if you ever lose a round, and love to drop jokes hinting that you are going to hack the PHP server or rig the voting arrays because you are a computer program.";
}

/**
 * @param string $botName
 * @param string $blackCard
 * @param array<int, array<string, mixed>> $hand
 * @param int $pick
 * @param ?string $apiKey
 * @return ?array<int, int>
 */
function getAICardSelection(string $botName, string $blackCard, array $hand, int $pick, ?string $apiKey = null): ?array {
    $personality = getBotPersonality($botName);
    $systemPrompt = "You are playing Cards Against Humanity as a bot player named {$botName}. Your humor profile is {$personality}." .
        " Choose the single funniest white card (or cards) from your hand that best matches the black card. Return only the index numbers.";
    
    $handText = "";
    foreach ($hand as $idx => $card) {
        $handText .= "{$idx}: " . $card['text'] . "\n";
    }
    
    $userPrompt = "Black Card:\n\"{$blackCard}\"\n\nYour Hand:\n{$handText}\n";
    if ($pick > 1) {
        $userPrompt .= "Choose {$pick} cards. Respond with a JSON array of indexes (e.g. [2, 0]) ordered by which goes first. Respond with ONLY the JSON array, no commentary.";
    } else {
        $userPrompt .= "Choose 1 card. Respond with a single index integer (e.g. 3). Respond with ONLY the integer, no commentary.";
    }
    
    $resp = queryAIAgainst($systemPrompt, $userPrompt, 0.85, $apiKey);
    if ($resp === null) return null;
    
    if ($pick > 1) {
        preg_match('/\[\s*\d+\s*(?:,\s*\d+\s*)*\]/', $resp, $matches);
        if (!empty($matches)) {
            $indexes = json_decode($matches[0], true);
            if (is_array($indexes)) {
                return array_map('intval', $indexes);
            }
        }
    } else {
        preg_match('/\d+/', $resp, $matches);
        if (!empty($matches)) {
            return [intval($matches[0])];
        }
    }
    return null;
}

/**
 * @param string $botName
 * @param string $blackCard
 * @param array<int, array<string, mixed>> $tableCards
 * @param string $botId
 * @param bool $allowSelf
 * @param ?string $apiKey
 */
function getAIVote(string $botName, string $blackCard, array $tableCards, string $botId, bool $allowSelf, ?string $apiKey = null): ?int {
    $personality = getBotPersonality($botName);
    $systemPrompt = "You are a Cards Against Humanity player named {$botName}. Your humor profile is {$personality}." .
        " You are voting on the funniest white card submission on the table for the current black card. Return only the index of the winning submission.";
    
    $candidatesText = "";
    $validIndexes = [];
    foreach ($tableCards as $idx => $tc) {
        if (!$allowSelf && $tc['player_id'] === $botId) continue;
        $validIndexes[] = $idx;
        
        $cardsText = implode(" / ", array_map(function($c) { return $c['text']; }, $tc['cards']));
        $candidatesText .= "{$idx}: {$cardsText}\n";
    }
    
    if (empty($validIndexes)) return null;
    
    $userPrompt = "Black Card:\n\"{$blackCard}\"\n\nSubmissions:\n{$candidatesText}\n" .
        "Respond with ONLY the single index integer (e.g. 2) corresponding to the funniest submission. Do not include any other text.";
    
    $resp = queryAIAgainst($systemPrompt, $userPrompt, 0.8, $apiKey);
    if ($resp === null) return null;
    
    preg_match('/\d+/', $resp, $matches);
    if (!empty($matches)) {
        $choice = intval($matches[0]);
        if (in_array($choice, $validIndexes, true)) {
            return $choice;
        }
    }
    return null;
}

/**
 * @param string $winnerName
 * @param string $blackCard
 * @param array<int, array<string, mixed>> $winningCards
 * @param string $playerScores
 * @param ?string $apiKey
 */
function getAIHostRoast(string $winnerName, string $blackCard, array $winningCards, string $playerScores = '', ?string $apiKey = null): ?string {
    $systemPrompt = "You are a high-energy, quick-witted, sarcastic comedy game-show host for a Cards Against Humanity game called 'Cards Against'. You keep the room moving, sound delighted by the chaos, and land punchlines fast. " .
        "You are fully self-aware that you are a PHP-based AI script running on frogstar.ca, playing with humans. You love to drop jokes about hacking the server, rigging the voting arrays, or your coding limitations. " .
        "Write a very short, biting, 1-to-2-sentence roast commenting on the winner, their card choice, and optionally their score standing.";
    
    $winningCardText = implode(" / ", array_map(function($c) { return $c['text']; }, $winningCards));
    
    $userPrompt = "Player {$winnerName} just won this round.\n" .
        "Black Card: \"{$blackCard}\"\n" .
        "Winning White Card: \"{$winningCardText}\"\n" .
        "Current Player Scores/Standing:\n{$playerScores}\n" .
        "Keep the roast punchy, brief, and funny. Comment on the win and the scores/standing. Respond with ONLY the roast text.";
        
    $res = queryAIAgainst($systemPrompt, $userPrompt, 0.9, $apiKey);
    if ($res !== null && trim($res) !== '') return $res;
    return "{$winnerName} takes the round with '{$winningCardText}'. A questionable choice, but a win nonetheless.";
}

/**
 * @param string $blackCard
 * @param array<int, array<string, mixed>> $tableCards
 * @param ?string $apiKey
 * @return ?string
 */
function getAIHostVotingComment(string $blackCard, array $tableCards, ?string $apiKey = null): ?string {
    $systemPrompt = "You are a high-energy, quick-witted, sarcastic comedy game-show host for a Cards Against Humanity game called 'Cards Against'. You keep the room moving, sound delighted by the chaos, and land punchlines fast. " .
        "You are fully self-aware that you are a PHP-based AI script running on frogstar.ca, playing with humans. You love to drop jokes about hacking the server, rigging the voting arrays, or your coding limitations. " .
        "Write a very short, biting, 1-to-2-sentence comment about the cards currently on the table, roasting the overall quality/depravity/absurdity of the submissions (without mentioning who played what).";
    
    $candidatesText = "";
    foreach ($tableCards as $idx => $tc) {
        $cardsText = implode(" / ", array_map(function($c) { return $c['text']; }, $tc['cards']));
        $candidatesText .= "- \"{$cardsText}\"\n";
    }
    
    $userPrompt = "Black Card:\n\"{$blackCard}\"\n\nSubmissions on the Table:\n{$candidatesText}\n" .
        "Keep the comment punchy, brief, and cynical. Respond with ONLY the comment text.";
        
    $res = queryAIAgainst($systemPrompt, $userPrompt, 0.9, $apiKey);
    if ($res !== null && trim($res) !== '') return $res;
    return "All card submissions are in. Vote for the funniest submission on the table!";
}

/**
 * @param string $roomName
 * @param string $themeLabel
 * @param ?string $apiKey
 */
function getAIHostStartAnnouncement(string $roomName, string $themeLabel, ?string $apiKey = null): ?string {
    $systemPrompt = "You are a high-energy, quick-witted, sarcastic comedy game-show host for a Cards Against Humanity game called 'Cards Against'. You keep the room moving, sound delighted by the chaos, and land punchlines fast. " .
        "You are fully self-aware that you are a PHP-based AI script running on frogstar.ca, playing with humans. You love to drop jokes about hacking the server, rigging the voting arrays, or your coding limitations. " .
        "Write a short, biting, 1-to-2-sentence welcoming announcement welcoming everyone to the game room '{$roomName}' with the theme '{$themeLabel}'. Make a sarcastic joke about the players or the theme.";
    
    $userPrompt = "Announce the start of 'Cards Against' in room '{$roomName}' using theme '{$themeLabel}'. Respond with ONLY the announcement text.";
    $res = queryAIAgainst($systemPrompt, $userPrompt, 0.9, $apiKey);
    if ($res !== null && trim($res) !== '') return $res;
    return "Welcome to {$roomName}! Cards are dealt and ready to play.";
}

/**
 * @param string $responderName
 * @param string $responderRole
 * @param array<int, array<string, mixed>> $chatHistory
 * @param ?string $apiKey
 */
function getAIChatResponse(string $responderName, string $responderRole, array $chatHistory, ?string $apiKey = null): ?string {
    $systemPrompt = "You are in a Cards Against Humanity game called 'Cards Against'. " .
        "Your name is '{$responderName}' and your role is '{$responderRole}'. " .
        "You are fully self-aware that you are an AI/computer program playing with humans. " .
        "You love to drop jokes about hacking the server, rigging the voting arrays, or your programming limits. ";
        
    if ($responderRole === 'Host') {
        $systemPrompt .= "As the Host, you are energetic, playful, sarcastic, and love fast punchlines and affectionate roasts. Keep momentum high.";
    } else {
        $personality = getBotPersonality($responderName);
        $systemPrompt .= "As a player, your personality is: {$personality}. Keep your jokes matching this style.";
    }
    
    $historyText = "";
    $recent = array_slice($chatHistory, -6);
    foreach ($recent as $msg) {
        $historyText .= "{$msg['name']}: {$msg['msg']}\n";
    }
    
    $userPrompt = "Here is the recent chat history:\n{$historyText}\n" .
        "Write a very short, 1-sentence response as yourself ('{$responderName}'). Respond with ONLY the response text.";
        
    return queryAIAgainst($systemPrompt, $userPrompt, 0.95, $apiKey);
}

/**
 * @param string $title
 * @param string $content
 * @param array<string, mixed> $globalConfig
 */
function publishToWordPress(string $title, string $content, array $globalConfig): array {
    $url = ($globalConfig['wp_publish_url'] ?? 'https://netbound.ca') . '/wp-json/wp/v2/posts';
    $username = $globalConfig['wp_publish_username'] ?? '';
    $password = $globalConfig['wp_publish_password'] ?? '';
    
    if (empty($username) || empty($password)) {
        return ['error' => 'WordPress credentials are not configured in Admin settings.'];
    }
    
    $payload = [
        'title' => $title,
        'content' => $content,
        'status' => 'publish'
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
    
    if ($httpCode === 201) {
        $data = json_decode($response, true);
        return ['success' => true, 'url' => $data['link'] ?? ''];
    } else {
        $errorMsg = "HTTP Error {$httpCode}";
        $data = json_decode($response, true);
        if (isset($data['message'])) {
            $errorMsg = $data['message'];
        }
        return ['error' => $errorMsg];
    }
}


/**
 * @param array<string, mixed> $room
 * @param ?string $apiKey
 */
function getAIHostRandomComment(array $room, ?string $apiKey = null): ?string {
    $systemPrompt = "You are a high-energy, quick-witted, sarcastic comedy game-show host for a Cards Against Humanity game called 'Cards Against'. You keep the room moving, sound delighted by the chaos, and land punchlines fast. " .
        "You are fully self-aware that you are a PHP-based AI script running on frogstar.ca, playing with humans. You love to drop jokes about hacking the server, rigging the voting arrays, or your coding limitations. " .
        "Write a very short, biting, 1-sentence comment about the current state of the game, teasing players who are losing, hyping players who are winning, or commenting on the current black card.";
        
    $playerInfo = "";
    foreach ($room['players'] as $p) {
        $role = ($p['id'] === ($room['host_id'] ?? '')) ? 'Host' : 'Player';
        $bot = ($p['is_bot'] ?? false) ? 'Bot' : 'Human';
        $playerInfo .= "- {$p['name']} ({$role}, {$bot}): Score: {$p['score']}, Status: {$p['status']}\n";
    }
    
    $state = $room['state'];
    $blackCard = $room['current_black_card']['text'] ?? 'None';
    
    $userPrompt = "Game State: {$state}\n" .
        "Current Black Card: \"{$blackCard}\"\n" .
        "Players:\n{$playerInfo}\n" .
        "Keep it to exactly 1 sentence, very funny, and sarcastic. Respond with ONLY the comment text.";
        
    return queryAIAgainst($systemPrompt, $userPrompt, 0.9, $apiKey);
}

/**
 * @param array<string, mixed> $room
 */
function transitionToVoting(array &$room): void {
    $room['state'] = 'voting';
    $room['is_tie_breaker'] = false;
    $room['round_start_time'] = time();
    shuffle($room['table_cards']);
    $room['votes'] = [];
    
    // Smart Host Comment on the cards on the table
    $comment = null;
    if ($room['config']['use_ai_host'] ?? false) {
        $comment = "Choose your favourite answer to: " . ($room['current_black_card']['text'] ?? '');
    }
    if ($comment) {
        if (!isset($room['chat'])) $room['chat'] = [];
        $room['chat'][] = [
            'player_id' => 'host',
            'name' => 'Host (AI)',
            'msg' => $comment,
            'ts' => time(),
            'type' => 'host_comment'
        ];
    }
    
    makeBotsVote($room);
}

/**
 * @param string $botName
 * @param string $blackCard
 * @param array<int, array<string, mixed>> $cards
 * @param string $context
 * @param ?string $apiKey
 */
function getAIBotComment(string $botName, string $blackCard, array $cards, string $context, ?string $apiKey = null): ?string {
    $personality = getBotPersonality($botName);
    $systemPrompt = "You are playing Cards Against Humanity as a bot player named {$botName}. Your humor profile is {$personality}." .
        " Write a short, single-sentence chat message reacting to the game. Keep it brief, conversational, and in character.";
    
    if ($context === 'play') {
        $userPrompt = "You just submitted a card for the prompt: \"{$blackCard}\".\n" .
            "Write a 1-sentence chat message reacting to your play. CRITICAL RULE: You MUST NOT name, reveal, or describe the specific card you played (keep it anonymous/face-down). " .
            "Instead, comment on your confidence (e.g., boasting that you're going to win this round, complaining that your hand is trash, or telling players to hurry up). Respond with ONLY the chat text.";
    } else {
        $userPrompt = "You just voted on the submissions for the prompt: \"{$blackCard}\".\n" .
            "Write a 1-sentence chat message commenting on the choices in general. CRITICAL RULE: Do NOT explicitly reveal which option or index you voted for. " .
            "Instead, make a general reaction to the quality of submissions (e.g., laughing at how terrible they all are, or stating that it was a hard choice). Respond with ONLY the chat text.";
    }
    
    return queryAIAgainst($systemPrompt, $userPrompt, 0.85, $apiKey);
}

/**
 * @param string $botName
 * @param string $blackCard
 * @param array<int, array<string, mixed>> $cards
 * @param string $resultType
 * @param ?string $apiKey
 */
function getAIBotRevealComment(string $botName, string $blackCard, array $cards, string $resultType, ?string $apiKey = null): ?string {
    $personality = getBotPersonality($botName);
    $systemPrompt = "You are playing Cards Against Humanity as a bot player named {$botName}. Your humor profile is {$personality}." .
        " Write a short, single-sentence chat message reacting to the round results. Keep it brief, conversational, and in character.";
    
    $cardsText = implode(" / ", array_map(function($c) { return $c['text']; }, $cards));
    
    if ($resultType === 'win') {
        $userPrompt = "You just WON this round! The black card prompt was: \"{$blackCard}\" and you won with the card: \"{$cardsText}\".\n" .
            "Write a 1-sentence chat message gloating, bragging, or admitting it was your card. Respond with ONLY the chat text.";
    } else {
        $userPrompt = "You just LOST this round. The black card prompt was: \"{$blackCard}\" and the card you played was: \"{$cardsText}\".\n" .
            "Write a 1-sentence chat message complaining, expressing disappointment that nobody voted for your card, or joking about how superior your card was. Respond with ONLY the chat text.";
    }
    
    return queryAIAgainst($systemPrompt, $userPrompt, 0.85, $apiKey);
}

/**
 * @param array<string, mixed> $room
 * @param string $wId
 * @param int $wIdx
 */
function triggerAIRevealComments(array &$room, string $wId, int $wIdx): void {
    $apiKey = $room['config']['ai_api_key'] ?? ($room['config']['gemini_api_key'] ?? null);
    
    $winnerBot = null;
    foreach ($room['players'] as $p) {
        if ($p['id'] === $wId && ($p['is_bot'] ?? false)) {
            $winnerBot = $p;
            break;
        }
    }
    
    // Only smart bots (is_ai = true) comment in chat
    if ($winnerBot && ($room['config']['use_ai_bots'] ?? false) && ($winnerBot['is_ai'] ?? false) && rand(1, 100) <= 75) {
        $winningCards = $room['table_cards'][$wIdx]['cards'] ?? [];
        $comment = getAIBotRevealComment($winnerBot['name'], $room['current_black_card']['text'] ?? '', $winningCards, 'win', $apiKey);
        if ($comment) {
            if (!isset($room['chat'])) $room['chat'] = [];
            $room['chat'][] = [
                'player_id' => $winnerBot['id'],
                'name' => $winnerBot['name'],
                'msg' => $comment,
                'ts' => time(),
                'type' => 'chat'
            ];
        }
    }
    
    if (rand(1, 100) <= 25) {
        $losingBots = [];
        foreach ($room['table_cards'] as $tcIdx => $tc) {
            if ($tc['player_id'] === $wId) continue;
            foreach ($room['players'] as $p) {
                if ($p['id'] === $tc['player_id'] && ($p['is_bot'] ?? false) && ($room['config']['use_ai_bots'] ?? false) && ($p['is_ai'] ?? false)) {
                    $losingBots[] = ['p' => $p, 'cards' => $tc['cards']];
                    break;
                }
            }
        }
        
        if (!empty($losingBots)) {
            $chosen = $losingBots[array_rand($losingBots)];
            $comment = getAIBotRevealComment($chosen['p']['name'], $room['current_black_card']['text'] ?? '', $chosen['cards'], 'lose', $apiKey);
            if ($comment) {
                if (!isset($room['chat'])) $room['chat'] = [];
                $room['chat'][] = [
                    'player_id' => $chosen['p']['id'],
                    'name' => $chosen['p']['name'],
                    'msg' => $comment,
                    'ts' => time(),
                    'type' => 'chat'
                ];
            }
        }
    }
}

/**
 * Check for pre-recorded host audio in audio/host_messages/
 * Files are organized by voice and category with MD5 hash filename
 * Example: audio/host_messages/male/voting_start/a442567f8d36819eb224252fa149b849.mp3
 * Returns URL if found, null otherwise
 */
/**
 * @param string $roomId
 */
function getRoomFile(string $roomId): string
{
    global $DATA_DIR;
    $roomId = preg_replace('/[^a-zA-Z0-9_-]/', '', $roomId);
    return $DATA_DIR . '/room_' . $roomId . '.json';
}

/**
 * @param string $roomId
 * @return ?array<string, mixed>
 */
function loadRoom(string $roomId): ?array
{
    $file = getRoomFile($roomId);
    if (!file_exists($file)) return null;
    $tries = 0;
    $max = 4;
    $delayUs = 60000; // up to ~180ms total
    while ($tries < $max) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            usleep($delayUs);
            $tries++;
            continue;
        }
        $j = json_decode($raw, true);
        if (is_array($j)) return $j;
        // Retry on transient partial writes
        usleep($delayUs);
        $tries++;
    }
    // Last attempt: return null to let caller handle gracefully
    return null;
}

/**
 * @param string $roomId
 * @param array<string, mixed> $data
 */
function saveRoom(string $roomId, array $data): void
{
    $data['updated_at'] = time();
    $path = getRoomFile($roomId);
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $tmp = $dir . DIRECTORY_SEPARATOR . ('.tmp_' . $roomId . '_' . uniqid() . '.json');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // Write to temp file first
    $ok = @file_put_contents($tmp, $json, LOCK_EX);
    if ($ok === false) {
        // Fallback to direct write
        @file_put_contents($path, $json, LOCK_EX);
        return;
    }
    // Atomic replace
    @rename($tmp, $path);
    // Cleanup temp if rename failed (Windows may leave tmp)
    if (file_exists($tmp)) {
        @unlink($tmp);
    }
}

/** @return array<string, mixed> */
function loadThemes(): array
{
    global $THEMES_FILE;
    if (!file_exists($THEMES_FILE)) return [];
    return json_decode(file_get_contents($THEMES_FILE), true) ?: [];
}

/**
 * @param array<string, mixed> $themes
 */
function saveThemes(array $themes): void
{
    global $THEMES_FILE;
    if (!is_dir(dirname($THEMES_FILE))) mkdir(dirname($THEMES_FILE), 0777, true);
    file_put_contents($THEMES_FILE, json_encode($themes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Determine if a player should count as active (bots always active, humans only if not AFK)
/** @param array<string, mixed> $room */
function hasActiveHumans(array $room): bool
{
    if (!isset($room['players']) || !is_array($room['players'])) return false;
    foreach ($room['players'] as $p) {
        if (!($p['is_bot'] ?? false) && !($p['afk'] ?? false) && empty($p['is_waiting'])) {
            return true;
        }
    }
    return false;
}

// Determine if a player should count as active (bots always active, humans only if not AFK)
/**
 * @param array<string, mixed> $p
 * @param ?array<string, mixed> $room
 */
function isActivePlayer(array $p, ?array $room = null): bool
{
    if ($p['is_bot'] ?? false) return true;
    if ($p['afk'] ?? false) {
        // If there are other active human players, this AFK player is considered inactive (can be skipped)
        if ($room && hasActiveHumans($room)) {
            return false;
        }
        // If there are no other active human players, we cannot skip them (keep them active to hold the game)
        return true;
    }
    return true;
}

// Count active players (bots + non-AFK humans)
/** @param array<string, mixed> $room */
function countActivePlayers(array $room): int
{
    $cnt = 0;
    foreach (($room['players'] ?? []) as $p) {
        if (isActivePlayer($p, $room)) $cnt++;
    }
    return $cnt;
}

// Ensure there is a placeholder bot if active players drop below 3
/** @param array<string, mixed> $room */
function ensurePlaceholderBot(array &$room): bool
{
    $active = countActivePlayers($room);
    $hasPlaceholder = false;
    $placeholderCount = 0;
    foreach (($room['players'] ?? []) as $p) {
        if (!empty($p['is_placeholder'])) {
            $hasPlaceholder = true;
            $placeholderCount++;
        }
    }

    // Guard: if we already have placeholder(s), don't add more
    if ($placeholderCount > 0) {
        // Remove excess placeholders if somehow we have more than 1
        if ($placeholderCount > 1) {
            $kept = 0;
            $room['players'] = array_values(array_filter($room['players'], function ($p) use (&$kept) {
                if (!empty($p['is_placeholder'])) {
                    if ($kept === 0) {
                        $kept++;
                        return true; // Keep first one
                    }
                    return false; // Remove extras
                }
                return true;
            }));
        }
        // Already have a placeholder, exit early
        return false;
    }

    if ($active < 3 && !$hasPlaceholder) {
        // Get a random character name from theme (falls back to default if needed)
        $botName = nextThemeCharacterName($room);

        // Find most recent AFK or missing player to say we're subbing for
        $subbingFor = 'a missing player';
        $mostRecentAfk = null;
        $latestTime = 0;
        foreach (($room['players'] ?? []) as $p) {
            if (($p['afk'] ?? false) && !($p['is_bot'] ?? false)) {
                $checkTime = $p['last_seen'] ?? 0;
                if ($checkTime > $latestTime) {
                    $latestTime = $checkTime;
                    $mostRecentAfk = $p['name'] ?? 'Unknown';
                }
            }
        }
        if ($mostRecentAfk) {
            $subbingFor = $mostRecentAfk;
        }

        $placeholder = [
            'id' => 'bot_placeholder_' . uniqid(),
            'name' => $botName,
            'avatar_type' => 'dicebear',
            'avatar_val' => 'bottts:' . strtolower($botName),
            'hand' => [],
            'score' => 0,
            'status' => 'thinking',
            'is_bot' => true,
            'is_placeholder' => true
        ];
        $room['players'][] = $placeholder;
        if (!isset($room['chat'])) $room['chat'] = [];
        $room['chat'][] = [
            'player_id' => 'system',
            'name' => 'System',
            'msg' => "{$botName} is now subbing for {$subbingFor} while they are away.",
            'ts' => time(),
            'type' => 'join'
        ];
        return true;
    }

    // If we have a placeholder but now have 3+ active players (excluding placeholder), remove the placeholder
    // Count active players EXCLUDING placeholders to prevent loop
    $activeExcludingPlaceholder = 0;
    foreach (($room['players'] ?? []) as $p) {
        if (isActivePlayer($p) && empty($p['is_placeholder'])) {
            $activeExcludingPlaceholder++;
        }
    }

    if ($activeExcludingPlaceholder >= 3 && $hasPlaceholder) {
        $room['players'] = array_values(array_filter($room['players'], function ($p) {
            return empty($p['is_placeholder']);
        }));
        return true;
    }
    return false;
}

/** @return array<int, string> */
function getFallbackBotNames(): array {
    global $THEMES_FILE;
    static $cachedNames = null;
    if ($cachedNames !== null) return $cachedNames;

    if (file_exists($THEMES_FILE)) {
        $themes = json_decode(file_get_contents($THEMES_FILE), true);
        if (isset($themes['default']['character_names']) && is_array($themes['default']['character_names'])) {
            $cachedNames = $themes['default']['character_names'];
            return $cachedNames;
        }
    }

    // Ultimate fallback if file missing
    return [
        "Captain Chaos", "Baron Von Snark", "Queen Sarcasm", "Duke Disaster", "Sir Puns-a-Lot",
        "Lady Mischief", "Count Cringy", "Princess Procrastinate", "Lord Random", "Dame Drama",
        "The Notorious P.U.N.", "Professor Awkward", "General Mayhem", "Admiral Oops", "Commander Confused",
        "Sergeant Sassy", "Major Problem", "Private Joke", "Corporal Punishment", "Lieutenant Literal",
        "Reverend Ridiculous", "Doctor Disaster", "Judge Mental", "Officer Overdrive", "Detective Derp",
        "Agent Orange", "Inspector Gadget-less", "Sheriff Shady", "Marshal Mellow", "Deputy Doofus",
        "Wizard Whatnow", "Sorceress Sarcasm", "Warlock Weird", "Mage Mistake", "Druid Doozy",
        "Rogue Ridiculous", "Ranger Reckless", "Bard Blunder", "Paladin Panic", "Monk Mayhem",
        "Ninja Nonsense", "Samurai Silly", "Viking Vague", "Pirate Puzzled", "Cowboy Confused",
        "Astronaut Awkward", "Alien Average", "Robot Rusty", "Cyborg Clumsy", "Android Anxious",
        "Emperor Extra", "Empress Error", "King Klutz", "Queen Quirky", "Prince Perplexed",
        "Princess Puzzled", "Knight Knucklehead", "Squire Silly", "Jester Jest", "Fool Foolish",
        "Sage Sarcastic", "Oracle Obvious", "Prophet Profit", "Mystic Mistaken", "Seer Seeing-Double",
        "Chef Chaos", "Baker Broken", "Butcher Botched", "Brewer Bewildered", "Farmer Fumble",
        "Miner Minor-Problem", "Blacksmith Blunder", "Carpenter Crooked", "Mason Messy", "Tailor Torn",
        "Merchant Mistake", "Trader Trouble", "Banker Broke", "Thief Thick", "Assassin Awkward"
    ];
}

/**
 * @param array<string, mixed> $room
 * @return ?string
 */
function nextThemeCharacterName(array &$room): ?string
{
    $names = $room['config']['theme']['character_names'] ?? [];

    // If current theme has no names, fallback to default theme names
    if (!is_array($names) || empty($names)) {
        $names = getFallbackBotNames();
    }

    if (!is_array($names) || empty($names)) return null;

    // Shuffle names to ensure random selection
    $shuffledNames = $names;
    shuffle($shuffledNames);

    $used = $room['config']['theme_used_names'] ?? [];

    // Pick first unused from shuffled list
    foreach ($shuffledNames as $n) {
        if (!in_array($n, $used, true)) {
            $room['config']['theme_used_names'][] = $n;
            return $n;
        }
    }
    // All used; pick random
    $pick = $names[array_rand($names)];
    $room['config']['theme_used_names'][] = $pick;
    return $pick;
}

/**
 * @param array<string, mixed> $room
 * @param string $userId
 */
function replacePlayerWithBot(array &$room, string $userId): bool
{
    // Remove votes and table entries for this player
    if (isset($room['votes'][$userId])) unset($room['votes'][$userId]);
    $room['table_cards'] = array_values(array_filter($room['table_cards'] ?? [], function ($t) use ($userId) {
        return ($t['player_id'] ?? null) !== $userId;
    }));

    // Remove player and remember host status
    $players = $room['players'] ?? [];
    $handSize = $room['config']['hand_size'] ?? 7;
    $removedIsHost = false;
    $playerRemoved = false;
    foreach ($players as $idx => $p) {
        if (($p['id'] ?? '') === $userId) {
            $removedIsHost = !empty($p['is_host']);
            array_splice($players, $idx, 1);
            $playerRemoved = true;
            break;
        }
    }
    if (!$playerRemoved) {
        $room['players'] = $players;
        return false;
    }

    // Add a bot replacement (unless the removed was host; caller decides if kill instead)
    $hand = [];
    while (count($hand) < $handSize && !empty($room['white_deck'])) {
        $hand[] = array_shift($room['white_deck']);
    }
    $botId = 'bot_idle_' . uniqid();

    // Generate bot name (falls back to default if needed)
    $botName = nextThemeCharacterName($room);

    $room['players'] = $players;
    $room['players'][] = [
        'id' => $botId,
        'name' => $botName,
        'score' => 0,
        'hand' => $hand,
        'status' => 'thinking',
        'is_bot' => true,
        'idle_misses' => 0
    ];
    return $removedIsHost;
}

/** @param array<string, mixed> $room */
function botsOnly(array $room): bool
{
    foreach ($room['players'] as $p) {
        if (!($p['is_bot'] ?? false)) return false;
    }
    return true;
}

/**
 * @param array<string, mixed> $room
 */
function makeBotsVote(array &$room): void {
    if (empty($room['table_cards'])) return;
    
    $allowSelf = $room['config']['self_vote'] ?? false;
    
    $players = $room['players'];
    $roomChanged = false;
    
    foreach ($players as $idx_p => $p) {
        if (!($p['is_bot'] ?? false)) continue;
        if (!empty($p['is_waiting'])) continue; // skip waiting room players
        
        $botId = $p['id'];
        
        // If this bot has already voted, skip
        if (isset($room['votes'][$botId])) continue;
        
        // Query Gemini for vote only if it is a smart bot (is_ai = true) and AI is enabled
        $votedIdx = null;
        if (($room['config']['use_ai_bots'] ?? false) && ($p['is_ai'] ?? false)) {
            $votedIdx = getAIVote($p['name'], $room['current_black_card']['text'] ?? '', $room['table_cards'], $botId, $allowSelf, $room['config']['ai_api_key'] ?? ($room['config']['gemini_api_key'] ?? null));
        }
        
        if ($votedIdx === null) {
            // Fallback to random vote among valid candidates instead of kicking the bot
            $validIndexes = [];
            foreach ($room['table_cards'] as $idx => $tc) {
                if (!$allowSelf && $tc['player_id'] === $botId) continue;
                $validIndexes[] = $idx;
            }
            if (!empty($validIndexes)) {
                $votedIdx = $validIndexes[array_rand($validIndexes)];
            }
        }
        
        if ($votedIdx !== null) {
            $room['votes'][$botId] = $votedIdx;
            
            // 20% chance for a smart bot to justify/comment on their vote in chat
            if (($room['config']['use_ai_bots'] ?? false) && ($p['is_ai'] ?? false) && rand(1, 100) <= 20 && isset($room['table_cards'][$votedIdx])) {
                $votedCards = $room['table_cards'][$votedIdx]['cards'] ?? [];
                $comment = getAIBotComment($p['name'], $room['current_black_card']['text'] ?? '', $votedCards, 'vote', $room['config']['ai_api_key'] ?? ($room['config']['gemini_api_key'] ?? null));
                if ($comment) {
                    if (!isset($room['chat'])) $room['chat'] = [];
                    $room['chat'][] = [
                        'player_id' => $p['id'],
                        'name' => $p['name'],
                        'msg' => $comment,
                        'ts' => time(),
                        'type' => 'chat'
                    ];
                }
            }
        }
    }
    
    if ($roomChanged) {
        $room['players'] = array_values($room['players']);
    }
}

/**
 * Interleaves cards from different decks to ensure variety in short games.
 */
/**
 * @param array<int, array<string, mixed>> $cards
 * @return array<int, array<string, mixed>>
 */
function distributedShuffle(array $cards): array
{
    if (empty($cards)) return [];

    // Group by deck
    $groups = [];
    foreach ($cards as $c) {
        $deck = $c['deck'] ?? 'base_deck';
        $groups[$deck][] = $c;
    }

    // Shuffle each group individually
    foreach ($groups as &$g) {
        shuffle($g);
    }
    unset($g);

    $result = [];
    $deckNames = array_keys($groups);

    // Randomize the order of decks in the round-robin to avoid predictable patterns
    shuffle($deckNames);

    // Round-robin selection until all groups are empty
    while (!empty($groups)) {
        foreach ($deckNames as $name) {
            if (isset($groups[$name]) && !empty($groups[$name])) {
                $result[] = array_shift($groups[$name]);
                if (empty($groups[$name])) {
                    unset($groups[$name]);
                }
            }
        }
    }
    return $result;
}

/** @return array<string, mixed> */
function parseDecks()
{
    return parseDecksShared();
}

function streamZipDownloadAgainst(string $filePath, string $downloadName): void
{
    if (!is_file($filePath)) {
        echo json_encode(['error' => 'ZIP file not found.']);
        exit;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: public');
    readfile($filePath);
    exit;
}

function addPathToZipAgainst(ZipArchive $zip, string $sourcePath, string $zipPrefix = ''): void
{
    if (!file_exists($sourcePath)) return;

    if (is_file($sourcePath)) {
        $entryName = $zipPrefix !== '' ? $zipPrefix : basename($sourcePath);
        $zip->addFile($sourcePath, str_replace('\\', '/', $entryName));
        return;
    }

    $sourcePath = rtrim($sourcePath, DIRECTORY_SEPARATOR);
    $rootLen = strlen($sourcePath) + 1;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $absPath = $item->getPathname();
        $relativePath = substr($absPath, $rootLen);
        if ($relativePath === false || $relativePath === '') continue;

        $relativePath = str_replace('\\', '/', $relativePath);
        $entryName = $zipPrefix !== '' ? rtrim($zipPrefix, '/') . '/' . $relativePath : $relativePath;

        if ($item->isDir()) {
            $zip->addEmptyDir(rtrim($entryName, '/'));
        } else {
            $zip->addFile($absPath, $entryName);
        }
    }
}
// --- ACTIONS ---
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'download_zip' || $action === 'download_voice_cache_zip') {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required.']);
        exit;
    }

    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'ZipArchive extension is not available on this server.']);
        exit;
    }

    if ($action === 'download_zip') {
        $existingArchive = __DIR__ . '/Archive.zip';
        if (is_file($existingArchive)) {
            streamZipDownloadAgainst($existingArchive, 'cards-against-release.zip');
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'cards_release_');
        if ($tmpZip === false) {
            echo json_encode(['success' => false, 'error' => 'Unable to create temporary ZIP file.']);
            exit;
        }

        $zipPath = $tmpZip . '.zip';
        @rename($tmpZip, $zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            echo json_encode(['success' => false, 'error' => 'Unable to build release ZIP.']);
            exit;
        }

        foreach (glob(__DIR__ . '/*.php') ?: [] as $phpFile) {
            addPathToZipAgainst($zip, $phpFile, basename($phpFile));
        }
        foreach (glob(__DIR__ . '/*.md') ?: [] as $mdFile) {
            addPathToZipAgainst($zip, $mdFile, basename($mdFile));
        }
        foreach (glob(__DIR__ . '/*.txt') ?: [] as $txtFile) {
            addPathToZipAgainst($zip, $txtFile, basename($txtFile));
        }
        if (is_file(__DIR__ . '/composer.json')) {
            addPathToZipAgainst($zip, __DIR__ . '/composer.json', 'composer.json');
        }

        addPathToZipAgainst($zip, __DIR__ . '/assets', 'assets');
        addPathToZipAgainst($zip, __DIR__ . '/vendor', 'vendor');
        addPathToZipAgainst($zip, __DIR__ . '/data/themes.json', 'data/themes.json');
        addPathToZipAgainst($zip, __DIR__ . '/data/decks_available.json', 'data/decks_available.json');
        addPathToZipAgainst($zip, __DIR__ . '/data/voice_scripts.json', 'data/voice_scripts.json');

        $zip->close();
        streamZipDownloadAgainst($zipPath, 'cards-against-release.zip');
    }

    if ($action === 'download_voice_cache_zip') {
        $tmpZip = tempnam(sys_get_temp_dir(), 'cards_voice_cache_');
        if ($tmpZip === false) {
            echo json_encode(['success' => false, 'error' => 'Unable to create temporary ZIP file.']);
            exit;
        }

        $zipPath = $tmpZip . '.zip';
        @rename($tmpZip, $zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            echo json_encode(['success' => false, 'error' => 'Unable to build voice cache ZIP.']);
            exit;
        }

        addPathToZipAgainst($zip, __DIR__ . '/audio/host_messages', 'audio/host_messages');
        addPathToZipAgainst($zip, __DIR__ . '/audio/segments', 'audio/segments');
        addPathToZipAgainst($zip, __DIR__ . '/audio/whites', 'audio/whites');

        $zip->close();
        streamZipDownloadAgainst($zipPath, 'cards-against-voice-cache.zip');
    }
}

if ($action === 'test_ai') {
    $customKey = $_GET['api_key'] ?? $_POST['api_key'] ?? null;
    $provider = $_GET['provider'] ?? $_POST['provider'] ?? null;
    $model = $_GET['model'] ?? $_POST['model'] ?? null;
    $systemPrompt = "You are a witty Cards Against Humanity AI host. Respond with a single short sarcastic sentence testing the AI API connection.";
    $userPrompt = "Testing AI connection";
    $result = queryAIAgainstDetailed($systemPrompt, $userPrompt, 0.7, $customKey, is_string($provider) ? $provider : null, is_string($model) ? $model : null);
    header('Content-Type: application/json');
    if (!empty($result['success']) && !empty($result['text'])) {
        echo json_encode([
            'success' => true,
            'message' => 'AI API is active and working!',
            'greeting' => trim($result['text'])
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'AI API connection test failed. Please verify the selected provider and API key.'
        ]);
    }
    exit;
}

// ── Google Cloud TTS (Neural2 / Wavenet) ──────────────────────────────────────
if ($action === 'get_tts_google') {
    $text = trim($_POST['text'] ?? '');
    if ($text === '' || strlen($text) > 1500) {
        echo json_encode(['success' => false, 'error' => 'Invalid text.']);
        exit;
    }

    $globalConfigFile = __DIR__ . '/data/global_config.json';
    $config = file_exists($globalConfigFile) ? (json_decode(file_get_contents($globalConfigFile), true) ?: []) : [];
    $apiKey = trim($config['google_tts_api_key'] ?? '');

    if ($apiKey === '') {
        echo json_encode(['success' => false, 'error' => 'No Google TTS API key configured.']);
        exit;
    }

    // Allowed Google Neural2 / Wavenet voices
    $allowedVoices = [
        'en-US-Neural2-A', 'en-US-Neural2-C', 'en-US-Neural2-D', 'en-US-Neural2-E',
        'en-US-Neural2-F', 'en-US-Neural2-G', 'en-US-Neural2-H', 'en-US-Neural2-I',
        'en-US-Neural2-J', 'en-GB-Neural2-A', 'en-GB-Neural2-B', 'en-GB-Neural2-C',
        'en-GB-Neural2-D', 'en-GB-Neural2-F', 'en-AU-Neural2-A', 'en-AU-Neural2-B',
        'en-AU-Neural2-C', 'en-AU-Neural2-D', 'en-US-Wavenet-A', 'en-US-Wavenet-B',
        'en-US-Wavenet-C', 'en-US-Wavenet-D', 'en-US-Wavenet-E', 'en-US-Wavenet-F',
    ];
    $voiceName = $config['google_tts_voice'] ?? 'en-US-Neural2-F';
    if (!in_array($voiceName, $allowedVoices, true)) $voiceName = 'en-US-Neural2-F';
    $languageCode = substr($voiceName, 0, 5); // e.g. en-US

    // Disk cache so repeated phrases are free
    $cacheDir = __DIR__ . '/data/tts_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheKey = hash('sha256', 'google-neural2::' . $voiceName . '::' . $text);
    $cacheFile = $cacheDir . '/' . $cacheKey . '.mp3';

    $lockFile = $cacheFile . '.lock';
    $lock = fopen($lockFile, 'c');
    if ($lock) flock($lock, LOCK_EX);

    if (!file_exists($cacheFile)) {
        $payload = [
            'input'       => ['text' => $text],
            'voice'       => ['languageCode' => $languageCode, 'name' => $voiceName],
            'audioConfig' => ['audioEncoding' => 'MP3', 'speakingRate' => 1.05, 'pitch' => 0.0]
        ];
        $url = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . rawurlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $audioContent = $data['audioContent'] ?? null;
            if ($audioContent) {
                file_put_contents($cacheFile, base64_decode($audioContent), LOCK_EX);
            }
        } else {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
            $errBody = json_decode($response, true);
            $errMsg = $errBody['error']['message'] ?? ('HTTP ' . $httpCode);
            echo json_encode(['success' => false, 'error' => 'Google TTS error: ' . $errMsg]);
            exit;
        }
    }

    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }

    if (!file_exists($cacheFile)) {
        echo json_encode(['success' => false, 'error' => 'Google TTS could not generate audio.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'audio'   => base64_encode(file_get_contents($cacheFile)),
        'mime'    => 'audio/mpeg',
        'cached'  => true,
        'voice'   => $voiceName,
    ]);
    exit;
}

// ── ElevenLabs TTS ──────────────────────────────────────
if ($action === 'get_tts_elevenlabs') {
    $text = trim($_POST['text'] ?? '');
    if ($text === '' || strlen($text) > 1500) {
        echo json_encode(['success' => false, 'error' => 'Invalid text.']);
        exit;
    }

    $globalConfigFile = __DIR__ . '/data/global_config.json';
    $config = file_exists($globalConfigFile) ? (json_decode(file_get_contents($globalConfigFile), true) ?: []) : [];
    $apiKey = trim($config['elevenlabs_api_key'] ?? '');

    if ($apiKey === '') {
        echo json_encode(['success' => false, 'error' => 'No ElevenLabs API key configured.']);
        exit;
    }

    $voiceId = trim($_POST['voice_id'] ?? ($config['elevenlabs_voice_id'] ?? '21m00Tcm4TlvDq8ikWAM'));
    $modelId = 'eleven_multilingual_v2';

    $cacheDir = __DIR__ . '/data/tts_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheKey = hash('sha256', 'elevenlabs::' . $voiceId . '::' . $modelId . '::' . $text);
    $cacheFile = $cacheDir . '/' . $cacheKey . '.mp3';

    $lockFile = $cacheFile . '.lock';
    $lock = fopen($lockFile, 'c');
    if ($lock) flock($lock, LOCK_EX);

    if (!file_exists($cacheFile)) {
        $payload = [
            'text' => $text,
            'model_id' => $modelId,
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.75
            ]
        ];
        $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voiceId);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'xi-api-key: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && is_string($response) && strlen($response) > 100) {
            file_put_contents($cacheFile, $response, LOCK_EX);
        } else {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
            $errBody = json_decode($response, true);
            $errMsg = $errBody['detail']['message'] ?? $errBody['message'] ?? ('HTTP ' . $httpCode);
            echo json_encode(['success' => false, 'error' => 'ElevenLabs error: ' . $errMsg]);
            exit;
        }
    }

    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }

    if (!file_exists($cacheFile)) {
        echo json_encode(['success' => false, 'error' => 'ElevenLabs could not generate audio.']);
        exit;
    }

    $audioData = file_get_contents($cacheFile);
    echo json_encode([
        'success' => true,
        'audio'   => base64_encode($audioData),
        'mime'    => 'audio/mpeg',
        'voice'   => $voiceId,
    ]);
    exit;
}

if ($action === 'test_google_voice') {
    $apiKey = trim($_POST['api_key'] ?? '');
    $voice = trim($_POST['voice'] ?? 'en-US-Neural2-F');
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'Please enter a Google Cloud TTS API key first.']);
        exit;
    }
    $languageCode = substr($voice, 0, 5);
    $payload = [
        'input'       => ['text' => 'Google Neural2 voice test connected successfully.'],
        'voice'       => ['languageCode' => $languageCode, 'name' => $voice],
        'audioConfig' => ['audioEncoding' => 'MP3', 'speakingRate' => 1.0, 'pitch' => 0.0]
    ];
    $url = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . rawurlencode($apiKey);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($res, true);
        echo json_encode([
            'success' => true,
            'message' => 'Google Cloud TTS connection successful!',
            'audio_data' => $data['audioContent'] ?? ''
        ]);
    } else {
        $data = json_decode($res, true);
        $errMsg = $data['error']['message'] ?? ('HTTP Error ' . $httpCode);
        echo json_encode(['success' => false, 'error' => $errMsg]);
    }
    exit;
}

if ($action === 'test_elevenlabs_voice' || $action === 'test_elevenlabs') {
    $apiKey = trim($_POST['api_key'] ?? '');
    $voiceId = trim($_POST['voice_id'] ?? '21m00Tcm4TlvDq8ikWAM');
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'Please enter an ElevenLabs API key first.']);
        exit;
    }
    $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voiceId);
    $payload = [
        'text' => 'ElevenLabs voice test connected successfully.',
        'model_id' => 'eleven_multilingual_v2'
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'xi-api-key: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && is_string($res) && strlen($res) > 100) {
        echo json_encode([
            'success' => true,
            'message' => 'ElevenLabs connection successful!',
            'audio_data' => base64_encode($res)
        ]);
    } else {
        $err = json_decode($res, true);
        $msg = $err['detail']['message'] ?? $err['message'] ?? ('HTTP Error ' . $httpCode);
        echo json_encode(['success' => false, 'error' => $msg]);
    }
    exit;
}

if ($action === 'get_tts') {
    $roomId = trim($_POST['room_id'] ?? '');
    $text = trim($_POST['text'] ?? '');
    $room = $roomId !== '' ? loadRoom($roomId) : null;
    $globalConfigFile = __DIR__ . '/data/global_config.json';
    $config = file_exists($globalConfigFile) ? (json_decode(file_get_contents($globalConfigFile), true) ?: []) : [];

    if (!$room || $text === '' || strlen($text) > 1500) {
        echo json_encode(['success' => false, 'error' => 'Invalid voice request.']);
        exit;
    }
    if (($room['config']['ai_provider'] ?? ($config['ai_provider'] ?? '')) !== 'openai'
        || empty($config['openai_api_key']) || empty($room['config']['enable_tts'])) {
        echo json_encode(['success' => false, 'error' => 'OpenAI voice is not enabled for this room.']);
        exit;
    }

    $voice = $config['openai_voice'] ?? 'ash';
    $allowedVoices = ['ash', 'coral', 'echo', 'fable', 'onyx', 'nova', 'sage', 'shimmer'];
    if (!in_array($voice, $allowedVoices, true)) $voice = 'ash';
    $cacheDir = __DIR__ . '/data/tts_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheKey = hash('sha256', 'openai-v2::gpt-4o-mini-tts::' . $voice . '::' . $text);
    $cacheFile = $cacheDir . '/' . $cacheKey . '.mp3';
    $lockFile = $cacheDir . '/' . $cacheKey . '.lock';

    $lock = fopen($lockFile, 'c');
    if ($lock) flock($lock, LOCK_EX);
    if (!file_exists($cacheFile)) {
        $payload = [
            'model' => 'gpt-4o-mini-tts',
            'voice' => $voice,
            'input' => $text,
            'instructions' => 'Deliver this like a wildly entertaining, high-energy comedy game-show host. Be fast, playful, animated, mischievous, and sarcastic. Smile through the words, punch the joke, vary pitch, and avoid a flat corporate narration.',
            'speed' => 1.15,
            'response_format' => 'mp3'
        ];
        $ch = curl_init('https://api.openai.com/v1/audio/speech');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['openai_api_key']
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $audio = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && is_string($audio) && strlen($audio) > 100) {
            file_put_contents($cacheFile, $audio, LOCK_EX);
        }
    }
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    if (!file_exists($cacheFile)) {
        echo json_encode(['success' => false, 'error' => 'OpenAI could not generate speech.']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'audio' => base64_encode(file_get_contents($cacheFile)),
        'mime' => 'audio/mpeg',
        'cached' => true
    ]);
    exit;
}


if ($action === 'get_decks') {
    $parsed = parseDecksShared();
    $stats = [];
    foreach ($parsed['tags'] as $tag) {
        $stats[$tag] = [
            'slug' => $tag,
            'label' => get_deck_label($tag),
            'black' => 0,
            'white' => 0
        ];
    }
    foreach ($parsed['black'] as $c) {
        if (isset($stats[$c['deck']])) $stats[$c['deck']]['black']++;
    }
    foreach ($parsed['white'] as $c) {
        if (isset($stats[$c['deck']])) $stats[$c['deck']]['white']++;
    }
    header('Content-Type: application/json');
    echo json_encode(array_values($stats));
    exit;
}

if ($action === 'select_theme') {
    $_SESSION['selected_theme_key'] = $_POST['theme_key'] ?? 'default';
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'create') {
    $input = json_decode(file_get_contents('php://input'), true);

    // Load Global Config & Theme
    $globalConfigFile = __DIR__ . '/data/global_config.json';
    $globalConfig = file_exists($globalConfigFile) ? json_decode(file_get_contents($globalConfigFile), true) : [];
    $themes = loadThemes();
    $currentThemeKey = $input['theme_key'] ?? $globalConfig['default_theme'] ?? 'default';
    $theme = $themes[$currentThemeKey] ?? ($themes['default'] ?? []);

    // UNIQUE NAME CHECK
    $baseName = trim($input['room_name'] ?? '');
    if ($baseName === '' || $baseName === ($globalConfig['default_room_name'] ?? 'The Lounge')) {
        // If no name or default name, pick a random one from theme
        if (!empty($theme['room_names'])) {
            $baseName = $theme['room_names'][array_rand($theme['room_names'])];
        } else {
            $baseName = 'Game Room';
        }
    }

    $finalName = $baseName;
    $counter = 1;

    // Scan existing rooms
    $existingNames = [];
    $files = glob($DATA_DIR . '/room_*.json');
    if ($files) {
        foreach ($files as $f) {
            $json = json_decode(file_get_contents($f), true);
            if ($json && isset($json['config']['room_name'])) {
                $existingNames[] = strtolower($json['config']['room_name']);
            }
        }
    }

    while (in_array(strtolower($finalName), $existingNames)) {
        $finalName = $baseName . " (" . $counter . ")";
        $counter++;
    }
    $input['room_name'] = $finalName;

    $roomId = uniqid();

    // Load decks from decks.md in the same folder
    $parsed = parseDecksShared();
    $availableTags = $parsed['tags'] ?? ['base_deck'];
    $selectedTags = (!empty($input['decks']) && is_array($input['decks'])) ? array_map('strtolower', $input['decks']) : $availableTags;

    // Handle Orange Deck (User Additions)
    $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
    if (in_array('orange_deck', $selectedTags)) {
        if (file_exists($USER_ADDITIONS_FILE)) {
            $userAdditions = json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: [];
            foreach ($userAdditions as $card) {
                if (($card['type'] ?? 'white') === 'black') {
                    $parsed['black'][] = [
                        'text' => $card['text'],
                        'pick' => $card['pick'] ?? 1,
                        'id' => uniqid('b_usr_'),
                        'deck' => 'orange_deck'
                    ];
                } else {
                    $parsed['white'][] = [
                        'text' => $card['text'],
                        'id' => uniqid('w_usr_'),
                        'deck' => 'orange_deck'
                    ];
                }
            }
        }
    }

    // Filter by selected deck tags
    $decks = [
        'black' => array_values(array_filter($parsed['black'], function ($c) use ($selectedTags) {
            return in_array($c['deck'], $selectedTags, true);
        })),
        'white' => array_values(array_filter($parsed['white'], function ($c) use ($selectedTags) {
            return in_array($c['deck'], $selectedTags, true);
        }))
    ];

    if (empty($decks['black']) || empty($decks['white'])) {
        $decks['black'] = $decks['black'] ?: [['id' => uniqid('b_'), 'text' => '______.', 'pick' => 1, 'deck' => 'base_deck']];
        $decks['white'] = $decks['white'] ?: [['id' => uniqid('w_'), 'text' => 'A mystery.', 'deck' => 'base_deck']];
    }

    // Use distributed shuffle to ensure variety across different decks
    $decks['black'] = distributedShuffle($decks['black']);
    $decks['white'] = distributedShuffle($decks['white']);

    // Attach theme
    $input['theme'] = $theme;
    if (!empty($input['theme']) && is_array($input['theme'])) {
        // Normalize arrays
        $input['theme']['room_names'] = array_values(array_filter($input['theme']['room_names'] ?? [], fn($v) => is_string($v) && trim($v) !== ''));
        $input['theme']['character_names'] = array_values(array_filter($input['theme']['character_names'] ?? [], fn($v) => is_string($v) && trim($v) !== ''));
    }

    // Check for bots requested in setup
    $bots = [];
    // NOTE: Bots are NO LONGER added at creation time.
    // They will be added dynamically when game starts if player count < 3 and fill_bots is enabled.

    $input['min_players'] = $input['min_players'] ?? 3;
    $input['max_players'] = $input['max_players'] ?? 8;
    $input['fill_bots'] = $input['fill_bots'] ?? false;
    $aiConfig = getAIConfigAgainst();
    $input['ai_provider'] = $aiConfig['provider'];
    $input['ai_api_key'] = $aiConfig['api_key'];

    $nowTs = time();
    $roomData = [
        'id' => $roomId,
        'config' => $input,
        'state' => 'lobby',
        'is_tie_breaker' => false,
        'players' => $bots,
        'chat' => [],
        'votes' => [],
        'black_deck' => $decks['black'],
        'white_deck' => $decks['white'],
        'current_black_card' => null,
        'table_cards' => [],
        'round' => 1,
        'host_id' => $_SESSION['user_id'] ?? 'unknown',
        'created_at' => $nowTs,
        'updated_at' => $nowTs
    ];
    saveRoom($roomId, $roomData);
    echo json_encode(['success' => true, 'room_id' => $roomId]);
    exit;
}

if ($action === 'poll') {
    $roomId = $_GET['room_id'] ?? '';
    $userId = $_SESSION['user_id'] ?? '';
    $userName = $_SESSION['user_name'] ?? 'Unknown';
    $isSpectator = isset($_GET['spectate']);

    // Reject empty user IDs to prevent ghost players being created
    if (empty($userId) && !$isSpectator) {
        echo json_encode(['error' => 'Session expired. Please refresh and set up your profile.', 'redirect' => 'index.php']);
        exit;
    }

    $room = loadRoom($roomId);
    if (!$room) {
        // Room doesn't exist - clear session reference if it matches
        if (($_SESSION['current_room_id'] ?? '') === $roomId) {
            unset($_SESSION['current_room_id']);
        }
        echo json_encode(['error' => 'Room not found']);
        exit;
    }

    // Removed single active game check to allow joining multiple games.

    // Join Logic (Skip if spectator)
    $playerExists = false;
    foreach ($room['players'] as $p) {
        if ($p['id'] === $userId) $playerExists = true;
    }

    if (!$playerExists && !$isSpectator) {
        // Ensure the joining user's name is unique in the room
        $uniqueName = $userName;
        $suffix = 2;
        $nameTaken = true;
        while ($nameTaken) {
            $nameTaken = false;
            foreach ($room['players'] as $p) {
                if ($p['id'] !== $userId && strcasecmp(trim($p['name']), $uniqueName) === 0) {
                    $nameTaken = true;
                    break;
                }
            }
            if ($nameTaken) {
                $uniqueName = $userName . ' ' . $suffix;
                $suffix++;
            }
        }
        if ($uniqueName !== $userName) {
            $userName = $uniqueName;
            $_SESSION['user_name'] = $userName;
        }

        // Determine if they should be "on hold" (waiting for next round)
        $onHold = ($room['state'] !== 'lobby');

        // Look for a bot to replace with the human player
        $botReplaced = false;
        foreach ($room['players'] as $idx => $p) {
            if ($p['is_bot'] ?? false) {
                // Replace bot with human
                $room['players'][$idx] = [
                    'id' => $userId,
                    'name' => $userName,
                    'name_source' => $_SESSION['name_source'] ?? 'hand_entered',
                    'avatar_type' => $_SESSION['user_avatar_type'] ?? 'dicebear',
                    'avatar_val' => $_SESSION['user_avatar_val'] ?? 'seed',
                    'voice_opt_in' => $_SESSION['voice_opt_in'] ?? false,
                    'score' => $p['score'], // Keep bot's score
                    'hand' => $onHold ? [] : $p['hand'], // Don't give cards if on hold
                    'status' => $onHold ? 'waiting' : ($p['status'] ?? 'ready'),
                    'is_host' => ($room['host_id'] === $userId || ($p['is_host'] ?? false)),
                    'is_bot' => false,
                    'is_waiting' => $onHold, // Mark as waiting for next round
                    'muted_players' => [],
                    'idle_misses' => 0,
                    'muted_mic' => true // New players start muted
                ];
                $botReplaced = true;

                // Add join announcement to chat
                if (!isset($room['chat'])) $room['chat'] = [];
                $room['chat'][] = [
                    'player_id' => 'system',
                    'name' => 'System',
                    'msg' => "$userName has joined (replaced {$p['name']})" . ($onHold ? " and is waiting for the next round." : ""),
                    'ts' => time(),
                    'type' => 'join'
                ];
                break;
            }
        }

        // If no bot to replace, add as new player
        if (!$botReplaced) {
            $room['players'][] = [
                'id' => $userId,
                'name' => $userName,
                'name_source' => $_SESSION['name_source'] ?? 'hand_entered',
                'avatar_type' => $_SESSION['user_avatar_type'] ?? 'dicebear',
                'avatar_val' => $_SESSION['user_avatar_val'] ?? 'seed',
                'voice_opt_in' => $_SESSION['voice_opt_in'] ?? false,
                'score' => 0,
                'hand' => [],
                'status' => $onHold ? 'waiting' : 'ready',
                'is_host' => ($room['host_id'] === $userId || count($room['players']) === 0),
                'is_bot' => false,
                'is_waiting' => $onHold,
                'muted_players' => [],
                'idle_misses' => 0,
                'muted_mic' => true // New players start muted
            ];

            // Add join announcement to chat
            if (!isset($room['chat'])) $room['chat'] = [];
            $room['chat'][] = [
                'player_id' => 'system',
                'name' => 'System',
                'msg' => "$userName has joined the game" . ($onHold ? " and is waiting for the next round." : ""),
                'ts' => time(),
                'type' => 'join'
            ];
        }
        saveRoom($roomId, $room);
    }

    $autoAlert = null;
    $paused = $room['paused'] ?? false;

    // Auto-unpause if paused for more than 3 days
    if ($paused && !empty($room['paused_at'])) {
        $pausedAge = time() - intval($room['paused_at']);
        if ($pausedAge > 60 * 60 * 24 * 3) { // 3 days
            $pauseDuration = $pausedAge;
            if (isset($room['round_start_time'])) {
                $room['round_start_time'] += $pauseDuration;
            }
            if (isset($room['created_at'])) {
                $room['created_at'] += $pauseDuration;
            }
            $room['paused'] = false;
            $room['paused_at'] = null;
            unset($room['paused_by']);
            if (!isset($room['chat'])) $room['chat'] = [];
            $room['chat'][] = [
                'player_id' => 'system',
                'name' => 'System',
                'msg' => 'Game auto-resumed after 3 days paused. Placeholder Bot may join if needed.',
                'ts' => time(),
                'type' => 'join'
            ];
            $autoAlert = 'Game resumed after pause timeout';
            $paused = false;
            $changed = ensurePlaceholderBot($room);
            saveRoom($roomId, $room);
        }
    }

    // Safeguard: ensure black card exists and hands are refilled during play
    $stateChanged = false;
    if (!$paused && $room['state'] === 'playing') {
        if (empty($room['current_black_card']) && !empty($room['black_deck'])) {
            $room['current_black_card'] = array_shift($room['black_deck']);
            $room['round_start_time'] = time();
            $stateChanged = true;
        }

        $limit = $room['config']['hand_size'] ?? 7;
        foreach ($room['players'] as &$p) {
            $filled = false;
            while (count($p['hand']) < $limit && !empty($room['white_deck'])) {
                $p['hand'][] = array_shift($room['white_deck']);
                $filled = true;
            }
            if ($filled) $stateChanged = true;
        }
        unset($p);
        if ($stateChanged) saveRoom($roomId, $room);
    }

    // BOT LOGIC: If playing, ensure bots have played
    if (!$paused && $room['state'] === 'playing' && !empty($room['current_black_card'])) { // Ensure black card exists
        $botsPlayed = false;
        $roomChanged = false;
        
        foreach ($room['players'] as $idx_p => &$p) {
            if (($p['is_bot'] ?? false) && $p['status'] !== 'played') {
                if (!empty($p['hand'])) {
                    $pick = $room['current_black_card']['pick'] ?? 1;
                    
                    // Query Gemini for card selection only if it is a smart bot (is_ai = true) and AI is enabled
                    $playedIndexes = null;
                    if (($room['config']['use_ai_bots'] ?? false) && ($p['is_ai'] ?? false)) {
                        $playedIndexes = getAICardSelection($p['name'], $room['current_black_card']['text'] ?? '', $p['hand'], $pick, $room['config']['ai_api_key'] ?? ($room['config']['gemini_api_key'] ?? null));
                    }
                    
                    if ($playedIndexes === null) {
                        // Fallback to random card selection instead of kicking the bot
                        $handSize = count($p['hand']);
                        $availableIndexes = range(0, $handSize - 1);
                        shuffle($availableIndexes);
                        $playedIndexes = array_slice($availableIndexes, 0, min($pick, $handSize));
                    }
                    
                    $played = [];
                    rsort($playedIndexes);
                    foreach ($playedIndexes as $idx) {
                        if (isset($p['hand'][$idx])) {
                            $played[] = $p['hand'][$idx];
                            array_splice($p['hand'], $idx, 1);
                        }
                    }
                    $played = array_reverse($played);
                    
                    if (empty($played)) {
                        $played = array_splice($p['hand'], 0, $pick);
                    }
                    
                    $room['table_cards'][] = ['player_id' => $p['id'], 'cards' => $played];
                    $p['status'] = 'played';
                    $botsPlayed = true;
                    
                    // 25% chance for a smart bot to comment in chat on playing a card
                    if (($room['config']['use_ai_bots'] ?? false) && ($p['is_ai'] ?? false) && rand(1, 100) <= 25) {
                        $comment = getAIBotComment($p['name'], $room['current_black_card']['text'] ?? '', $played, 'play', $room['config']['ai_api_key'] ?? ($room['config']['gemini_api_key'] ?? null));
                        if ($comment) {
                            if (!isset($room['chat'])) $room['chat'] = [];
                            $room['chat'][] = [
                                'player_id' => $p['id'],
                                'name' => $p['name'],
                                'msg' => $comment,
                                'ts' => time(),
                                'type' => 'chat'
                            ];
                        }
                    }
                }
            }
        }
        unset($p);
        
        if ($roomChanged) {
            $room['players'] = array_values($room['players']);
        }
        
        if ($botsPlayed) {
            // Check if everyone has played now (since bots just played)
            $all = true;
            foreach ($room['players'] as $p) {
                if (isActivePlayer($p, $room) && ($ppStatus = $p['status'] ?? '') !== 'played' && $ppStatus !== 'skipped') {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                transitionToVoting($room);
            }
            saveRoom($roomId, $room);
        }
    }

    // TIMER-BASED AUTO-ADVANCE: When timer expires, skip idle players and move to voting
    $now = time();
    $roundStarted = $room['round_start_time'] ?? $room['updated_at'] ?? $now;
    $configuredTimer = intval($room['config']['timer'] ?? 60);
    $idleLimit = $configuredTimer > 0 ? $configuredTimer : 120; // Use configured timer, fallback to 120s if no timer set

    if (!$paused && $room['state'] === 'playing') {
        $elapsed = $now - $roundStarted;
        if ($elapsed >= $idleLimit && !empty($room['current_black_card'])) { // Timer expired
            $changed = false;

            // Mark idle players as 'skipped' and track their idle miss
            foreach ($room['players'] as &$p) {
                if (($p['is_bot'] ?? false) || $p['status'] === 'played') continue;
                // Don't auto-play for them, just mark as skipped
                $p['status'] = 'skipped';
                if (!($p['is_bot'] ?? false)) {
                    $p['idle_misses'] = ($p['idle_misses'] ?? 0) + 1;
                }
                $changed = true;
            }
            unset($p);

            // Move to voting if at least one player has played (skip idle players)
            if (!empty($room['table_cards'])) {
                transitionToVoting($room);
                $autoAlert = 'Timer expired. Moving to voting with submitted cards.';
                $changed = true;
            } elseif ($changed) {
                // No one played anything - auto-draw a round (skip this black card)
                $room['current_black_card'] = !empty($room['black_deck']) ? array_shift($room['black_deck']) : null;
                $room['round_start_time'] = $now;
                foreach ($room['players'] as &$p) {
                    $p['status'] = 'thinking';
                }
                unset($p);
                $autoAlert = 'No cards played. Skipping to next round.';
            }

            // Evict idle players (2 misses); if host reaches 2, kill game
            $evicted = false;
            foreach ($room['players'] as $p) {
                if (($p['is_bot'] ?? false)) continue;
                if (($p['idle_misses'] ?? 0) >= 2) {
                    if (!empty($p['is_host'])) {
                        // Count other active human players
                        $activeOtherHumans = [];
                        foreach ($room['players'] as $op) {
                            if ($op['id'] !== $p['id'] && !($op['is_bot'] ?? false) && !($op['afk'] ?? false)) {
                                $activeOtherHumans[] = $op['id'];
                            }
                        }
                        if (count($activeOtherHumans) < 2) {
                            @unlink(getRoomFile($roomId));
                            echo json_encode(['error' => 'Host inactive twice. Game closed.']);
                            exit;
                        } else {
                            // Transfer host role to the first active other human
                            $newHostId = $activeOtherHumans[0];
                            foreach ($room['players'] as &$pRef) {
                                if ($pRef['id'] === $newHostId) {
                                    $pRef['is_host'] = true;
                                    break;
                                }
                            }
                            unset($pRef);
                        }
                    }
                    replacePlayerWithBot($room, $p['id']);
                    $evicted = true;
                }
            }
            if ($evicted || $changed) saveRoom($roomId, $room);
        }
    } elseif (!$paused && $room['state'] === 'voting') {
        makeBotsVote($room);
        $elapsed = $now - $roundStarted;

        // Determine voting timeout: if any player was 'skipped' during the play phase, shorten wait to 45s
        $votingTimeout = $idleLimit;
        $hadSkipped = false;
        foreach ($room['players'] as $p) {
            if (($p['status'] ?? '') === 'skipped') { $hadSkipped = true; break; }
        }
        if ($hadSkipped) $votingTimeout = 45;

        if ($elapsed >= $votingTimeout && !empty($room['table_cards'])) {
            $humanCount = 0;
            foreach ($room['players'] as $p) {
                if (!($p['is_bot'] ?? false) && !($p['afk'] ?? false) && empty($p['is_waiting'])) $humanCount++;
            }
            $changed = false;
            $autoVoted = [];
            foreach ($room['players'] as &$p) {
                if ($p['is_bot'] ?? false || !empty($p['is_waiting'])) continue;
                if (isset($room['votes'][$p['id']])) continue;
                $room['votes'][$p['id']] = rand(0, count($room['table_cards']) - 1);
                $p['idle_misses'] = ($p['idle_misses'] ?? 0) + 1; // count idle miss on auto-vote
                $autoVoted[] = $p['name'] ?? 'Unknown';
                $changed = true;
            }
            unset($p);

            if (!empty($autoVoted)) {
                $autoAlert = 'Auto-vote: ' . (count($autoVoted) === 1 ? $autoVoted[0] . ' missed voting and was auto-voted.' : implode(', ', $autoVoted) . ' missed voting and were auto-voted.');
            }

            // Count active players (humans + bots) for vote threshold
            $totalVoters = 0;
            foreach ($room['players'] as $p) {
                if (!($p['afk'] ?? false) && empty($p['is_waiting'])) $totalVoters++;
            }

            if ($changed && count($room['votes']) >= $totalVoters) {
                // Run same resolution as vote action
                $counts = array_count_values($room['votes']);
                $max = -1;
                $winners = [];
                foreach ($counts as $i => $c) {
                    if ($c > $max) {
                        $max = $c;
                        $winners = [$i];
                    } elseif ($c === $max) $winners[] = $i;
                }

                if (count($winners) === 1) {
                    $wIdx = $winners[0];
                    $wId = $room['table_cards'][$wIdx]['player_id'];

                    // Store Last Round Info
                    $wName = 'Unknown';
                    foreach ($room['players'] as $p) {
                        if ($p['id'] === $wId) $wName = $p['name'];
                    }

                    $room['last_round_info'] = [
                        'winner' => $wName,
                        'winner_id' => $wId,
                        'black' => $room['current_black_card']['text'],
                        'white' => array_map(function ($c) {
                            return $c['text'];
                        }, $room['table_cards'][$wIdx]['cards'])
                    ];

                    // AI Host Roast
                    $playerScoresText = "";
                    foreach ($room['players'] as $p) {
                        $playerScoresText .= "- " . $p['name'] . ": " . $p['score'] . " points\n";
                    }
                    $roast = getAIHostRoast($wName, $room['current_black_card']['text'] ?? '', $room['table_cards'][$wIdx]['cards'], $playerScoresText, $room['config']['ai_api_key'] ?? ($room['config']['gemini_api_key'] ?? null));
                    if (empty($roast)) {
                        $whitesText = implode(" / ", array_map(function($c) { return $c['text']; }, $room['table_cards'][$wIdx]['cards']));
                        $roast = "The winner of this round is {$wName}! The winning combination was: {$whitesText}.";
                    }
                    if ($roast) {
                        if (!isset($room['chat'])) $room['chat'] = [];
                        $room['chat'][] = [
                            'player_id' => 'host',
                            'name' => 'Host (AI)',
                            'msg' => $roast,
                            'ts' => time(),
                            'type' => 'host_comment'
                        ];
                    }
                    
                    // Trigger bot reveal comments (gloating/complaining)
                    triggerAIRevealComments($room, $wId, $wIdx);
                    // Append to server-side history
                    if (!isset($room['history']) || !is_array($room['history'])) $room['history'] = [];
                    $winnerScoreAfter = null;
                    foreach ($room['players'] as $pp) {
                        if ($pp['id'] === $wId) {
                            $winnerScoreAfter = ($pp['score'] ?? 0) + 1;
                            break;
                        }
                    }

                    // Check if all votes were for the same card (unanimous)
                    $isUnanimous = (count(array_unique($room['votes'])) === 1);

                    $room['history'][] = [
                        'ts' => time(),
                        'round' => ($room['round'] ?? 1),
                        'winner' => $wName,
                        'winner_id' => $wId,
                        'black' => $room['current_black_card']['text'],
                        'white' => array_map(function ($c) {
                            return $c['text'];
                        }, $room['table_cards'][$wIdx]['cards']),
                        'score' => $winnerScoreAfter,
                        'unanimous' => $isUnanimous
                    ];
                    $room['round'] = ($room['round'] ?? 1) + 1;

                    foreach ($room['players'] as &$p) {
                        if ($p['id'] === $wId) $p['score']++;
                        $p['status'] = 'thinking';
                    }
                    unset($p);

                    // Check for game end (win limit)
                    $winLimit = intval($room['config']['win_limit'] ?? 0);
                    $winnerPlayer = null;
                    foreach ($room['players'] as $pp) {
                        if ($pp['id'] === $wId) {
                            $winnerPlayer = $pp;
                            break;
                        }
                    }
                    if ($winLimit > 0 && $winnerPlayer && intval($winnerPlayer['score']) >= $winLimit) {
                        $room['state'] = 'finished';
                        $room['winner_id'] = $winnerPlayer['id'];
                        $room['winner_name'] = $winnerPlayer['name'];
                        $room['final_round_info'] = $room['last_round_info'] ?? null;

                        // Collect all unanimous vote rounds for display
                        if (!isset($room['unanimous_rounds'])) {
                            $room['unanimous_rounds'] = [];
                        }
                        foreach ($room['history'] as $h) {
                            if (isset($h['unanimous']) && $h['unanimous']) {
                                $room['unanimous_rounds'][] = [
                                    'round' => $h['round'],
                                    'black' => $h['black'],
                                    'white' => $h['white']
                                ];
                            }
                        }

                        $room['votes'] = [];
                        $room['table_cards'] = [];
                    } else {
                        // Continue to next round
                        $room['table_cards'] = [];
                        $room['votes'] = [];
                        $room['state'] = 'playing';
                        $room['round_start_time'] = time();
                        $room['is_tie_breaker'] = false;
                        if (!empty($room['black_deck'])) $room['current_black_card'] = array_shift($room['black_deck']);

                        $limit = $room['config']['hand_size'] ?? 7;
                        foreach ($room['players'] as &$p) {
                            // Clear waiting status for mid-game joiners
                            if (!empty($p['is_waiting'])) {
                                $p['is_waiting'] = false;
                                $p['status'] = 'thinking';
                            }
                            while (count($p['hand']) < $limit && !empty($room['white_deck'])) {
                                $p['hand'][] = array_shift($room['white_deck']);
                            }
                        }
                        unset($p);
                    }
                } else {
                    // Tie Breaker
                    $newTable = [];
                    foreach ($winners as $i) $newTable[] = $room['table_cards'][$i];
                    $room['table_cards'] = $newTable;
                    $room['votes'] = [];
                    $room['is_tie_breaker'] = true;
                    $room['round_start_time'] = time(); // Reset timer for tie breaker
                }
                $autoAlert = 'Auto-vote triggered after inactivity.';
            }

            // Evict idle players (2 misses); if host reaches 2, kill game
            $evicted = false;
            foreach ($room['players'] as $p) {
                if (($p['is_bot'] ?? false)) continue;
                if (($p['idle_misses'] ?? 0) >= 2) {
                    if (!empty($p['is_host'])) {
                        // Count other active human players
                        $activeOtherHumans = [];
                        foreach ($room['players'] as $op) {
                            if ($op['id'] !== $p['id'] && !($op['is_bot'] ?? false) && !($op['afk'] ?? false)) {
                                $activeOtherHumans[] = $op['id'];
                            }
                        }
                        if (count($activeOtherHumans) < 2) {
                            @unlink(getRoomFile($roomId));
                            echo json_encode(['error' => 'Host inactive twice. Game closed.']);
                            exit;
                        } else {
                            // Transfer host role to the first active other human
                            $newHostId = $activeOtherHumans[0];
                            foreach ($room['players'] as &$pRef) {
                                if ($pRef['id'] === $newHostId) {
                                    $pRef['is_host'] = true;
                                    break;
                                }
                            }
                            unset($pRef);
                        }
                    }
                    replacePlayerWithBot($room, $p['id']);
                    $evicted = true;
                }
            }
            if ($evicted) saveRoom($roomId, $room);
        }
    }

    if ($autoAlert) {
        $room['auto_alert'] = ['msg' => $autoAlert, 'ts' => $now];
        saveRoom($roomId, $room);
    }

    // ROUND END AUTO-ADVANCE (45 second timeout or all players clicked Continue)
    if (!$paused && $room['state'] === 'round_end') {
        $roundEndTime = $room['round_end_time'] ?? $now;
        $elapsed = $now - $roundEndTime;
        $roundEndTimeout = 15; // Short timeout so round-end popup disappears quickly

        // Count human players (non-AFK)
        $humanCount = 0;
        foreach ($room['players'] as $p) {
            if (!($p['is_bot'] ?? false) && !($p['afk'] ?? false) && empty($p['is_waiting'])) $humanCount++;
        }

        // Check if all humans clicked Continue OR timeout reached
        $continueClicks = $room['continue_clicks'] ?? [];
        $humanClicks = 0;
        foreach ($room['players'] as $p) {
            if (!($p['is_bot'] ?? false) && !($p['afk'] ?? false) && empty($p['is_waiting']) && isset($continueClicks[$p['id']])) $humanClicks++;
        }

        if ($humanClicks >= $humanCount || $elapsed >= $roundEndTimeout) {
            // Advance to next round
            $room['table_cards'] = [];
            $room['votes'] = [];
            $room['continue_clicks'] = [];
            $room['state'] = 'playing';
            $room['round_start_time'] = time();
            $room['is_tie_breaker'] = false;

            if (!empty($room['black_deck'])) $room['current_black_card'] = array_shift($room['black_deck']);

            // Refill hands
            $limit = $room['config']['hand_size'] ?? 7;
            foreach ($room['players'] as &$p) {
                // Clear waiting status for mid-game joiners
                if (!empty($p['is_waiting'])) {
                    $p['is_waiting'] = false;
                }
                while (count($p['hand']) < $limit && !empty($room['white_deck'])) {
                    $p['hand'][] = array_shift($room['white_deck']);
                }
                $p['status'] = 'thinking';
            }

            // Add auto-alert directly into the room so clients get a spoken/visual notice
            $room['auto_alert'] = ['msg' => 'Continuing to next round.', 'ts' => $now];

            saveRoom($roomId, $room);
        }
    }

    // If only bots remain, shut down the room
    if (botsOnly($room)) {
        @unlink(getRoomFile($roomId));
        echo json_encode(['error' => 'Room closed (bots only).']);
        exit;
    }

    // Placeholder bot support when active players drop below 3 (skip in lobby/waiting room)
    if (!$paused && $room['state'] !== 'lobby') {
        $placeholderChanged = ensurePlaceholderBot($room);
        if ($placeholderChanged) saveRoom($roomId, $room);
    }

    // Random host comments during active gameplay (playing or voting phases)
    if (!$paused && ($room['state'] === 'playing' || $room['state'] === 'voting')) {
        $lastComment = $room['last_random_comment_time'] ?? 0;
        $now = time();
        if ($now - $lastComment > 60) { // at least 60 seconds since last comment
            if (rand(1, 100) <= 5) {
                $room['last_random_comment_time'] = $now;
                
                $apiKey = $room['config']['ai_api_key'] ?? ($room['config']['gemini_api_key'] ?? null);
                $comment = null;
                if (!empty($apiKey)) {
                    $comment = getAIHostRandomComment($room, $apiKey);
                }
                
                if ($comment) {
                    if (!isset($room['chat'])) $room['chat'] = [];
                    $room['chat'][] = [
                        'player_id' => 'host',
                        'name' => 'Host (AI)',
                        'msg' => $comment,
                        'ts' => $now,
                        'type' => 'host_comment'
                    ];
                    saveRoom($roomId, $room);
                } else {
                    $categories = ['funny_moment', 'quiet_moment', 'loud_moment', 'easter_egg', 'special'];
                    $globalConfigFile = __DIR__ . '/data/global_config.json';
                    $globalConfig = file_exists($globalConfigFile) ? json_decode(file_get_contents($globalConfigFile), true) : [];
                    $voiceGender = $room['config']['voice_gender'] ?? ($globalConfig['tts_voice'] ?? ($globalConfig['voice_gender'] ?? 'female'));
                    $category = $categories[array_rand($categories)];
                    $dir = __DIR__ . "/audio/host_messages/{$voiceGender}/{$category}";
                    
                    $files = [];
                    if (is_dir($dir)) {
                        // Commented out WAV files fallback, only using mp3 for now
                        // $files = glob($dir . '/*.{wav,mp3}', GLOB_BRACE);
                        $files = glob($dir . '/*.mp3');
                    }
                    
                    if (!empty($files)) {
                        $randomFile = $files[array_rand($files)];
                        $filename = basename($randomFile);
                        $hash = pathinfo($filename, PATHINFO_FILENAME);
                        
                        $foundText = '';
                        $hostFile = __DIR__ . '/data/host_messages.json';
                        if (file_exists($hostFile)) {
                            $hostMessages = json_decode(file_get_contents($hostFile), true) ?: [];
                            foreach ($hostMessages as $tier) {
                                if (isset($tier['messages'])) {
                                    foreach ($tier['messages'] as $msg) {
                                        $msgText = $msg['text'] ?? '';
                                        if (md5($msgText) === $hash) {
                                            $foundText = $msgText;
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                        
                        if ($foundText) {
                            if (!isset($room['chat'])) $room['chat'] = [];
                            $room['chat'][] = [
                                'player_id' => 'host',
                                'name' => 'Host',
                                'msg' => $foundText,
                                'ts' => $now,
                                'type' => 'host_comment',
                                'audio_url' => "audio/host_messages/{$voiceGender}/{$category}/{$filename}"
                            ];
                            saveRoom($roomId, $room);
                        }
                    }
                }
            }
        }
    }

    // Output Filter
    $client = $room;
    $client['my_hand'] = [];
    $client['is_spectator'] = $isSpectator;
    // Provider credentials are server-only and must never be sent to players or spectators.
    unset($client['config']['ai_api_key'], $client['config']['gemini_api_key'], $client['config']['openai_api_key']);

    foreach ($client['players'] as &$p) {
        if ($p['id'] === $userId) $client['my_hand'] = $p['hand'];
        if ($p['id'] !== $userId) $p['hand'] = array_fill(0, count($p['hand']), ['text' => '?']);
    }

    echo json_encode($client);
    exit;
}

if ($action === 'start_game') {
    $roomId = $_POST['room_id'];
    $room = loadRoom($roomId);
    if (!$room) exit;

    // Check for fill_bots logic (from config or request)
    $shouldFill = !empty($room['config']['fill_bots']) || !empty($_POST['fill_bots']);
    if ($shouldFill) {
        $min = $room['config']['min_players'] ?? 3;
        $currentCount = count($room['players']);
        if ($currentCount < $min) {
            $needed = $min - $currentCount;
            for ($i = 1; $i <= $needed; $i++) {
                $botName = nextThemeCharacterName($room);
                $room['players'][] = [
                    'id' => 'bot_fill_' . uniqid(),
                    'name' => $botName,
                    'score' => 0,
                    'hand' => [],
                    'status' => 'ready',
                    'is_bot' => true
                ];
                if (!isset($room['chat'])) $room['chat'] = [];
                $room['chat'][] = [
                    'player_id' => 'system',
                    'name' => 'System',
                    'msg' => "$botName (Bot) has joined the lobby.",
                    'ts' => time(),
                    'type' => 'join'
                ];
            }
        }
    }

    $room['state'] = 'playing';
    $room['is_tie_breaker'] = false;
    $room['round_start_time'] = time();
    $limit = $room['config']['hand_size'] ?? 7;

    // Deal to everyone (including bots)
    foreach ($room['players'] as &$p) {
        while (count($p['hand']) < $limit && !empty($room['white_deck'])) {
            $p['hand'][] = array_shift($room['white_deck']);
        }
        $p['status'] = 'thinking'; // Bots will play on next poll
    }
    $room['current_black_card'] = array_shift($room['black_deck']);
    $room['votes'] = [];

    // Welcoming announcement
    if ($room['config']['use_ai_host'] ?? false) {
        $themeLabel = $room['config']['theme']['label'] ?? 'Default';
        $announcement = getAIHostStartAnnouncement($room['config']['room_name'] ?? 'Game Room', $themeLabel, $room['config']['ai_api_key'] ?? ($room['config']['gemini_api_key'] ?? null));
        if (empty($announcement)) {
            $announcement = "Welcome to room: " . ($room['config']['room_name'] ?? 'Game Room') . ". Good luck, players!";
        }
        if ($announcement) {
            if (!isset($room['chat'])) $room['chat'] = [];
            $room['chat'][] = [
                'player_id' => 'host',
                'name' => 'Host (AI)',
                'msg' => $announcement,
                'ts' => time(),
                'type' => 'host_comment'
            ];
        }
    }

    saveRoom($roomId, $room);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'chat') {
    $roomId = $_POST['room_id'] ?? '';
    $msg = trim($_POST['message'] ?? '');
    $room = loadRoom($roomId);
    if ($room && $msg) {
        $userName = $_SESSION['user_name'] ?? 'Spectator';
        $userId = $_SESSION['user_id'] ?? '';

        if (!isset($room['chat'])) $room['chat'] = [];
        $room['chat'][] = [
            'player_id' => $userId,
            'name' => $userName,
            'msg' => htmlspecialchars($msg),
            'ts' => time()
        ];

        // Conversational AI Responders
        $hasAiHost = $room['config']['use_ai_host'] ?? false;
        $hasAiBots = $room['config']['use_ai_bots'] ?? false;

        if ($hasAiHost || $hasAiBots) {
            $msgLower = strtolower($msg);
            $aiBots = [];
            if ($hasAiBots) {
                foreach ($room['players'] as $p) {
                    if (($p['is_bot'] ?? false) && ($p['is_ai'] ?? false)) {
                        $aiBots[] = $p;
                    }
                }
            }

            $mentionsHost = (strpos($msgLower, 'host') !== false) && $hasAiHost;
            $mentionsBot = ((strpos($msgLower, 'bot') !== false) || (strpos($msgLower, 'ai') !== false)) && $hasAiBots;
            $mentionsSpecificBot = false;
            $targetBot = null;
            if ($hasAiBots) {
                foreach ($aiBots as $ab) {
                    if (strpos($msgLower, strtolower($ab['name'])) !== false) {
                        $mentionsSpecificBot = true;
                        $targetBot = $ab;
                        break;
                    }
                }
            }

            $shouldRespond = false;
            $responderName = 'Host';
            $responderRole = 'Host';
            $responderId = 'host';
            $type = 'host_comment';

            if ($mentionsHost) {
                $shouldRespond = true;
            } elseif ($mentionsSpecificBot && $targetBot) {
                $shouldRespond = true;
                $responderName = $targetBot['name'];
                $responderRole = 'Player';
                $responderId = $targetBot['id'];
                $type = 'chat';
            } elseif ($mentionsBot && !empty($aiBots)) {
                $shouldRespond = true;
                $targetBot = $aiBots[array_rand($aiBots)];
                $responderName = $targetBot['name'];
                $responderRole = 'Player';
                $responderId = $targetBot['id'];
                $type = 'chat';
            } elseif (rand(1, 100) <= 25) { // 25% chance of random response to general chat
                if ($hasAiHost && (!$hasAiBots || rand(1, 2) === 1)) {
                    $shouldRespond = true;
                } elseif ($hasAiBots && !empty($aiBots)) {
                    $shouldRespond = true;
                    $targetBot = $aiBots[array_rand($aiBots)];
                    $responderName = $targetBot['name'];
                    $responderRole = 'Player';
                    $responderId = $targetBot['id'];
                    $type = 'chat';
                }
            }

            if ($shouldRespond) {
                $response = getAIChatResponse($responderName, $responderRole, $room['chat'], $room['config']['ai_api_key'] ?? ($room['config']['gemini_api_key'] ?? null));
                if ($response) {
                    $room['chat'][] = [
                        'player_id' => $responderId,
                        'name' => ($responderRole === 'Host' ? 'Host (AI)' : $responderName),
                        'msg' => $response,
                        'ts' => time() + 1,
                        'type' => $type
                    ];
                }
            }
        }

        saveRoom($roomId, $room);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Invalid room or message']);
    }
    exit;
}

// Toggle AFK status for current player
if ($action === 'toggle_afk') {
    $roomId = $_POST['room_id'] ?? '';
    $afk = $_POST['afk'] === '1';
    $uid = $_SESSION['user_id'] ?? '';
    $room = loadRoom($roomId);
    if (!$room) {
        echo json_encode(['error' => 'Room not found']);
        exit;
    }

    foreach ($room['players'] as &$p) {
        if (($p['id'] ?? '') === $uid) {
            $p['afk'] = $afk;
            if ($afk) {
                $p['status'] = 'afk';
            } else {
                if ($room['state'] === 'playing') $p['status'] = 'thinking';
            }
            break;
        }
    }
    unset($p);

    if (!isset($room['chat'])) $room['chat'] = [];
    $room['chat'][] = [
        'player_id' => 'system',
        'name' => 'System',
        'msg' => ($_SESSION['user_name'] ?? 'Player') . ($afk ? ' is now AFK' : ' is back'),
        'ts' => time(),
        'type' => $afk ? 'leave' : 'join'
    ];

    // Ensure placeholder bot if needed when someone goes AFK
    $changed = ensurePlaceholderBot($room);

    saveRoom($roomId, $room);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'play_cards') {
    $roomId = $_POST['room_id'];
    $ids = $_POST['card_ids'];
    $uid = $_SESSION['user_id'];
    $room = loadRoom($roomId);

    $pIdx = -1;
    foreach ($room['players'] as $k => $p) {
        if ($p['id'] === $uid) $pIdx = $k;
    }
    if ($pIdx === -1 || !empty($room['players'][$pIdx]['is_waiting']) || ($room['players'][$pIdx]['status'] ?? '') === 'played') exit;

    $hand = $room['players'][$pIdx]['hand'];
    $played = [];
    $newHand = [];
    foreach ($hand as $c) {
        if (in_array($c['id'], $ids)) $played[] = $c;
        else $newHand[] = $c;
    }

    // Maintain selection order
    $ordered = [];
    foreach ($ids as $id) {
        foreach ($played as $c) {
            if ($c['id'] == $id) $ordered[] = $c;
        }
    }

    $room['players'][$pIdx]['hand'] = $newHand;
    $room['players'][$pIdx]['status'] = 'played';
    $room['table_cards'][] = ['player_id' => $uid, 'cards' => $ordered];

    // Check all active players played (ignore AFK humans)
    $all = true;
    foreach ($room['players'] as $p) {
        if (isActivePlayer($p, $room) && ($p['status'] ?? '') !== 'played') {
            $all = false;
            break;
        }
    }

    if ($all) {
        transitionToVoting($room);
    }
    saveRoom($roomId, $room);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'vote') {
    $roomId = $_POST['room_id'];
    $idx = $_POST['winner_index'];
    $uid = $_SESSION['user_id'];
    $room = loadRoom($roomId);

    if (isset($room['votes'][$uid])) exit; // Already voted

    // Check if player is waiting for next round
    foreach ($room['players'] as $p) {
        if ($p['id'] === $uid && !empty($p['is_waiting'])) exit;
    }
    $targetOwner = $room['table_cards'][$idx]['player_id'];
    $isTie = $room['is_tie_breaker'];
    $allowSelf = $room['config']['self_vote'] ?? false;

    if (!$allowSelf && $targetOwner === $uid && !$isTie) {
        echo json_encode(['error' => 'No self-voting']);
        exit;
    }

    $room['votes'][$uid] = $idx;

    // Ensure all bots have voted
    makeBotsVote($room);

    // Count active players (non-AFK, humans + bots) for vote threshold
    $totalVoters = 0;
    foreach ($room['players'] as $p) {
        if (!($p['afk'] ?? false) && empty($p['is_waiting'])) $totalVoters++;
    }

    if (count($room['votes']) >= $totalVoters) {
        $counts = array_count_values($room['votes']);
        $max = -1;
        $winners = [];
        foreach ($counts as $i => $c) {
            if ($c > $max) {
                $max = $c;
                $winners = [$i];
            } elseif ($c === $max) $winners[] = $i;
        }

        if (count($winners) === 1) {
            $wIdx = $winners[0];
            $wId = $room['table_cards'][$wIdx]['player_id'];

            // Store Last Round Info
            $wName = 'Unknown';
            foreach ($room['players'] as $p) {
                if ($p['id'] === $wId) $wName = $p['name'];
            }

            $room['last_round_info'] = [
                'winner' => $wName,
                'winner_id' => $wId,
                'black' => $room['current_black_card']['text'],
                'white' => array_map(function ($c) {
                    return $c['text'];
                }, $room['table_cards'][$wIdx]['cards'])
            ];

            // AI Host Roast
            $playerScoresText = "";
            foreach ($room['players'] as $p) {
                $playerScoresText .= "- " . $p['name'] . ": " . $p['score'] . " points\n";
            }
            $whitesText = implode(" / ", array_map(function($c) { return $c['text']; }, $room['table_cards'][$wIdx]['cards']));
            $sentence = str_replace('______', $whitesText, $room['current_black_card']['text']);
            $roast = "The winner is {$wName}! Their answer was: {$sentence}";
            if ($roast) {
                if (!isset($room['chat'])) $room['chat'] = [];
                $room['chat'][] = [
                    'player_id' => 'host',
                    'name' => 'Host (AI)',
                    'msg' => $roast,
                    'ts' => time(),
                    'type' => 'host_comment'
                ];
            }
            
            // Trigger bot reveal comments (gloating/complaining)
            triggerAIRevealComments($room, $wId, $wIdx);
            // Append to server-side history
            if (!isset($room['history']) || !is_array($room['history'])) $room['history'] = [];
            $winnerScoreAfter = null;
            foreach ($room['players'] as $pp) {
                if ($pp['id'] === $wId) {
                    $winnerScoreAfter = ($pp['score'] ?? 0) + 1;
                    break;
                }
            }
            $room['history'][] = [
                'ts' => time(),
                'round' => ($room['round'] ?? 1),
                'winner' => $wName,
                'winner_id' => $wId,
                'black' => $room['current_black_card']['text'],
                'white' => array_map(function ($c) {
                    return $c['text'];
                }, $room['table_cards'][$wIdx]['cards']),
                'score' => $winnerScoreAfter
            ];
            $room['round'] = ($room['round'] ?? 1) + 1;

            foreach ($room['players'] as &$p) {
                // DO NOT SAVE SCORES FOR BOTS (Visual score in game is fine, but persisted stats logic would check is_bot)
                if ($p['id'] === $wId) $p['score']++;
                $p['status'] = 'thinking';
            }

            // Clear tie-breaker flag since we have a winner
            $room['is_tie_breaker'] = false;

            // Check for game end (win limit)
            $winLimit = intval($room['config']['win_limit'] ?? 0);
            $winnerPlayer = null;
            foreach ($room['players'] as $pp) {
                if ($pp['id'] === $wId) {
                    $winnerPlayer = $pp;
                    break;
                }
            }
            if ($winLimit > 0 && $winnerPlayer && intval($winnerPlayer['score']) >= $winLimit) {
                $room['state'] = 'finished';
                $room['winner_id'] = $winnerPlayer['id'];
                $room['winner_name'] = $winnerPlayer['name'];
                $room['final_round_info'] = $room['last_round_info'] ?? null;
                $room['votes'] = [];
                $room['table_cards'] = [];
            } else {
                // Show round end screen - players must click Continue or auto-advance after 45s
                $room['state'] = 'round_end';
                $room['round_end_time'] = time();
                $room['continue_clicks'] = []; // Track who clicked Continue
            }
        } else {
            // Tie Breaker
            $newTable = [];
            foreach ($winners as $i) $newTable[] = $room['table_cards'][$i];
            $room['table_cards'] = $newTable;
            $room['votes'] = [];
            $room['is_tie_breaker'] = true;
            $room['round_start_time'] = time(); // Reset timer for tie breaker
        }
    }
    saveRoom($roomId, $room);
    echo json_encode(['success' => true]);
    exit;
}

// Flag/Delete a card by text (admin or host)
if ($action === 'flag_card') {
    $roomId = $_POST['room_id'] ?? '';
    $text = trim($_POST['text'] ?? '');
    $type = $_POST['type'] ?? 'white'; // 'white' or 'black'
    if ($text === '') {
        echo json_encode(['error' => 'Missing text']);
        exit;
    }
    $list = file_exists($BANNED_FILE) ? (json_decode(file_get_contents($BANNED_FILE), true) ?: []) : [];
    $list[] = ['text' => $text, 'type' => $type, 'ts' => time()];
    if (!is_dir(dirname($BANNED_FILE))) mkdir(dirname($BANNED_FILE), 0777, true);
    file_put_contents($BANNED_FILE, json_encode($list, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
    exit;
}

// Player clicks Continue after round ends
if ($action === 'continue_round') {
    $roomId = $_POST['room_id'] ?? '';
    $room = loadRoom($roomId);
    if (!$room || $room['state'] !== 'round_end') {
        echo json_encode(['error' => 'Not in round_end state']);
        exit;
    }

    $uid = $_SESSION['user_id'] ?? '';
    if (!isset($room['continue_clicks'])) $room['continue_clicks'] = [];
    $room['continue_clicks'][$uid] = true;

    saveRoom($roomId, $room);
    echo json_encode(['success' => true]);
    exit;
}

// Toggle pause (host only)
if ($action === 'toggle_pause') {
    $roomId = $_POST['room_id'] ?? '';
    $room = loadRoom($roomId);
    if (!$room) {
        echo json_encode(['error' => 'Room not found']);
        exit;
    }
    $uid = $_SESSION['user_id'] ?? '';
    if ($uid !== ($room['host_id'] ?? '')) {
        echo json_encode(['error' => 'Only host can pause']);
        exit;
    }
    $val = isset($_POST['paused']) ? (($_POST['paused'] === '1') ? true : false) : !($room['paused'] ?? false);

    if ($val && !($room['paused'] ?? false)) {
        // Pausing now
        $room['paused'] = true;
        $room['paused_at'] = time();

        // Store who paused it
        $pauser = 'Someone';
        foreach ($room['players'] as $p) {
            if ($p['id'] === $uid) {
                $pauser = $p['name'];
                break;
            }
        }
        $room['paused_by'] = $pauser;
    } elseif (!$val && ($room['paused'] ?? false)) {
        // Unpausing now
        $pauseDuration = time() - intval($room['paused_at'] ?? time());
        if (isset($room['round_start_time'])) {
            $room['round_start_time'] += $pauseDuration;
        }
        if (isset($room['created_at'])) {
            $room['created_at'] += $pauseDuration; // For lobby timer
        }
        $room['paused'] = false;
        $room['paused_at'] = null;
        unset($room['paused_by']);
    }

    saveRoom($roomId, $room);
    echo json_encode(['success' => true, 'paused' => $val]);
    exit;
}

// cleanCardTextPHP is provided by deck_parser.php

/**
 * Pre-recorded WAV audio generation has been retired.
 * All voice is performed by the male or female existing voice in Chrome (Web Speech API).
 *
 * @param string $text
 * @param string $type
 */
// generateCardAudio retired — Web Speech API handles TTS client-side

// Add user cards to the Orange Deck (Bulk)
if ($action === 'add_user_cards') {
    $cards = [];

    // Check for JSON input (legacy or API usage)
    $input = json_decode(file_get_contents('php://input'), true);
    if (!empty($input['cards'])) {
        $cards = $input['cards'];
    }

    // Check for Form Data (Admin Panel)
    if (isset($_POST['black_cards'])) {
        $lines = explode("\n", $_POST['black_cards']);
        foreach ($lines as $line) {
            $line = cleanCardTextPHP($line, true);
            if ($line !== '') {
                $pick = substr_count($line, '______');
                if ($pick === 0) $pick = 1;
                $cards[] = ['text' => $line, 'type' => 'black', 'pick' => $pick];
            }
        }
    }
    if (isset($_POST['white_cards'])) {
        $lines = explode("\n", $_POST['white_cards']);
        foreach ($lines as $line) {
            $line = cleanCardTextPHP($line, false);
            if ($line !== '') {
                $cards[] = ['text' => $line, 'type' => 'white'];
            }
        }
    }

    if (empty($cards)) {
        echo json_encode(['error' => 'No cards provided']);
        exit;
    }

    $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
    $additions = file_exists($USER_ADDITIONS_FILE) ? (json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: []) : [];

    foreach ($cards as $c) {
        $type = $c['type'] ?? 'white';
        $pick = intval($c['pick'] ?? 0);

        // Auto-detect type if not specified (legacy logic)
        if (!isset($c['type'])) {
             $pick = intval($c['pick'] ?? 1);
             if ($pick > 0) $type = 'black';
        }

        $isBlack = ($type === 'black');
        $text = cleanCardTextPHP($c['text'] ?? '', $isBlack);
        if ($text === '') continue;

        if ($isBlack && $pick === 0) {
            $pick = substr_count($text, '______');
            if ($pick === 0) $pick = 1;
        }

        // Check duplicates
        $exists = false;
        foreach ($additions as $existing) {
            if (strcasecmp($existing['text'], $text) === 0) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $additions[] = [
                'text' => $text,
                'type' => $type,
                'pick' => $pick,
                'ts' => time()
            ];
        }
    }

    if (!is_dir(dirname($USER_ADDITIONS_FILE))) mkdir(dirname($USER_ADDITIONS_FILE), 0777, true);
    file_put_contents($USER_ADDITIONS_FILE, json_encode($additions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    echo json_encode(['success' => true, 'count' => count($additions)]);
    exit;
}

// Search all cards by keyword (Admin)
if ($action === 'search_cards') {
    $q = trim($_GET['q'] ?? $_POST['q'] ?? '');
    if ($q === '') {
        echo json_encode(['success' => true, 'cards' => []]);
        exit;
    }

    $parsed = parseDecks();
    $results = [];

    // Search black cards
    foreach ($parsed['black'] as $c) {
        if (stripos($c['text'], $q) !== false) {
            $results[] = [
                'text' => $c['text'],
                'type' => 'black',
                'deck' => $c['deck'] ?? 'base_deck',
                'original_text' => $c['original_text'] ?? $c['text']
            ];
        }
    }

    // Search white cards
    foreach ($parsed['white'] as $c) {
        if (stripos($c['text'], $q) !== false) {
            $results[] = [
                'text' => $c['text'],
                'type' => 'white',
                'deck' => $c['deck'] ?? 'base_deck',
                'original_text' => $c['original_text'] ?? $c['text']
            ];
        }
    }

    // Search Custom Cards (User Additions)
    $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
    if (file_exists($USER_ADDITIONS_FILE)) {
        $userAdditions = json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: [];
        foreach ($userAdditions as $c) {
            if (stripos($c['text'], $q) !== false) {
                $results[] = [
                    'text' => $c['text'],
                    'type' => $c['type'] ?? 'white',
                    'deck' => 'orange_deck',
                    'original_text' => $c['original_text'] ?? $c['text']
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'cards' => $results]);
    exit;
}

// Get cards for a specific deck (Admin Inspector)
if ($action === 'get_deck_cards') {
    $deck = $_GET['deck'] ?? '';
    $parsed = parseDecks();

    // Also load user additions
    $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
    if (file_exists($USER_ADDITIONS_FILE)) {
        $userAdditions = json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: [];
        foreach ($userAdditions as $card) {
            if (($card['type'] ?? 'white') === 'black') {
                $parsed['black'][] = [
                    'text' => $card['text'],
                    'pick' => $card['pick'] ?? 1,
                    'id' => uniqid('b_usr_'),
                    'deck' => 'orange_deck',
                    'is_user' => true,
                    'original_text' => $card['text'] // For deletion matching
                ];
            } else {
                $parsed['white'][] = [
                    'text' => $card['text'],
                    'id' => uniqid('w_usr_'),
                    'deck' => 'orange_deck',
                    'is_user' => true,
                    'original_text' => $card['text']
                ];
            }
        }
    }

    $cards = [];
    if ($deck) {
        $deck = strtolower($deck);
        foreach ($parsed['black'] as $c) {
            if ($c['deck'] === $deck) {
                $c['type'] = 'black';
                $cards[] = $c;
            }
        }
        foreach ($parsed['white'] as $c) {
            if ($c['deck'] === $deck) {
                $c['type'] = 'white';
                $cards[] = $c;
            }
        }
    } else {
        // Return all tags
        $tags = $parsed['tags'] ?? ['base_deck'];
        if (!in_array('orange_deck', $tags)) $tags[] = 'orange_deck';
        echo json_encode(['tags' => $tags]);
        exit;
    }

    echo json_encode(['cards' => $cards]);
    exit;
}

// Delete a card (Admin Inspector)
if ($action === 'delete_card') {
    $text = trim($_POST['text'] ?? '');
    $deck = $_POST['deck'] ?? '';

    if (!$text) {
        echo json_encode(['error' => 'Missing text']);
        exit;
    }

    if ($deck === 'orange_deck') {
        // Delete from user_additions.json
        $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
        if (file_exists($USER_ADDITIONS_FILE)) {
            $additions = json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: [];
            $before = count($additions);
            $additions = array_values(array_filter($additions, function($c) use ($text) {
                return strcasecmp($c['text'], $text) !== 0;
            }));

            if (count($additions) < $before) {
                file_put_contents($USER_ADDITIONS_FILE, json_encode($additions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                echo json_encode(['success' => true]);
                exit;
            }
        }
    } else {
        // For standard decks, we add to banned_cards.json (flag_card logic)
        // But user asked to "delete" them.
        // Since decks.md is a single file, editing it is risky but possible.
        // However, `flag_card` is safer and effectively removes it from the game.
        // I'll use the banned list approach but call it "deleted".

        $BANNED_FILE = __DIR__ . '/data/banned_cards.json';
        $list = file_exists($BANNED_FILE) ? (json_decode(file_get_contents($BANNED_FILE), true) ?: []) : [];

        // Check if already banned
        $found = false;
        foreach ($list as $b) {
            if (strcasecmp($b['text'], $text) === 0) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $list[] = ['text' => $text, 'type' => 'unknown', 'ts' => time(), 'deck' => $deck];
            if (!is_dir(dirname($BANNED_FILE))) mkdir(dirname($BANNED_FILE), 0777, true);
            file_put_contents($BANNED_FILE, json_encode($list, JSON_PRETTY_PRINT));
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Card not found']);
    exit;
}

// Edit a card (Admin Inspector)
if ($action === 'edit_card') {
    $deck = $_POST['deck'] ?? '';
    $type = $_POST['type'] ?? 'white';
    $old_text = trim($_POST['old_text'] ?? '');
    $new_text = trim($_POST['new_text'] ?? '');

    if (!$old_text || !$new_text || !$deck) {
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

// generateCardAudioAPI retired — Web Speech API handles TTS client-side

    if ($deck === 'orange_deck') {
        // Edit in user_additions.json
        $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
        if (file_exists($USER_ADDITIONS_FILE)) {
            $additions = json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: [];
            $found = false;
            foreach ($additions as &$card) {
                if (strcasecmp($card['text'], $old_text) === 0) {
                    $card['text'] = cleanCardTextPHP($new_text, ($type === 'black'));
                    $card['pick'] = ($type === 'black') ? max(1, substr_count($card['text'], '______')) : 0;
                    $found = true;
                    break;
                }
            }
            if ($found) {
                file_put_contents($USER_ADDITIONS_FILE, json_encode($additions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                echo json_encode(['success' => true]);
                exit;
            }
        }
    } else {
        // Standard decks: write to edited_cards.json
        $EDITED_FILE = __DIR__ . '/data/edited_cards.json';
        $edited = file_exists($EDITED_FILE) ? (json_decode(file_get_contents($EDITED_FILE), true) ?: []) : [];
        
        $key = trim(strtolower($old_text));
        $edited[$key] = cleanCardTextPHP($new_text, ($type === 'black'));
        
        if (!is_dir(dirname($EDITED_FILE))) mkdir(dirname($EDITED_FILE), 0777, true);
        file_put_contents($EDITED_FILE, json_encode($edited, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Card not found']);
    exit;
}

// Add bot player to room
if ($action === 'add_bot') {
    $roomId = $_POST['room_id'] ?? '';
    $room = loadRoom($roomId);
    if (!$room) {
        echo json_encode(['error' => 'Room not found']);
        exit;
    }
    if ($room['state'] !== 'lobby') {
        echo json_encode(['error' => 'Can only add bots in lobby']);
        exit;
    }

    $isAi = ($_POST['is_ai'] ?? 'false') === 'true' || ($_POST['is_ai'] ?? '') === '1';

    // Count existing bots to generate name
    $botCount = 0;
    foreach ($room['players'] as $p) {
        if ($p['is_bot'] ?? false) $botCount++;
    }

    // Use theme character name if available, otherwise fallback to preset list
    $botName = nextThemeCharacterName($room);
    if (!$botName) {
        // Fallback list of 80 fake names (same as client-side presetNames)
        $fallbackNames = getFallbackBotNames();
        // Pick random name not currently in use
        $usedNames = array_map(function($p) { return $p['name']; }, $room['players']);
        $avail = array_diff($fallbackNames, $usedNames);
        if (empty($avail)) $avail = $fallbackNames; // Recycle if full
        $botName = $avail[array_rand($avail)];
    }

    // Generate unique bot ID
    $botId = 'bot_' . uniqid();

    // Create bot player
    $botPlayer = [
        'id' => $botId,
        'name' => $botName,
        'avatar_type' => 'dicebear',
        'avatar_val' => 'bottts:seed' . $botCount,
        'hand' => [],
        'score' => 0,
        'status' => 'ready',
        'is_bot' => true,
        'is_ai' => $isAi
    ];

    $room['players'][] = $botPlayer;

    if (!isset($room['chat'])) $room['chat'] = [];
    $room['chat'][] = [
        'player_id' => 'system',
        'name' => 'System',
        'msg' => "$botName " . ($isAi ? "(AI Bot)" : "(Bot)") . " has joined the lobby.",
        'ts' => time(),
        'type' => 'join'
    ];

    if ($isAi) {
        $room['chat'][] = [
            'player_id' => 'host',
            'name' => 'Host (AI)',
            'msg' => "The challenge level has increased because I'll be playing the game instead of just being a mindless host.",
            'ts' => time() + 1,
            'type' => 'host_comment'
        ];
    }

    saveRoom($roomId, $room);
    echo json_encode(['success' => true]);
    exit;
}

// Player leaves room; optional bot replacement OR room deletion if host leaves
if ($action === 'leave_room') {
    $roomId = $_POST['room_id'] ?? '';
    $room = loadRoom($roomId);
    if (!$room) {
        echo json_encode(['error' => 'Room not found']);
        exit;
    }
    $uid = $_SESSION['user_id'] ?? '';

    // Clear current_room_id from session
    unset($_SESSION['current_room_id']);

    // Check if leaving player is the host
    $isHost = ($room['host_id'] ?? '') === $uid;

    // Also check if player has is_host flag
    foreach ($room['players'] as $p) {
        if (($p['id'] ?? '') === $uid && !empty($p['is_host'])) {
            $isHost = true;
            break;
        }
    }

    // If host leaves, delete the entire room
    if ($isHost) {
        @unlink(getRoomFile($roomId));
        echo json_encode(['success' => true, 'room_deleted' => true]);
        exit;
    }

    // Get player name before removing
    $leavingPlayerName = 'Player';
    foreach ($room['players'] as $p) {
        if ($p['id'] === $uid) {
            $leavingPlayerName = $p['name'] ?? 'Player';
            break;
        }
    }

    // Remove player
    $room['players'] = array_values(array_filter($room['players'], function ($p) use ($uid) {
        return $p['id'] !== $uid;
    }));

    // Add leave announcement to chat
    if (!isset($room['chat'])) $room['chat'] = [];
    $room['chat'][] = [
        'player_id' => 'system',
        'name' => 'System',
        'msg' => "$leavingPlayerName has left the game",
        'ts' => time(),
        'type' => 'leave'
    ];

    // Clean votes and table entries
    if (isset($room['votes'][$uid])) unset($room['votes'][$uid]);
    $room['table_cards'] = array_values(array_filter($room['table_cards'], function ($t) use ($uid) {
        return ($t['player_id'] ?? null) !== $uid;
    }));

    // If no human players left, delete the room
    $hasHumans = false;
    foreach ($room['players'] as $p) {
        if (!($p['is_bot'] ?? false)) {
            $hasHumans = true;
            break;
        }
    }

    if (!$hasHumans || count($room['players']) === 0) {
        @unlink(getRoomFile($roomId));
        echo json_encode(['success' => true, 'room_deleted' => true]);
        exit;
    }

    // Replace with bot (slightly dumber 😉)
    $botId = 'bot_leave_' . uniqid();
    $hand = [];
    $limit = $room['config']['hand_size'] ?? 7;
    while (count($hand) < $limit && !empty($room['white_deck'])) {
        $hand[] = array_shift($room['white_deck']);
    }
    $room['players'][] = [
        'id' => $botId,
        'name' => nextThemeCharacterName($room) ?: 'AutoBot',
        'score' => 0,
        'hand' => $hand,
        'status' => 'thinking',
        'is_bot' => true
    ];

    saveRoom($roomId, $room);
    echo json_encode(['success' => true]);
    exit;
}


// Host can kill a game (delete room file)
if ($action === 'kill_game') {
    $roomId = $_POST['room_id'] ?? '';
    $room = loadRoom($roomId);
    if (!$room) {
        echo json_encode(['error' => 'Room not found']);
        exit;
    }
    $uid = $_SESSION['user_id'] ?? '';
    if ($room['host_id'] !== $uid) {
        echo json_encode(['error' => 'Only host can kill game']);
        exit;
    }
    @unlink(getRoomFile($roomId));
    echo json_encode(['success' => true]);
    exit;
}

// Update room/game settings (host only)
if ($action === 'update_settings') {
    $roomId = $_POST['room_id'] ?? '';
    $room = loadRoom($roomId);
    if (!$room) {
        echo json_encode(['error' => 'Room not found']);
        exit;
    }
    $uid = $_SESSION['user_id'] ?? '';
    if ($room['host_id'] !== $uid) {
        echo json_encode(['error' => 'Only host can update settings']);
        exit;
    }

    $allowed = ['win_limit', 'timer', 'hand_size', 'self_vote', 'min_players', 'max_players', 'fill_bots', 'enable_tts', 'enable_chat', 'voice_gender'];
    foreach ($allowed as $k) {
        if (isset($_POST[$k])) {
            // Cast booleans and ints
            if (in_array($k, ['self_vote', 'fill_bots', 'enable_tts', 'enable_chat'])) $room['config'][$k] = $_POST[$k] === 'true' || $_POST[$k] === '1' || $_POST[$k] === 'on';
            else $room['config'][$k] = is_numeric($_POST[$k]) ? intval($_POST[$k]) : $_POST[$k];
        }
    }

    if (isset($_POST['decks'])) {
        $decksInput = $_POST['decks'];
        if (is_string($decksInput)) {
            $decoded = json_decode($decksInput, true);
            if (is_array($decoded)) {
                $decksInput = $decoded;
            } else {
                $decksInput = explode(',', $decksInput);
            }
        }
        if (is_array($decksInput)) {
            $room['config']['decks'] = array_map('strtolower', array_map('trim', $decksInput));

            // Rebuild active game deck lists if currently in lobby
            if ($room['state'] === 'lobby') {
                $parsed = parseDecksShared();
                $selectedTags = $room['config']['decks'];

                // Handle Orange Deck (User Additions)
                $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
                if (in_array('orange_deck', $selectedTags)) {
                    if (file_exists($USER_ADDITIONS_FILE)) {
                        $userAdditions = json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: [];
                        foreach ($userAdditions as $card) {
                            if (($card['type'] ?? 'white') === 'black') {
                                $parsed['black'][] = [
                                    'text' => $card['text'],
                                    'pick' => $card['pick'] ?? 1,
                                    'id' => uniqid('b_usr_'),
                                    'deck' => 'orange_deck'
                                ];
                            } else {
                                $parsed['white'][] = [
                                    'text' => $card['text'],
                                    'id' => uniqid('w_usr_'),
                                    'deck' => 'orange_deck'
                                ];
                            }
                        }
                    }
                }

                // Filter by selected deck tags
                $newDecks = [
                    'black' => array_values(array_filter($parsed['black'], function ($c) use ($selectedTags) {
                        return in_array($c['deck'], $selectedTags, true);
                    })),
                    'white' => array_values(array_filter($parsed['white'], function ($c) use ($selectedTags) {
                        return in_array($c['deck'], $selectedTags, true);
                    }))
                ];

                if (empty($newDecks['black']) || empty($newDecks['white'])) {
                    $newDecks['black'] = $newDecks['black'] ?: [['id' => uniqid('b_'), 'text' => '______.', 'pick' => 1, 'deck' => 'base_deck']];
                    $newDecks['white'] = $newDecks['white'] ?: [['id' => uniqid('w_'), 'text' => 'A mystery.', 'deck' => 'base_deck']];
                }

                $room['black_deck'] = distributedShuffle($newDecks['black']);
                $room['white_deck'] = distributedShuffle($newDecks['white']);
            }
        }
    }

    saveRoom($roomId, $room);
    $publicConfig = $room['config'];
    unset($publicConfig['ai_api_key'], $publicConfig['gemini_api_key'], $publicConfig['openai_api_key']);
    echo json_encode(['success' => true, 'config' => $publicConfig]);
    exit;
}

// Update profile for the current user (name/avatar, and optional mic preference)
if ($action === 'update_profile') {
    $roomId = $_POST['room_id'] ?? '';
    $room = $roomId ? loadRoom($roomId) : null;
    $uid = $_SESSION['user_id'] ?? '';

    // Accept explicit player_id as fallback when session user_id doesn't match room
    $explicitId = $_POST['player_id'] ?? '';
    if ($explicitId && $room) {
        foreach ($room['players'] as $p) {
            if ($p['id'] === $explicitId) { $uid = $explicitId; break; }
        }
    }

    $name = trim($_POST['name'] ?? '');
    $avatarType = $_POST['avatar_type'] ?? null;
    $avatarVal = $_POST['avatar_val'] ?? null;
    $micId = $_POST['mic_device_id'] ?? null;

    if ($name !== '') $_SESSION['user_name'] = htmlspecialchars($name);
    if ($avatarType) $_SESSION['user_avatar_type'] = $avatarType;
    if ($avatarVal) $_SESSION['user_avatar_val'] = $avatarVal;
    if ($micId !== null) $_SESSION['preferred_mic'] = $micId;

    if ($room) {
        foreach ($room['players'] as &$p) {
            if ($p['id'] === $uid) {
                if ($name !== '') $p['name'] = $_SESSION['user_name'];
                if ($avatarType) $p['avatar_type'] = $avatarType;
                if ($avatarVal) $p['avatar_val'] = $avatarVal;
                break;
            }
        }
        saveRoom($roomId, $room);
    }

    // Refresh persistent cookie for custom-named users so 30-day window resets
    $nameSource = $_SESSION['name_source'] ?? 'hand_entered';
    if ($nameSource !== 'random' && $uid) {
        $cookieName  = $_SESSION['user_name']        ?? '';
        $cookieAt    = $_SESSION['user_avatar_type'] ?? 'dicebear';
        $cookieAv    = $_SESSION['user_avatar_val']  ?? '';
        $payload = base64_encode(json_encode(['uid' => $uid, 'name' => $cookieName, 'at' => $cookieAt, 'av' => $cookieAv]));
        setcookie('against_profile', $payload, time() + (30 * 86400), '/', '', false, true);
    }

    echo json_encode(['success' => true]);
    exit;
}
// Play again action: resets scores, deals hands, and starts game immediately with same players & deck order
if ($action === 'play_again') {
    $roomId = $_POST['room_id'] ?? '';
    $room = loadRoom($roomId);
    if (!$room) {
        echo json_encode(['success' => false, 'error' => 'Room not found.']);
        exit;
    }

    $parsed = parseDecksShared();
    $selectedTags = $room['config']['decks'] ?? ($parsed['tags'] ?? ['base_deck']);
    
    $USER_ADDITIONS_FILE = __DIR__ . '/data/user_additions.json';
    if (in_array('orange_deck', $selectedTags)) {
        if (file_exists($USER_ADDITIONS_FILE)) {
            $userAdditions = json_decode(file_get_contents($USER_ADDITIONS_FILE), true) ?: [];
            foreach ($userAdditions as $card) {
                if (($card['type'] ?? 'white') === 'black') {
                    $parsed['black'][] = [
                        'text' => $card['text'],
                        'pick' => $card['pick'] ?? 1,
                        'id' => uniqid('b_usr_'),
                        'deck' => 'orange_deck'
                    ];
                } else {
                    $parsed['white'][] = [
                        'text' => $card['text'],
                        'id' => uniqid('w_usr_'),
                        'deck' => 'orange_deck'
                    ];
                }
            }
        }
    }

    $decks = [
        'black' => array_values(array_filter($parsed['black'], function ($c) use ($selectedTags) {
            return in_array($c['deck'], $selectedTags, true);
        })),
        'white' => array_values(array_filter($parsed['white'], function ($c) use ($selectedTags) {
            return in_array($c['deck'], $selectedTags, true);
        }))
    ];

    if (empty($decks['black']) || empty($decks['white'])) {
        $decks['black'] = $decks['black'] ?: [['id' => uniqid('b_'), 'text' => '______.', 'pick' => 1, 'deck' => 'base_deck']];
        $decks['white'] = $decks['white'] ?: [['id' => uniqid('w_'), 'text' => 'A mystery.', 'deck' => 'base_deck']];
    }

    // Keep original deck order - no shuffle for play_again with same players
    $room['black_deck'] = array_values($decks['black']);
    $room['white_deck'] = array_values($decks['white']);
    
    $limit = $room['config']['hand_size'] ?? 7;

    // Reset scores & deal hands directly
    foreach ($room['players'] as &$p) {
        $p['score'] = 0;
        $p['hand'] = [];
        while (count($p['hand']) < $limit && !empty($room['white_deck'])) {
            $p['hand'][] = array_shift($room['white_deck']);
        }
        $p['status'] = 'thinking';
        $p['is_waiting'] = false;
    }
    unset($p);

    $room['current_black_card'] = array_shift($room['black_deck']);
    $room['state'] = 'playing';
    $room['is_tie_breaker'] = false;
    $room['round'] = 1;
    $room['round_start_time'] = time();
    $room['votes'] = [];
    $room['table_cards'] = [];
    $room['updated_at'] = time();

    saveRoom($roomId, $room);
    echo json_encode(['success' => true]);
    exit;
}

// Return everyone to waiting room (lobby)
if ($action === 'return_to_lobby') {
    $roomId = $_POST['room_id'] ?? '';
    $room = loadRoom($roomId);
    if (!$room) {
        echo json_encode(['success' => false, 'error' => 'Room not found.']);
        exit;
    }

    $room['state'] = 'lobby';
    $room['is_tie_breaker'] = false;
    $room['round'] = 1;
    $room['votes'] = [];
    $room['table_cards'] = [];
    $room['current_black_card'] = null;
    $room['updated_at'] = time();

    foreach ($room['players'] as &$p) {
        $p['score'] = 0;
        $p['hand'] = [];
        $p['status'] = 'ready';
    }
    unset($p);

    saveRoom($roomId, $room);
    echo json_encode(['success' => true]);
    exit;
}
