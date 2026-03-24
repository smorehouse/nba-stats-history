<?php
require_once __DIR__ . '/db.php';

$db = get_db();

$player_id = (int)($_GET['id'] ?? 0);
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['to'] ?? date('Y-m-d');

// Get player info
$stmt = $db->prepare('SELECT player_name FROM players WHERE player_id = :id');
$stmt->execute([':id' => $player_id]);
$player = $stmt->fetch();

if (!$player) {
    die('Player not found.');
}

// Get game logs within date range
$stmt = $db->prepare('
    SELECT * FROM game_logs
    WHERE player_id = :id
      AND game_date >= :date_from
      AND game_date <= :date_to
    ORDER BY game_date DESC
');
$stmt->execute([
    ':id' => $player_id,
    ':date_from' => $date_from,
    ':date_to' => $date_to,
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

// Build back link preserving date range
$back_params = http_build_query(['from' => $date_from, 'to' => $date_to]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($player['player_name']) ?> &mdash; NBA Stats History</title>
    <script>
        (function() {
            var saved = localStorage.getItem('theme');
            if (saved) document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
    <style>
        :root {
            --bg: #f5f5f5;
            --text: #333;
            --text-muted: #666;
            --text-faint: #888;
            --surface: #fff;
            --surface-alt: #f9f9f9;
            --hover: #e8edf5;
            --border: #e0e0e0;
            --shadow: rgba(0,0,0,0.1);
            --accent: #1d428a;
            --accent-hover: #163570;
            --accent-text: #fff;
            --z-pos: #2e7d32;
            --z-neg: #c62828;
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --bg: #1a1a2e;
                --text: #e0e0e0;
                --text-muted: #aaa;
                --text-faint: #888;
                --surface: #16213e;
                --surface-alt: #1a2744;
                --hover: #1f2f50;
                --border: #2a3a5c;
                --shadow: rgba(0,0,0,0.3);
                --accent: #5b8bd4;
                --accent-hover: #7aa3e0;
                --accent-text: #fff;
                --z-pos: #66bb6a;
                --z-neg: #ef5350;
            }
        }
        :root[data-theme="dark"] {
            --bg: #1a1a2e;
            --text: #e0e0e0;
            --text-muted: #aaa;
            --text-faint: #888;
            --surface: #16213e;
            --surface-alt: #1a2744;
            --hover: #1f2f50;
            --border: #2a3a5c;
            --shadow: rgba(0,0,0,0.3);
            --accent: #5b8bd4;
            --accent-hover: #7aa3e0;
            --accent-text: #fff;
            --z-pos: #66bb6a;
            --z-neg: #ef5350;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); padding: 2rem; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.25rem; }
        h1 { margin-bottom: 0; }
        #theme-toggle { background: none; border: 1px solid var(--border); border-radius: 4px; cursor: pointer; font-size: 1.2rem; padding: 0.2rem 0.5rem; line-height: 1; color: var(--text); }
        #theme-toggle:hover { background: var(--hover); }
        .subtitle { color: var(--text-muted); margin-bottom: 1.5rem; }
        .averages { background: var(--surface); border-radius: 8px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; display: flex; gap: 2rem; flex-wrap: wrap; box-shadow: 0 1px 3px var(--shadow); }
        .avg-stat { text-align: center; }
        .avg-stat .value { font-size: 1.5rem; font-weight: 700; color: var(--accent); }
        .avg-stat .label { font-size: 0.8rem; color: var(--text-faint); text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px var(--shadow); }
        th, td { padding: 0.5rem 0.75rem; text-align: center; }
        th { background: var(--accent); color: var(--accent-text); font-size: 0.85rem; text-transform: uppercase; }
        tr:nth-child(even) { background: var(--surface-alt); }
        tr:hover { background: var(--hover); }
        td { font-size: 0.9rem; }
        .win { color: var(--z-pos); font-weight: 600; }
        .loss { color: var(--z-neg); font-weight: 600; }
        .empty { color: var(--text-faint); font-style: italic; margin-top: 1rem; }
    </style>
</head>
<body>
    <p><a href="/?<?= $back_params ?>">&larr; Back to rankings</a></p>
    <div class="header">
        <h1><?= htmlspecialchars($player['player_name']) ?></h1>
        <button id="theme-toggle" title="Toggle dark mode"></button>
    </div>
    <p class="subtitle"><?= $date_from ?> to <?= $date_to ?> (<?= $count ?> games)</p>

    <?php if ($count > 0): ?>
        <div class="averages">
            <div class="avg-stat"><div class="value"><?= $avgs['pts'] ?></div><div class="label">PPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['reb'] ?></div><div class="label">RPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['ast'] ?></div><div class="label">APG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['stl'] ?></div><div class="label">SPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['blk'] ?></div><div class="label">BPG</div></div>
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
        <p class="empty">No games found in this date range.</p>
    <?php endif; ?>
<script>
    var html = document.documentElement;
    var toggle = document.getElementById('theme-toggle');
    function isDark() {
        return html.getAttribute('data-theme') === 'dark' ||
            (!html.getAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }
    function updateIcon() { toggle.textContent = isDark() ? '\u2600\uFE0F' : '\uD83C\uDF19'; }
    updateIcon();
    toggle.addEventListener('click', function() {
        var next = isDark() ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
        updateIcon();
    });
</script>
</body>
</html>
