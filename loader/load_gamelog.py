#!/usr/bin/env python3
"""
Fetch all NBA player game logs for the 2025-26 season and load into SQLite.

Usage:
    loader/.venv/bin/python loader/load_gamelog.py
"""

import sqlite3
import time
import os
from pathlib import Path

from nba_api.stats.endpoints import playergamelog, commonallplayers
from nba_api.stats.library.parameters import SeasonAll

DB_PATH = Path(__file__).resolve().parent.parent / "db" / "nba.sqlite"
SEASON = "2025-26"

# nba.com rate-limits aggressively; be polite
REQUEST_DELAY = 0.6  # seconds between API calls


def create_schema(conn: sqlite3.Connection):
    conn.executescript("""
        CREATE TABLE IF NOT EXISTS players (
            player_id INTEGER PRIMARY KEY,
            player_name TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS game_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player_id INTEGER NOT NULL,
            game_id TEXT NOT NULL,
            game_date TEXT NOT NULL,       -- YYYY-MM-DD
            matchup TEXT NOT NULL,         -- e.g. 'LAL vs. GSW'
            wl TEXT,                       -- W or L
            min INTEGER,
            pts INTEGER,
            reb INTEGER,
            ast INTEGER,
            stl INTEGER,
            blk INTEGER,
            tov INTEGER,
            fgm INTEGER,
            fga INTEGER,
            fg_pct REAL,
            fg3m INTEGER,
            fg3a INTEGER,
            fg3_pct REAL,
            ftm INTEGER,
            fta INTEGER,
            ft_pct REAL,
            plus_minus INTEGER,
            FOREIGN KEY (player_id) REFERENCES players(player_id),
            UNIQUE(player_id, game_id)
        );

        CREATE INDEX IF NOT EXISTS idx_game_logs_player_date
            ON game_logs(player_id, game_date);

        CREATE INDEX IF NOT EXISTS idx_game_logs_date
            ON game_logs(game_date);
    """)


def normalize_date(date_str: str) -> str:
    """Convert 'MAR 15, 2026' or 'Jan 02, 2026' to 'YYYY-MM-DD'."""
    from datetime import datetime
    for fmt in ("%b %d, %Y", "%B %d, %Y"):
        try:
            return datetime.strptime(date_str, fmt).strftime("%Y-%m-%d")
        except ValueError:
            continue
    return date_str  # fallback


def get_active_players() -> list[dict]:
    """Return list of players active in the current season."""
    print("Fetching player list...")
    all_players = commonallplayers.CommonAllPlayers(
        is_only_current_season=1,
        season=SEASON,
    )
    time.sleep(REQUEST_DELAY)
    df = all_players.get_data_frames()[0]
    # Filter to players who have actually played games
    active = df[df["ROSTERSTATUS"] == 1]
    return [
        {"player_id": int(row["PERSON_ID"]), "player_name": row["DISPLAY_FIRST_LAST"]}
        for _, row in active.iterrows()
    ]


def fetch_player_gamelog(player_id: int) -> list[dict]:
    """Fetch all game log rows for a single player in the current season."""
    log = playergamelog.PlayerGameLog(
        player_id=player_id,
        season=SEASON,
    )
    time.sleep(REQUEST_DELAY)
    df = log.get_data_frames()[0]
    rows = []
    for _, g in df.iterrows():
        rows.append({
            "player_id": player_id,
            "game_id": g["Game_ID"],
            "game_date": normalize_date(g["GAME_DATE"]),
            "matchup": g["MATCHUP"],
            "wl": g.get("WL"),
            "min": int(g["MIN"]) if g["MIN"] else 0,
            "pts": int(g["PTS"]),
            "reb": int(g["REB"]),
            "ast": int(g["AST"]),
            "stl": int(g["STL"]),
            "blk": int(g["BLK"]),
            "tov": int(g["TOV"]),
            "fgm": int(g["FGM"]),
            "fga": int(g["FGA"]),
            "fg_pct": float(g["FG_PCT"]) if g["FG_PCT"] else None,
            "fg3m": int(g["FG3M"]),
            "fg3a": int(g["FG3A"]),
            "fg3_pct": float(g["FG3_PCT"]) if g["FG3_PCT"] else None,
            "ftm": int(g["FTM"]),
            "fta": int(g["FTA"]),
            "ft_pct": float(g["FT_PCT"]) if g["FT_PCT"] else None,
            "plus_minus": int(g["PLUS_MINUS"]) if g["PLUS_MINUS"] else 0,
        })
    return rows


def insert_game_logs(conn: sqlite3.Connection, rows: list[dict]):
    conn.executemany("""
        INSERT OR IGNORE INTO game_logs
            (player_id, game_id, game_date, matchup, wl, min, pts, reb, ast,
             stl, blk, tov, fgm, fga, fg_pct, fg3m, fg3a, fg3_pct,
             ftm, fta, ft_pct, plus_minus)
        VALUES
            (:player_id, :game_id, :game_date, :matchup, :wl, :min, :pts, :reb, :ast,
             :stl, :blk, :tov, :fgm, :fga, :fg_pct, :fg3m, :fg3a, :fg3_pct,
             :ftm, :fta, :ft_pct, :plus_minus)
    """, rows)


def main():
    os.makedirs(DB_PATH.parent, exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    create_schema(conn)

    players = get_active_players()
    print(f"Found {len(players)} active players for {SEASON}")

    conn.executemany(
        "INSERT OR IGNORE INTO players (player_id, player_name) VALUES (:player_id, :player_name)",
        players,
    )
    conn.commit()

    total = len(players)
    for i, p in enumerate(players, 1):
        print(f"[{i}/{total}] {p['player_name']}...", end=" ", flush=True)
        try:
            rows = fetch_player_gamelog(p["player_id"])
            if rows:
                insert_game_logs(conn, rows)
                conn.commit()
                print(f"{len(rows)} games")
            else:
                print("no games")
        except Exception as e:
            print(f"ERROR: {e}")
            continue

    conn.close()
    print(f"\nDone. Database saved to {DB_PATH}")


if __name__ == "__main__":
    main()
