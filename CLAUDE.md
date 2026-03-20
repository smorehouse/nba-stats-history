# nba-stats-history

## GitHub / SSH
When setting up git remotes, use `git@github-personal` instead of `git@github.com` to ensure the correct SSH key (`~/.ssh/id_ed25519_claude`) is used for this personal GitHub account.

Example:
```
git remote add origin git@github-personal:smorehouse/nba-stats-history.git
```

## Project Overview
NBA player stats history viewer. View any player's game-by-game performance over the last N days.

## Tech Stack
- **Data loader**: Python (`nba_api`) — fetches game logs and loads into SQLite
- **Database**: SQLite (`db/nba.sqlite`)
- **Web app**: PHP (vanilla, no framework)
- **Dev server**: `php -S localhost:8000 -t public/`

## Project Structure
```
db/              — SQLite database (gitignored)
loader/          — Python data loader scripts + venv
public/          — PHP web app (document root)
```

## Commands
- Start dev server: `./start.sh`
- Load data: `loader/.venv/bin/python loader/load_gamelog.py`