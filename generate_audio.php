<?php
/**
 * @file generate_audio.php
 * Audio generation script for Against game
 *
 * Note: Intelephense may show warnings about Google Cloud types.
 * These are resolved at runtime via composer autoload and are safe to ignore.
 */

// Version 1.4 - Remove test-mode limits, add batch processing with memory cleanup for ~2000 cards
// Generates pre-recorded audio segments and white cards using Google Cloud Text-to-Speech
// Organized by voice and content type for fast on-demand playback

// Autoload dependencies
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    echo "Warning: Composer dependencies not found. Google Cloud TTS will use REST API.\n";
}

/**
 * GenerationStateTracker handles daily request limit tracking
 */
class GenerationStateTracker
{
    private string $stateFile;
    private array $state;

    public function __construct(string $stateFile)
    {
        $this->stateFile = $stateFile;
        $this->loadState();
    }

    private function loadState(): void
    {
        if (file_exists($this->stateFile)) {
            $this->state = json_decode(file_get_contents($this->stateFile), true) ?: [];
        } else {
            $this->state = [];
        }

        $today = date('Y-m-d');
        if (($this->state['last_date'] ?? '') !== $today) {
            $this->state['last_date'] = $today;
            $this->state['today_request_count'] = 0;
        }
    }

    public function getTodayRequestCount(): int
    {
        return $this->state['today_request_count'] ?? 0;
    }

    public function incrementRequestCount(): void
    {
        $this->state['today_request_count'] = ($this->state['today_request_count'] ?? 0) + 1;
        file_put_contents($this->stateFile, json_encode($this->state, JSON_PRETTY_PRINT));
    }
}

/**
 * AudioGenerator class for generating pre-recorded voice audio
 */
class AudioGenerator
{
    /** @var TextToSpeechClient|null - Composer dependency when available */
    private $client = null;

    /** @var AudioConfig|null - Composer dependency when available */
    private $audioConfig = null;
    private array $voices;
    private string $baseDir;
    private bool $testMode;
    private string $apiKey = '';
    private string $provider = 'google'; // google or elevenlabs
    private string $elevenLabsVoiceId = '';
    private int $totalCharacters = 0;
    private int $batchSize = 50;        // Process cards in batches to manage memory
    private int $startIndex = 0;        // Resume from specific index if needed
    private int $stopIndex = 0;         // 0 = process all; set to limit (e.g., 20 for testing)
    private int $dailyLimit = 1000;
    private ?GenerationStateTracker $stateTracker = null;

    public function __construct(string $apiKey = '', string $baseDir = 'audio', array $voices = [], int $batchSize = 50, int $startIndex = 0, int $stopIndex = 0, string $provider = 'google', string $elevenLabsVoiceId = '', int $dailyLimit = 1000)
    {
        $this->baseDir = $baseDir;
        $this->testMode = empty($apiKey);
        $this->batchSize = $batchSize;
        $this->startIndex = $startIndex;
        $this->stopIndex = $stopIndex;
        $this->provider = $provider;
        $this->elevenLabsVoiceId = $elevenLabsVoiceId;
        $this->dailyLimit = $dailyLimit;

        $this->voices = $voices ?: [
            'male' => 'en-US-Neural2-D',
            'female' => 'en-US-Neural2-C'
        ];

        if ($this->testMode) {
            echo "Test mode: No API key provided. Will echo texts instead of generating audio.\n";
        } else {
            $this->apiKey = $apiKey;
            if ($this->dailyLimit > 0) {
                $this->stateTracker = new GenerationStateTracker(dirname($this->baseDir) . '/data/audio_generation_state.json');
            }
        }

        $this->createOutputDirectories();
    }

    /**
     * Initialize the Google Cloud Text-to-Speech client
     */
    private function initializeClient(): void
    {
        // No longer needed - using REST API with API key
    }

