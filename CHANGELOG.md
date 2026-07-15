# Changelog - Cards Against Project Improvements
**Date:** June 18, 2026
**Version:** 3.01

## Overview
This release fixes issues with duplicate blank cards, adds deck validation during setup, and implements comprehensive syntax checking for deck imports.

## Issues Fixed

### 1. Duplicate Blank Black Cards in Decks
**Problem:** decks.md had blank lines mixed in with card entries, creating occasional duplicate blank cards
**Solution:** Removed stray blank line at line 38 in decks.md between valid black cards
**Files:** decks.md

### 2. No Warning for Insufficient Black Cards
**Problem:** New game setup didn't warn users if they selected decks with too few black cards for the game duration
**Solution:** Added JavaScript validation in setup.php that:
- Counts total black cards in selected decks
- Calculates minimum needed (2 per round)
- Shows warning dialog if insufficient
- Allows user to proceed or select different decks
**Files:** setup.php

### 3. No Deck Import Validation
**Problem:** Deck imports didn't check for proper syntax, allowing malformed decks to be imported
**Solution:** Added validateDeckImport() function that checks:
- Proper section headers (Black Cards, White Cards)
- All black cards have 6-character blanks (______)
- No white cards have blanks
- At least one card exists
- Detailed error messages for failures
**Files:** settings.php

## Technical Details

### setup.php Changes

#### Added Deck Card Counting (Lines 47-68)
```php
// Added orange deck card counting
$ORANGE_DECK_FILE = __DIR__ . '/data/user_additions.json';
$orangeDeckCards = ['black' => 0, 'white' => 0];
if (file_exists($ORANGE_DECK_FILE)) {
    // Count user-added cards
}
if ($orangeDeckCards['black'] > 0 || $orangeDeckCards['white'] > 0) {
    $deckCounts['orange_deck'] = $orangeDeckCards;
}
```

#### Added Deck Info to JavaScript (Lines 310-313)
```php
// Deck info for validation
window.DECKS_INFO = <?php echo json_encode($deckCounts, JSON_UNESCAPED_SLASHES); ?>;
```

#### Enhanced Game Creation (Lines 417-443)
- Validates at least one deck is selected
- Checks black card count against rounds to win
- Shows warning if: totalBlackCards < (winLimit * 2)
- User can override warning and continue
- Provides clear message about why warning appears

### settings.php Changes

#### New validateDeckImport() Function (Lines 70-158)
```
Input: Deck content text
Output: {
  valid: boolean,
  errors: array of error messages,
  blackCardCount: number,
  whiteCardCount: number
}
```

**Validation Checks:**
1. Parse lines and track current section (black/white)
2. For each card line:
   - Black cards: MUST have "______" (6 underscores)
   - White cards: MUST NOT have "______"
3. Check headers exist and cards exist for each header
4. Generate descriptive error messages with line numbers

#### Updated import_deck Action (Lines 597-638)
- Calls validateDeckImport() first
- Returns JSON error response if validation fails
- Proceeds with import only if valid
- Shows success message with card counts
- Maintains backward compatibility

## Testing Checklist

- [ ] Load setup.php - verify deck card counts display
- [ ] Select decks with few black cards, set high rounds - verify warning
- [ ] Select invalid deck import - verify error message
- [ ] Import properly formatted deck - verify success message
- [ ] Play game - verify no functionality broken
- [ ] Check decks.md for blank line at old line 38 - should be gone

## Performance Impact
- Zero: No database changes, no new queries
- Validation happens on import (one-time)
- Game setup validation happens client-side (JavaScript)

## Backward Compatibility
- All changes are additive
- No breaking changes to existing functionality
- Game files remain 100% compatible
- Existing games unaffected

## Future Improvements
- Add UI for viewing validation errors in detail
- Add duplicate card detection in import validation
- Add card count statistics dashboard
- Add automated backup of imported decks

## Deployment Notes

1. No special deployment steps required
2. Clear browser cache if JavaScript validation doesn't work
3. No server restart needed
4. Can be deployed immediately
5. Safe to roll back if needed
