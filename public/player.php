<?php
require_once __DIR__ . '/db.php';

$db = get_db();

$player_id = (int)($_GET['id'] ?? 0);
$days = (int)($_GET['days'] ?? 30);

if ($days < 1) $days = 30;
if ($days > 365) $days = 365;

// Get player info
$stmt = $db->prepare('SELECT player_name FROM players WHERE player_id = :id');
$stmt->execute([':id' => $player_id]);
$player = $stmt->fetch();

if (!$player) {
    die('Player not found.');
}

// Get game logs within the last N days
$stmt = $db->prepare('
    SELECT * FROM game_logs
    WHERE player_id = :id
      AND game_date >= date(:today, :offset)
    ORDER BY game_date DESC
');
$stmt->execute([
    ':id' => $player_id,
    ':today' => date('Y-m-d'),
    ':offset' => "-{$days} days",
]);
$games = $stmt->fetchAll();

// Compute averages
$totals = ['pts' => 0, 'reb' => 0, 'ast' => 0, 'stl' => 0, 'blk' => 0, 'tov' => 0,
           'fgm' => 0, 'fga' => 0, 'fg3m' => 0, 'fg3a' => 0, 'ftm' => 0, 'fta' => 0,
           'min' => 0, 'plus_minus' => 0];
$count = count($games);

foreach ($games as $g) {
    foreach ($totals as $key => &$val) {
        $val += (int)$g[$key];
    }
    unset($val);
}

$avgs = [];
if ($count > 0) {
    foreach ($totals as $key => $val) {
        $avgs[$key] = round($val / $count, 1);
    }
    $avgs['fg_pct'] = $totals['fga'] > 0 ? round($totals['fgm'] / $totals['fga'] * 100, 1) : 0;
    $avgs['fg3_pct'] = $totals['fg3a'] > 0 ? round($totals['fg3m'] / $totals['fg3a'] * 100, 1) : 0;
    $avgs['ft_pct'] = $totals['fta'] > 0 ? round($totals['ftm'] / $totals['fta'] * 100, 1) : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($player['player_name']) ?> &mdash; NBA Stats History</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; color: #333; padding: 2rem; }
        a { color: #1d428a; text-decoration: none; }
        a:hover { text-decoration: underline; }
        h1 { margin-bottom: 0.25rem; }
        .subtitle { color: #666; margin-bottom: 1.5rem; }
        .controls { margin-bottom: 1.5rem; display: flex; gap: 0.5rem; align-items: center; }
        .controls label { font-weight: 600; }
        .controls input { width: 80px; padding: 0.4rem; font-size: 1rem; border: 1px solid #ccc; border-radius: 4px; }
        .controls button { padding: 0.4rem 1rem; font-size: 1rem; background: #1d428a; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .averages { background: white; border-radius: 8px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; display: flex; gap: 2rem; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .avg-stat { text-align: center; }
        .avg-stat .value { font-size: 1.5rem; font-weight: 700; color: #1d428a; }
        .avg-stat .label { font-size: 0.8rem; color: #888; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th, td { padding: 0.5rem 0.75rem; text-align: center; }
        th { background: #1d428a; color: white; font-size: 0.85rem; text-transform: uppercase; }
        tr:nth-child(even) { background: #f9f9f9; }
        td { font-size: 0.9rem; }
        .win { color: #2e7d32; font-weight: 600; }
        .loss { color: #c62828; font-weight: 600; }
        .empty { color: #888; font-style: italic; margin-top: 1rem; }
    </style>
</head>
<body>
    <p><a href="/">&larr; Back to search</a></p>
    <h1><?= htmlspecialchars($player['player_name']) ?></h1>
    <p class="subtitle">2025-26 Season &mdash; Last <?= $days ?> days (<?= $count ?> games)</p>

    <form class="controls" method="get" action="/player.php">
        <input type="hidden" name="id" value="<?= $player_id ?>">
        <label for="days">Last</label>
        <input type="number" id="days" name="days" value="<?= $days ?>" min="1" max="365">
        <label>days</label>
        <button type="submit">Update</button>
    </form>

    <?php if ($count > 0): ?>
        <div class="averages">
            <div class="avg-stat"><div class="value"><?= $avgs['pts'] ?></div><div class="label">PPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['reb'] ?></div><div class="label">RPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['ast'] ?></div><div class="label">APG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['stl'] ?></div><div class="label">SPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['blk'] ?></div><div class="label">BPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['tov'] ?></div><div class="label">TOV</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['fg_pct'] ?>%</div><div class="label">FG%</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['fg3_pct'] ?>%</div><div class="label">3P%</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['ft_pct'] ?>%</div><div class="label">FT%</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['min'] ?></div><div class="label">MPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['plus_minus'] > 0 ? '+' : '' ?><?= $avgs['plus_minus'] ?></div><div class="label">+/-</div></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Date</th><th>Matchup</th><th>W/L</th><th>MIN</th>
                    <th>PTS</th><th>REB</th><th>AST</th><th>STL</th><th>BLK</th><th>TOV</th>
                    <th>FG</th><th>3PT</th><th>FT</th><th>+/-</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($games as $g): ?>
                <tr>
                    <td><?= $g['game_date'] ?></td>
                    <td><?= htmlspecialchars($g['matchup']) ?></td>
                    <td class="<?= $g['wl'] === 'W' ? 'win' : 'loss' ?>"><?= $g['wl'] ?></td>
                    <td><?= $g['min'] ?></td>
                    <td><strong><?= $g['pts'] ?></strong></td>
                    <td><?= $g['reb'] ?></td>
                    <td><?= $g['ast'] ?></td>
                    <td><?= $g['stl'] ?></td>
                    <td><?= $g['blk'] ?></td>
                    <td><?= $g['tov'] ?></td>
                    <td><?= $g['fgm'] ?>/<?= $g['fga'] ?></td>
                    <td><?= $g['fg3m'] ?>/<?= $g['fg3a'] ?></td>
                    <td><?= $g['ftm'] ?>/<?= $g['fta'] ?></td>
                    <td><?= $g['plus_minus'] > 0 ? '+' : '' ?><?= $g['plus_minus'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="empty">No games found in the last <?= $days ?> days.</p>
    <?php endif; ?>
</body>
</html>
