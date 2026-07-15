<?php
// deck_parser.php
// Version: 3.03 - Fix card edit lookup key mismatch with trailing periods

function get_deck_slug($name) {
    $name = preg_replace('/^the\s+/i', '', trim($name));
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
    return trim($slug, '_') ?: 'base_deck';
}

function get_deck_label($slug) {
    $labels = [
        'base_deck' => 'Base Deck',
        'hot_box' => 'The Hot Box',
        'dad_pack' => 'The Dad Pack',
        'geek_pack' => 'The Geek Pack',
        'weed_pack' => 'Weed Pack',
        '90s_nostalgia_pack' => '90s Nostalgia Pack',
        '2000s_nostalgia_pack' => '2000s Nostalgia Pack',
        'sci_fi_pack' => 'The Sci-Fi Pack',
        'green_box' => 'Green Box',
        'red_box' => 'Red Box',
        'blue_box' => 'Blue Box',
        'new_box' => 'New Box',
        'orange_deck' => 'Orange Deck (User Additions)'
    ];
    return $labels[$slug] ?? ucwords(str_replace('_', ' ', $slug));
}

function parseDecksShared($includeDeleted = false) {
    $DECK_FILE = __DIR__ . '/decks.md';
    $IMPORTED_FILE = __DIR__ . '/data/imported_decks.md';
    $BANNED_FILE = __DIR__ . '/data/banned_cards.json';
    $DELETED_FILE = __DIR__ . '/data/deleted_decks.json';
    $EDITED_FILE = __DIR__ . '/data/edited_cards.json';
    
    if (!file_exists($DECK_FILE) && !file_exists($IMPORTED_FILE)) {
        return ['black' => [], 'white' => [], 'tags' => ['base_deck']];
    }

    $lines = [];
    if (file_exists($DECK_FILE)) {
        $content = file_get_contents($DECK_FILE);
        $lines = array_merge($lines, preg_split('/\R/', $content));
    }
    if (file_exists($IMPORTED_FILE)) {
        $content = file_get_contents($IMPORTED_FILE);
        $lines[] = ''; // spacing
        $lines = array_merge($lines, preg_split('/\R/', $content));
    }

    $deletedDecks = [];
    if (!$includeDeleted && file_exists($DELETED_FILE)) {
        $deletedDecks = json_decode(file_get_contents($DELETED_FILE), true) ?: [];
    }

    $edited = [];
    if (file_exists($EDITED_FILE)) {
        $rawEdited = json_decode(file_get_contents($EDITED_FILE), true) ?: [];
        foreach ($rawEdited as $k => $v) {
            $edited[trim(strtolower($k))] = trim($v);
        }
    }

    $black = [];
    $white = [];
    $tags = ['base_deck'];
    $currentPack = 'base_deck';
    $type = null;
    
    $banned = file_exists($BANNED_FILE) ? (json_decode(file_get_contents($BANNED_FILE), true) ?: []) : [];
    $bannedTexts = array_map(function ($x) { return trim(strtolower($x['text'] ?? '')); }, $banned);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Strip backslashes from markdown formatting (e.g. \_\_\_\_\_\_ -> ______)
        $line = str_replace('\\', '', $line);

        // Normalize any sequence of 2 or more underscores to standard 6 underscores
        $line = preg_replace('/_{2,}/', '______', $line);

        // Header detection (e.g., The Sci-Fi Pack Black Cards List)
        if (preg_match('/^(?:The\s+)?(.+?)\s+(Black|White)\s+Cards(?:s)?(?: List)?\s*$/i', $line, $m)) {
            $packName = strtolower(trim($m[1]));
            if ($packName !== 'cards against humanity' && $packName !== 'more cards against humanity') {
                $currentPack = get_deck_slug($packName);
            } else {
                $currentPack = 'more_cards_against_humanity';
            }
            $type = strtolower(trim($m[2]));
            continue;
        }

        // Fallback header detection for standalone "White Cards List" or "Black Cards List"
        if (preg_match('/^\s*(Black|White)\s+Cards\s*(?:List)?\s*$/i', $line, $m)) {
            $type = strtolower(trim($m[1]));
            continue;
        }

        // Skip other subheaders
        if (stripos($line, 'Cards List') !== false) continue;

        $deckTag = $currentPack;

        // Extract inline tags like "(Red)", "(Blue)", etc. at the very end of the line
        $inlineTag = null;
        if (preg_match('/\s+\((red|blue|green|new)\)$/i', $line, $m)) {
            $inlineTag = strtolower(trim($m[1]));
            // Strip it from the card text
            $line = trim(preg_replace('/\s+\((?:red|blue|green|new)\)$/i', '', $line));
        }

        // Map inline tags or categorize them
        if ($inlineTag !== null) {
            if ($inlineTag === 'red') $deckTag = 'red_box';
            elseif ($inlineTag === 'blue') $deckTag = 'blue_box';
            elseif ($inlineTag === 'green') $deckTag = 'green_box';
            elseif ($inlineTag === 'new') $deckTag = 'new_box';
            else $deckTag = $inlineTag;
        } elseif ($currentPack === 'more_cards_against_humanity') {
            // Check specific untagged ones under More Cards Against Humanity
            $lineLower = strtolower($line);
            if (strpos($lineLower, '10 football players') !== false || 
                strpos($lineLower, 'resexualizing') !== false ||
                strpos($lineLower, 'offended on behalf') !== false) {
                $deckTag = 'green_box';
            } else {
                $deckTag = 'new_box';
            }
        }

        if (!$includeDeleted && in_array($deckTag, $deletedDecks, true)) {
            continue;
        }

        if (!in_array($deckTag, $tags, true)) {
            $tags[] = $deckTag;
        }

        // Check if there is an edit for this card text
        $trimmedLine = trim($line);
        $editKey = strtolower($trimmedLine);
        $editKeyNoPeriod = rtrim($editKey, '.');
        $originalLineText = $trimmedLine;
        if (isset($edited[$editKey])) {
            $line = $edited[$editKey];
        } elseif (isset($edited[$editKeyNoPeriod])) {
            $line = $edited[$editKeyNoPeriod];
        }

        if ($type === 'black') {
            $lc = strtolower($line);
            $isHaiku = (strpos($lc, 'make a haiku') !== false);
            $isInsteadOf = (strpos($lc, 'instead of') === 0);
            
            // Check if card has no blank; if so, append one at the end
            $hasBlank = (strpos($line, '______') !== false);
            if (!$hasBlank && !$isHaiku && !$isInsteadOf) {
                $line .= ' ______';
                $hasBlank = true;
            }

            if ($hasBlank && !$isHaiku && !$isInsteadOf && !in_array(trim($lc), $bannedTexts, true)) {
                $pick = max(1, substr_count($line, '______'));
                $black[] = [
                    'text' => $line,
                    'original_text' => $originalLineText,
                    'pick' => $pick,
                    'id' => uniqid('b_'),
                    'deck' => $deckTag
                ];
            }
        } elseif ($type === 'white') {
            if (!in_array(trim(strtolower($line)), $bannedTexts, true)) {
                $cleanLine = rtrim($line, '.');
                $white[] = [
                    'text' => $cleanLine,
                    'original_text' => $originalLineText,
                    'id' => uniqid('w_'),
                    'deck' => $deckTag
                ];
            }
        }
    }

    // Filter out 'more_cards_against_humanity' from tags if empty
    $tags = array_filter($tags, function($t) { return $t !== 'more_cards_against_humanity'; });

    return ['black' => $black, 'white' => $white, 'tags' => array_values(array_unique($tags))];
}
