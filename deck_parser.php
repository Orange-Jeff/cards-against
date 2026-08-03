<?php
/**
 * Shared Deck Parser and Sanitizer for Cards Against Humanity
 */

if (!function_exists('cleanCardTextPHP')) {
    /**
     * Clean and format card text.
     * Enforces exactly 6 underscores (______) for blanks and strips trailing periods from listings.
     *
     * @param string $text
     * @param bool $isBlack
     * @return string
     */
    function cleanCardTextPHP(string $text, bool $isBlack): string {
        $cleaned = trim($text);
        if ($cleaned === '') return '';

        // Strip backslashes if present
        $cleaned = str_replace('\\', '', $cleaned);

        // Strip <i> and </i> tags
        $cleaned = preg_replace('/<\/?i\b[^>]*>/i', '', $cleaned);

        // Strip parenthesis characters ( and )
        $cleaned = str_replace(['(', ')'], '', $cleaned);

        if ($isBlack) {
            // Replace runs of dots/slashes/underscores representing blanks (e.g. ./././././. or .....) with standard 6 underscores
            $cleaned = preg_replace('/[._\/]{4,}/', '______', $cleaned);
            // Replace any run of 2 or more underscores with standard 6-underscore blank
            $cleaned = preg_replace('/_{2,}/', '______', $cleaned);
            // Strip remaining period characters
            $cleaned = str_replace('.', '', $cleaned);
            // Ensure single space before blank if preceded by word character
            $cleaned = preg_replace('/(\w)\s*______/', '$1 ______', $cleaned);
            // Ensure single space after blank if followed by word character
            $cleaned = preg_replace('/______\s*(\w)/', '______ $1', $cleaned);
            // Remove space before punctuation following blank
            $cleaned = preg_replace('/______\s+([,;:?!])/', '______$1', $cleaned);
            // Collapse multiple spaces
            $cleaned = preg_replace('/ {2,}/', ' ', $cleaned);
        } else {
            // White cards: strip all underscores and period characters
            $cleaned = preg_replace('/_+/', '', $cleaned);
            $cleaned = str_replace('.', '', $cleaned);
            // Collapse multiple spaces
            $cleaned = preg_replace('/ {2,}/', ' ', $cleaned);
            $cleaned = trim($cleaned);
        }
        return trim($cleaned);
    }
}

if (!function_exists('parseDeckHeaderLine')) {
    /**
     * Parse a line to detect if it is a Black/White card section header.
     * Supports various formats, typos (Lst, Cards Cards), markdown prefixes (#, ===), and trailing words.
     *
     * @param string $line
     * @return array{type: string, raw_pack: string, pack_slug: string}|null
     */
    function parseDeckHeaderLine(string $line): ?array {
        $trimmed = trim($line, " \t\n\r\0\x0B#=*-_[]()");
        if ($trimmed === '' || strlen($trimmed) > 120) return null;

        // Any header line should NOT contain underscore blanks
        if (strpos($trimmed, '__') !== false) return null;

        if (preg_match('/^(?:The\s+)?(.*?)\s*[-:\/(]*\s*(Black|White)\s*Cards?(?:\s+(?:Cards\s+)?(?:List|Lst|Pack|Deck)*)*[\)]*$/i', $trimmed, $m)) {
            $rawPack = trim($m[1], " \t#=*-_[]()");
            $type = strtolower($m[2]);

            // Exclude normal sentences ending in a period or question mark
            if ($rawPack !== '' && preg_match('/[.,?!]$/', $rawPack)) {
                return null;
            }

            if (strcasecmp($rawPack, 'Cards Against Humanity') === 0 || strcasecmp($rawPack, 'More Cards Against Humanity') === 0 || $rawPack === '') {
                $rawPack = 'Base Deck';
                $packSlug = 'base_deck';
            } else {
                $packSlug = strtolower(str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9 _-]/', '', $rawPack)));
                if ($packSlug === '') $packSlug = 'base_deck';
            }

            return [
                'type' => $type,
                'raw_pack' => $rawPack,
                'pack_slug' => $packSlug
            ];
        }

        return null;
    }
}

if (!function_exists('sanitizeDeckContent')) {
    /**
     * Sanitize full deck markdown content.
     *
     * @param string $content
     * @return string
     */
    function sanitizeDeckContent(string $content): string {
        $lines = preg_split('/\R/', $content);
        $cleanedLines = [];
        $currentType = 'black'; // Default section assumption

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $cleanedLines[] = '';
                continue;
            }

            // Detect section headers
            $hdr = parseDeckHeaderLine($trimmed);
            if ($hdr !== null) {
                $currentType = $hdr['type'];
                // Write out a standardized clean header
                $cleanedLines[] = ($hdr['raw_pack'] === 'Base Deck' ? '' : $hdr['raw_pack'] . ' ') . ucfirst($hdr['type']) . ' Cards';
                continue;
            }
            if (stripos($trimmed, 'Cards List') !== false) {
                $cleanedLines[] = $trimmed;
                continue;
            }

            $isBlack = ($currentType === 'black');
            $cleanedCard = cleanCardTextPHP($trimmed, $isBlack);

            if ($cleanedCard !== '') {
                // If it's a black card without any blank, append standard blank
                if ($isBlack && strpos($cleanedCard, '______') === false) {
                    $cleanedCard .= ' ______';
                    $cleanedCard = cleanCardTextPHP($cleanedCard, true);
                }
                $cleanedLines[] = $cleanedCard;
            }
        }

        return implode("\n", $cleanedLines);
    }
}

