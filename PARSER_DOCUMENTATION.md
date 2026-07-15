# How the Deck Parser Works

**File:** `deck_parser.php`

## What it Does

The parser reads `decks.md` and converts it into a structured data format used by the game. It handles:
1. Card text extraction and normalization
2. Separating black cards from white cards
3. Categorizing cards by deck/color tags
4. Counting underscores for card "pick" values
5. Removing banned cards

## When the Parser Runs

The parser is called whenever `deck_parser.php` is included/required. This happens in several places:

### 1. **Setup Page** (`setup.php`)
- **When:** User visits `/setup.php` (New Game screen)
- **What it does:** Parses decks to show card counts next to each deck
- **Code:** Line 36 includes `deck_parser.php`, line 45 calls `parseDecksShared()`
- **Purpose:** Display "50 cards • 25 black • 25 white" next to each deck option

### 2. **Settings Page** (`settings.php`)
- **When:** Admin visits settings page or performs deck operations
- **What it does:** Gets deck statistics for display and management
- **Code:** Line 647 includes `deck_parser.php`
- **Purpose:** Admin can see all decks and manage them

### 3. **API - Game Creation** (`api.php`)
- **When:** User clicks "Initialize Game" button
- **What it does:** Parses selected decks to populate the game's black/white card pools
- **Code:** Line 428 includes `deck_parser.php`, line 505 calls `parseDecks()`
- **Purpose:** Load the actual cards into the new game room

### 4. **API - Deck Stats** (`api.php`)
- **When:** Frontend requests deck statistics via `api.php?action=get_decks`
- **What it does:** Returns card count info for all decks
- **Code:** Line 439 calls `parseDecksShared()`

## How the Parser Works (Step by Step)

### Step 1: Read File
```php
$content = file_get_contents($DECK_FILE);
$lines = preg_split('/\R/', $content);  // Split into lines (handles all line endings)
```

### Step 2: Loop Through Lines
For each line in the file:

```php
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;  // Skip blank lines
    
    // Rest of processing...
}
```

### Step 3: Detect Section Headers
Recognizes different deck sections:
```
Examples that match:
- "Black Cards"
- "The Hot Box Black Cards List"
- "Base Deck White Cards List"
- "Red Box White Cards List"

Sets: $currentPack and $type (black or white)
```

### Step 4: Extract Color Tags
If a card has a color tag at the end, extract it:
```
"A blue card example. (Blue)"
  ↓
- Text: "A blue card example."
- Tag: "blue" → Categorizes into "blue_box" deck
```

Supported tags:
- **(Red)** → red_box
- **(Blue)** → blue_box
- **(Green)** → green_box
- **(New)** → new_box

### Step 5: Count Underscores (Black Cards Only)
For black cards, count how many underscores = how many white cards player must pick:
```
"This is ______ and ______."
  ↓
picks = 2 (player must select 2 white cards)
```

### Step 6: Check Banned Cards
If a card is in `banned_cards.json`, skip it

### Step 7: Return Structured Data
```php
return [
    'black' => [
        ['text' => '...', 'pick' => 1, 'id' => 'b_123', 'deck' => 'red_box'],
        ['text' => '...', 'pick' => 2, 'id' => 'b_124', 'deck' => 'new_box'],
    ],
    'white' => [
        ['text' => '...', 'id' => 'w_123', 'deck' => 'blue_box'],
        ['text' => '...', 'id' => 'w_124', 'deck' => 'green_box'],
    ],
    'tags' => ['base', 'red_box', 'blue_box', 'green_box', 'new_box']
]
```

## Color Tag System

The parser **automatically** separates cards by color tags into different "decks":

| Tag | Deck Name | Use Case |
|-----|-----------|----------|
| (Red) | red_box | User-curated red cards |
| (Blue) | blue_box | User-curated blue cards |
| (Green) | green_box | User-curated green cards |
| (New) | new_box | Recently added cards |
| None | base/hot_box/etc | Default deck name |

**Important:** The color tags are **metadata** - they don't affect card meaning, only organization. The parser automatically detects them and groups cards into separate selectable decks.

## Example: How decks.temp.txt Gets Parsed

**Input:**
```markdown
Red Box White Cards List
A card marked red. (Red)
A card marked blue. (Blue)

Green Box White Cards List  
A green card. (Green)
```

**Parser Processing:**
1. Reads header → Sets deck = "red_box"
2. Reads "A card marked red. (Red)"
   - Extracts tag: "red" → deckTag = "red_box" ✓
3. Reads "A card marked blue. (Blue)"
   - Extracts tag: "blue" → deckTag = "blue_box" (overrides section header)
4. Reads "A green card. (Green)"
   - Extracts tag: "green" → deckTag = "green_box"

**Output:**
```json
{
  "red_box": ["A card marked red."],
  "blue_box": ["A card marked blue."],
  "green_box": ["A green card."]
}
```

## Configuration Files Used

### `decks.md`
- Main deck file (required)
- Contains all cards in markdown format

### `data/imported_decks.md`
- Optional: User-imported decks
- Appended during setup import

### `data/deleted_decks.json`
- List of deck slugs to hide/exclude
- Parser skips these unless `includeDeleted = true`

### `data/banned_cards.json`
- List of cards to exclude from parsing
- Format: `[{"text": "banned card text"}, ...]`

## Performance Note

The parser is **fast** because it:
- Single-pass through file
- No database queries
- Text-based matching only
- Results cached in memory until next parse

It runs every time you need deck data, but it's designed to be quick enough that it doesn't impact user experience.

## Common Issues

### Cards Not Appearing
- Check if card text exactly matches a banned card
- Verify deck is selected in game setup
- Check that card's deck tag is enabled

### Wrong Deck Assignment
- Inline color tags override section headers
- Example: A card under "Red Box" section with "(Blue)" tag goes to blue_box
- Solution: Remove color tags from section headers or fix card tags

### Black Card with No Blanks
- Parser checks for `______` (6 underscores)
- If missing, it auto-adds one to the end
- Cards without blanks won't be playable as black cards

### Parser Includes Blank Lines
- All blank lines are explicitly skipped
- No blank cards should be created
- If happening, check for cards with only whitespace
