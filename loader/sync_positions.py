#!/usr/bin/env python3
"""
Fetch Fantrax player positions for the fantasy league and sync them into nba.sqlite.

Usage:
    python3 loader/sync_positions.py <FANTRAX_LEAGUE_ID> <DB_PATH>
"""

import sqlite3
import sys

import requests

from sync_fantasy import fuzzy_match, normalize_name

FANTRAX_URL = "https://www.fantrax.com/fxpa/req"
CATEGORIES = {"G", "F", "C", "G/F", "F/C"}
PAGE_SIZE = 500


def fetch_page(league_id: str, page: int) -> dict:
    resp = requests.post(
        FANTRAX_URL,
        params={"leagueId": league_id},
        json={"msgs": [{"method": "getPlayerStats", "data": {
            "leagueId": league_id,
            "statusOrTeamFilter": "ALL",
            "maxResultsPerPage": str(PAGE_SIZE),
            "pageNumber": str(page),
        }}]},
        timeout=30,
    )
    resp.raise_for_status()
    body = resp.json()
    if body.get("pageError"):
        raise RuntimeError(f"Fantrax error: {body['pageError'].get('code')}")
    return body["responses"][0]["data"]


def fetch_positions(league_id: str) -> dict[str, str]:
    """Return {player name: position category} for every player in the league's pool."""
    positions = {}
    has_team = set()
    page, total_pages = 1, 1
    # Fantrax repeats the last page past the end, so stop at totalNumPages
    while page <= total_pages:
        data = fetch_page(league_id, page)
        total_pages = data["paginatedResultSet"]["totalNumPages"]
        for row in data["statsTable"]:
            scorer = row["scorer"]
            position = scorer.get("posShortNames", "").replace(",", "/")
            if position not in CATEGORIES:
                continue
            name = scorer["name"]
            # On duplicate names, prefer the player currently on an NBA team
            on_team = scorer.get("teamShortName") not in (None, "", "(N/A)")
            if name in positions and (name in has_team or not on_team):
                continue
            positions[name] = position
            if on_team:
                has_team.add(name)
        page += 1
    return positions


def main():
    if len(sys.argv) != 3:
        print(f"Usage: {sys.argv[0]} <FANTRAX_LEAGUE_ID> <DB_PATH>", file=sys.stderr)
        sys.exit(1)

    league_id = sys.argv[1]
    db_path = sys.argv[2]

    print("Fetching Fantrax player positions...")
    positions = fetch_positions(league_id)
    print(f"  {len(positions)} Fantrax players")
    by_norm = {normalize_name(name): pos for name, pos in positions.items()}

    conn = sqlite3.connect(db_path)
    conn.execute("DROP TABLE IF EXISTS player_positions")
    conn.execute("CREATE TABLE player_positions ("
                 "player_id INTEGER PRIMARY KEY, "
                 "position TEXT NOT NULL)")

    nba_players = conn.execute("SELECT player_id, player_name FROM players").fetchall()
    print(f"\nMatching {len(nba_players)} NBA players to Fantrax positions...")

    matched = 0
    unmatched = []
    for player_id, player_name in nba_players:
        position = by_norm.get(normalize_name(player_name)) or fuzzy_match(player_name, positions)
        if position:
            conn.execute("INSERT INTO player_positions (player_id, position) VALUES (?, ?)",
                         (player_id, position))
            matched += 1
        else:
            unmatched.append(player_name)

    conn.commit()
    conn.close()

    print(f"Matched: {matched}/{len(nba_players)}")
    if unmatched:
        print(f"Unmatched ({len(unmatched)}): {', '.join(unmatched[:10])}")
        if len(unmatched) > 10:
            print(f"  ... and {len(unmatched) - 10} more")


if __name__ == "__main__":
    main()