if (!function_exists('sanitizeDeckFile')) {
    /**
     * Sanitize a deck file in place.
     *
     * @param string $filepath
     * @return bool
     */
    function sanitizeDeckFile(string $filepath): bool {
        if (!file_exists($filepath)) return false;
        $content = file_get_contents($filepath);
        if ($content === false) return false;
        $sanitized = sanitizeDeckContent($content);
        return file_put_contents($filepath, $sanitized) !== false;
    }
}

if (!function_exists('parseDecksShared')) {
    /**
     * Parses decks from decks.md and data/imported_decks.md.
     *
     * @param bool $includeDeleted
     * @return array{black: array<int, array<string, mixed>>, white: array<int, array<string, mixed>>, tags: array<int, string>}
     */
    function parseDecksShared(bool $includeDeleted = false): array {
        $deckFiles = [
            __DIR__ . '/decks.md',
            __DIR__ . '/data/imported_decks.md'
        ];

        $black = [];
        $white = [];
        $tags = ['base_deck'];

        $bannedFile = __DIR__ . '/data/banned_cards.json';
        $bannedMap = [];
        if (!$includeDeleted && file_exists($bannedFile)) {
            $banned = json_decode(file_get_contents($bannedFile), true) ?: [];
            foreach ($banned as $b) {
                if (!empty($b['text'])) {
                    $bDeck = !empty($b['deck']) ? trim(strtolower($b['deck'])) : 'all';
                    $bText1 = trim(strtolower(cleanCardTextPHP($b['text'], true)));
                    $bText2 = trim(strtolower(cleanCardTextPHP($b['text'], false)));
                    $bannedMap[$bDeck . '::' . $bText1] = true;
                    $bannedMap[$bDeck . '::' . $bText2] = true;
                }
            }
        }

        $editedFile = __DIR__ . '/data/edited_cards.json';
        $editedMap = [];
        if (file_exists($editedFile)) {
            $editedMap = json_decode(file_get_contents($editedFile), true) ?: [];
        }

        foreach ($deckFiles as $file) {
            if (!file_exists($file)) continue;

            $content = file_get_contents($file);
            if (!$content) continue;

            $lines = preg_split('/\R/', $content);
            $type = 'black';
            $currentPack = 'base_deck';

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') continue;

                $hdr = parseDeckHeaderLine($trimmed);
                if ($hdr !== null) {
                    $currentPack = $hdr['pack_slug'];
                    $type = $hdr['type'];
                    if (!in_array($currentPack, $tags, true)) $tags[] = $currentPack;
                    continue;
                }

                if (stripos($trimmed, 'Cards List') !== false) continue;

                $deckTag = $currentPack;
                if (preg_match('/\(([^)]+)\)\s*$/', $trimmed, $m)) {
                    $inlineTag = strtolower(trim($m[1]));
                    $deckTag = str_replace(' ', '_', $inlineTag);
                    $trimmed = trim(preg_replace('/\s*\([^)]+\)\s*$/', '', $trimmed));
                }

                if (!in_array($deckTag, $tags, true)) $tags[] = $deckTag;

                $isBlack = ($type === 'black');
                $cleanText = cleanCardTextPHP($trimmed, $isBlack);
                if ($cleanText === '') continue;

                $normTextOriginal = trim(strtolower($cleanText));
                $normDeck = trim(strtolower($deckTag));

                // 1. Check if banned by original text
                if (!$includeDeleted && (isset($bannedMap['all::' . $normTextOriginal]) || isset($bannedMap[$normDeck . '::' . $normTextOriginal]))) {
                    continue; // Skip banned card
                }

                // 2. Apply edits if present
                if (isset($editedMap[$normTextOriginal])) {
                    $cleanText = $editedMap[$normTextOriginal];
                }

                $normText = trim(strtolower($cleanText));

                // 3. Check if banned by edited text
                if (!$includeDeleted && (isset($bannedMap['all::' . $normText]) || isset($bannedMap[$normDeck . '::' . $normText]))) {
                    continue; // Skip banned card
                }

                if ($isBlack) {
                    if (strpos($cleanText, '______') === false) {
                        $cleanText .= ' ______';
                    }
                    $pick = max(1, substr_count($cleanText, '______'));
                    $black[] = [
                        'text' => $cleanText,
                        'pick' => $pick,
                        'id' => uniqid('b_'),
                        'deck' => $deckTag
                    ];
                } else {
                    $white[] = [
                        'text' => $cleanText,
                        'id' => uniqid('w_'),
                        'deck' => $deckTag
                    ];
                }
            }
        }

        return [
            'black' => $black,
            'white' => $white,
            'tags' => array_values(array_unique($tags))
        ];
    }
}

if (!function_exists('get_deck_label')) {
    /**
     * Get a human-readable label for a deck tag.
     *
     * @param string $tag
     * @return string
     */
    function get_deck_label(string $tag): string {
        if ($tag === 'base_deck') return 'Base Deck (Original)';
        if ($tag === 'orange_deck') return 'Orange Deck (User Additions)';
        return ucwords(str_replace('_', ' ', $tag));
    }
}
