![Cards Against banner](assets/banner.webp)

# Cards Against

A free-to-play, fill-in-the-blank party card game for game night, parties, and streams.

🎮 **Play it live:** [netbound.ca/against](https://netbound.ca/against)

## What it is

The party game format you know, rebuilt with extras:

- **Everyone judges each round** — no rotating judge, all players vote every round
- **Dumb bots** — fill empty seats for solo play or testing
- **Original decks plus custom themes** — built-in original decks, theme packs, and the deck editor for your own creations (the official Cards Against Humanity decks are included legally under their CC BY-NC-SA license)
- **Voice-hosted** — a game-show voice host calls the action to add to the fun
- **Same room or around the globe** — play together in person or online

This project is not affiliated with or endorsed by the publishers of any commercial card game.

## How to play

1. Each round, a prompt card with a blank is revealed.
2. Every player (and any bots) picks the funniest answer card from their hand.
3. Everyone judges — all players vote on the winner each round.
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

## Beta testers welcome

The game is in active development and we'd love your help testing it. Play a few rounds at [netbound.ca/against](https://netbound.ca/against), and if something breaks or you have an idea, [open an issue](https://github.com/Orange-Jeff/cards-against/issues). All feedback welcome.

## License

[CC BY-NC-SA 2.0](https://creativecommons.org/licenses/by-nc-sa/2.0/) — free to share and remix non-commercially, with credit. See [LICENSE](LICENSE).
