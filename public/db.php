<?php
/**
 * Shared database connection helper.
 * Returns a PDO instance connected to the SQLite database.
 */
function get_db(): PDO {
    $db_path = __DIR__ . '/../db/nba.sqlite';

    if (!file_exists($db_path)) {
        die('Database not found. Run the data loader first: loader/.venv/bin/python loader/load_gamelog.py');
    }

    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}