    private function createOutputDirectories(): void
    {
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0777, true);
        }
        foreach (['segments', 'whites'] as $type) {
            foreach (array_keys($this->voices) as $voiceName) {
                $dir = "{$this->baseDir}/{$type}/{$voiceName}";
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
            }
        }
    }

    public function parseDecks(string $filePath): ?array
    {
        require_once __DIR__ . '/deck_parser.php';
        $parsed = parseDecksShared();
        
        $blackTexts = array_map(function($c) { return $c['text']; }, $parsed['black']);
        $whiteTexts = array_map(function($c) { return $c['text']; }, $parsed['white']);
        
        echo "Total unique cards found: " . count($blackTexts) . " black, " . count($whiteTexts) . " white.\n";

        return [
            'black' => array_values(array_unique($blackTexts)),
            'white' => array_values(array_unique($whiteTexts))
        ];
    }


    public function generateSegmentsForBlackCard(string $card): void
    {
        if (empty($card)) return;

        // Split by 3 or more underscores
        $segments = preg_split('/_{3,}/', $card);
        $numSegments = count($segments);

        foreach ($segments as $i => $segment) {
            $originalSegment = trim($segment);
            if (empty($originalSegment)) continue;

            $followedByBlank = ($i < $numSegments - 1);
            $ttsText = $originalSegment;

            if ($followedByBlank) {
                // If it ends with a letter or digit, append a comma for mid-sentence inflection
                if (preg_match('/[a-zA-Z0-9]$/', $originalSegment)) {
                    $ttsText = $originalSegment . ',';
                }
            }

            $hash = md5($originalSegment);
            foreach ($this->voices as $voiceName => $voiceCode) {
                $filePath = "{$this->baseDir}/segments/{$voiceName}/{$hash}.mp3";
                $this->synthesizeAndSave($ttsText, $voiceName, $voiceCode, $filePath, "segment");
            }
        }
    }

    public function generateAudioForWhiteCard(string $card): void
    {
        $card = trim($card);
        if (empty($card)) return;

        $hash = md5($card);
        foreach ($this->voices as $voiceName => $voiceCode) {
            $filePath = "{$this->baseDir}/whites/{$voiceName}/{$hash}.mp3";
            $this->synthesizeAndSave($card, $voiceName, $voiceCode, $filePath, "white card");
        }
    }

    /**
     * Synthesize text to audio and save to file using REST API
     * @param string $text The text to synthesize
     * @param string $voiceName The voice name (male/female/british)
     * @param string $voiceCode The Google voice code or ElevenLabs voice ID
     * @param string $filePath The output file path
     * @param string $type The type of content (segment/white card/host_message)
     */
    public function synthesizeAndSave(string $text, string $voiceName, string $voiceCode, string $filePath, string $type): void
    {
        if (file_exists($filePath)) {
            return; // Skip existing files silently to speed up re-runs
        }

        if ($this->stateTracker && !$this->testMode && !empty($this->apiKey)) {
            if ($this->stateTracker->getTodayRequestCount() >= $this->dailyLimit) {
                echo "\n🛑 Daily API limit of {$this->dailyLimit} requests reached. Stopping generation.\n";
                exit(0);
            }
            $this->stateTracker->incrementRequestCount();
        }

        $this->totalCharacters += strlen($text);

        if ($this->testMode || empty($this->apiKey)) {
            echo "TEST MODE: Would generate {$type} audio: '{$text}' ({$voiceName}) -> {$filePath}\n";
            return;
        }

        if ($this->provider === 'elevenlabs') {
            $this->synthesizeWithElevenLabs($text, $voiceName, $voiceCode, $filePath, $type);
        } elseif ($this->provider === 'gemini') {
            $this->synthesizeWithGemini($text, $voiceName, $voiceCode, $filePath, $type);
        } else {
            $this->synthesizeWithGoogle($text, $voiceName, $voiceCode, $filePath, $type);
        }
    }

    /**
     * Synthesize using Gemini (Generative Language API) Multimodal Audio
     */
    private function synthesizeWithGemini(string $text, string $voiceName, string $voiceCode, string $filePath, string $type): void
    {
        $model = 'gemini-2.5-flash-preview-tts';
        $api = 'v1beta';
        $url = "https://generativelanguage.googleapis.com/{$api}/models/{$model}:generateContent?key={$this->apiKey}";
        
        $maxRetries = 20;
        
        for ($retry = 1; $retry <= $maxRetries; $retry++) {
            $data = [
                'contents' => [['parts' => [['text' => "Please read the following text transcript out loud:\n" . $text]]]],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig' => [
                        'voiceConfig' => [
                            'prebuiltVoiceConfig' => ['voiceName' => $voiceCode ?: 'Aoede']
                        ]
                    ]
                ]
            ];

            $options = [
                'http' => [
                    'header' => "Content-Type: application/json\r\n",
                    'method' => 'POST',
                    'content' => json_encode($data),
                    'timeout' => 90,
                    'ignore_errors' => true
                ]
            ];

            $context = stream_context_create($options);
            $result = @file_get_contents($url, false, $context);

            if ($result) {
                $responseData = json_decode($result, true);
                $inline = $responseData['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;
                
                if ($inline) {
                    $pcmContent = base64_decode($inline);
                    $wavContent = $this->addWavHeader($pcmContent);
                    file_put_contents($filePath, $wavContent);
                    echo "Generated {$type} audio: {$text} (Gemini: {$model})\n";
                    
                    // Wait to avoid rate limits (8 seconds between successful calls)
                    sleep(8); 
                    return;
                } elseif (isset($responseData['error'])) {
                    $code = $responseData['error']['code'] ?? 0;
                    $msg = $responseData['error']['message'] ?? 'Unknown Gemini error';
                    echo "⚠️  Gemini Error ({$model}): [{$code}] {$msg}\n";
                    
                    if ($code === 429) {
                        echo "⏳ Rate limited. Backing off for 65s (Attempt {$retry} of {$maxRetries})...\n";
                        $secondsToSleep = 65;
                        $startTime = microtime(true);
                        while ($secondsToSleep > 0) {
                            $secondsToSleep = sleep($secondsToSleep);
                        }
                        $elapsed = round(microtime(true) - $startTime, 2);
                        echo "  [Debug] Slept for {$elapsed}s\n";
                        continue;
                    }
                } else {
                    echo "⚠️  Gemini Error ({$model}): Response missing audio data\n";
                }
            }
            
            echo "⏳ Error or empty response. Retrying in 10s (Attempt {$retry} of {$maxRetries})...\n";
            sleep(10);
        }
        
        echo "❌ FAILED: All retries exhausted for '{$text}'\n";
    }

    /**
     * Add WAV header to raw PCM data
     */
    private function addWavHeader(string $pcmData, int $sampleRate = 24000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
        $blockAlign = $channels * ($bitsPerSample / 8);
        $dataSize = strlen($pcmData);
        $chunkSize = 36 + $dataSize;

        $header = 'RIFF';
        $header .= pack('V', $chunkSize);
        $header .= 'WAVEfmt ';
        $header .= pack('V', 16);
        $header .= pack('v', 1);
        $header .= pack('v', $channels);
        $header .= pack('V', $sampleRate);
        $header .= pack('V', $byteRate);
        $header .= pack('v', $blockAlign);
        $header .= pack('v', $bitsPerSample);
        $header .= 'data';
        $header .= pack('V', $dataSize);

        return $header . $pcmData;
    }

    /**
     * Synthesize using Google Cloud Text-to-Speech REST API
     */
    private function synthesizeWithGoogle(string $text, string $voiceName, string $voiceCode, string $filePath, string $type): void
    {
        // Prepare REST API request
        $url = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . $this->apiKey;

        $data = [
            'input' => ['text' => $text],
            'voice' => [
                'languageCode' => explode('-', $voiceCode)[0] . '-' . explode('-', $voiceCode)[1],
                'name' => $voiceCode
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'speakingRate' => 1.0,
                'pitch' => 0.0
            ]
        ];

        $options = [
            'http' => [
                'header' => "Content-Type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result) {
            $responseData = json_decode($result, true);
            if (isset($responseData['audioContent'])) {
                $audioContent = base64_decode($responseData['audioContent']);
                file_put_contents($filePath, $audioContent);
                echo "Generated {$type} audio: {$text} ({$voiceName})\n";
            } else {
                echo "API Error: " . ($responseData['error']['message'] ?? 'Unknown error') . "\n";
            }
        } else {
            echo "Network Error generating '{$text}'\n";
        }
    }

    /**
     * Synthesize using ElevenLabs Text-to-Speech API
     */
    private function synthesizeWithElevenLabs(string $text, string $voiceName, string $voiceId, string $filePath, string $type): void
    {
        $url = "https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}";

        $data = [
            'text' => $text,
            'model_id' => 'eleven_monolingual_v1',
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.5
            ]
        ];

        $options = [
            'http' => [
                'header' => "Accept: audio/mpeg\r\n" .
                           "Content-Type: application/json\r\n" .
                           "xi-api-key: {$this->apiKey}\r\n",
                'method' => 'POST',
                'content' => json_encode($data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result !== false) {
            file_put_contents($filePath, $result);
            echo "Generated {$type} audio: {$text} (ElevenLabs)\n";
        } else {
            echo "ElevenLabs API Error generating '{$text}'\n";
        }
    }

    public function close(): void
    {
        // No client to close when using REST API
    }

    public function printCharacterCountSummary(): void
    {
        echo "\nTotal characters for billing: {$this->totalCharacters}\n";
        if ($this->totalCharacters > 1000000) {
            echo "WARNING: Exceeded 1M character free tier limit for Google Cloud Text-to-Speech.\n";
        } else {
            echo "Within free tier limit.\n";
        }
    }
}

// --- Main Execution (only run when executed directly) ---
if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
    echo "Starting audio generation process...\n";

// --- Configuration ---
// The script automatically checks for the GOOGLE_APPLICATION_CREDENTIALS environment variable.
// If not set, it runs in test mode.
$credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: '';
$decksFilePath = __DIR__ . '/decks.md';

// --- Initialization ---
$generator = new AudioGenerator($credentialsPath);
$decks = $generator->parseDecks($decksFilePath);

if ($decks) {
    // Test limits: Process a small subset of cards to avoid large API usage during testing.
    // Apply start/stop filters for batch processing (remove test slice)
    $blackCards = $decks['black'] ?? [];
    $whiteCards = $decks['white'] ?? [];

    // Optionally limit for testing
    if ($stopIndex > 0) {
        $blackCards = array_slice($blackCards, $startIndex, min($stopIndex - $startIndex, count($blackCards)));
        $whiteCards = array_slice($whiteCards, $startIndex, min($stopIndex - $startIndex, count($whiteCards)));
    }

    echo "\n--- Generating Black Card Segments (" . count($blackCards) . " cards) ---\n";
    $processedBlack = 0;
    foreach ($blackCards as $card) {
        $generator->generateSegmentsForBlackCard($card);
        $processedBlack++;
        if ($processedBlack % 50 === 0) {
            echo "Progress: $processedBlack/{" . count($blackCards) . "} black cards processed...\n";
            gc_collect_cycles(); // Free memory between batches
        }
    }

    echo "\n--- Generating White Card Audio (" . count($whiteCards) . " cards) ---\n";
    $processedWhite = 0;
    foreach ($whiteCards as $card) {
        $generator->generateAudioForWhiteCard($card);
        $processedWhite++;
        if ($processedWhite % 50 === 0) {
            echo "Progress: $processedWhite/{" . count($whiteCards) . "} white cards processed...\n";
            gc_collect_cycles(); // Free memory between batches
        }
    }
}

// --- Finalization ---
$generator->close();
$generator->printCharacterCountSummary();

echo "\nAudio generation complete.\n";
}
