# Sync Instructions - Cards Against Project Changes

**Date:** June 18, 2026
**Local Path:** e:\OrangeJeff\against
**Remote Path:** /home/netbound/against
**Remote Host:** frogstar.ca (user: netbound)

## Summary of Changes

### 1. Fixed Duplicate Blank Black Cards
**File:** `decks.md`
- **Line 38:** Removed blank line between black cards that was creating duplicate blank card entries
- **Status:** ✓ FIXED

### 2. Added Deck Card Count Validation
**File:** `setup.php`
- **Lines 47-68:** Added orange deck card counting logic to include user-created cards in validation
- **Lines 310-313:** Added window.DECKS_INFO variable to pass deck card counts to JavaScript
- **Lines 417-443:** Enhanced createGame() function with:
  - Deck selection validation
  - Black card count validation
  - Warning dialog if insufficient cards for game duration (minimum 2 black cards per round needed)
  - User can continue anyway or go back and select more decks
- **Status:** ✓ COMPLETE

### 3. Added Deck Import Syntax Validation
**File:** `settings.php`
- **Lines 70-158:** New validateDeckImport() function that checks:
  - Required section headers ("Black Cards" and "White Cards")
  - All black cards must have 6-underscore blanks (______)
  - All white cards must NOT have blanks
  - At least one card must be present
  - Returns detailed error messages for validation failures
- **Lines 597-638:** Updated import_deck action to:
  - Call validateDeckImport() before processing
  - Reject imports with validation errors
  - Display detailed error messages
  - Show success message with card count on success
- **Status:** ✓ COMPLETE

## Sync Instructions

### Option 1: Using rsync (Recommended)
```bash
# From Windows Command Prompt or PowerShell:
# Install rsync if needed, or use from Git Bash or WSL

rsync -avz --delete \
  --include="*.md" \
  --include="*.php" \
  --include="data/" \
  --exclude="vendor/" \
  --exclude="node_modules/" \
  "e:\OrangeJeff\against\" \
  netbound@frogstar.ca:/home/netbound/against/

# Or for Windows paths (adjust for your shell):
rsync -avz "e:/OrangeJeff/against/" netbound@frogstar.ca:/home/netbound/against/ \
  --exclude=vendor --exclude=node_modules --delete
```

### Option 2: Using scp (File by File)
```bash
# Copy modified files:
scp "e:\OrangeJeff\against\decks.md" netbound@frogstar.ca:/home/netbound/against/
scp "e:\OrangeJeff\against\setup.php" netbound@frogstar.ca:/home/netbound/against/
scp "e:\OrangeJeff\against\settings.php" netbound@frogstar.ca:/home/netbound/against/
```

### Option 3: Manual SSH + Upload
```bash
ssh netbound@frogstar.ca
cd /home/netbound/against

# Then upload files using your SFTP client
# Files to upload:
#   - decks.md
#   - setup.php  
#   - settings.php
```

### Option 4: Git Push (if using Git)
```bash
cd e:\OrangeJeff\against
git add decks.md setup.php settings.php
git commit -m "Fix: Remove duplicate blank cards, add deck validation, enhance import syntax checking"
git push origin main
```

## Verification Steps

After syncing, verify on remote server:

1. **Check decks.md is correct:**
   ```bash
   grep -n "I never truly understood" /home/netbound/against/decks.md
   grep -n "I'm going on a cleanse" /home/netbound/against/decks.md
   # Should show these lines are consecutive with no blank line between
   ```

2. **Test new game setup validation:**
   - Open Cards Against game setup page
   - Select decks
   - Verify deck card counts show (e.g., "100 cards • 50 black • 50 white")
   - Set rounds to win = 10
   - If fewer than 20 black cards, should see warning dialog

3. **Test deck import with validation:**
   - Go to admin settings
   - Try importing a deck without proper headers → should be rejected
   - Try importing with black cards missing blanks → should be rejected  
   - Try importing with white cards that have blanks → should be rejected
   - Try importing properly formatted deck → should succeed with card count

## Files Modified

| File | Changes | Lines |
|------|---------|-------|
| decks.md | Removed blank line at line 38 | 1 line removed |
| setup.php | Added deck validation logic | +100 lines |
| settings.php | Added validateDeckImport() function and updated import action | +160 lines |

## Rollback Plan

If issues occur, restore from backup:
```bash
# On remote server
git reset --hard HEAD~1
# Or restore from backup files
```

## Notes

- All changes are backward compatible
- No database migrations needed
- No new dependencies added
- Validation only adds helpful error messages
- Game functionality remains unchanged
