<?php
require_once __DIR__ . '/db.php';

$db = get_db();
$query = trim($_GET['q'] ?? '');
$players = [];

if ($query !== '') {
    $stmt = $db->prepare('SELECT player_id, player_name FROM players WHERE player_name LIKE :q ORDER BY player_name LIMIT 25');
    $stmt->execute([':q' => '%' . $query . '%']);
    $players = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NBA Stats History</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; color: #333; padding: 2rem; }
        h1 { margin-bottom: 1rem; }
        .search-form { margin-bottom: 1.5rem; }
        .search-form input[type="text"] { padding: 0.5rem 1rem; font-size: 1rem; width: 300px; border: 1px solid #ccc; border-radius: 4px; }
        .search-form button { padding: 0.5rem 1rem; font-size: 1rem; background: #1d428a; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .search-form button:hover { background: #163570; }
        .player-list { list-style: none; }
        .player-list li { padding: 0.5rem 0; }
        .player-list a { color: #1d428a; text-decoration: none; font-size: 1.1rem; }
        .player-list a:hover { text-decoration: underline; }
        .empty { color: #888; font-style: italic; }
    </style>
</head>
<body>
    <h1>NBA Stats History</h1>
    <p style="margin-bottom: 1rem; color: #666;">2025-26 Season &mdash; Search for a player to view their game log</p>

    <form class="search-form" method="get" action="/">
        <input type="text" name="q" placeholder="Search players..." value="<?= htmlspecialchars($query) ?>" autofocus>
        <button type="submit">Search</button>
    </form>

    <?php if ($query !== '' && empty($players)): ?>
        <p class="empty">No players found for &ldquo;<?= htmlspecialchars($query) ?>&rdquo;</p>
    <?php elseif (!empty($players)): ?>
        <ul class="player-list">
            <?php foreach ($players as $p): ?>
                <li>
                    <a href="/player.php?id=<?= $p['player_id'] ?>">
                        <?= htmlspecialchars($p['player_name']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</body>
</html>
