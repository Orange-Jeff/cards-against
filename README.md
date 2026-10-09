![Cards Against banner](assets/banner.webp)

# Cards Against

A free-to-play, fill-in-the-blank party card game for game night, parties, and streams. Built in the spirit of the classic black-and-white card game, with original cards and extra features.

🎮 **Play it live:** [netbound.ca/against](https://netbound.ca/against)

## What it is

The party game format you know, rebuilt with extras:

- **Themed decks** — pick your flavor, from family-friendly to delightfully unhinged
- **Deck editor** — build your own custom decks with your own cards
- **Game-show voice host** — an announcer that calls the game like it's primetime TV
- **Fill-in-the-blank rounds** — prompt cards with blanks, answer cards to complete them

All cards are original. This project is not affiliated with or endorsed by the publishers of any commercial card game.

## How to play

1. Each round, a prompt card with a blank is revealed.
2. Every player picks the funniest answer card from their hand.
3. The judge (rotates each round) picks the winner.
4. Most winning cards at the end takes the crown.

## Run it yourself

This is a PHP app. Drop it on any PHP-capable web host:

```bash
git clone https://github.com/Orange-Jeff/cards-against.git
```

- Requires PHP 7.4+ with standard extensions
- Point your web server at the project root
- Open `index.php` and start a game

See [PARSER_DOCUMENTATION.md](PARSER_DOCUMENTATION.md) for the deck file format if you want to build custom decks.

## Project layout

| File / Dir | What it does |
|---|---|
| `index.php` | Game entry point |
| `game.php` | Core game logic |
| `deck_parser.php` | Deck file parser |
| `chat.php` | In-game chat |
| `api.php` | API endpoints |
| `assets/` | Images, CSS, JS |
| `audio/` | Voice host clips |
| `data/` | Deck data files |

## License

[CC BY-NC-SA 2.0](https://creativecommons.org/licenses/by-nc-sa/2.0/) — free to share and remix non-commercially, with credit. See [LICENSE](LICENSE).

## Beta testers welcome

The game is in active development and we'd love your help testing it. Play a few rounds at [netbound.ca/against](https://netbound.ca/against), and if something breaks or you have an idea, [open an issue](https://github.com/Orange-Jeff/cards-against/issues). All feedback welcome.
